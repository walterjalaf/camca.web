// ============================================================
// Mapa de la jornada. Obj. 1, paso F1.8.
//
// LA DECISIÓN DE FONDO, Y POR QUÉ NO ES LA OBVIA:
//
// El plan pedía teselas del corredor real servidas desde el propio hosting.
// tools/corredor_teselas.mjs midió el corredor de las seis rutas con un buffer
// de 2 km: son 1.815 teselas y unos 25 MB entre los zooms 12 y 16. Ese número
// cambia la respuesta.
//
//   · 25 MB no se precargan en el teléfono de un chofer. Es más que toda la
//     app, y la cola de evidencia compite por el mismo espacio.
//   · De dónde salen esas imágenes sigue sin estar decidido (supuesto S7): el
//     bulk download de los servidores de OpenStreetMap viola su política de
//     uso, y renderizar desde datos OSM necesita un motor que en Hostinger
//     compartido no existe.
//
// Así que este mapa NO depende de teselas. Dibuja la jornada con los datos que
// ya tenemos —paradas, base, la posición del GPS— en un SVG de unos pocos kB
// que funciona sin señal desde el primer día y sin pedirle permiso a nadie.
//
// Y ADEMÁS es lo que el chofer necesita. Arriba del cerro la pregunta no es
// "¿cómo se llama esta calle?" sino "¿dónde estoy respecto de las paradas que
// me faltan?". Para eso, el nombre de las calles es ruido.
//
// Si mañana hay teselas, se dibujan DETRÁS de esto sin tocar nada más: la
// función fondo() ya está, y cuando no hay teselas no rompe, no reintenta y no
// deja un rectángulo gris. R8 sigue en pie: el modo por defecto es la LISTA.
// ============================================================

const NS = 'http://www.w3.org/2000/svg';

/** Web Mercator normalizado a 0..1. Es la misma proyección de cualquier mapa. */
function proyectar(lat, lon) {
  const x = (lon + 180) / 360;
  const r = (lat * Math.PI) / 180;
  const y = (1 - Math.log(Math.tan(r) + 1 / Math.cos(r)) / Math.PI) / 2;
  return { x, y };
}

const crear = (tag, attrs = {}) => {
  const el = document.createElementNS(NS, tag);
  for (const [k, v] of Object.entries(attrs)) el.setAttribute(k, String(v));
  return el;
};

export class Mapa {
  /**
   * @param {HTMLElement} contenedor
   * @param {(orden:number)=>void} alTocarParada
   */
  constructor(contenedor, alTocarParada) {
    this.cont = contenedor;
    this.alTocarParada = alTocarParada;
    this.svg = null;
    this.posicion = null;
    this.jornada = null;
  }

  fijarPosicion(lat, lon, precisionM) {
    this.posicion = (lat === undefined || lat === null) ? null : { lat, lon, precisionM };

    // Se redibuja SÓLO si el mapa se está viendo. El modo por defecto es lista
    // (R8), así que la mayor parte de la jornada esto está oculto: rearmar el
    // SVG entero en cada fix sería tirar batería todo el día para que nadie lo
    // vea, y el criterio de aceptación de la Fase 0 es menos de 20 % de
    // batería en tres horas. Al volver al mapa se dibuja con la última
    // posición guardada.
    if (this.jornada && this.visible()) this.dibujar(this.jornada);
  }

  visible() {
    return !!this.cont && !this.cont.hidden && this.cont.offsetParent !== null;
  }

