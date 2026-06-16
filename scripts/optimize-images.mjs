// Convierte logos de /public/clientes y /public/integraciones a webp redimensionado.
// Los logos se muestran a ≤140px; generamos a 280px (retina) y borramos los originales.
// Ejecutar: node scripts/optimize-images.mjs
import sharp from 'sharp';
import { readdir, unlink, writeFile, rename } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, resolve, extname, basename, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const only = process.argv[2]; // opcional: 'clientes' | 'integraciones'
const all = [
  { dir: resolve(__dirname, '../public/clientes'), width: 280 },
  { dir: resolve(__dirname, '../public/integraciones'), width: 240 },
];
const targets = only ? all.filter((t) => t.dir.endsWith(only)) : all;

for (const { dir, width } of targets) {
  const files = await readdir(dir);
  for (const f of files) {
    const ext = extname(f).toLowerCase();
    // Solo rasters no-webp (los .webp ya están y se bloquean al reescribir en Windows)
    if (!['.png', '.jpg', '.jpeg', '.jfif'].includes(ext)) continue;
    const src = join(dir, f);
    const out = join(dir, basename(f, extname(f)) + '.webp');
    try {
      const buf = await sharp(src)
        .resize({ width, withoutEnlargement: true })
        .webp({ quality: 82 })
        .toBuffer();
      const tmp = out + '.tmp';
      await writeFile(tmp, buf);
      if (src !== out) await unlink(src).catch(() => {});
      await rename(tmp, out);
    } catch (err) {
      console.warn('  ⚠ no se pudo procesar', f, '—', err.message);
    }
  }
  console.log('optimizado:', dir);
}
console.log('Listo.');
