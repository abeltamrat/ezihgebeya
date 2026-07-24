// Copies the built SPA (frontend/dist) into app/ for serving at /app/*.
// Clears app/assets/ first so old content-hashed bundles (index-XXXX.js/css) don't
// accumulate across builds — app/ also holds the PHP core includes, so only the
// SPA-owned entries (assets/, licenses/, index.html, favicon.svg, icons.svg)
// are ever touched.
// Usage: npm run spa:sync   (after `npm run build` in frontend/)
import { cpSync, rmSync, existsSync, copyFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const dist = path.join(root, 'frontend', 'dist');
const app = path.join(root, 'app');

if (!existsSync(path.join(dist, 'index.html'))) {
  console.error('frontend/dist/index.html not found - run `npm run build` in frontend/ first.');
  process.exit(1);
}

rmSync(path.join(app, 'assets'), { recursive: true, force: true });
cpSync(path.join(dist, 'assets'), path.join(app, 'assets'), { recursive: true });
rmSync(path.join(app, 'licenses'), { recursive: true, force: true });
if (existsSync(path.join(dist, 'licenses'))) {
  cpSync(
    path.join(dist, 'licenses'),
    path.join(app, 'licenses'),
    { recursive: true },
  );
}
for (const file of ['index.html', 'favicon.svg', 'icons.svg']) {
  if (existsSync(path.join(dist, file))) copyFileSync(path.join(dist, file), path.join(app, file));
}
console.log('SPA synced: frontend/dist -> app/ (stale hashed assets removed).');
