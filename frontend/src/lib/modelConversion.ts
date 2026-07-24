export const CLIENT_MODEL_PRIMARY_EXTENSIONS = ['3ds', 'dae', 'fbx', 'gltf', 'obj', 'ply', 'stl'] as const;

export const CLIENT_MODEL_AUXILIARY_EXTENSIONS = [
  'bin',
  'bmp',
  'jpeg',
  'jpg',
  'mtl',
  'png',
  'tga',
  'webp',
] as const;

export const CLIENT_MODEL_ACCEPT = [
  ...CLIENT_MODEL_PRIMARY_EXTENSIONS,
  ...CLIENT_MODEL_AUXILIARY_EXTENSIONS,
].map((extension) => `.${extension}`).join(',');

export type ModelConversionProgress =
  | 'loading'
  | 'reading'
  | 'converting'
  | 'validating';

interface WorkerModelInput {
  name: string;
  buffer: ArrayBuffer;
}

type ConversionWorkerMessage =
  | { type: 'stage'; stage: 'loading' | 'converting' }
  | { type: 'success'; buffer: ArrayBuffer }
  | { type: 'error'; message: string };

function extensionOf(name: string): string {
  const base = name.replaceAll('\\', '/').split('/').pop() ?? '';
  const dot = base.lastIndexOf('.');
  return dot > 0 ? base.slice(dot + 1).toLowerCase() : '';
}

export function validateClientModelFiles(files: File[], maxSourceBytes: number): File {
  if (files.length === 0) throw new Error('Choose a 3D source file.');
  if (files.length > 50) throw new Error('Choose no more than 50 source, material, and texture files at once.');

  const supported = new Set<string>([
    ...CLIENT_MODEL_PRIMARY_EXTENSIONS,
    ...CLIENT_MODEL_AUXILIARY_EXTENSIONS,
  ]);
  const primary = files.filter((file) =>
    (CLIENT_MODEL_PRIMARY_EXTENSIONS as readonly string[]).includes(extensionOf(file.name)));
  const unsupported = files.filter((file) => !supported.has(extensionOf(file.name)));
  const totalBytes = files.reduce((sum, file) => sum + file.size, 0);
  const seenNames = new Set<string>();

  if (unsupported.length > 0) {
    throw new Error(`Unsupported file: ${unsupported[0].name}. Use FBX, OBJ, DAE, 3DS, STL, PLY, or glTF plus their material and texture files.`);
  }
  if (primary.length === 0) {
    throw new Error('Select one FBX, OBJ, DAE, 3DS, STL, PLY, or glTF model file.');
  }
  if (primary.length > 1) {
    throw new Error('Select one main model at a time, plus only its material and texture files.');
  }
  if (totalBytes > maxSourceBytes) {
    throw new Error(`The selected files exceed the ${Math.floor(maxSourceBytes / (1024 * 1024))} MB on-device conversion limit.`);
  }

  for (const file of files) {
    const name = (file.webkitRelativePath || file.name).replaceAll('\\', '/');
    const normalizedName = name.toLowerCase();
    if (
      name === ''
      || name.startsWith('/')
      || /^[a-z]:\//i.test(name)
      || name.split('/').some((part) => part === '..')
    ) {
      throw new Error(`Unsafe companion filename: ${file.name}.`);
    }
    if (seenNames.has(normalizedName)) {
      throw new Error('Two selected files have the same name. Keep only one copy of each companion file.');
    }
    seenNames.add(normalizedName);
  }

  return primary[0];
}

export async function convertModelFilesToGlb(
  files: File[],
  maxSourceBytes: number,
  maxOutputBytes: number,
  onProgress?: (progress: ModelConversionProgress) => void,
): Promise<File> {
  const primary = validateClientModelFiles(files, maxSourceBytes);
  onProgress?.('reading');

  const orderedFiles = [primary, ...files.filter((file) => file !== primary)];
  const inputs: WorkerModelInput[] = await Promise.all(orderedFiles.map(async (file) => ({
    name: (file.webkitRelativePath || file.name).replaceAll('\\', '/'),
    buffer: await file.arrayBuffer(),
  })));
  const transfer = inputs.map((input) => input.buffer);
  const worker = new Worker(new URL('../workers/modelConversion.worker.ts', import.meta.url));
  const safeBaseName = primary.name
    .replace(/\.[^.]+$/, '')
    .replace(/[^\p{L}\p{N}._-]+/gu, '-')
    .slice(0, 120) || 'model';

  return new Promise<File>((resolve, reject) => {
    let finished = false;
    const stop = () => {
      if (finished) return;
      finished = true;
      window.clearTimeout(timeout);
      worker.terminate();
    };
    const fail = (message: string) => {
      stop();
      reject(new Error(message));
    };
    const timeout = window.setTimeout(() => {
      fail('Browser conversion timed out. Try a smaller model or use server conversion.');
    }, 120_000);

    worker.onmessage = (event: MessageEvent<ConversionWorkerMessage>) => {
      if (event.data.type === 'stage') {
        onProgress?.(event.data.stage);
        return;
      }
      if (event.data.type === 'error') {
        fail(event.data.message);
        return;
      }

      onProgress?.('validating');
      const output = new Uint8Array(event.data.buffer);
      if (
        output.byteLength < 12
        || output[0] !== 0x67
        || output[1] !== 0x6c
        || output[2] !== 0x54
        || output[3] !== 0x46
      ) {
        fail('The converter did not produce a valid GLB file.');
        return;
      }
      if (output.byteLength > maxOutputBytes) {
        fail(`The converted GLB is larger than the ${Math.floor(maxOutputBytes / (1024 * 1024))} MB upload limit. Reduce texture sizes or simplify the model.`);
        return;
      }

      stop();
      resolve(new File([output], `${safeBaseName}.glb`, {
        type: 'model/gltf-binary',
        lastModified: Date.now(),
      }));
    };
    worker.onerror = (event) => {
      fail(event.message || 'The browser converter could not start.');
    };
    worker.postMessage({ files: inputs }, transfer);
  });
}
