# Fotos del sitio — material real de CAMCA

Estos archivos son **fotos reales de CAMCA**, extraídas de la presentación institucional
y el flyer de marca (ver [`docs/referencias/`](../../docs/referencias)), optimizadas a
`.webp`. Si conseguís fotos nuevas de mejor calidad, reemplazá el archivo manteniendo el
**mismo nombre**.

Si borrás un archivo, no se rompe nada: el componente muestra un gradiente de marca
verde/terracota como fallback. Pero lo ideal es mantener una foto real.

## Archivos esperados

| Archivo | Dónde se usa | Contenido actual |
|---|---|---|
| `hero-home.webp` | Portada (Home), panel del hero | Panorámica de la cordillera de Calingasta |
| `nosotros.webp` | Página Nosotros, banda "Compromiso comunitario" | Baño ecológico + camioneta 4x4 en alta montaña |
| `industrias/mineria.webp` | Hero industria Minería | Baño ecológico en operación minera |
| `industrias/construccion-y-obras.webp` | Hero industria Construcción y Obras | Baño + módulo habitacional en obra |
| `industrias/eventos-masivos.webp` | Hero industria Eventos Masivos | Baños ecológicos instalados en fila |
| `industrias/agroindustria.webp` | Hero industria Agroindustria | Unidad + flota 4x4 en terreno rural/desértico |
| `industrias/organismos-publicos.webp` | Hero industria Organismos Públicos | Módulo sanitario en alta montaña |
| `industrias/empresas-privadas.webp` | Hero industria Empresas Privadas | Garita de control de acceso |
| `servicio-banos.webp` / `servicio-modulos.webp` / `servicio-modulos-habitacionales.webp` / `servicio-garitas.webp` / `servicio-desagote.webp` | Galería en `/servicios` | Baños ecológicos, módulos sanitarios y habitacionales, garita y camión de desagote |
| `camion-camca.webp` | Galería en `/servicios` + bloque "Desagote de Biodigestores" | Camión cisterna CAMCA a color, con la marca en el tanque |

## Cómo optimizar antes de subir

Pasá la foto a `.webp` (~80% calidad, ancho máx ~1600px), por ejemplo con `sharp`
(ya es una dependencia del proyecto) o cualquier conversor a WebP.
