// ============================================================
// CIAF · Clientes reales por vertical
// logo: ruta en /public/clientes o null (→ placeholder tipográfico)
// SOLO clientes reales. No inventar.
// ============================================================

export type Vertical =
  | 'gastronomia'
  | 'mineria-y-servicios'
  | 'energia'
  | 'retail'
  | 'turismo-y-deportes'
  | 'distribucion';

export interface Cliente {
  name: string;
  vertical: Vertical;
  logo: string | null;
  /** El logo tiene letras/arte blanco → se muestra sobre chip oscuro (si no, desaparece sobre blanco). */
  dark?: boolean;
}

export const CLIENTES: Cliente[] = [
  // ---- Gastronomía ----
  { name: 'Del Parque', vertical: 'gastronomia', logo: '/clientes/del-parque.webp', dark: true },
  { name: 'Ancestral Cerveza Artesanal', vertical: 'gastronomia', logo: '/clientes/ancestral.webp', dark: true },
  { name: 'Tagore', vertical: 'gastronomia', logo: '/clientes/tagore.webp' },
  { name: 'Parque Parador de Montaña', vertical: 'gastronomia', logo: '/clientes/parador-montana.webp' },
  { name: 'Panificadora Mauri', vertical: 'gastronomia', logo: '/clientes/mauri.webp' },
  { name: 'Buffet de Sociales', vertical: 'gastronomia', logo: '/clientes/buffet.webp', dark: true },

  // ---- Minería y servicios B2B ----
  { name: 'FRAM Servicios', vertical: 'mineria-y-servicios', logo: '/clientes/fram.webp' },
  { name: 'Puntoi', vertical: 'mineria-y-servicios', logo: '/clientes/puntoi.webp' },

  // ---- Energía ----
  { name: 'YPF DANPE', vertical: 'energia', logo: '/clientes/ypf-danpe.webp' },
  { name: 'Nuevo Cuyo', vertical: 'energia', logo: '/clientes/nuevo-cuyo.webp', dark: true },

  // ---- Retail ----
  { name: 'Óptica LIC', vertical: 'retail', logo: '/clientes/optica-lic.webp' },
  { name: 'Santa Clara', vertical: 'retail', logo: '/clientes/santa-clara.webp' },
  { name: 'Don Américo', vertical: 'retail', logo: '/clientes/don-americo.webp' },
  { name: 'Itala', vertical: 'retail', logo: '/clientes/itala.webp' },
  { name: 'Menin', vertical: 'retail', logo: '/clientes/menin.webp' },
  { name: 'Puntoi', vertical: 'retail', logo: '/clientes/puntoi.webp' },

  // ---- Turismo y deportes ----
  { name: 'RK Rustik Aventura', vertical: 'turismo-y-deportes', logo: '/clientes/rustik.webp' },
  { name: 'Ushuaia Aventura', vertical: 'turismo-y-deportes', logo: '/clientes/ushuaia.webp', dark: true },
  { name: 'El Almendro Sport', vertical: 'turismo-y-deportes', logo: '/clientes/almendro.webp' },
  { name: 'ADN Asociación de Nadadores', vertical: 'turismo-y-deportes', logo: '/clientes/nadadores.webp', dark: true },

  // ---- Distribución ----
  { name: 'De Los Andes Distribuidora', vertical: 'distribucion', logo: '/clientes/de-los-andes.webp' },
  { name: 'Algar', vertical: 'distribucion', logo: '/clientes/algar.webp' },
  { name: 'Ansilta Distribuciones', vertical: 'distribucion', logo: '/clientes/ansilta.webp' },
];

export const clientesByVertical = (v: Vertical): Cliente[] =>
  CLIENTES.filter((c) => c.vertical === v);

// Selección curada para la grilla de prueba social del Home
export const HOME_LOGOS: Cliente[] = [
  CLIENTES.find((c) => c.name === 'Panificadora Mauri')!,
  CLIENTES.find((c) => c.name === 'YPF DANPE')!,
  CLIENTES.find((c) => c.name === 'FRAM Servicios')!,
  CLIENTES.find((c) => c.name === 'Ancestral Cerveza Artesanal')!,
  CLIENTES.find((c) => c.name === 'Del Parque')!,
  CLIENTES.find((c) => c.name === 'Óptica LIC')!,
  CLIENTES.find((c) => c.name === 'De Los Andes Distribuidora')!,
  CLIENTES.find((c) => c.name === 'Puntoi')!,
  CLIENTES.find((c) => c.name === 'Tagore')!,
  CLIENTES.find((c) => c.name === 'Don Américo')!,
];
