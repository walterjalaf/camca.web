// ============================================================
// CIAF · Verticales de industria
// Contenido real. Casos citables únicamente: FRAM (ISO 9001 con
// acompañamiento de CIAF) y YPF DANPE (ISO 9001 + Mención Oro al
// Premio Provincial a la Calidad 2023). El resto, cualitativo.
// ============================================================

import type { Vertical } from './clientes';

export interface Faq {
  q: string;
  a: string;
}

export interface Industria {
  slug: Vertical;
  name: string;
  // tono de redacción: 'vos' (voseo) | 'usted' (impersonal/formal)
  tone: 'vos' | 'usted';
  // gancho corto para la tarjeta del Home
  hook: string;
  order: number;
  seoTitle: string;
  seoDescription: string;
  heroEyebrow: string;
  heroTitle: string;
  heroLead: string;
  // "Lo que resolvemos" — dolores del rubro
  dolores: { title: string; desc: string }[];
  // servicios priorizados para el rubro
  prioridades: string[];
  // mini-caso (cualitativo o citable)
  caso: { label: string; title: string; body: string };
  faqs: Faq[];
}

export const INDUSTRIAS: Industria[] = [
  // ───────────────────────── MINERÍA Y SERVICIOS (prioritaria, usted) ─────────────────────────
  {
    slug: 'mineria-y-servicios',
    name: 'Minería y servicios',
    tone: 'usted',
    order: 1,
    hook: 'Nómina pesada y cobranzas a 90 días con grandes operadores.',
    seoTitle: 'Consultora para proveedores mineros en San Juan | CIAF',
    seoDescription:
      'Outsourcing administrativo, contable e impositivo para empresas de servicios a la minería en San Juan: payroll, cobranzas, cash flow e ISO 9001.',
    heroEyebrow: 'Industria · Minería y servicios B2B',
    heroTitle: 'El respaldo administrativo que exige operar para la gran minería.',
    heroLead:
      'Las empresas de servicios a la minería conviven con una nómina que pesa, certificaciones de servicio complejas y cobranzas a 90 días con operadores grandes y el Estado. CIAF se incorpora a su operación para que la administración deje de ser un cuello de botella.',
    dolores: [
      {
        title: 'Payroll que domina el costo',
        desc: 'Cuando el 70–80% del costo es mano de obra, cada liquidación, adicional y convenio impacta de lleno en el resultado. Ordenamos la nómina y su cumplimiento normativo.',
      },
      {
        title: 'Cobranzas a 90 días',
        desc: 'Operar con mineras grandes y el Estado implica plazos largos y circuitos de aprobación exigentes. Estructuramos el seguimiento de cobranzas y la facturación por horas-hombre.',
      },
      {
        title: 'Proyección de cash flow',
        desc: 'Cubrir la nómina mientras la cobranza se demora requiere anticipación. Proyectamos el flujo de fondos para que el pago de sueldos nunca dependa del azar.',
      },
      {
        title: 'Certificación de servicios',
        desc: 'La facturación se valida contra servicios certificados y partes de obra. Acompañamos el circuito para que cada hora trabajada se cobre.',
      },
      {
        title: 'Licitaciones e ISO 9001',
        desc: 'Acceder a nuevos contratos exige documentación, calidad y procesos formalizados. Acompañamos la participación en licitaciones y la certificación de calidad.',
      },
    ],
    prioridades: [
      'Gestión de personal y liquidación de nóminas',
      'Proyección de flujo de caja y tesorería',
      'Seguimiento de cobranzas y facturación por horas-hombre',
      'Participación en licitaciones',
      'Acompañamiento en certificación ISO 9001',
      'Cumplimiento impositivo nacional, provincial y municipal',
    ],
    caso: {
      label: 'Caso · FRAM Servicios',
      title: 'Certificación ISO 9001:2015 con acompañamiento de CIAF',
      body:
        'FRAM Servicios SRL certificó la norma ISO 9001:2015 con el acompañamiento de CIAF en la organización de sus procesos administrativos y de calidad. Un ejemplo del tipo de respaldo que una empresa de servicios necesita para crecer y acceder a contratos exigentes.',
    },
    faqs: [
      {
        q: '¿Trabajan con empresas que prestan servicios a la minería?',
        a: 'Sí. Acompañamos a empresas de servicios cuya operación gira en torno a una nómina intensiva, cobranzas a plazos largos y exigencias de certificación. Conocemos ese contexto de cerca.',
      },
      {
        q: '¿Pueden ayudarnos a proyectar el flujo de fondos para cubrir la nómina?',
        a: 'Sí. Construimos una proyección de cash flow alimentada con la operación real, de modo que el pago de sueldos y obligaciones se planifique con anticipación frente a las cobranzas diferidas.',
      },
      {
        q: '¿Acompañan procesos de certificación ISO 9001?',
        a: 'Sí. Acompañamos la organización de procesos administrativos y de calidad. FRAM Servicios certificó ISO 9001:2015 con nuestro acompañamiento.',
      },
      {
        q: '¿Se ocupan de la participación en licitaciones?',
        a: 'Brindamos seguimiento en todo el proceso de licitación, desde la preparación de la documentación y las ofertas hasta la adjudicación.',
      },
    ],
  },

  // ───────────────────────── GASTRONOMÍA (vos) ─────────────────────────
  {
    slug: 'gastronomia',
    name: 'Gastronomía',
    tone: 'vos',
    order: 2,
    hook: 'Caja diaria que no cuadra y medios de pago que no concilian.',
    seoTitle: 'Outsourcing administrativo para gastronomía en San Juan | CIAF',
    seoDescription:
      'Contabilidad, impuestos y administración para restaurantes, bares y cervecerías en San Juan: conciliación de medios de pago, food cost y CCT gastronómicos.',
    heroEyebrow: 'Industria · Gastronomía',
    heroTitle: 'Que la administración no te saque del salón.',
    heroLead:
      'Restaurantes, bares y cervecerías viven al ritmo de la caja diaria, los medios de pago y el costo de la mercadería. Nos metemos en tu operación para que los números cierren todos los días y vos te dediques a tus clientes.',
    dolores: [
      {
        title: 'Conciliación de medios de pago',
        desc: 'Efectivo, tarjetas, QR y apps de delivery: cada medio liquida distinto. Conciliamos todo para que sepas cuánto entró de verdad cada día.',
      },
      {
        title: 'Caja diaria que no cuadra',
        desc: 'Arqueo por turno, diferencias y faltantes. Ordenamos el circuito de caja para que el descuadre deje de ser la norma.',
      },
      {
        title: 'Food cost y rentabilidad',
        desc: 'Si no sabés cuánto te cuesta cada plato, no sabés cuánto ganás. Estructuramos costos para ver el margen real por línea.',
      },
      {
        title: 'CCT gastronómicos (UTHGRA)',
        desc: 'Liquidación de sueldos según convenio, adicionales y rotación alta de personal. Llevamos la nómina al día y en regla.',
      },
    ],
    prioridades: [
      'Conciliación de medios de pago y caja diaria',
      'Estructura de costos y food cost',
      'Liquidación de sueldos bajo CCT gastronómico',
      'Cumplimiento impositivo y de habilitaciones',
      'Reportes de rentabilidad por local',
    ],
    caso: {
      label: 'Cómo trabajamos',
      title: 'De la caja diaria al reporte mensual',
      body:
        'En gastronomía ordenamos primero el circuito de caja y la conciliación de medios de pago día a día. Sobre esa base construimos la estructura de costos y un cierre mensual prolijo, para que cada local se mida por su propio resultado. Trabajamos con clientes como Del Parque, Ancestral, Tagore, Mauri y Circo.',
    },
    faqs: [
      {
        q: '¿Concilian tarjetas, QR y apps de delivery?',
        a: 'Sí. Conciliamos todos los medios de pago contra las liquidaciones de cada operador, así sabés cuánto ingresó realmente y cuánto se retuvo por comisiones.',
      },
      {
        q: '¿Pueden liquidar sueldos bajo convenio gastronómico?',
        a: 'Sí. Liquidamos nóminas según el CCT correspondiente, con sus adicionales, y gestionamos altas, bajas y cumplimiento normativo laboral.',
      },
      {
        q: '¿Me ayudan a entender el costo de cada plato?',
        a: 'Estructuramos tus costos para que puedas analizar el food cost y el margen por línea de negocio, base para decidir precios y carta.',
      },
      {
        q: '¿Sirve si tengo más de un local?',
        a: 'Sí. Medimos cada local como un centro de costos propio, para que veas qué punto rinde y cuál necesita atención.',
      },
    ],
  },

  // ───────────────────────── ENERGÍA (usted) ─────────────────────────
  {
    slug: 'energia',
    name: 'Energía',
    tone: 'usted',
    order: 3,
    hook: 'Conciliación de surtidores e inventario de combustible.',
    seoTitle: 'Administración para estaciones de servicio en San Juan | CIAF',
    seoDescription:
      'Outsourcing contable y administrativo para estaciones de servicio en San Juan: conciliación de medios de pago en surtidores, inventario de combustibles y reporting al franquiciante.',
    heroEyebrow: 'Industria · Energía y estaciones de servicio',
    heroTitle: 'La operación de una estación no perdona descuadres.',
    heroLead:
      'Una estación de servicio mueve grandes volúmenes con márgenes finos y un franquiciante exigente. CIAF ordena la conciliación de surtidores, el inventario de combustibles y el reporting para que cada turno cierre.',
    dolores: [
      {
        title: 'Conciliación de medios de pago en surtidores',
        desc: 'Efectivo, tarjetas, flotas y apps por cada isla y turno. Conciliamos los ingresos contra despachos para detectar diferencias a tiempo.',
      },
      {
        title: 'Inventario de combustibles',
        desc: 'Stock, mermas y diferencias de medición. Ordenamos el control de inventario para que las variaciones se expliquen y no se acumulen.',
      },
      {
        title: 'Reporting al franquiciante',
        desc: 'Operar bajo bandera exige informes en formato y plazo. Preparamos el reporting para cumplir sin retrabajo.',
      },
      {
        title: 'Márgenes finos, alto volumen',
        desc: 'Con márgenes ajustados, cada gasto y cada diferencia importa. Damos visibilidad de costos y resultado por estación.',
      },
    ],
    prioridades: [
      'Conciliación de medios de pago por turno e isla',
      'Control de inventario de combustibles',
      'Reporting al franquiciante',
      'Cumplimiento impositivo y de tesorería',
      'Reportes de rentabilidad por estación',
    ],
    caso: {
      label: 'Caso · YPF DANPE',
      title: 'Calidad reconocida: ISO 9001 y Mención Oro 2023',
      body:
        'YPF DANPE SRL cuenta con certificación ISO 9001:2015 y obtuvo la Mención Oro del Premio Provincial a la Calidad 2023. Acompañamos su operación administrativa en un rubro donde la disciplina de procesos y la calidad de la información hacen la diferencia.',
    },
    faqs: [
      {
        q: '¿Concilian los medios de pago de los surtidores?',
        a: 'Sí. Conciliamos los ingresos por turno e isla contra los despachos y las liquidaciones de cada operador, de modo que las diferencias se detecten en el día.',
      },
      {
        q: '¿Llevan el inventario de combustibles?',
        a: 'Ordenamos el control de stock y las mermas, para que las diferencias de medición se expliquen y no se transformen en pérdidas silenciosas.',
      },
      {
        q: '¿Preparan los informes que pide el franquiciante?',
        a: 'Sí. Preparamos el reporting en el formato y los plazos requeridos, integrando la información contable y operativa.',
      },
      {
        q: '¿Pueden medir la rentabilidad por estación?',
        a: 'Estructuramos la información para analizar costos y resultado por estación, base para decidir con márgenes ajustados.',
      },
    ],
  },

  // ───────────────────────── RETAIL (vos) ─────────────────────────
  {
    slug: 'retail',
    name: 'Retail',
    tone: 'vos',
    order: 4,
    hook: 'Stock, rotación y caja multi-medio sin visibilidad.',
    seoTitle: 'Outsourcing administrativo para retail en San Juan | CIAF',
    seoDescription:
      'Contabilidad, impuestos y administración para comercios y retail especializado en San Juan: control de stock, caja multi-medio y facturación a obras sociales.',
    heroEyebrow: 'Industria · Retail y consumo especializado',
    heroTitle: 'Vender bien no alcanza si no sabés qué te queda.',
    heroLead:
      'El retail especializado vive de la rotación, el stock y una caja con muchos medios de pago. Nos incorporamos a tu operación para darte visibilidad de margen, inventario y cobranzas.',
    dolores: [
      {
        title: 'Stock y rotación',
        desc: 'Faltantes, sobrestock y capital inmovilizado. Ordenamos la gestión de inventario para que el stock trabaje a favor.',
      },
      {
        title: 'Caja diaria multi-medio',
        desc: 'Efectivo, tarjetas, QR y planes de cuotas que liquidan distinto. Conciliamos todo para saber el ingreso real.',
      },
      {
        title: 'Facturación a obras sociales y abonos',
        desc: 'Rubros como óptica o salud facturan a obras sociales y por abonos. Ordenamos el circuito de facturación y cobranza.',
      },
      {
        title: 'Margen por línea de producto',
        desc: 'No todos los productos rinden igual. Estructuramos costos para ver el margen real por línea y categoría.',
      },
    ],
    prioridades: [
      'Gestión de stock y rotación',
      'Conciliación de caja y medios de pago',
      'Facturación a obras sociales y abonos',
      'Análisis de margen por línea',
      'Cumplimiento impositivo',
    ],
    caso: {
      label: 'Cómo trabajamos',
      title: 'Visibilidad de inventario y margen',
      body:
        'En retail ordenamos la gestión de stock y la conciliación de caja, y sobre esa base damos visibilidad del margen por línea de producto. Trabajamos con comercios especializados como Óptica LIC, Santa Clara y Don Américo.',
    },
    faqs: [
      {
        q: '¿Ayudan con el control de stock?',
        a: 'Sí. Mantenemos el inventario al día para evitar faltantes y excedentes, y para que el capital inmovilizado en mercadería sea el justo.',
      },
      {
        q: '¿Concilian todos los medios de pago?',
        a: 'Sí. Conciliamos efectivo, tarjetas, QR y planes de cuotas contra las liquidaciones, para que sepas el ingreso real de cada jornada.',
      },
      {
        q: '¿Sirve para rubros que facturan a obras sociales?',
        a: 'Sí. Ordenamos el circuito de facturación y cobranza a obras sociales y por abonos, frecuente en óptica y salud.',
      },
      {
        q: '¿Puedo ver qué línea de producto me rinde?',
        a: 'Estructuramos tus costos para analizar el margen por línea y categoría, base para decidir surtido y precios.',
      },
    ],
  },

  // ───────────────────────── TURISMO Y DEPORTES (vos) ─────────────────────────
  {
    slug: 'turismo-y-deportes',
    name: 'Turismo y deportes',
    tone: 'vos',
    order: 5,
    hook: 'Estacionalidad extrema y seguros de actividades de riesgo.',
    seoTitle: 'Administración para turismo, aventura y clubes en San Juan | CIAF',
    seoDescription:
      'Outsourcing contable y administrativo para turismo aventura, clubes y asociaciones civiles en San Juan: estacionalidad, seguros y contabilidad de asociaciones.',
    heroEyebrow: 'Industria · Turismo aventura y deportes',
    heroTitle: 'Temporada alta, temporada baja: que los números acompañen.',
    heroLead:
      'El turismo aventura y las instituciones deportivas conviven con ingresos estacionales, actividades de riesgo y formas jurídicas particulares. Nos metemos en tu operación para que la administración funcione todo el año.',
    dolores: [
      {
        title: 'Estacionalidad extrema',
        desc: 'Meses de gran actividad y meses muy flojos. Proyectamos el flujo de fondos para atravesar la temporada baja sin sobresaltos.',
      },
      {
        title: 'Seguros de actividades de riesgo',
        desc: 'Las actividades de aventura exigen coberturas específicas. Ordenamos el seguimiento administrativo de seguros y obligaciones.',
      },
      {
        title: 'Contabilidad de asociaciones civiles',
        desc: 'Clubes y asociaciones tienen normativa y reporting propios. Llevamos la contabilidad acorde a su forma jurídica.',
      },
      {
        title: 'Picos de personal por temporada',
        desc: 'Altas y bajas según la temporada. Gestionamos la nómina y su cumplimiento en los picos de actividad.',
      },
    ],
    prioridades: [
      'Proyección de flujo de caja frente a la estacionalidad',
      'Seguimiento administrativo de seguros',
      'Contabilidad de asociaciones civiles',
      'Gestión de personal de temporada',
      'Cumplimiento impositivo',
    ],
    caso: {
      label: 'Cómo trabajamos',
      title: 'Administración estable para ingresos estacionales',
      body:
        'En turismo y deportes el foco está en proyectar la caja para sostener la temporada baja y en adecuar la contabilidad a la forma jurídica de cada cliente. Acompañamos a Rustik Aventura, Ushuaia Aventura, El Almendro Sport y la Asociación de Nadadores.',
    },
    faqs: [
      {
        q: '¿Cómo manejan la estacionalidad de ingresos?',
        a: 'Proyectamos el flujo de fondos considerando los picos y valles de la temporada, para planificar pagos y obligaciones sin quedar sin caja.',
      },
      {
        q: '¿Trabajan con asociaciones civiles y clubes?',
        a: 'Sí. Llevamos la contabilidad y el cumplimiento acorde a la normativa propia de asociaciones civiles e instituciones deportivas.',
      },
      {
        q: '¿Acompañan el tema de seguros de actividades de riesgo?',
        a: 'Ordenamos el seguimiento administrativo de coberturas y obligaciones asociadas a las actividades de aventura.',
      },
      {
        q: '¿Pueden gestionar el personal de temporada?',
        a: 'Sí. Gestionamos altas, bajas y liquidaciones en los picos de actividad, con su cumplimiento normativo laboral.',
      },
    ],
  },

  // ───────────────────────── DISTRIBUCIÓN (vos) ─────────────────────────
  {
    slug: 'distribucion',
    name: 'Distribución',
    tone: 'vos',
    order: 6,
    hook: 'Cuentas corrientes largas y cobranzas con cheques diferidos.',
    seoTitle: 'Administración para distribución y mayoristas en San Juan | CIAF',
    seoDescription:
      'Outsourcing contable y administrativo para distribución, retail y comercio especializado en San Juan: cuentas corrientes, cobranzas con cheques e IIBB multi-jurisdicción.',
    heroEyebrow: 'Industria · Distribución, retail y comercio especializado',
    heroTitle: 'Mover volumen exige una cobranza ordenada.',
    heroLead:
      'La distribución mayorista trabaja con cuentas corrientes largas, cobranzas con cheques diferidos e impuestos en varias jurisdicciones. Nos incorporamos a tu operación para que la plata entre en tiempo y los números cierren.',
    dolores: [
      {
        title: 'Cuentas corrientes con plazos largos',
        desc: 'Vender a crédito tensiona la caja. Ordenamos el seguimiento de cuentas corrientes y políticas de cobranza.',
      },
      {
        title: 'Cobranzas con cheques diferidos',
        desc: 'Cartera de cheques, vencimientos y disponibilidad. Llevamos el control para que ningún valor se pierda de vista.',
      },
      {
        title: 'IIBB multi-jurisdicción',
        desc: 'Operar en varias provincias multiplica las obligaciones. Gestionamos el cumplimiento de Ingresos Brutos en cada jurisdicción.',
      },
      {
        title: 'Flujo de fondos y stock',
        desc: 'Capital atado a stock y a cobranzas diferidas. Proyectamos la caja para sostener la operación.',
      },
    ],
    prioridades: [
      'Seguimiento de cuentas corrientes y cobranzas',
      'Control de cartera de cheques y vencimientos',
      'Cumplimiento de IIBB multi-jurisdicción',
      'Proyección de flujo de caja',
      'Gestión de stock',
    ],
    caso: {
      label: 'Cómo trabajamos',
      title: 'Cobranza y cumplimiento bajo control',
      body:
        'En distribución ordenamos el seguimiento de cuentas corrientes y la cartera de cheques, y gestionamos el cumplimiento de IIBB en las jurisdicciones donde operás. Acompañamos a De Los Andes Distribuidora Mayorista, entre otros.',
    },
    faqs: [
      {
        q: '¿Ayudan con el seguimiento de cuentas corrientes?',
        a: 'Sí. Ordenamos la gestión de cuentas corrientes y las políticas de cobranza, para reducir la mora y mejorar el ingreso de caja.',
      },
      {
        q: '¿Controlan la cartera de cheques?',
        a: 'Llevamos el control de cheques diferidos, sus vencimientos y la disponibilidad, para que ningún valor se pierda de vista.',
      },
      {
        q: '¿Gestionan Ingresos Brutos en varias provincias?',
        a: 'Sí. Gestionamos el cumplimiento de IIBB multi-jurisdicción, frecuente en operaciones de distribución.',
      },
      {
        q: '¿Proyectan el flujo de fondos?',
        a: 'Sí. Proyectamos la caja considerando el capital atado a stock y a cobranzas diferidas, para sostener la operación con previsibilidad.',
      },
    ],
  },
];

export const industriaBySlug = (slug: string): Industria | undefined =>
  INDUSTRIAS.find((i) => i.slug === slug);

export const INDUSTRIAS_ORDENADAS = [...INDUSTRIAS].sort((a, b) => a.order - b.order);
