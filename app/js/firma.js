// ============================================================
// Pad de firma del cliente.
//
// Pointer Events y no mouse/touch por separado: el mismo codigo anda con
// mouse, con lapiz y con el dedo. En una computadora se firma con el mouse o
// con el trackpad, que es torpe pero legalmente vale igual; en una tablet con
// lapiz queda bien.
//
// LA EVIDENCIA ES LA TRAZA VECTORIAL, no una imagen.
// Se guarda la secuencia de puntos con su tiempo, y de ahi sale un SVG de
// unos pocos kB. Tres razones:
//   1. Pesa ~4 kB contra ~40 kB de un PNG: viaja en el carril liviano y llega
//      antes que cualquier foto.
//   2. Conserva la VELOCIDAD del trazo, que es lo que distingue una firma
//      hecha a mano de una copiada: un perito puede analizarla, un PNG no.
//   3. Escala sin pixelarse cuando se imprima el remito.
//
// El trazo se suaviza por punto medio (cuadraticas entre puntos medios). Sin
// eso una firma hecha con mouse sale con esquinas duras y parece un dibujo de
// otra persona.
// ============================================================

const COLOR = '#17201b';
const GROSOR = 2.4;

export class PadFirma {
  /**
   * @param {HTMLCanvasElement} canvas
   * @param {object} opciones {alCambiar}
   */
  constructor(canvas, opciones = {}) {
    this.canvas = canvas;
    this.ctx = canvas.getContext('2d');
    this.alCambiar = opciones.alCambiar || (() => {});
    /** @type {Array<Array<{x:number,y:number,t:number}>>} trazos */
    this.trazos = [];
    this.actual = null;
    this.comenzado = null;
    this._dimensionar();
    this._escuchar();

    // Al cambiar el tamanio de la ventana hay que redimensionar el buffer o
    // el trazo queda escalado y borroso.
    this._alRedimensionar = () => { this._dimensionar(); this._repintar(); };
    window.addEventListener('resize', this._alRedimensionar);
  }

  _dimensionar() {
    const r = this.canvas.getBoundingClientRect();
    const dpr = window.devicePixelRatio || 1;
    this.canvas.width = Math.round(r.width * dpr);
    this.canvas.height = Math.round(r.height * dpr);
    this.ancho = r.width;
    this.alto = r.height;
    this.ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    this.ctx.lineCap = 'round';
    this.ctx.lineJoin = 'round';
    this.ctx.strokeStyle = COLOR;
    this.ctx.lineWidth = GROSOR;
  }

  _punto(ev) {
    const r = this.canvas.getBoundingClientRect();
    return {
      x: +(ev.clientX - r.left).toFixed(2),
      y: +(ev.clientY - r.top).toFixed(2),
      t: Math.round(performance.now() - this.comenzado),
    };
  }

  _escuchar() {
    const abajo = (ev) => {
      // Solo el boton principal: un click derecho no empieza una firma.
      if (ev.button !== undefined && ev.button !== 0) return;
      ev.preventDefault();

      // Red de seguridad: si el pad se construyo mientras el canvas estaba
      // oculto (un dialogo, un acordeon cerrado), el buffer quedo en 0x0 y el
      // trazo se dibujaria en la nada — se registra pero no se ve. Antes de
      // empezar a firmar se vuelve a medir si hace falta.
      if (this.canvas.width === 0 || this.canvas.height === 0) {
        this._dimensionar();
        this._repintar();
      }

      // La captura del puntero es una MEJORA, no un requisito: sirve para que
      // el trazo siga si el mouse se sale del canvas. Si falla, se sigue igual.
      // Sin este try/catch, un NotFoundError aborta el handler antes de
      // registrar el trazo y la firma deja de funcionar EN SILENCIO: el
      // usuario dibuja y no aparece nada, sin ningún mensaje.
      try {
        this.canvas.setPointerCapture?.(ev.pointerId);
      } catch { /* se firma igual, solo que sin captura */ }

      if (this.comenzado === null) this.comenzado = performance.now();
      this.actual = [this._punto(ev)];
      this.trazos.push(this.actual);
    };

    const mover = (ev) => {
      if (!this.actual) return;
      ev.preventDefault();

      // getCoalescedEvents recupera las posiciones que el navegador agrupo
      // entre dos frames. Sin esto, un trazo rapido con mouse sale poligonal.
      //
      // PERO puede devolver una lista VACIA, y entonces el punto se pierde.
      // Pasa con eventos sinteticos y con algunos navegadores para eventos que
      // no vienen del compositor. Sin este respaldo la firma pierde tramos de
      // forma intermitente: el peor tipo de falla, porque parece que "a veces
      // anda". Verificado: 40 movimientos dejaban el trazo en UN solo punto.
      let eventos = ev.getCoalescedEvents ? ev.getCoalescedEvents() : null;
      if (!eventos || eventos.length === 0) eventos = [ev];
      for (const e of eventos) this.actual.push(this._punto(e));

      this._repintar();
      this.alCambiar(this.hayFirma());
    };

    const arriba = (ev) => {
      if (!this.actual) return;
      ev.preventDefault?.();
      // Un toque sin movimiento es un punto, no un trazo: se descarta para que
      // un apoyo accidental no cuente como firma.
      if (this.actual.length < 2) this.trazos.pop();
      this.actual = null;
      this._repintar();
      this.alCambiar(this.hayFirma());
    };

    this.canvas.addEventListener('pointerdown', abajo);
    this.canvas.addEventListener('pointermove', mover);
    this.canvas.addEventListener('pointerup', arriba);
    this.canvas.addEventListener('pointercancel', arriba);
    this.canvas.addEventListener('pointerleave', arriba);
    // touch-action:none en CSS evita que el gesto haga scroll en vez de firmar.
  }

