// ============================================================
// Informe de la jornada por WhatsApp.
//
// Se conserva a proposito: la cuadrilla ya usa este circuito todos los dias y
// funciona. Cambiar de un golpe la herramienta Y el circuito de comunicacion
// es como se pierde la adopcion de una app de campo.
//
// El informe DICE LA VERDAD sobre lo que todavia no llego al servidor. Un
// informe que se presenta como definitivo cuando faltan doce fotos por subir
// es peor que no mandar nada: alguien lo archiva como cierre del dia.
// ============================================================

import { horaDe } from './geo.js';

export default function armarInforme(jornada, base, pendientes) {
  const hechas = jornada.paradas.filter((p) => p.estado === 'ejecutada');
  const noHechas = jornada.paradas.filter((p) => p.estado === 'no_ejecutada');
  const baniosHechos = hechas.reduce((a, p) => a + (p.cantidad_real ?? p.cantidad_plan), 0);

  const ahora = new Date();
  const fecha = new Date(jornada.fecha + 'T12:00:00').toLocaleDateString('es-AR');

  const L = [];

  if (pendientes && pendientes.total > 0) {
    L.push('*INFORME PRELIMINAR*');
    L.push('_Faltan ' + pendientes.total + ' registros por subir. Se completa solo cuando haya señal._');
    L.push('');
  }

  L.push('*INFORME DE RECORRIDO — CAMCA*');
  L.push('📅 ' + jornada.ruta + ' ' + fecha + ' · ⏰ ' + horaDe(ahora.getTime()));
  if (base?.direccion) L.push('Base: ' + base.direccion);
  L.push('');
  L.push('✅ Paradas cumplidas: ' + hechas.length + '/' + jornada.paradas.length);
  L.push('🚽 Baños atendidos: ' + baniosHechos + '/' + jornada.banios_plan);

  // Los km se presentan como lo que son. Si no hay odometro, es una
  // estimacion por posiciones y el haversine infla por ruido de GPS: decirle
  // "recorrido real" a eso es mentir en un documento que puede terminar en
  // una discusion de facturacion.
  if (jornada.km_real && jornada.km_fuente === 'odometro') {
    L.push('🛣️ Recorrido real: ' + jornada.km_real.toFixed(1) + ' km (odómetro)');
  } else if (jornada.km_real) {
    L.push('🛣️ Recorrido aproximado: ' + jornada.km_real.toFixed(1) + ' km (estimado por posiciones)');
  } else {
    L.push('🛣️ Recorrido estimado: ' + jornada.km_estimado + ' km');
  }
  L.push('');

  if (hechas.length) {
    L.push('*Cumplido:*');
    for (const p of hechas) {
      const hora = p.arribo_utc ? ' — ' + horaDe(Date.parse(p.arribo_utc + 'Z')) + ' hs' : '';
      const marca = p.origen === 'telefono' || p.origen === 'flota' ? ' 📍' : '';
      L.push(p.orden + '. ' + p.nombre + (p.direccion ? ' - ' + p.direccion : '') + hora + marca);
    }
    L.push('');
  }

  if (noHechas.length) {
    L.push('*Pendiente:*');
    for (const p of noHechas) {
      L.push(p.orden + '. ' + p.nombre + (p.direccion ? ' - ' + p.direccion : '') +
             ' — Motivo: ' + (p.motivo || 'sin especificar'));
    }
  } else if (hechas.length === jornada.paradas.length) {
    L.push('🎉 Recorrido completo, sin pendientes.');
  }

  return L.join('\n');
}
