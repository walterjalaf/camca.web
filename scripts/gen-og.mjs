// Genera public/og-image.png (1200×630) — paleta del brochure + logo real.
// Ejecutar: node scripts/gen-og.mjs
import sharp from 'sharp';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const out = resolve(__dirname, '../public/og-image.png');
const logoPath = resolve(__dirname, '../public/logo.webp');

const INK = '#0E1F33';
const GOLD = '#F5A623';
const WHITE = '#FFFFFF';

let grid = '';
for (let x = 0; x <= 1200; x += 48) grid += `<line x1="${x}" y1="0" x2="${x}" y2="630" stroke="#FFFFFF" stroke-opacity="0.045"/>`;
for (let y = 0; y <= 630; y += 48) grid += `<line x1="0" y1="${y}" x2="1200" y2="${y}" stroke="#FFFFFF" stroke-opacity="0.045"/>`;

const svg = `<svg width="1200" height="630" viewBox="0 0 1200 630" xmlns="http://www.w3.org/2000/svg">
  <defs>
    <radialGradient id="g" cx="80%" cy="12%" r="70%">
      <stop offset="0%" stop-color="#2D6CB8" stop-opacity="0.35"/>
      <stop offset="60%" stop-color="#1E4A82" stop-opacity="0"/>
    </radialGradient>
  </defs>
  <rect width="1200" height="630" fill="${INK}"/>
  <rect width="1200" height="630" fill="url(#g)"/>
  ${grid}
  <text x="90" y="270" font-family="Arial, sans-serif" font-weight="700" font-size="22" letter-spacing="5" fill="${GOLD}">CONSULTORA INTEGRAL · SAN JUAN</text>
  <text x="86" y="350" font-family="Arial, sans-serif" font-weight="800" font-size="74" fill="${WHITE}">Más que números,</text>
  <text x="86" y="434" font-family="Arial, sans-serif" font-weight="800" font-size="74" fill="${WHITE}">soluciones a medida.</text>
  <rect x="90" y="452" width="360" height="6" fill="${GOLD}"/>
  <text x="92" y="520" font-family="Arial, sans-serif" font-size="22" fill="#FFFFFF" fill-opacity="0.6">ciafconsultora.com.ar</text>
</svg>`;

// Logo real arriba a la izquierda
const logo = await sharp(logoPath).resize({ height: 90 }).toBuffer();

await sharp(Buffer.from(svg))
  .composite([{ input: logo, top: 80, left: 90 }])
  .png()
  .toFile(out);
console.log('og-image.png generado en', out);
