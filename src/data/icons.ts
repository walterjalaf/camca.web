// Iconos de línea (stroke) como string SVG, 24×24, heredan currentColor.
const wrap = (inner: string) =>
  `<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${inner}</svg>`;

const PATHS: Record<string, string> = {
  // Respuesta inmediata — reloj
  clock:
    '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
  // Calidad y seguridad — escudo
  shield:
    '<path d="M12 3l7 3v6c0 4.5-3 8-7 9-4-1-7-4.5-7-9V6l7-3z"/><path d="M9 12l2 2 4-4"/>',
  // Alta montaña / flota 4x4 — cordillera
  mountain:
    '<path d="M3 20L9 8l4 6 3-4.5 5 10.5"/><path d="M3 20h18"/>',
  // Compromiso ambiental / sustentabilidad — hoja
  leaf:
    '<path d="M4.5 19.5C4.5 10 10 4.5 19.5 4.5 19.5 14 14 19.5 4.5 19.5z"/><path d="M4.5 19.5c3-4 6-9 6-14"/>',
};

export const iconSvg = (name: string): string => wrap(PATHS[name] ?? '');

// ---- Logos de marca (glifos rellenos, viewBox propio). Heredan currentColor. ----
const BRAND: Record<string, string> = {
  whatsapp:
    '<path d="M.057 24l1.687-6.163a11.867 11.867 0 0 1-1.587-5.946C.16 5.335 5.495 0 12.05 0a11.817 11.817 0 0 1 8.413 3.488 11.824 11.824 0 0 1 3.48 8.414c-.003 6.557-5.338 11.892-11.893 11.892a11.9 11.9 0 0 1-5.688-1.448L.057 24zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884a9.86 9.86 0 0 0 1.51 5.26l-.999 3.648 3.978-1.467zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/>',
};

export const socialSvg = (name: string, size = 20): string =>
  `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">${BRAND[name] ?? ''}</svg>`;
