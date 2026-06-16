// ============================================================
// CIAF · Datos institucionales y de contacto (única fuente)
// ============================================================

export const SITE = {
  name: 'CIAF Consultora Integral',
  shortName: 'CIAF',
  domain: 'ciafconsultora.com.ar',
  url: 'https://ciafconsultora.com.ar',
  claim: 'Más que números, soluciones a medida.',
  tagline: 'Transformamos los desafíos en oportunidades',
  locale: 'es-AR',
} as const;

export const CONTACT = {
  address: 'Pedro de Valdivia Este 370',
  city: 'San Juan',
  country: 'Argentina',
  countryCode: 'AR',
  // Correos por área (reemplazan al gmail anterior)
  emails: [
    { label: 'Ventas', address: 'ventas@ciafconsultora.com.ar' },
    { label: 'Soporte', address: 'soporte@ciafconsultora.com.ar' },
  ],
  // Número en formato internacional para tel: y wa.me
  phoneDisplay: '+54 264 562-8679',
  phoneTel: '+542645628679',
  whatsapp: '5492645628679',
  // Coordenadas aproximadas de la dirección (San Juan capital)
  geo: { lat: -31.5366, lng: -68.5247 },
  social: {
    instagram: { label: '@ciaf.consultoraintegral', url: 'https://www.instagram.com/ciaf.consultoraintegral' },
    linkedin: { label: 'CIAF Consultora Integral', url: 'https://www.linkedin.com/company/ciaf-consultora-integral' },
  },
} as const;

// Mensaje predefinido para el CTA de WhatsApp
export const waLink = (text = 'Hola CIAF, quiero agendar una reunión') =>
  `https://wa.me/${CONTACT.whatsapp}?text=${encodeURIComponent(text)}`;

// Métricas duras citables (no inventar fuera de esto)
export const STATS = [
  { value: '+30', label: 'Empresas activas' },
  { value: '+5', label: 'Años de trayectoria' },
  { value: '6', label: 'Áreas de servicio' },
  { value: '10', label: 'Industrias atendidas' },
] as const;

// Navegación principal
export const NAV = [
  { label: 'Inicio', href: '/' },
  { label: 'Servicios', href: '/servicios' },
  {
    label: 'Industrias',
    href: '/industrias/gastronomia',
    children: [
      { label: 'Gastronomía', href: '/industrias/gastronomia' },
      { label: 'Minería y servicios', href: '/industrias/mineria-y-servicios' },
      { label: 'Energía', href: '/industrias/energia' },
      { label: 'Retail', href: '/industrias/retail' },
      { label: 'Turismo y deportes', href: '/industrias/turismo-y-deportes' },
      { label: 'Distribución', href: '/industrias/distribucion' },
    ],
  },
  { label: 'Nosotros', href: '/nosotros' },
  { label: 'Contacto', href: '/contacto' },
] as const;
