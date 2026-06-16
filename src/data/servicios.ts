// ============================================================
// CIAF · Servicios y sub-servicios reales (del material institucional)
// No inventar sub-servicios.
// ============================================================

export interface Area {
  id: 'administracion' | 'contabilidad' | 'impuestos' | 'finanzas' | 'software' | 'ia';
  title: string;
  tagline: string;
  intro: string;
  items: string[];
}

export const OUTSOURCING_BENEFITS = [
  'Ahorro de tiempo y dinero',
  'Mayor eficiencia',
  'Acceso a expertos',
  'Flexibilidad',
  'Enfoque en el core business',
] as const;

export const AREAS: Area[] = [
  {
    id: 'administracion',
    title: 'Administración',
    tagline: 'Simplificamos tus procesos y optimizamos tus recursos',
    intro:
      'Diseñamos y ejecutamos los procesos administrativos para que cada peso y cada hora cuenten.',
    items: [
      'Gestión de personal: nóminas, altas y bajas, liquidaciones y cumplimiento normativo laboral',
      'Gestión de proveedores: selección, negociación y seguimiento de pagos',
      'Gestión de clientes y cobranzas',
      'Gestión documental: archivo, digitalización y control',
      'Gestión de proyectos: de la planificación a la ejecución',
      'Administración de costos',
      'Gestión de stock',
      'Flujo de caja',
      'Estados financieros',
      'Conciliaciones bancarias',
      'Implementación de sistemas',
      'Participación en licitaciones',
      'Inteligencia de negocios',
    ],
  },
  {
    id: 'contabilidad',
    title: 'Contabilidad',
    tagline: 'Transparencia y control en cada transacción',
    intro:
      'Llevamos tu contabilidad con prolijidad y al día, con informes claros para que entiendas tu negocio.',
    items: [
      'Contabilidad general: registro de transacciones, conciliaciones, facturación y cuentas por cobrar/pagar',
      'Contabilidad analítica: análisis de costos, control presupuestario e indicadores de gestión',
      'Cierre contable y estados financieros (balance, estado de resultados, flujo de efectivo)',
      'Preparación para auditorías',
      'Asesoramiento contable e interpretación de estados financieros',
    ],
  },
  {
    id: 'impuestos',
    title: 'Impuestos',
    tagline: 'Tranquilidad fiscal garantizada',
    intro:
      'Cumplimiento al día y planificación fiscal estratégica, con conocimiento del entorno tributario argentino.',
    items: [
      'Cálculo y declaración de impuestos nacionales, provinciales y municipales',
      'Planificación fiscal e identificación de beneficios fiscales',
      'Asesoramiento tributario y defensa ante fiscalizaciones',
      'Gestión de vencimientos, presentaciones digitales y devoluciones',
    ],
  },
  {
    id: 'finanzas',
    title: 'Finanzas',
    tagline: 'Maximizá tus ganancias y minimizá tus riesgos',
    intro:
      'Te damos visibilidad sobre tu dinero: liquidez, rentabilidad y decisiones de inversión.',
    items: [
      'Análisis financiero: situación, ratios y proyecciones',
      'Planificación financiera: presupuestos, planes de inversión y de negocio',
      'Gestión de tesorería: flujo de efectivo, bancos e inversiones de corto plazo',
      'Valoración de empresas para fusiones, adquisiciones o venta',
      'Asesoramiento financiero y estrategias de financiamiento',
    ],
  },
  {
    id: 'software',
    title: 'Desarrollo de software',
    tagline: 'Sistemas a medida para tu operación',
    intro:
      'Cuando ningún sistema del mercado te alcanza, lo desarrollamos: software a medida que se integra a tu forma de trabajar.',
    items: [
      'Aplicaciones y sistemas de gestión a medida',
      'Integraciones entre tus sistemas (ERP, facturación, bancos)',
      'Tableros y reportes automáticos en tiempo real',
      'Automatización de procesos administrativos',
    ],
  },
  {
    id: 'ia',
    title: 'Implementaciones con IA',
    tagline: 'Inteligencia artificial aplicada a tu negocio',
    intro:
      'Implementamos IA donde realmente mueve la aguja: para ahorrar tiempo, ordenar tus datos y darte mejor información para decidir.',
    items: [
      'Automatización de tareas administrativas con IA',
      'Asistentes y agentes a medida para tu equipo',
      'Análisis y clasificación inteligente de datos',
      'Implementación de herramientas de IA en tu operación',
    ],
  },
];

// Servicios complementarios mencionados
export const COMPLEMENTARIOS = [
  'Consultoría societaria',
  'Auditoría',
  'Consultoría laboral',
  'Acompañamiento en certificaciones ISO 9001',
] as const;

// Metodología: transformación en 4 dimensiones
export const DIMENSIONES = [
  {
    title: 'Procesos',
    desc: 'Rediseño de circuitos operativos para eliminar fricción y doble carga.',
  },
  {
    title: 'Personas',
    desc: 'Capacitación y estructura para que el equipo sostenga la mejora.',
  },
  {
    title: 'Tecnología',
    desc: 'Implementación de sistemas que conectan tu operación.',
  },
  {
    title: 'Infraestructura',
    desc: 'Recursos físicos y digitales alineados a la estrategia.',
  },
] as const;
