import * as THREE from 'three';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { RoomEnvironment } from 'three/addons/environments/RoomEnvironment.js';

const mount = document.querySelector('#hero-three');
const canvas = mount?.querySelector('canvas');

if (mount && canvas) {
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  let renderer;

  try {
    renderer = new THREE.WebGLRenderer({
      canvas,
      alpha: true,
      antialias: window.innerWidth > 700,
      powerPreference: 'low-power',
    });
  } catch {
    renderer = null;
  }

  if (renderer) {
    renderer.setClearColor(0x000000, 0);
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, window.innerWidth < 700 ? 1 : 1.5));
    renderer.outputColorSpace = THREE.SRGBColorSpace;
    renderer.toneMapping = THREE.ACESFilmicToneMapping;
    renderer.toneMappingExposure = 1.08;
    renderer.shadowMap.enabled = true;
    renderer.shadowMap.type = THREE.PCFShadowMap;

    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(32, 1, 0.1, 100);
    camera.position.set(2.75, 1.55, 3.55);
    camera.lookAt(0.05, 0.45, 0.85);

    // Soft studio-style reflections/ambient color from a generated environment (no HDR file to fetch).
    try {
      const pmrem = new THREE.PMREMGenerator(renderer);
      scene.environment = pmrem.fromScene(new RoomEnvironment()).texture;
      scene.environmentIntensity = 0.32;
      pmrem.dispose();
    } catch {
      /* environment map is a polish-only enhancement; scene still lights fine without it */
    }

    const showroom = new THREE.Group();
    showroom.rotation.y = -0.18;
    scene.add(showroom);

    // Real furniture meshes (see assets/models/README.txt for source + CC0 license), each shipped
    // with its own photographed/authored PBR textures (diffuse, normal, roughness, metalness) at
    // true real-world scale (meters) — unlike hand-built primitives, so materials are left as
    // authored rather than recolored, and positions below are real furniture-layout distances.
    const loader = new GLTFLoader();
    const placeModel = async (url, { x, y = 0, z, scale = 1, rotationY = 0 }) => {
      const gltf = await loader.loadAsync(url);
      const root = gltf.scene;
      root.traverse((node) => {
        if (node.isMesh) {
          node.castShadow = true;
          node.receiveShadow = true;
        }
      });
      const box = new THREE.Box3().setFromObject(root);
      const center = box.getCenter(new THREE.Vector3());
      root.position.set(-center.x, -box.min.y, -center.z);

      const anchor = new THREE.Group();
      anchor.add(root);
      anchor.scale.setScalar(scale);
      anchor.position.set(x, y, z);
      anchor.rotation.y = rotationY;
      showroom.add(anchor);
      return anchor;
    };

    const tablePos = { x: 0.65, z: 1.45 };
    const tableTopY = 0.49; // coffee_table_round_01's real height, meters
    const loadFurniture = () => Promise.all([
      placeModel(mount.dataset.sofa, { x: -0.35, z: 0, rotationY: 0.14 }),
      placeModel(mount.dataset.table, tablePos),
      placeModel(mount.dataset.vase, {
        x: tablePos.x + 0.18,
        y: tableTopY,
        z: tablePos.z + 0.05,
        rotationY: 0.5,
      }),
    ]);

    // Ground and subtle brand-colored orbit lines add depth without competing with copy.
    const floor = new THREE.Mesh(
      new THREE.CircleGeometry(3.8, 64),
      new THREE.MeshStandardMaterial({ color: 0xffffff, transparent: true, opacity: 0.12, roughness: 1 })
    );
    floor.rotation.x = -Math.PI / 2;
    floor.position.y = -0.02;
    floor.receiveShadow = true;
    showroom.add(floor);

    [2.55, 3.1].forEach((radius, index) => {
      const ring = new THREE.Mesh(
        new THREE.TorusGeometry(radius, 0.014, 8, 96),
        new THREE.MeshBasicMaterial({ color: index ? 0xf0a24e : 0x8fb2ff, transparent: true, opacity: 0.42 })
      );
      ring.rotation.set(Math.PI / 2, 0, index ? 0.22 : -0.16);
      ring.position.y = 0.02;
      showroom.add(ring);
    });

    scene.add(new THREE.HemisphereLight(0xdbeafe, 0x172554, 1.3));
    const key = new THREE.DirectionalLight(0xffffff, 2.6);
    key.position.set(3, 5, 3.4);
    key.castShadow = true;
    key.shadow.mapSize.set(1024, 1024);
    key.shadow.camera.near = 1;
    key.shadow.camera.far = 12;
    key.shadow.camera.left = -3.5;
    key.shadow.camera.right = 3.5;
    key.shadow.camera.top = 3.5;
    key.shadow.camera.bottom = -3.5;
    key.shadow.bias = -0.0025;
    key.shadow.radius = 3;
    scene.add(key);
    const warm = new THREE.PointLight(0xffb65c, 12, 12);
    warm.position.set(-2.4, 2, 2.2);
    scene.add(warm);

    const pointer = new THREE.Vector2();
    const target = new THREE.Vector2();
    let visible = true;
    let frame = 0;
    const clock = new THREE.Timer();

    const resize = () => {
      const rect = mount.getBoundingClientRect();
      if (!rect.width || !rect.height) return;
      renderer.setSize(rect.width, rect.height, false);
      camera.aspect = rect.width / rect.height;
      camera.updateProjectionMatrix();
    };

    const render = () => {
      frame = 0;
      if (!visible || document.hidden) return;
      clock.update();
      const elapsed = clock.getElapsed();
      pointer.lerp(target, 0.045);
      showroom.rotation.y = -0.18 + pointer.x * 0.13 + (reducedMotion ? 0 : Math.sin(elapsed * 0.28) * 0.025);
      showroom.rotation.x = pointer.y * 0.035;
      showroom.position.y = reducedMotion ? 0 : Math.sin(elapsed * 0.55) * 0.035;
      renderer.render(scene, camera);
      if (!reducedMotion) frame = requestAnimationFrame(render);
    };

    const start = () => {
      if (!frame && !reducedMotion) frame = requestAnimationFrame(render);
      else if (reducedMotion) render();
    };

    mount.closest('.hero')?.addEventListener('pointermove', (event) => {
      const rect = mount.getBoundingClientRect();
      target.set(
        ((event.clientX - rect.left) / rect.width - 0.5) * 2,
        -((event.clientY - rect.top) / rect.height - 0.5) * 2
      );
    }, { passive: true });

    new ResizeObserver(() => {
      resize();
      if (reducedMotion) render();
    }).observe(mount);

    new IntersectionObserver(([entry]) => {
      visible = entry.isIntersecting;
      if (visible) start();
      else if (frame) {
        cancelAnimationFrame(frame);
        frame = 0;
      }
    }, { rootMargin: '100px' }).observe(mount);

    document.addEventListener('visibilitychange', () => {
      if (!document.hidden && visible) start();
    });

    resize();
    renderer.render(scene, camera);
    start();

    loadFurniture()
      .then(() => {
        resize();
        mount.classList.add('is-ready');
        // the continuous rAF loop (if running) will pick up the newly-added meshes on its own,
        // but reduced-motion mode only renders on demand — force one frame so furniture actually
        // appears instead of leaving the pre-load empty-room frame on screen.
        if (reducedMotion) render();
      })
      .catch(() => {
        /* model fetch failed (offline/CDN hiccup) — leave the panel hidden, same as the
           WebGL-unavailable fallback, rather than reveal an empty lit floor. */
      });
  }
}