  _repintar() {
    const { ctx } = this;
    ctx.clearRect(0, 0, this.ancho, this.alto);
    for (const trazo of this.trazos) this._dibujarTrazo(ctx, trazo);
  }

  /** Suavizado por punto medio: cuadraticas entre los puntos medios. */
  _dibujarTrazo(ctx, p) {
    if (p.length < 2) return;
    ctx.beginPath();
    ctx.moveTo(p[0].x, p[0].y);
    for (let i = 1; i < p.length - 1; i++) {
      const mx = (p[i].x + p[i + 1].x) / 2;
      const my = (p[i].y + p[i + 1].y) / 2;
      ctx.quadraticCurveTo(p[i].x, p[i].y, mx, my);
    }
    ctx.lineTo(p[p.length - 1].x, p[p.length - 1].y);
    ctx.stroke();
  }

  /** Una firma de verdad tiene al menos un trazo con recorrido. */
  hayFirma() {
    return this.trazos.some((t) => t.length >= 3 && this._largo(t) > 20);
  }

  _largo(t) {
    let d = 0;
    for (let i = 1; i < t.length; i++) d += Math.hypot(t[i].x - t[i - 1].x, t[i].y - t[i - 1].y);
    return d;
  }

  limpiar() {
    this.trazos = [];
    this.actual = null;
    this.comenzado = null;
    this._repintar();
    this.alCambiar(false);
  }

  /**
   * SVG con la traza. Es lo que se sube: es la evidencia.
   * Incluye los tiempos como atributo de datos para poder analizar la
   * velocidad del trazo mas adelante sin volver a pedir la firma.
   */
  aSvg(meta = {}) {
    const caminos = this.trazos
      .filter((t) => t.length >= 2)
      .map((t) => {
        let d = 'M' + t[0].x + ',' + t[0].y;
        for (let i = 1; i < t.length - 1; i++) {
          const mx = ((t[i].x + t[i + 1].x) / 2).toFixed(2);
          const my = ((t[i].y + t[i + 1].y) / 2).toFixed(2);
          d += 'Q' + t[i].x + ',' + t[i].y + ' ' + mx + ',' + my;
        }
        d += 'L' + t[t.length - 1].x + ',' + t[t.length - 1].y;
        const tiempos = t.map((p) => p.t).join(' ');
        return '<path d="' + d + '" data-t="' + tiempos + '"/>';
      })
      .join('');

    const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

    return '<?xml version="1.0" encoding="UTF-8"?>' +
      '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + Math.round(this.ancho) + ' ' + Math.round(this.alto) + '" ' +
      'width="' + Math.round(this.ancho) + '" height="' + Math.round(this.alto) + '">' +
      '<metadata>' +
        '<firma xmlns="https://camcasoluciones.com.ar/ns/firma" ' +
        'firmante="' + esc(meta.firmante) + '" ' +
        'sitio="' + esc(meta.sitio) + '" ' +
        'fecha="' + esc(meta.fecha || new Date().toISOString()) + '" ' +
        'trazos="' + this.trazos.length + '" ' +
        'duracion_ms="' + this.duracion() + '"/>' +
      '</metadata>' +
      '<g fill="none" stroke="' + COLOR + '" stroke-width="' + GROSOR + '" stroke-linecap="round" stroke-linejoin="round">' +
      caminos + '</g></svg>';
  }

  duracion() {
    let max = 0;
    for (const t of this.trazos) for (const p of t) if (p.t > max) max = p.t;
    return max;
  }

  async aBlob(meta = {}) {
    const svg = this.aSvg(meta);
    const blob = new Blob([svg], { type: 'image/svg+xml' });
    const hash = await crypto.subtle.digest('SHA-256', await blob.arrayBuffer());
    return {
      blob,
      sha256: [...new Uint8Array(hash)].map((b) => b.toString(16).padStart(2, '0')).join(''),
      bytes: blob.size,
      trazos: this.trazos.length,
      duracion_ms: this.duracion(),
    };
  }

  destruir() {
    window.removeEventListener('resize', this._alRedimensionar);
  }
}
