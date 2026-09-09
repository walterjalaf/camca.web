// Genera public/og-image.png (1200×630) — paleta CAMCA + logotipo recreado.
// Ejecutar: node scripts/gen-og.mjs
import sharp from 'sharp';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const out = resolve(__dirname, '../public/og-image.png');

const INK = '#0B3B24';
const GOLD = '#C97D4A';
const WHITE = '#FFFFFF';
const CUBE_TOP = '#9C8D7C';
const CUBE_FACE = '#2E7D53';

let grid = '';
for (let x = 0; x <= 1200; x += 48) grid += `<line x1="${x}" y1="0" x2="${x}" y2="630" stroke="#FFFFFF" stroke-opacity="0.045"/>`;
for (let y = 0; y <= 630; y += 48) grid += `<line x1="0" y1="${y}" x2="1200" y2="${y}" stroke="#FFFFFF" stroke-opacity="0.045"/>`;

const svg = `<svg width="1200" height="630" viewBox="0 0 1200 630" xmlns="http://www.w3.org/2000/svg">
  <defs>
    <radialGradient id="g" cx="80%" cy="12%" r="70%">
      <stop offset="0%" stop-color="${CUBE_FACE}" stop-opacity="0.35"/>
      <stop offset="60%" stop-color="${INK}" stop-opacity="0"/>
    </radialGradient>
  </defs>
  <rect width="1200" height="630" fill="${INK}"/>
  <rect width="1200" height="630" fill="url(#g)"/>
  ${grid}

  <!-- Isotipo -->
  <g transform="translate(90,70)">
    <polygon points="38,2 68,18 38,34 8,18" fill="${CUBE_TOP}"/>
    <polygon points="8,18 38,34 38,66 8,50" fill="${WHITE}"/>
    <polygon points="68,18 38,34 38,66 68,50" fill="${CUBE_FACE}"/>
  </g>
  <text x="132" y="98" font-family="Georgia, 'Times New Roman', serif" font-size="30" letter-spacing="4" fill="${WHITE}">CAMCA</text>

  <text x="90" y="270" font-family="Arial, sans-serif" font-weight="700" font-size="20" letter-spacing="5" fill="${GOLD}">SERVICIOS INTEGRALES · CALINGASTA</text>
  <text x="86" y="350" font-family="Arial, sans-serif" font-weight="800" font-size="66" fill="${WHITE}">Soluciones sanitarias</text>
  <text x="86" y="424" font-family="Arial, sans-serif" font-weight="800" font-size="66" fill="${WHITE}">de alta montaña.</text>
  <rect x="90" y="452" width="360" height="6" fill="${GOLD}"/>
  <text x="92" y="520" font-family="Arial, sans-serif" font-size="22" fill="#FFFFFF" fill-opacity="0.6">camcasoluciones.com.ar</text>
</svg>`;

await sharp(Buffer.from(svg)).png().toFile(out);
console.log('og-image.png generado en', out);
