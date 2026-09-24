// ============================================================
// Capa de GPS del telefono.
//
// El prototipo pedia alta precision todo el dia. En una jornada de ocho horas
// eso deja el telefono sin bateria a media tarde, y un chofer con 12% a las
// 15:00 deja de generar evidencia: apaga la app y el resto del dia no existe.
//
// H6 — Dos modos:
//   - ECONOMIA (por defecto): enableHighAccuracy:false, maximumAge 30 s.
//   - PRECISION: solo cuando el camion esta a menos de ~500 m de la proxima
//     parada, que es el unico momento en que la precision decide algo.
// La histeresis (entra a 500 m, sale a 700 m) evita el zigzag que haria
// alternar de modo cada pocos segundos en el borde.
//
// LIMITE CONOCIDO Y DOCUMENTADO: con la pantalla apagada o la app en segundo
// plano, watchPosition deja de entregar posiciones (en iOS el JS se suspende;
// en Android depende del fabricante). Por eso el arribo AUTORITATIVO sale del
// rastro de flota del servidor y esto solo CORROBORA. La app nunca le promete
// al chofer que puede guardar el telefono en el bolsillo y que se va a tildar
// todo solo.
// ============================================================

const RADIO_TIERRA = 6371000;
const RAD = Math.PI / 180;

export function distancia(lat1, lon1, lat2, lon2) {
  const dLat = (lat2 - lat1) * RAD;
  const dLon = (lon2 - lon1) * RAD;
  const a = Math.sin(dLat / 2) ** 2 +
            Math.cos(lat1 * RAD) * Math.cos(lat2 * RAD) * Math.sin(dLon / 2) ** 2;
  return RADIO_TIERRA * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

/** Medianoche de hoy en hora de Argentina (UTC-3), en segundos epoch.
 *  Usar la medianoche local del navegador daria 3 h de diferencia si el
 *  equipo esta configurado en otra zona horaria. Viene del prototipo. */
export function inicioDiaArt() {
  const art = new Date(Date.now() - 3 * 3600 * 1000);
  return Math.floor(Date.UTC(art.getUTCFullYear(), art.getUTCMonth(), art.getUTCDate(), 3, 0, 0) / 1000);
}

/** hour12:false explicito: sin eso, segun el idioma del telefono, sale
 *  "11:53 a. m." en vez de "11:53". Tambien viene del prototipo. */
export function horaDe(ms) {
  return new Date(ms).toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit', hour12: false });
}

const CERCA = 500;
const LEJOS = 700;

export class Gps {
  constructor() {
    this.ultima = null;
    this.modoPreciso = false;
    this._id = null;
    this._oyentes = new Set();
    this._objetivo = null;
    this._denegado = false;
  }

  /** La proxima parada. Con esto se decide si conviene gastar bateria. */
  fijarObjetivo(lat, lon) {
    this._objetivo = (lat == null || lon == null) ? null : { lat, lon };
    this._revisarModo();
  }

  escuchar(fn) {
    this._oyentes.add(fn);
    if (this.ultima) fn(this.ultima);
    return () => this._oyentes.delete(fn);
  }

  get disponible() {
    return 'geolocation' in navigator && !this._denegado;
  }

  arrancar() {
    if (!('geolocation' in navigator)) {
      this._avisarError('sin-soporte');
      return;
    }
    this._observar(false);
  }

  detener() {
    if (this._id !== null) {
      navigator.geolocation.clearWatch(this._id);
      this._id = null;
    }
  }

  _observar(preciso) {
    this.detener();
    this.modoPreciso = preciso;
    this._id = navigator.geolocation.watchPosition(
      (pos) => this._alRecibir(pos),
      (err) => this._alFallar(err),
      {
        enableHighAccuracy: preciso,
        // En economia se acepta una posicion de hasta 30 s: alcanza para
        // saber por donde anda el camion y no enciende el GPS a cada rato.
        maximumAge: preciso ? 2000 : 30000,
        timeout: preciso ? 15000 : 45000,
      }
    );
  }

  _alRecibir(pos) {
    const p = {
      lat: pos.coords.latitude,
      lon: pos.coords.longitude,
      precision: pos.coords.accuracy ?? null,
      velocidad: pos.coords.speed ?? null,
      ts: pos.timestamp,
      edad_seg: Math.max(0, Math.round((Date.now() - pos.timestamp) / 1000)),
    };
    this.ultima = p;
    this._revisarModo();
    for (const fn of this._oyentes) {
      try { fn(p); } catch (e) { console.error('[camca] oyente de gps', e); }
    }
  }

  _alFallar(err) {
    // 1 = permiso denegado. No tiene sentido seguir pidiendo: se avisa una vez
    // y la app sigue funcionando en modo manual, que es perfectamente valido.
    if (err.code === 1) {
      this._denegado = true;
      this.detener();
      this._avisarError('denegado');
      return;
    }
    this._avisarError(err.code === 3 ? 'sin-senial' : 'error');
  }

  _avisarError(motivo) {
    window.dispatchEvent(new CustomEvent('camca:gps-error', { detail: { motivo } }));
  }

  /** H6: cambia de modo con histeresis, no en cada fix. */
  _revisarModo() {
    if (!this.ultima || !this._objetivo) return;
    const d = distancia(this.ultima.lat, this.ultima.lon, this._objetivo.lat, this._objetivo.lon);
    if (!this.modoPreciso && d < CERCA) this._observar(true);
    else if (this.modoPreciso && d > LEJOS) this._observar(false);
  }
}
