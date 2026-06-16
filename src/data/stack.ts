// ============================================================
// CIAF · Stack de sistemas con los que trabajamos (logos reales)
// ============================================================

export interface StackSystem {
  name: string;
  logo: string | null; // null → se muestra el nombre como texto
}

export const STACK: StackSystem[] = [
  { name: 'Contabilium', logo: '/integraciones/contabilium.webp' },
  { name: 'Odoo', logo: '/integraciones/odoo.webp' },
  { name: 'SGC', logo: '/integraciones/sgc.webp' },
  { name: 'SOS Contador', logo: '/integraciones/sos-contador.webp' },
  { name: 'AdministraNET', logo: '/integraciones/administranet.svg' },
  { name: 'Control Comercio', logo: '/integraciones/control-comercio.webp' },
  { name: 'PxSol', logo: '/integraciones/pxsol.svg' },
  { name: 'Optix', logo: '/integraciones/optix-sm.webp' },
  { name: 'Dragonfish', logo: '/integraciones/dragonfish.webp' },
  { name: 'Toteat', logo: '/integraciones/toteat.svg' },
  { name: 'Fudo', logo: '/integraciones/fudo.svg' },
  { name: 'Maxirest', logo: '/integraciones/maxirest.svg' },
  { name: 'Bistrosoft', logo: '/integraciones/bistrosoft.webp' },
  { name: 'Gestión Cervecera', logo: null },
  { name: 'Power BI', logo: '/integraciones/powerbi.webp' },
  { name: 'Looker Studio', logo: '/integraciones/looker-studio.svg' },
];
