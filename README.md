# CIAF Consultora Integral — Sitio web

Sitio institucional de **CIAF Consultora Integral** (San Juan, Argentina): outsourcing
administrativo, contable, impositivo y financiero. Construido en **Astro 5** (estático),
con un design system propio (sin Tailwind ni librerías UI) y casi nada de JavaScript en
el cliente.

---

## Stack

- **Astro 5** · output estático (`output: 'static'`)
- CSS propio con custom properties → [`src/styles/tokens.css`](src/styles/tokens.css)
- Identidad fiel al brochure institucional: paleta `--ink #0E1F33` / `--brand #1E4A82` / `--gold #F5A623`, tipografías **Montserrat** (display) + **Open Sans** (body) + **JetBrains Mono** (etiquetas), self-hosted en `/public/fonts` (subset latin, `preload`)
- Logo real (`logo_grande.png` → `logo.webp`) en navbar, footer y OG
- Sección **Stack / 12** (PCB animado de integraciones) portada del brochure en `StackSection.astro`
- Vanilla JS solo para: reveal on scroll, navbar mobile/dropdown y envío del formulario
- `@astrojs/sitemap` → `sitemap-index.xml`
- Deploy: **Netlify** (`netlify.toml` + Netlify Forms)

## Requisitos

- Node 20+ (probado en Node 24)

## Comandos

```bash
npm install        # instalar dependencias
npm run dev        # servidor de desarrollo (http://localhost:4321)
npm run build      # build de producción → dist/
npm run preview    # previsualizar el build
```

Scripts auxiliares (se corren a mano, no en cada build):

```bash
node scripts/gen-og.mjs            # regenera public/og-image.png (1200×630)
node scripts/optimize-images.mjs   # redimensiona logos a webp (clientes + integraciones)
# opcional: node scripts/optimize-images.mjs clientes|integraciones
```

## Estructura

```
src/
  data/         # ÚNICA fuente de contenido (editar acá)
    site.ts         → contacto, redes, navegación, KPIs, claim
    servicios.ts    → áreas, sub-servicios, dimensiones, complementarios
    clientes.ts     → clientes por vertical (+ logo o null)
    industrias.ts   → contenido de las 6 verticales (dolores, FAQ, caso…)
    stack.ts        → sistemas con los que trabaja CIAF
    icons.ts        → iconos SVG de línea
  components/    # Navbar, Footer, Hero, Card, LogoGrid, KpiStat, ComparisonTable,
                 # ServiceBlock, FaqAccordion, CtaBanner, SectionHeading, Seo, Logo
  layouts/       # BaseLayout.astro (head + SEO + reveal)
  pages/         # index, servicios, nosotros, contacto, 404
    industrias/[slug].astro  → genera las 6 verticales
  styles/        # tokens.css, global.css
public/
  fonts/         # fraunces.woff2, outfit.woff2
  clientes/      # logos de clientes (webp)
  integraciones/ # logos de sistemas (webp + svg)
  favicon.svg, og-image.png, robots.txt
```

## Editar contenido

Todo el contenido vive en `src/data/`. No hace falta tocar los componentes.

- **Datos de contacto / redes / dirección:** [`src/data/site.ts`](src/data/site.ts)
  (`CONTACT`). El número de WhatsApp y teléfono se definen una sola vez ahí.
- **Servicios y sub-servicios:** [`src/data/servicios.ts`](src/data/servicios.ts)
- **Industrias (verticales):** [`src/data/industrias.ts`](src/data/industrias.ts) —
  cada objeto genera su página en `/industrias/<slug>`.
- **Clientes:** [`src/data/clientes.ts`](src/data/clientes.ts)

### Reemplazar logos de clientes (placeholders → archivos reales)

Algunos clientes todavía no tienen logo y se muestran como **placeholder tipográfico**
(el nombre sobre fondo navy). Hoy son: **Bonafide, Las Invernadas Restó, Lorsani**
(gastronomía) y **Enjoy RH** (minería). Para cargar el logo real:

1. Dejá el archivo en `public/clientes/` (idealmente `.webp`, ~280px de ancho).
   Si tenés un PNG/JPG grande, corré `node scripts/optimize-images.mjs clientes`
   para convertir y redimensionar automáticamente.
2. En [`src/data/clientes.ts`](src/data/clientes.ts), cambiá `logo: null` por la ruta,
   p. ej. `logo: '/clientes/bonafide.webp'`.

El mismo procedimiento sirve para reemplazar cualquier logo existente.

> Nota: `Gestión Cervecera` en el stack se muestra como texto porque el archivo de logo
> original estaba dañado. Para mostrar su logo, agregá `public/integraciones/gestion-cervecera.webp`
> y poné su `logo` en [`src/data/stack.ts`](src/data/stack.ts).

## Formulario de contacto (Netlify Forms)

El formulario de [`/contacto`](src/pages/contacto.astro) usa Netlify Forms:
`data-netlify="true"`, honeypot `bot-field` y un input oculto `form-name`. El envío se
hace por `fetch` y muestra un mensaje de éxito en la misma página.

- Netlify detecta el formulario automáticamente en el primer deploy.
- Las respuestas quedan en **Netlify → Forms**. Configurá ahí las notificaciones por email.

## Deploy en Netlify

1. Subí el repo a GitHub/GitLab y conectalo en Netlify (o `netlify deploy`).
2. Netlify lee [`netlify.toml`](netlify.toml): build `npm run build`, publish `dist`.
3. En **Site settings → Forms**, verificá que `contacto` aparezca y sumá notificaciones.
4. Apuntá el dominio `ciafconsultora.com.ar`. Si cambia, actualizá `site` en
   [`astro.config.mjs`](astro.config.mjs) y la `Sitemap:` de `public/robots.txt`.

## SEO

- Metas únicas, canonical, Open Graph y Twitter en todas las páginas (`Seo.astro`).
- **JSON-LD**: `ProfessionalService` + `WebSite` (home), `Service` + `BreadcrumbList`
  (servicios y verticales), `FAQPage` (verticales), `OfferCatalog` (servicios).
  Validar en [Rich Results Test](https://search.google.com/test/rich-results).
- `sitemap-index.xml` y `robots.txt` se generan/sirven en el build.

## Rendimiento y accesibilidad (Lighthouse)

Medido sobre `npm run build` + `npm run preview`:

- **Desktop:** Performance **100**, Accesibilidad **100**, Best Practices **100**, SEO **100**
  (LCP ~0.5s, TBT ~60ms, CLS 0).
- **Mobile:** Accesibilidad **100**, Best Practices **100**, SEO **100**. Performance es
  alto (LCP < 2.2s, CLS 0, payload de imágenes ~360 KB, fuentes self-hosted, JS mínimo);
  el puntaje puntual de Performance mobile puede variar según la CPU de la máquina que
  corre la auditoría (Lighthouse aplica throttling 4× de CPU).

> **Validación final recomendada:** correr Lighthouse / PageSpeed Insights sobre la URL
> ya desplegada en Netlify, que es el entorno de referencia (CPU consistente).

## Reglas de contenido

- No inventar clientes, testimonios, números ni premios. Datos citables: +24 clientes,
  +5 años, ISO 9001 de FRAM (con acompañamiento de CIAF) y de YPF DANPE (+ Mención Oro
  al Premio Provincial a la Calidad 2023).
- Los espacios para testimonios quedan marcados con
  `<!-- TESTIMONIO PENDIENTE DE APROBACIÓN -->`.
- Tono: voseo en gastronomía/retail/turismo/distribución/home; trato impersonal/de
  "usted" en minería-B2B y energía.
