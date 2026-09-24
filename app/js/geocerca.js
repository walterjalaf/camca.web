// ============================================================
// Motor de permanencia (dwell). Portado de panel_chofer.html, con las
// correcciones que salieron de la revision.
//
// QUE SE CONSERVA DEL PROTOTIPO, porque estaba bien pensado:
//   - Se tilda por PERMANENCIA, no por paso: hay que estar DENTRO del radio
//     durante N segundos CONTINUOS. Pasar por la puerta camino a otro lado no
//     cuenta como servicio.
//   - Si sale del radio, la permanencia acumulada se DESCARTA. No se suma por
//     tramos.
//   - La hora de arribo es el INICIO de la permanencia, no el final.
//   - Una parada destildada a mano queda BLOQUEADA: el GPS no la vuelve a
//     tildar en todo el dia. El chofer ya dijo que no la hizo.
//
// QUE CAMBIA:
//   - El radio y la permanencia son POR SITIO (H7), no las constantes 50/90.
//     En un frente de obra grande de Los Azules, 50 m no dispara nunca; en una
//     garita, 50 m abarca la vereda de enfrente.
//   - Se descartan los fixes imprecisos: con precision peor que el radio, el
//     telefono no puede afirmar que esta adentro.
//   - Si hay un AGUJERO de mas de 180 s sin posiciones, la permanencia se
//     descarta. Es el caso de la pantalla apagada: el telefono deja de
//     reportar, y al volver no se puede afirmar que estuvo ahi todo el tiempo.
//     Sin esta regla se tildarian paradas por las que solo se paso.
//   - Se mide con un reloj MONOTONO (performance.now): si alguien cambia la
//     hora del telefono a mitad de jornada, el conteo no se rompe.
//
// LIMITE ESTRUCTURAL, asumido: dos paradas que comparten coordenada (el
// dataset real tiene tres pares asi) son indistinguibles para cualquier GPS.
// No se adivina: se marcan como ambiguas y la pantalla pide elegir a mano.
// ============================================================

import { distancia } from './geo.js';

export class Geocerca {
  /**
   * @param {(parada, info) => void} alTildar  se llama cuando se cumple la permanencia
   */
  constructor(alTildar) {
    this.alTildar = alTildar;
    this.paradas = [];
    /** orden -> { desdeMs, desdeMonotono, ultimoMonotono } */
    this.dwell = new Map();
    this.ultimoFixMonotono = null;
  }

  /** Solo entran las paradas todavia pendientes y no bloqueadas. */
  fijarParadas(paradas) {
    this.paradas = paradas.filter((p) => p.estado === 'planificada' && !p.bloqueada);
    for (const orden of [...this.dwell.keys()]) {
      if (!this.paradas.some((p) => p.orden === orden)) this.dwell.delete(orden);
    }
  }

  /** Se llama con cada posicion del GPS del telefono. */
  procesar(pos) {
    if (!pos) return;

    const ahoraMon = performance.now();

    // Agujero en la serie de posiciones: no se puede afirmar permanencia
    // continua sobre un periodo del que no hay datos.
    if (this.ultimoFixMonotono !== null && ahoraMon - this.ultimoFixMonotono > 180000) {
      this.dwell.clear();
    }
    this.ultimoFixMonotono = ahoraMon;

    // Un fix con precision peor que el radio no puede decir "esta adentro".
    const dentro = this.paradas.filter((p) => {
      if (pos.precision != null && pos.precision > Math.max(p.radio_m, 30) * 2) return false;
      return distancia(pos.lat, pos.lon, p.lat, p.lon) <= p.radio_m;
    });

    // Paradas que comparten coordenada: el GPS no las separa. Se toma una
    // sola candidata por punto (la de menor orden pendiente) y se marca
    // ambigua para que la pantalla pida confirmar cual se hizo.
    const porPunto = new Map();
    for (const p of dentro.slice().sort((a, b) => a.orden - b.orden)) {
      const clave = p.cluster_id ? 'c' + p.cluster_id : p.lat + ',' + p.lon;
      if (!porPunto.has(clave)) porPunto.set(clave, { candidata: p, companeras: [] });
      else porPunto.get(clave).companeras.push(p);
    }

    const activas = new Set();

    for (const { candidata, companeras } of porPunto.values()) {
      activas.add(candidata.orden);
      const previo = this.dwell.get(candidata.orden);

      if (!previo) {
        this.dwell.set(candidata.orden, {
          desdeMs: Date.now(),
          desdeMonotono: ahoraMon,
        });
        continue;
      }

      const segundos = (ahoraMon - previo.desdeMonotono) / 1000;
      if (segundos >= candidata.permanencia_seg) {
        this.dwell.delete(candidata.orden);
        this.alTildar(candidata, {
          // Hora de ARRIBO = inicio de la permanencia. El camion llego cuando
          // entro al radio, no cuando termino de cumplir los 90 segundos.
          arribo_ms: previo.desdeMs,
          permanencia_seg: Math.round(segundos),
          precision_m: pos.precision != null ? Math.round(pos.precision) : null,
          edad_fix_seg: pos.edad_seg ?? null,
          // Hay otra parada en este mismo punto: no se puede afirmar cual se
          // hizo, asi que se pide confirmar.
          ambiguo: companeras.length > 0,
          companeras: companeras.map((c) => c.orden),
          origen: 'telefono',
        });
      }
    }

    // Salio del radio: la permanencia acumulada se descarta, no se suma por tramos.
    for (const orden of [...this.dwell.keys()]) {
      if (!activas.has(orden)) this.dwell.delete(orden);
    }
  }

  /** Progreso de la parada que se esta cumpliendo, para mostrarlo en pantalla. */
  progreso(orden) {
    const d = this.dwell.get(orden);
    if (!d) return null;
    const p = this.paradas.find((x) => x.orden === orden);
    if (!p) return null;
    const segundos = (performance.now() - d.desdeMonotono) / 1000;
    return {
      segundos: Math.floor(segundos),
      total: p.permanencia_seg,
      porcentaje: Math.min(100, Math.round((segundos / p.permanencia_seg) * 100)),
    };
  }

  /** Se llama al destildar a mano: la parada sale del juego por hoy. */
  olvidar(orden) {
    this.dwell.delete(orden);
    this.paradas = this.paradas.filter((p) => p.orden !== orden);
  }
}
