// ============================================================
// CAMCA · Servicios reales (del material institucional: presentación
// y manual de marca). No inventar sub-servicios.
// ============================================================

export interface Area {
  id:
    | 'banos-ecologicos'
    | 'modulos-sanitarios'
    | 'modulos-habitacionales'
    | 'garitas'
    | 'mantenimiento-desagote'
    | 'desagote-biodigestores'
    | 'logistica-4x4'
    | 'saneamiento-eventos';
  title: string;
  tagline: string;
  intro: string;
  items: string[];
  image: string;
}

export const BENEFICIOS = [
  'Instalación y logística incluida',
  'Mantenimiento periódico sin cargo adicional',
  'Flota propia 4x4 para zonas de difícil acceso',
  'Respuesta rápida ante emergencias',
  'Cumplimiento de normativas de higiene y seguridad',
] as const;

export const AREAS: Area[] = [
  {
    id: 'banos-ecologicos',
    title: 'Alquiler de Baños Ecológicos',
    tagline: 'Baños químicos de alta resistencia',
    intro:
      'Unidades sanitarias móviles diseñadas para soportar las condiciones más extremas: minería de alta montaña, obras industriales, eventos y campamentos.',
    items: [
      'Unidades numeradas e identificadas por sector (hombres / mujeres)',
      'Estructura reforzada para clima de alta montaña',
      'Provisión, traslado e instalación incluidos',
      'Servicio de mantenimiento y limpieza periódica',
      'Señalética de seguridad e higiene en cada unidad',
    ],
    image: '/fotos/servicio-banos.webp',
  },
  {
    id: 'modulos-sanitarios',
    title: 'Módulos Sanitarios',
    tagline: 'Infraestructura completa, en alquiler o venta',
    intro:
      'Módulos sanitarios prefabricados para campamentos mineros, obras de gran escala y proyectos de larga duración, con instalación llave en mano. Disponibles tanto en alquiler como en venta, según la duración y el tipo de proyecto.',
    items: [
      'Estructura metálica aislada para climas hostiles',
      'Conexión a red de agua, desagote o biodigestor',
      'Iluminación y artefactos completos',
      'Traslado e instalación con equipo propio',
      'Modalidad de alquiler o venta, a elección del cliente',
    ],
    image: '/fotos/servicio-modulos.webp',
  },
  {
    id: 'modulos-habitacionales',
    title: 'Módulos Habitacionales',
    tagline: 'Oficinas y dormitorios móviles, en alquiler o venta',
    intro:
      'Módulos habitacionales equipados para personal en obra: oficinas de campo, dormitorios y espacios de uso diario en operaciones remotas. Disponibles tanto en alquiler como en venta, según la duración y el tipo de proyecto.',
    items: [
      'Aislación térmica para alta montaña',
      'Instalación eléctrica y de climatización',
      'Configuración a medida según el proyecto',
      'Traslado con flota propia 4x4',
      'Modalidad de alquiler o venta, a elección del cliente',
    ],
    image: '/fotos/servicio-modulos-habitacionales.webp',
  },
  {
    id: 'garitas',
    title: 'Garitas de Seguridad',
    tagline: 'Control de acceso e ingreso',
    intro:
      'Garitas móviles para control de acceso en obras, plantas industriales y predios que requieren un puesto de vigilancia permanente.',
    items: [
      'Estructura resistente con ventana de visibilidad completa',
      'Fácil traslado y reubicación dentro del predio',
      'Terminación e identidad de marca del cliente disponible',
    ],
    image: '/fotos/servicio-garitas.webp',
  },
  {
    id: 'mantenimiento-desagote',
    title: 'Mantenimiento y Desagote',
    tagline: 'Servicio periódico con flota propia',
    intro:
      'Limpieza, desinfección y desagote de baños y módulos sanitarios con una flota de camiones y camionetas 4x4 equipadas para responder en cualquier terreno.',
    items: [
      'Frecuencia de servicio ajustada a la demanda del proyecto',
      'Equipos propios: camión desagote + flota 4x4',
      'Registro de servicio por unidad',
      'Respuesta ante urgencias fuera de cronograma',
    ],
    image: '/fotos/servicio-desagote.webp',
  },
  {
    id: 'desagote-biodigestores',
    title: 'Desagote de Biodigestores',
    tagline: 'Tratamiento responsable de efluentes',
    intro:
      'Servicio especializado de desagote y mantenimiento de biodigestores para industrias y proyectos con sistemas de tratamiento propio.',
    items: [
      'Camión cisterna equipado para alta montaña',
      'Disposición de efluentes conforme a normativa ambiental',
      'Coordinación con el cronograma de obra o planta',
    ],
    image: '/fotos/camion-camca.webp',
  },
  {
    id: 'logistica-4x4',
    title: 'Logística 4x4 de Alta Montaña',
    tagline: 'Acceso donde otros no llegan',
    intro:
      'Flota de camionetas 4x4 equipadas para transportar e instalar equipamiento en zonas de alta montaña y terrenos de difícil acceso.',
    items: [
      'Cobertura en toda la región de Cuyo con base en Calingasta',
      'Choferes con experiencia en rutas de alta montaña',
      'Capacidad de despliegue rápido ante pedidos urgentes',
    ],
    image: '/fotos/servicio-logistica.webp',
  },
  {
    id: 'saneamiento-eventos',
    title: 'Saneamiento para Eventos',
    tagline: 'Cobertura sanitaria para grandes convocatorias',
    intro:
      'Soluciones sanitarias completas para eventos masivos, festivales y actividades corporativas, dimensionadas según el público esperado.',
    items: [
      'Cálculo de unidades según cantidad de asistentes',
      'Instalación, mantenimiento y retiro incluidos',
      'Disponibilidad para eventos de uno o varios días',
    ],
    image: '/fotos/servicio-eventos.webp',
  },
];

// Cómo trabajamos — de la consulta al mantenimiento en el terreno
export const PROCESO = [
  {
    title: 'Consultá',
    desc: 'Contanos tu proyecto por WhatsApp, teléfono o formulario. Te respondemos el mismo día.',
  },
  {
    title: 'Coordinamos logística',
    desc: 'Definimos cantidad de unidades, plazos y acceso — con flota 4x4 propia para cualquier terreno.',
  },
  {
    title: 'Instalamos',
    desc: 'Trasladamos e instalamos el equipamiento en el lugar exacto que necesita tu proyecto.',
  },
  {
    title: 'Mantenimiento',
    desc: 'Limpieza, desagote y control periódico durante todo el proyecto, sin que tengas que pedirlo.',
  },
] as const;
