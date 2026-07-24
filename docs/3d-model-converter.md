# EzihGebeya 3D model conversion

EzihGebeya displays GLB on the web/Android and optionally USDZ on Apple devices.
Vendors can upload those final formats directly. The product form also has a free
on-device converter for common formats. A separately configured worker remains
available for SKP, large files, and formats needing native desktop software.

## Supported upload workflow

- Direct, no worker required: `.glb`, `.usdz`
- Free on-device conversion to GLB: `.fbx`, `.obj`, `.dae`, `.3ds`, `.stl`,
  `.ply`, `.gltf`; companion `.mtl`, `.bin`, and image textures can be selected
  in the same file picker.
- Optional server-worker sources: `.skp`, `.blend`, `.fbx`, `.obj`, `.dae`,
  `.3ds`, `.stl`, `.ply`, `.gltf`, `.zip`
- Upload a ZIP to the worker when the model depends on more than one file, such as
  `chair.obj + chair.mtl + textures/*` or `scene.gltf + scene.bin + textures/*`.
  The browser route accepts those files together without a ZIP.
- "Any 3D file" is not a safe or technically accurate promise. The accepted list
  in Admin → System Settings must match the worker's installed importers.

## Free open-source browser conversion

The vendor form dynamically loads [AssimpJS](https://github.com/kovacsv/assimpjs)
(MIT) and its WebAssembly build of [Assimp](https://github.com/assimp/assimp)
(BSD-3-Clause) only after a vendor selects source files. Geometry, material, and
texture source files are processed on the vendor's device. The application checks:

- exactly one supported main model plus up to 49 companion files;
- duplicate and unsupported filenames;
- a 100 MB total browser-source ceiling;
- the resulting GLB magic header and the admin-configured final-model size limit.

Only the validated GLB is uploaded when the product is saved. This avoids converter
hosting cost and keeps source files off the server. Browser conversion is still
CPU/RAM intensive; the asynchronous worker is preferable for large production
models.

The npm dependency is pinned by `frontend/package-lock.json`, Vite emits the
converter and WASM as content-hashed production assets, and the required licence
texts are deployed under `/app/licenses/assimpjs/`.

## Recommended open-source worker pipeline

For a self-hosted Linux/Docker worker, use components with one clear job each:

| Stage | Free/open-source project | Purpose |
|---|---|---|
| Import/export | [Assimp](https://github.com/assimp/assimp) or [Blender](https://projects.blender.org/blender/blender) | Convert installed source formats to GLB |
| FBX specialization | [FBX2glTF](https://github.com/facebookincubator/FBX2glTF) | Optional dedicated FBX → glTF converter |
| Mobile optimization | [`gltfpack` in meshoptimizer](https://github.com/zeux/meshoptimizer) | Reduce mesh size and GPU cost |
| Output validation | [Khronos glTF Validator](https://github.com/KhronosGroup/glTF-Validator) | Reject malformed or non-conformant GLB |
| USD/USDZ tooling | [tinyusdz](https://github.com/lighttransport/tinyusdz) | Read/write USD-family assets where its feature set is sufficient |

Run every model in an isolated, unprivileged container with strict CPU, memory,
disk, file-count, and time limits. Do not install a tool merely because it claims
"all formats"; enable only formats covered by repeatable test fixtures.

## SketchUp (`.skp`) limitation

SKP is the important exception: Assimp's maintained importer list does not include
SketchUp, and a normal Blender install cannot reliably import current `.skp` files.
The safe choices are:

1. Ask the vendor to export GLTF Binary (`.glb`) from a current SketchUp release.
2. Build a controlled worker with the
   [official SketchUp C API](https://extensions.sketchup.com/developers/sketchup_c_api/sketchup/index.html)
   after reviewing and accepting its SDK licence.
3. Use a third-party conversion service only after reviewing its privacy, retention,
   file-version, pricing, and API guarantees.

The public form links to SketchUp's official GLB export instructions. EzihGebeya
does not label a closed-source web converter as open source and does not promise
that arbitrary SKP versions will work.

## Enable the optional server worker

The browser converter needs no database migration or external service. To add the
server-worker route:

1. Apply `database/upgrade30.sql` from Admin → Backups → Run migrations.
2. Deploy a converter worker on a separate VPS/container.
3. In Admin → System Settings → 3D source-file conversion:
   - enter the worker's HTTPS job endpoint;
   - enter the same long random shared secret used by the worker;
   - limit accepted formats to the worker's real import capabilities;
   - set source size and retry limits;
   - enable conversion and save.
4. Run the dedicated dispatcher every five minutes (daily cron remains a fallback):

   ```sh
   curl -fsS -H "X-Cron-Secret: YOUR_CRON_SECRET" \
     https://YOUR_SITE/cron/model-conversions
   ```

The shared web host only stores files and dispatches jobs. Blender, SketchUp SDK,
Assimp, Apple Reality Converter, or other heavy tools belong on the worker.

## Worker request contract

EzihGebeya sends `POST multipart/form-data` to the configured endpoint with:

| Field | Meaning |
|---|---|
| `job_id` | EzihGebeya conversion job ID |
| `product_id` | Product listing ID |
| `source_format` | Lowercase extension |
| `callback_url` | Absolute signed-callback destination |
| `callback_token` | Per-job HMAC token |
| `model` | Private source model file |

The request includes:

```http
Authorization: Bearer <admin-configured-shared-secret>
Accept: application/json
```

The worker must authenticate first, copy the upload into an isolated per-job working
directory, enqueue it, and return quickly:

```json
{
  "accepted": true,
  "job_id": "worker-job-123"
}
```

A rejected request should use a non-2xx status and a short JSON error:

```json
{
  "accepted": false,
  "error": "SKP 2026 import is not installed"
}
```

## Success callback

After conversion and visual validation, the worker sends `POST multipart/form-data`
to `callback_url` with:

| Field/header | Value |
|---|---|
| `job_id` | Original EzihGebeya job ID |
| `status` | `completed` |
| `model_glb` | Required valid GLB file |
| `model_usdz` | Optional valid USDZ file |
| `X-Model-Callback-Token` | Original `callback_token` |

The application content-sniffs both outputs, enforces the configured final-model
size limit, atomically replaces older product models, removes the private source,
and notifies the vendor.

## Failure callback

If import/export fails:

```http
POST <callback_url>
Content-Type: application/x-www-form-urlencoded
X-Model-Callback-Token: <callback_token>

job_id=123&status=failed&error=Readable%20failure%20reason
```

The vendor receives a notification and can upload a different source or direct GLB.

## Worker security requirements

- Never execute scripts, macros, embedded binaries, or arbitrary commands from a model.
- Use an unprivileged container/process with CPU, memory, disk, file-count, and time limits.
- Reject archive traversal, symlinks, nested archive bombs, and excessive uncompressed size.
- Disable outbound network access while importing so external texture URLs cannot scan
  internal services.
- Delete worker input/output after callback completion.
- Normalize scale, origin, orientation, materials, and texture paths.
- Optimize dense geometry and textures for mobile delivery.
- Validate GLB structure and USDZ packaging before callback, then visually inspect a
  representative sample of conversions for each source format/version.
