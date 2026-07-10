// ============================================================
// CAMCA · Datos institucionales y de contacto (única fuente)
// ============================================================

export const SITE = {
  name: 'CAMCA Servicios Integrales',
  shortName: 'CAMCA',
  domain: 'camcaserviciosintegrales.com.ar',
  url: 'https://camcaserviciosintegrales.com.ar',
  claim: 'Soluciones rápidas y responsables en todo tipo de terrenos.',
  tagline: 'Referentes en saneamiento ambiental de alta montaña',
  locale: 'es-AR',
} as const;

export const CONTACT = {
  address: 'Tamberías',
  city: 'Calingasta',
  region: 'San Juan',
  country: 'Argentina',
  countryCode: 'AR',
  emails: [{ label: 'Consultas', address: 'camcadistribuciones@gmail.com' }],
  // Número en formato internacional para tel: y wa.me
  phoneDisplay: '264 318-6073',
  phoneTel: '+542643186073',
  whatsapp: '5492643186073',
  // Tamberías, Calingasta, San Juan
  geo: { lat: -31.3804, lng: -69.209 },
  horarios: [
    { label: 'Lunes a viernes', value: '8:00 a 18:00 hs' },
    { label: 'Sábados', value: '9:00 a 13:00 hs' },
    { label: 'Emergencias', value: 'Disponible 24/7' },
  ],
} as const;

// Mensaje predefinido para el CTA de WhatsApp
export const waLink = (text = 'Hola CAMCA, quiero hacer una consulta') =>
  `https://wa.me/${CONTACT.whatsapp}?text=${encodeURIComponent(text)}`;

// Métricas citables
export const STATS = [
  { value: '+15', label: 'Años de experiencia' },
  { value: '24/7', label: 'Respuesta de emergencia' },
  { value: '100%', label: 'Compromiso comunitario' },
  { value: '+50', label: 'Clientes corporativos' },
] as const;

// Navegación principal
export const NAV = [
  { label: 'Inicio', href: '/' },
  { label: 'Servicios', href: '/servicios' },
  {
    label: 'Industrias',
    href: '/industrias/mineria',
    children: [
      { label: 'Minería', href: '/industrias/mineria' },
      { label: 'Construcción y obras', href: '/industrias/construccion-y-obras' },
      { label: 'Eventos masivos', href: '/industrias/eventos-masivos' },
      { label: 'Agroindustria', href: '/industrias/agroindustria' },
      { label: 'Organismos públicos', href: '/industrias/organismos-publicos' },
      { label: 'Empresas privadas', href: '/industrias/empresas-privadas' },
    ],
  },
  { label: 'Nosotros', href: '/nosotros' },
  { label: 'Contacto', href: '/contacto' },
] as const;
