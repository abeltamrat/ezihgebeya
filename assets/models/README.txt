Homepage hero 3D showroom models (assets/js/home-three.js)

All three are CC0 (public domain, no attribution required), downloaded at 1k
resolution (glTF + diffuse/normal/ARM texture maps) from Poly Haven
(https://polyhaven.com), which hosts real photoreal PBR-textured models at
true real-world scale (meters) rather than flat-shaded low-poly game assets:

  sofa/sofa_02_1k.gltf    "Sofa 02"              by Kirill Sannikov
                          https://polyhaven.com/a/sofa_02

  table/coffee_table_round_01_1k.gltf   "Coffee Table Round 01"  by Ulan Cabanilla
                          https://polyhaven.com/a/coffee_table_round_01

  vase/ceramic_vase_01_1k.gltf   "Ceramic Vase 01"  by James Ray Cock
                          https://polyhaven.com/a/ceramic_vase_01

Each folder is self-contained (gltf + .bin + textures/) exactly matching the
relative paths Poly Haven's exporter wrote into the .gltf, so don't rename or
move files within a folder without updating the .gltf's own references.

Materials are used as authored (real diffuse/normal/roughness/metalness
texture maps) — not recolored in code — since tinting a photographed PBR
texture toward a brand color looks muddy rather than realistic.
