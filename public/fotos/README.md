# Fotos del sitio — fotos de stock (reemplazables por las tuyas)

Estos archivos son **fotos de stock** (de Unsplash, libres de uso) ya optimizadas a `.webp`
(1200×950, ~q78). Sirven para que el sitio se vea terminado. Cuando tengas fotos propias,
reemplazá cada archivo manteniendo el **mismo nombre** y formato `.webp` (o `.jpg`, pero
entonces actualizá la ruta en el código).

Si borrás un archivo, no se rompe nada: el componente muestra un gradiente de marca como
fallback. Pero lo ideal es mantener una foto real.

## Archivos esperados

| Archivo | Dónde se usa | Tamaño sugerido | Sugerencia de contenido |
|---|---|---|---|
| `hero-home.webp` | Portada (Home), panel del hero | ~1200×1000 | Equipo trabajando / persona del equipo con un cliente |
| `nosotros.webp` | Página Nosotros, banda "Personas que atienden a personas" | ~1200×900 | El equipo de CIAF, oficina, San Juan |
| `industrias/gastronomia.webp` | Hero industria Gastronomía | ~1200×900 | Cocina/salón de un cliente del rubro |
| `industrias/mineria-y-servicios.webp` | Hero industria Minería y servicios | ~1200×900 | Operación/servicios a la minería |
| `industrias/energia.webp` | Hero industria Energía | ~1200×900 | Estación / energía |
| `industrias/retail.webp` | Hero industria Retail | ~1200×900 | Local comercial |
| `industrias/turismo-y-deportes.webp` | Hero industria Turismo y deportes | ~1200×900 | Turismo aventura / deporte |
| `industrias/distribucion.webp` | Hero industria Distribución | ~1200×900 | Logística / depósito |

## Cómo optimizar antes de subir
Pasá la foto a `.webp` (~80% calidad, ancho máx ~1400px). Podés usar el script existente
`scripts/optimize-images.mjs` como referencia, o cualquier conversor a WebP.
