# CAMCA Servicios Integrales — Sitio web

Sitio institucional de **CAMCA Servicios Integrales** (Tamberías, Calingasta, San Juan,
Argentina): soluciones sanitarias móviles para minería, construcción, eventos y
operaciones de alta exigencia. Construido en **Astro 5** (estático), con un design
system propio (sin Tailwind ni librerías UI) y casi nada de JavaScript en el cliente.

---

## Stack

- **Astro 5** · output estático (`output: 'static'`)
- CSS propio con custom properties → [`src/styles/tokens.css`](src/styles/tokens.css)
- Paleta muestreada del logo real: `--ink #0B3B24` (verde bosque) / `--brand #2E7D53`
  (verde marca) / `--gold #C97D4A` (terracota, color de la cordillera de Calingasta),
  tipografías **Montserrat** (display) + **Open Sans** (body) + **JetBrains Mono**
  (etiquetas), self-hosted en `/public/fonts` (subset latin, `preload`)
- Logo recreado en SVG (`Logo.astro`, sin dependencia de un archivo raster)
- Vanilla JS solo para: reveal on scroll, navbar mobile/dropdown y envío del formulario
- `@astrojs/sitemap` → `sitemap-index.xml`
- Deploy: **Netlify** (`netlify.toml` + Netlify Forms) o **Hostinger** vía FTP
  (`.github/workflows/deploy.yml`)

## Requisitos

- Node 20+ (probado en Node 24)

## Comandos

```bash
npm install        # instalar dependencias
npm run dev        # servidor de desarrollo (http://localhost:4321)
npm run build      # build de producción → dist/
npm run preview    # previsualizar el build
```

Script auxiliar (se corre a mano, no en cada build):

```bash
node scripts/gen-og.mjs   # regenera public/og-image.png (1200×630)
```

## Estructura

```
src/
  data/         # ÚNICA fuente de contenido (editar acá)
    site.ts         → contacto, navegación, KPIs, claim
    servicios.ts     → los 8 servicios, beneficios, diferenciales
    clientes.ts      → clientes reales (sin logos disponibles → placeholder)
    industrias.ts    → contenido de las 6 verticales (dolores, FAQ, caso…)
    icons.ts          → iconos SVG de línea
  components/    # Navbar, Footer, PageHero, Card, LogoMarquee, KpiStat,
                 # ServiceBlock, FaqAccordion, CtaBanner, SectionHeading, Seo, Logo
  layouts/       # BaseLayout.astro (head + SEO + reveal)
  pages/         # index, servicios, nosotros, contacto, 404
    industrias/[slug].astro  → genera las 6 verticales
  styles/        # tokens.css, global.css
public/
  fonts/         # montserrat.woff2, opensans.woff2, jetbrains.woff2
  fotos/         # fotos reales de CAMCA (equipos, módulos, industrias)
  favicon.svg, og-image.png, robots.txt
```

## Editar contenido

Todo el contenido vive en `src/data/`. No hace falta tocar los componentes.

- **Datos de contacto / dirección:** [`src/data/site.ts`](src/data/site.ts) (`CONTACT`).
  El número de WhatsApp y teléfono se definen una sola vez ahí.
- **Servicios:** [`src/data/servicios.ts`](src/data/servicios.ts)
- **Industrias (verticales):** [`src/data/industrias.ts`](src/data/industrias.ts) —
  cada objeto genera su página en `/industrias/<slug>`.
- **Clientes:** [`src/data/clientes.ts`](src/data/clientes.ts) — hoy son 4 clientes
  reales sin archivo de logo (se muestran como placeholder tipográfico). Para cargar un
  logo real, agregá el archivo a `public/clientes/` y poné su ruta en `logo`.

## Formulario de contacto (Netlify Forms)

El formulario de [`/contacto`](src/pages/contacto.astro) usa Netlify Forms:
`data-netlify="true"`, honeypot `bot-field` y un input oculto `form-name`. El envío se
hace por `fetch` y muestra un mensaje de éxito en la misma página.

- Netlify detecta el formulario automáticamente en el primer deploy.
- Las respuestas quedan en **Netlify → Forms**. Configurá ahí las notificaciones por email.

## Deploy

- **Netlify:** Netlify lee [`netlify.toml`](netlify.toml): build `npm run build`,
  publish `dist`. En **Site settings → Forms**, verificá que `contacto` aparezca.
- **Hostinger (FTP):** [`.github/workflows/deploy.yml`](.github/workflows/deploy.yml)
  sube `dist/` por FTP en cada push a `main`. Requiere los secrets `FTP_SERVER`,
  `FTP_USERNAME`, `FTP_PASSWORD`, `FTP_SERVER_DIR` en GitHub.
- El dominio `camcaserviciosintegrales.com.ar` usado en `astro.config.mjs` y
  `public/robots.txt` es un **placeholder** — actualizalo cuando el dominio real esté
  definido.

## SEO

- Metas únicas, canonical, Open Graph y Twitter en todas las páginas (`Seo.astro`).
- **JSON-LD**: `ProfessionalService` + `WebSite` (home), `Service` + `BreadcrumbList`
  (servicios y verticales), `FAQPage` (verticales), `OfferCatalog` (servicios).
- `sitemap-index.xml` y `robots.txt` se generan/sirven en el build.

## Reglas de contenido

- No inventar clientes, testimonios ni números. Datos citables: los 4 clientes de
  `clientes.ts` (Los Azules, Clínica El Castaño, Parque · Parador de Montaña, 4R
  Ferretería y Bulonería) y las cifras de `STATS` en `site.ts`.
- Fuente de la identidad y el contenido institucional: [`docs/referencias/`](docs/referencias)
  (manual de marca, presentación institucional y flyer de CAMCA).