  dibujar(jornada) {
    this.jornada = jornada;
    const paradas = (jornada.paradas ?? []).filter((p) => p.lat && p.lon);
    this.cont.innerHTML = '';

    if (!paradas.length) {
      this.cont.innerHTML = '<p class="app-nota">Esta jornada no tiene paradas con coordenada.</p>';
      return;
    }

    // Todo lo que tiene que entrar: paradas, base y dónde estoy.
    const puntos = paradas.map((p) => ({ ...proyectar(p.lat, p.lon), p }));
    const extras = [];
    if (jornada.base?.lat) extras.push(proyectar(jornada.base.lat, jornada.base.lon));
    if (this.posicion) extras.push(proyectar(this.posicion.lat, this.posicion.lon));

    const todos = [...puntos, ...extras];
    let minX = Math.min(...todos.map((t) => t.x));
    let maxX = Math.max(...todos.map((t) => t.x));
    let minY = Math.min(...todos.map((t) => t.y));
    let maxY = Math.max(...todos.map((t) => t.y));

    // Un margen proporcional, y un mínimo para que una jornada de dos paradas
    // vecinas no quede con un zoom absurdo.
    const MINIMO = 0.00012;   // ~5 km a esta latitud
    const anchoRaw = Math.max(maxX - minX, MINIMO);
    const altoRaw = Math.max(maxY - minY, MINIMO);
    const cx = (minX + maxX) / 2;
    const cy = (minY + maxY) / 2;
    // Se iguala la escala de los dos ejes: si no, el mapa sale estirado y las
    // distancias que el chofer lee no son las que hay.
    const lado = Math.max(anchoRaw, altoRaw) * 1.25;
    minX = cx - lado / 2; maxX = cx + lado / 2;
    minY = cy - lado / 2; maxY = cy + lado / 2;

    const W = 1000, H = 1000;
    const px = (x) => ((x - minX) / (maxX - minX)) * W;
    const py = (y) => ((y - minY) / (maxY - minY)) * H;

    const svg = crear('svg', {
      viewBox: `0 0 ${W} ${H}`,
      class: 'mapa-svg',
      role: 'img',
      'aria-label': 'Mapa de la jornada con ' + paradas.length + ' paradas',
    });

    // Capa de fondo: vacía mientras no haya teselas. No se dibuja un
    // rectángulo gris ni se pone "cargando": un mapa que promete y no cumple
    // es peor que uno que no promete.
    svg.appendChild(crear('g', { class: 'mapa-fondo' }));

    // El recorrido planificado, en orden.
    const d = puntos.map((t, i) => (i === 0 ? 'M' : 'L') + px(t.x).toFixed(1) + ' ' + py(t.y).toFixed(1)).join(' ');
    svg.appendChild(crear('path', { d, class: 'mapa-ruta' }));

    // La base, si está.
    if (jornada.base?.lat) {
      const b = proyectar(jornada.base.lat, jornada.base.lon);
      const g = crear('g', { class: 'mapa-base' });
      g.appendChild(crear('rect', {
        x: px(b.x) - 11, y: py(b.y) - 11, width: 22, height: 22, rx: 4,
      }));
      svg.appendChild(g);
    }

    // ABANICO PARA LAS QUE SE PISAN.
    //
    // El dataset real tiene tres pares de paradas que comparten coordenada
    // exacta (Olivos 164 y 17, La Ernestina 42 y 51, Aimara y Melo). Dibujadas
    // una encima de la otra queda UNA marca: el chofer ve doce paradas donde
    // hay trece, y la de abajo es intocable. Se abren en abanico alrededor del
    // punto real, con una guía fina hasta él para no mentir sobre dónde están.
    const RADIO = 17;
    const grupos = new Map();
    for (const t of puntos) {
      const clave = Math.round(px(t.x) / (RADIO * 1.6)) + ':' + Math.round(py(t.y) / (RADIO * 1.6));
      if (!grupos.has(clave)) grupos.set(clave, []);
      grupos.get(clave).push(t);
    }
    for (const grupo of grupos.values()) {
      if (grupo.length < 2) { grupo[0].dx = 0; grupo[0].dy = 0; continue; }
      const sep = RADIO * 1.25;
      grupo.forEach((t, i) => {
        const ang = (i / grupo.length) * Math.PI * 2 - Math.PI / 2;
        t.dx = Math.cos(ang) * sep;
        t.dy = Math.sin(ang) * sep;
        t.abanico = true;
      });
    }

    // Las paradas. El estado se lee por COLOR Y POR FORMA: un tilde, una cruz
    // o el número. Con el sol de frente el color solo no alcanza.
    for (const t of puntos) {
      const p = t.p;
      const g = crear('g', {
        class: 'mapa-parada',
        'data-estado': p.estado,
        'data-orden': p.orden,
        tabindex: '0',
        role: 'button',
        'aria-label': 'Parada ' + p.orden + ', ' + (p.nombre ?? '') + ', ' + etiquetaEstado(p.estado) +
          (t.abanico ? '. Comparte coordenada con otra parada.' : ''),
      });
      const mx = px(t.x) + (t.dx ?? 0);
      const my = py(t.y) + (t.dy ?? 0);
      if (t.abanico) {
        // La guía dice dónde está de verdad: sin ella el abanico sería una
        // mentira chiquita sobre la posición.
        g.appendChild(crear('line', {
          x1: px(t.x), y1: py(t.y), x2: mx, y2: my, class: 'mapa-guia',
        }));
      }
      g.appendChild(crear('circle', { cx: mx, cy: my, r: RADIO }));
      const texto = crear('text', {
        x: mx, y: my + 6, 'text-anchor': 'middle', class: 'mapa-num',
      });
      texto.textContent = p.estado === 'ejecutada' ? '✓' : p.estado === 'no_ejecutada' ? '✕' : String(p.orden);
      g.appendChild(texto);

      const ir = () => this.alTocarParada?.(p.orden);
      g.addEventListener('click', ir);
      g.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); ir(); } });
      svg.appendChild(g);
    }

    // Dónde estoy, al final para que quede arriba de todo.
    if (this.posicion) {
      const m = proyectar(this.posicion.lat, this.posicion.lon);
      const g = crear('g', { class: 'mapa-yo' });
      // El círculo de precisión no es decoración: dice cuánto puede estar
      // equivocado el punto. Sin él, un fix de 300 m se lee como certeza.
      if (this.posicion.precisionM) {
        const metrosPorUnidad = 40075016.686 * Math.cos((this.posicion.lat * Math.PI) / 180);
        const rUnidades = this.posicion.precisionM / metrosPorUnidad;
        const rPx = (rUnidades / (maxX - minX)) * W;
        if (rPx > 4) g.appendChild(crear('circle', { cx: px(m.x), cy: py(m.y), r: Math.min(rPx, W / 2), class: 'mapa-precision' }));
      }
      g.appendChild(crear('circle', { cx: px(m.x), cy: py(m.y), r: 9, class: 'mapa-punto' }));
      svg.appendChild(g);
    }

    this.cont.appendChild(svg);
    this.svg = svg;

    const pie = document.createElement('p');
    pie.className = 'app-nota mapa-pie';
    pie.textContent = this.posicion
      ? 'Tu posición en azul' + (this.posicion.precisionM ? ' (precisión ±' + Math.round(this.posicion.precisionM) + ' m)' : '') + '.'
      : 'Todavía no hay posición del GPS.';
    this.cont.appendChild(pie);

    this.fondo();
  }

  /**
   * Teselas de fondo, si algún día las hay.
   *
   * Hoy no hace nada, a propósito y no por olvido: el origen de las imágenes
   * es la decisión S7 y todavía está abierta. Cuando se resuelva, esto pide
   * /api/v1/tesela/{z}/{x}/{y} y las dibuja detrás. Si una tesela falta, se
   * queda sin fondo y ya: NO se reintenta ni se deja un hueco gris.
   */
  fondo() {
    /* Pendiente de S7. Ver tools/corredor_teselas.mjs para el tamaño real. */
  }
}

function etiquetaEstado(estado) {
  return { ejecutada: 'hecha', no_ejecutada: 'no se hizo', planificada: 'pendiente' }[estado] ?? 'pendiente';
}

export default Mapa;
