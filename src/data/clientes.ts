// ============================================================
// CAMCA · Clientes reales (del material institucional: "Empresas
// que confían en nuestros servicios", + Veladero). SOLO clientes reales.
// No inventar. Logos oficiales de cada empresa (ver public/clientes/).
// "Los Azules": foto de perfil oficial de su página de Facebook.
// `dark`: el logo tiene zonas muy claras/blancas → se muestra sobre
// chip oscuro para que no se pierda contra un fondo blanco.
// ============================================================

export interface Cliente {
  name: string;
  logo: string | null;
  dark?: boolean;
}

export const CLIENTES: Cliente[] = [
  { name: 'Los Azules', logo: '/clientes/los-azules.webp' },
  { name: 'Veladero', logo: '/clientes/veladero.webp' },
  { name: 'Clínica El Castaño', logo: '/clientes/clinica-el-castano.webp' },
  { name: 'Parque · Parador de Montaña', logo: '/clientes/parque-parador.webp' },
  { name: '4R Ferretería y Bulonería', logo: '/clientes/4r.webp', dark: true },
];
