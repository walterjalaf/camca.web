// ============================================================
// CAMCA · Verticales de industria — contenido real, basado en la
// presentación institucional y los rubros que la empresa atiende.
// Caso citable: Los Azules (proyecto minero de Calingasta). El resto,
// cualitativo o con los clientes reales listados en clientes.ts.
// ============================================================

export type IndustriaSlug =
  | 'mineria'
  | 'construccion-y-obras'
  | 'eventos-masivos'
  | 'agroindustria'
  | 'organismos-publicos'
  | 'empresas-privadas';

export interface Faq {
  q: string;
  a: string;
}

export interface Industria {
  slug: IndustriaSlug;
  name: string;
  hook: string;
  order: number;
  seoTitle: string;
  seoDescription: string;
  heroEyebrow: string;
  heroTitle: string;
  heroLead: string;
  dolores: { title: string; desc: string }[];
  prioridades: string[];
  caso: { label: string; title: string; body: string };
  faqs: Faq[];
}

export const INDUSTRIAS: Industria[] = [
  // ───────────────────────── MINERÍA (prioritaria) ─────────────────────────
  {
    slug: 'mineria',
    name: 'Minería',
    order: 1,
    hook: 'Sanitarios y módulos donde la logística es la parte más difícil.',
    seoTitle: 'Servicios sanitarios para minería en San Juan | CAMCA',
    seoDescription:
      'Baños ecológicos, módulos sanitarios y habitacionales, y logística 4x4 para operaciones mineras de alta montaña en Calingasta, San Juan.',
    heroEyebrow: 'Industria · Minería de alta montaña',
    heroTitle: 'El respaldo sanitario que exige operar en alta montaña.',
    heroLead:
      'Los proyectos mineros de Calingasta operan en altura, frío extremo y con accesos limitados. CAMCA lleva baños ecológicos, módulos sanitarios y habitacionales hasta el campamento, con flota 4x4 propia y capacidad de respuesta ante cualquier imprevisto.',
    dolores: [
      {
        title: 'Terreno extremo',
        desc: 'El acceso a campamentos de alta montaña exige vehículos y choferes preparados para rutas de difícil acceso. Nuestra flota 4x4 llega donde otros no pueden.',
      },
      {
        title: 'Personal en régimen de turnos',
        desc: 'Grandes dotaciones que rotan por turnos necesitan infraestructura sanitaria dimensionada y mantenida con frecuencia constante.',
      },
      {
        title: 'Clima hostil',
        desc: 'El frío y la altura exigen equipamiento de alta resistencia, no soluciones pensadas para condiciones estándar de baja montaña.',
      },
      {
        title: 'Normativa de higiene y seguridad',
        desc: 'Los proyectos mineros exigen protocolos de higiene documentados y cumplimiento estricto en cada unidad instalada.',
      },
    ],
    prioridades: [
      'Alquiler de baños ecológicos de alta resistencia',
      'Módulos sanitarios y habitacionales para campamento',
      'Mantenimiento y desagote con frecuencia fija',
      'Logística 4x4 para acceso al obrador',
      'Garitas de control de acceso',
    ],
    caso: {
      label: 'Clientes · Los Azules y Veladero',
      title: 'Servicios sanitarios para dos de los proyectos mineros más relevantes de San Juan',
      body:
        'CAMCA provee servicios sanitarios móviles para Los Azules, un proyecto de cobre de gran escala en Calingasta, y para Veladero, mina de oro y plata de alta montaña en el departamento de Iglesia — dos operaciones que exigen logística y confiabilidad constantes.',
    },
    faqs: [
      {
        q: '¿Trabajan con proyectos mineros de alta montaña?',
        a: 'Sí. Contamos con flota 4x4 propia y equipamiento de alta resistencia preparado específicamente para operar en altura y clima extremo.',
      },
      {
        q: '¿Con qué frecuencia hacen el mantenimiento en campamentos mineros?',
        a: 'La frecuencia se ajusta a la dotación de personal y al régimen de turnos de cada proyecto, para garantizar higiene constante.',
      },
      {
        q: '¿Pueden instalar módulos habitacionales además de sanitarios?',
        a: 'Sí. Además de baños ecológicos y módulos sanitarios, instalamos módulos habitacionales equipados para el personal en obra.',
      },
      {
        q: '¿Cómo llegan a campamentos de difícil acceso?',
        a: 'Con nuestra flota de camionetas 4x4 equipadas para rutas de alta montaña, con choferes con experiencia en la zona de Calingasta.',
      },
    ],
  },

  // ───────────────────────── CONSTRUCCIÓN Y OBRAS ─────────────────────────
  {
    slug: 'construccion-y-obras',
    name: 'Construcción y Obras',
    order: 2,
    hook: 'Sanitarios y garitas que acompañan el ritmo de la obra.',
    seoTitle: 'Servicios sanitarios para obras y construcción | CAMCA',
    seoDescription:
      'Baños ecológicos, garitas de control y módulos para obras civiles y de infraestructura en San Juan. Instalación y traslado según el avance del proyecto.',
    heroEyebrow: 'Industria · Construcción y obras civiles',
    heroTitle: 'Infraestructura sanitaria que avanza al ritmo de la obra.',
    heroLead:
      'Cada etapa de una obra tiene necesidades distintas: la dotación de personal, los plazos y la ubicación cambian. CAMCA se adapta con baños ecológicos, garitas de control y módulos que se instalan y reubican según el avance del proyecto.',
    dolores: [
      {
        title: 'Dotación variable de personal',
        desc: 'La cantidad de trabajadores en obra cambia por etapa. Escalamos la cantidad de baños ecológicos según la dotación real.',
      },
      {
        title: 'Control de acceso al obrador',
        desc: 'Las obras necesitan un punto de control de ingreso y egreso claro. Instalamos garitas resistentes y fáciles de reubicar.',
      },
      {
        title: 'Traslados frecuentes de obrador',
        desc: 'Cuando el frente de obra avanza, la infraestructura tiene que moverse con él. Nuestra logística 4x4 permite reubicaciones rápidas.',
      },
      {
        title: 'Cumplimiento de higiene laboral',
        desc: 'La normativa de seguridad e higiene en obra exige condiciones sanitarias documentadas para todo el personal.',
      },
    ],
    prioridades: [
      'Alquiler de baños ecológicos',
      'Garitas de control de acceso',
      'Mantenimiento y desagote periódico',
      'Módulos habitacionales para personal de obra',
      'Logística de traslado entre frentes de obra',
    ],
    caso: {
      label: 'Cómo trabajamos',
      title: 'Infraestructura que se reubica con el avance de la obra',
      body:
        'En obras y construcción coordinamos la instalación inicial y las reubicaciones sucesivas a medida que el frente de trabajo avanza, sin interrumpir el cronograma ni el cumplimiento de higiene en el predio.',
    },
    faqs: [
      {
        q: '¿Pueden escalar la cantidad de baños según la dotación de la obra?',
        a: 'Sí. Ajustamos la cantidad de unidades a medida que la dotación de personal crece o disminuye por etapa.',
      },
      {
        q: '¿Instalan garitas de control de acceso?',
        a: 'Sí. Nuestras garitas son resistentes y de fácil traslado dentro del predio, ideales para el ingreso y egreso de personal y proveedores.',
      },
      {
        q: '¿Pueden reubicar las unidades si cambia el frente de obra?',
        a: 'Sí. Con nuestra flota 4x4 coordinamos el traslado de baños, módulos y garitas cuando el trabajo se desplaza dentro del proyecto.',
      },
      {
        q: '¿El mantenimiento se adapta al cronograma de la obra?',
        a: 'Sí. Coordinamos la frecuencia de mantenimiento y desagote con la dirección de obra para no interferir con el trabajo diario.',
      },
    ],
  },

  // ───────────────────────── EVENTOS MASIVOS ─────────────────────────
  {
    slug: 'eventos-masivos',
    name: 'Eventos Masivos',
    order: 3,
    hook: 'Cobertura sanitaria dimensionada para tu convocatoria.',
    seoTitle: 'Saneamiento para eventos masivos en San Juan | CAMCA',
    seoDescription:
      'Baños ecológicos y saneamiento para eventos, festivales y actividades corporativas de gran convocatoria en San Juan. Instalación, mantenimiento y retiro incluidos.',
    heroEyebrow: 'Industria · Eventos y festivales',
    heroTitle: 'Que la logística sanitaria nunca sea un problema del evento.',
    heroLead:
      'Festivales, eventos deportivos y actividades corporativas de gran convocatoria necesitan una cobertura sanitaria calculada con precisión. CAMCA instala, mantiene y retira las unidades necesarias para cada jornada.',
    dolores: [
      {
        title: 'Cálculo de unidades según público',
        desc: 'Subestimar la cantidad de baños genera colas y mala experiencia. Dimensionamos la cobertura según los asistentes esperados.',
      },
      {
        title: 'Mantenimiento durante el evento',
        desc: 'En eventos de varios días, la limpieza no puede esperar al cierre de la jornada. Coordinamos mantenimiento durante el evento.',
      },
      {
        title: 'Instalación y retiro en plazos ajustados',
        desc: 'Los eventos tienen ventanas de montaje y desmontaje muy acotadas. Cumplimos esos plazos con logística propia.',
      },
      {
        title: 'Identificación clara de unidades',
        desc: 'Con miles de asistentes, la señalética y numeración de unidades es clave para el flujo de gente en el predio.',
      },
    ],
    prioridades: [
      'Saneamiento para eventos',
      'Alquiler de baños ecológicos',
      'Mantenimiento y desagote durante el evento',
      'Logística de instalación y retiro',
    ],
    caso: {
      label: 'Cómo trabajamos',
      title: 'Cobertura sanitaria calculada por asistente',
      body:
        'Para eventos masivos dimensionamos la cantidad de unidades según el público esperado y la duración de la actividad, y coordinamos montaje, mantenimiento en jornada y retiro dentro de los plazos del organizador.',
    },
    faqs: [
      {
        q: '¿Cómo calculan la cantidad de baños necesarios?',
        a: 'A partir de la cantidad de asistentes esperados y la duración del evento, para evitar colas y garantizar disponibilidad durante toda la jornada.',
      },
      {
        q: '¿Hacen mantenimiento durante eventos de varios días?',
        a: 'Sí. Coordinamos rondas de limpieza y desagote durante el evento, no solo al finalizar.',
      },
      {
        q: '¿Pueden instalar y retirar en plazos muy ajustados?',
        a: 'Sí. Contamos con logística propia para cumplir ventanas de montaje y desmontaje acotadas.',
      },
      {
        q: '¿Sirve para eventos corporativos además de festivales?',
        a: 'Sí. Trabajamos tanto en festivales y eventos deportivos como en actividades corporativas de gran convocatoria.',
      },
    ],
  },

  // ───────────────────────── AGROINDUSTRIA ─────────────────────────
  {
    slug: 'agroindustria',
    name: 'Agroindustria',
    order: 4,
    hook: 'Saneamiento para cosechas, plantas y operaciones de campo.',
    seoTitle: 'Servicios sanitarios para agroindustria en San Juan | CAMCA',
    seoDescription:
      'Baños ecológicos y logística 4x4 para cosechas, plantas agroindustriales y operaciones de campo en San Juan.',
    heroEyebrow: 'Industria · Agroindustria',
    heroTitle: 'Saneamiento ambiental para el trabajo de campo.',
    heroLead:
      'Cosechas, plantas agroindustriales y operaciones rurales requieren soluciones sanitarias móviles que se instalan donde está el trabajo, sin depender de infraestructura fija.',
    dolores: [
      {
        title: 'Personal disperso en el campo',
        desc: 'Durante la cosecha, el personal se distribuye en distintos sectores de la finca. Instalamos unidades cerca del punto de trabajo.',
      },
      {
        title: 'Falta de infraestructura fija',
        desc: 'Muchas zonas rurales no cuentan con red sanitaria. Nuestros baños ecológicos no dependen de conexión fija.',
      },
      {
        title: 'Traslado entre parcelas',
        desc: 'A medida que avanza la cosecha, las unidades necesitan reubicarse. Nuestra flota 4x4 lo resuelve con rapidez.',
      },
      {
        title: 'Cumplimiento de normativa rural',
        desc: 'La actividad agroindustrial también exige condiciones de higiene documentadas para el personal de campo.',
      },
    ],
    prioridades: [
      'Alquiler de baños ecológicos',
      'Logística 4x4 para zonas rurales',
      'Mantenimiento y desagote',
      'Módulos sanitarios para plantas agroindustriales',
    ],
    caso: {
      label: 'Cómo trabajamos',
      title: 'Unidades que se mueven con la cosecha',
      body:
        'En agroindustria instalamos baños ecológicos cerca de los sectores de trabajo activo y los reubicamos a medida que la cosecha o la operación avanza dentro de la finca o planta.',
    },
    faqs: [
      {
        q: '¿Instalan baños en zonas sin conexión de agua o cloaca?',
        a: 'Sí. Nuestros baños ecológicos son unidades autónomas que no requieren conexión a red fija.',
      },
      {
        q: '¿Pueden reubicar las unidades durante la cosecha?',
        a: 'Sí. Con nuestra flota 4x4 trasladamos las unidades a medida que el trabajo se desplaza dentro del campo.',
      },
      {
        q: '¿Trabajan con plantas agroindustriales además de campo abierto?',
        a: 'Sí. Instalamos módulos sanitarios para plantas y también unidades móviles para el trabajo a campo abierto.',
      },
      {
        q: '¿Cómo coordinan el mantenimiento en zonas alejadas?',
        a: 'Programamos rondas de mantenimiento con nuestra flota propia, ajustadas a la distancia y accesibilidad de cada sector.',
      },
    ],
  },

  // ───────────────────────── ORGANISMOS PÚBLICOS ─────────────────────────
  {
    slug: 'organismos-publicos',
    name: 'Organismos Públicos',
    order: 5,
    hook: 'Soluciones sanitarias para municipios y obra pública.',
    seoTitle: 'Servicios sanitarios para organismos públicos | CAMCA',
    seoDescription:
      'Módulos sanitarios y baños ecológicos para municipios, gobiernos provinciales y proyectos de infraestructura pública en San Juan.',
    heroEyebrow: 'Industria · Organismos públicos',
    heroTitle: 'El mismo estándar de servicio para la obra pública.',
    heroLead:
      'Municipios, gobiernos provinciales y proyectos de infraestructura pública necesitan un proveedor confiable, con capacidad de respuesta y cumplimiento normativo documentado.',
    dolores: [
      {
        title: 'Procesos y documentación formal',
        desc: 'La contratación pública exige documentación y trazabilidad del servicio. Ordenamos el registro de cada unidad instalada.',
      },
      {
        title: 'Cobertura en localidades alejadas',
        desc: 'Calingasta tiene localidades dispersas y de difícil acceso. Nuestra flota 4x4 cubre todo el departamento.',
      },
      {
        title: 'Eventos comunitarios',
        desc: 'Actividades y eventos organizados por el municipio también requieren cobertura sanitaria temporal.',
      },
      {
        title: 'Continuidad del servicio',
        desc: 'Los proyectos de infraestructura pública se extienden en el tiempo y necesitan continuidad sin interrupciones.',
      },
    ],
    prioridades: [
      'Módulos sanitarios para obra pública',
      'Alquiler de baños ecológicos para eventos comunitarios',
      'Mantenimiento y desagote con registro de servicio',
      'Logística 4x4 para localidades de Calingasta',
    ],
    caso: {
      label: 'Compromiso local',
      title: 'Una empresa de Tamberías, comprometida con Calingasta',
      body:
        'Como empresa de Tamberías, Calingasta, CAMCA está comprometida con el desarrollo de su comunidad y acompaña proyectos de infraestructura y eventos organizados por el municipio y otros organismos de la región.',
    },
    faqs: [
      {
        q: '¿Trabajan con municipios y organismos provinciales?',
        a: 'Sí. Brindamos servicios sanitarios para obra pública y eventos comunitarios organizados por municipios y otros organismos.',
      },
      {
        q: '¿Cubren localidades alejadas dentro de Calingasta?',
        a: 'Sí. Nuestra flota 4x4 nos permite cubrir todo el departamento, incluidas las localidades más alejadas.',
      },
      {
        q: '¿Llevan registro documentado del servicio?',
        a: 'Sí. Mantenemos un registro de servicio por unidad, útil para la trazabilidad que exige la contratación pública.',
      },
      {
        q: '¿Pueden cubrir eventos comunitarios además de obra pública?',
        a: 'Sí. Instalamos baños ecológicos para actividades y eventos organizados por el municipio o la comunidad.',
      },
    ],
  },

  // ───────────────────────── EMPRESAS PRIVADAS ─────────────────────────
  {
    slug: 'empresas-privadas',
    name: 'Empresas Privadas',
    order: 6,
    hook: 'Garitas, módulos y sanitarios para tu operación diaria.',
    seoTitle: 'Servicios sanitarios para empresas privadas | CAMCA',
    seoDescription:
      'Garitas, baños ecológicos y módulos sanitarios para empresas privadas en Calingasta y la región de Cuyo, sin obra civil permanente.',
    heroEyebrow: 'Industria · Empresas privadas',
    heroTitle: 'Soluciones sanitarias sin obra civil permanente.',
    heroLead:
      'Empresas de distintos rubros —desde clínicas hasta paradores de montaña— eligen CAMCA para resolver infraestructura sanitaria y control de acceso sin depender de obra fija.',
    dolores: [
      {
        title: 'Soluciones sin obra civil',
        desc: 'No siempre conviene invertir en infraestructura fija. Nuestros módulos y baños se instalan y retiran sin obra.',
      },
      {
        title: 'Control de acceso a instalaciones',
        desc: 'Muchas empresas necesitan un punto de control de ingreso propio. Instalamos garitas listas para operar.',
      },
      {
        title: 'Mantenimiento tercerizado y confiable',
        desc: 'Delegar el mantenimiento sanitario libera a la empresa de una tarea que no es su núcleo de negocio.',
      },
      {
        title: 'Presencia en zonas remotas',
        desc: 'Empresas con operación en zonas alejadas de Calingasta necesitan un proveedor que llegue con la misma calidad.',
      },
    ],
    prioridades: [
      'Garitas de seguridad',
      'Alquiler de baños ecológicos',
      'Módulos sanitarios y habitacionales',
      'Mantenimiento y desagote',
    ],
    caso: {
      label: 'Clientes · Clínica El Castaño, Parque · Parador de Montaña, 4R Ferretería y Bulonería',
      title: 'Confianza de empresas de distintos rubros en Calingasta',
      body:
        'Clínica El Castaño, Parque · Parador de Montaña y 4R Ferretería y Bulonería forman parte de las empresas privadas que confían en los servicios de CAMCA en la región de Calingasta.',
    },
    faqs: [
      {
        q: '¿Instalan garitas para empresas privadas?',
        a: 'Sí. Nuestras garitas son ideales para el control de acceso en predios privados, sin necesidad de obra civil.',
      },
      {
        q: '¿Sirve para empresas que no están en Tamberías o Calingasta?',
        a: 'Sí. Con nuestra flota 4x4 cubrimos la región de Cuyo, con base estratégica en Calingasta.',
      },
      {
        q: '¿Pueden encargarse de todo el mantenimiento sanitario?',
        a: 'Sí. Nos ocupamos de la limpieza, el desagote y el mantenimiento periódico de cada unidad instalada.',
      },
      {
        q: '¿Qué tipo de empresas trabajan con CAMCA?',
        a: 'Trabajamos con empresas de rubros muy distintos: salud, turismo, comercio y más. Nos adaptamos a la necesidad específica de cada una.',
      },
    ],
  },
];

export const industriaBySlug = (slug: string): Industria | undefined =>
  INDUSTRIAS.find((i) => i.slug === slug);

export const INDUSTRIAS_ORDENADAS = [...INDUSTRIAS].sort((a, b) => a.order - b.order);
