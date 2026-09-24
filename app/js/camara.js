// ============================================================
// Captura de fotos. Pensada para USO WEB: la fuente principal es la webcam
// de la computadora, con el archivo como alternativa (arrastrar, pegar o
// elegir). En un telefono la misma pantalla usa la camara trasera.
//
// Lo que hace, y por que cada cosa:
//
//  - REORIENTA. Sin `imageOrientation: 'from-image'` la mitad de las fotos
//    sacadas con un telefono salen acostadas: el sensor guarda la rotacion en
//    EXIF y el canvas la ignora. Una evidencia de costado se ve como un
//    descuido y le resta seriedad a todo el remito.
//  - REESCALA a 1600 px. Una foto de 12 MP son ~4 MB; a 1600 px queda en
//    200 kB y se sigue viendo perfectamente un bano limpio o un porton
//    cerrado. Con seis choferes subiendo desde el cerro, esa diferencia es
//    entre sincronizar en dos minutos o no sincronizar.
//  - SELLA la imagen con fecha, hora, sitio y coordenada. El sello va QUEMADO
//    en los pixeles: un metadato se edita con cualquier programa, y esto tiene
//    que servir como evidencia frente a un cliente minero.
//  - Calcula el SHA-256 antes de encolar, para que el servidor pueda
//    verificar que llego intacta.
// ============================================================

const ANCHO_MAX = 1600;
const CALIDAD = 0.75;

/** WebP pesa ~30% menos que JPEG a la misma calidad. Safari viejo no lo tiene. */
function formatoSoportado() {
  const c = document.createElement('canvas');
  c.width = c.height = 1;
  return c.toDataURL('image/webp').startsWith('data:image/webp')
    ? { mime: 'image/webp', ext: 'webp' }
    : { mime: 'image/jpeg', ext: 'jpg' };
}

async function aBitmap(origen) {
  // from-image respeta el EXIF. Es la diferencia entre una foto derecha y una
  // acostada, y no hay forma de arreglarlo despues sin perder calidad.
  try {
    return await createImageBitmap(origen, { imageOrientation: 'from-image' });
  } catch {
    return await createImageBitmap(origen);
  }
}

function medidas(ancho, alto) {
  if (ancho <= ANCHO_MAX && alto <= ANCHO_MAX) return { ancho, alto };
  const escala = ANCHO_MAX / Math.max(ancho, alto);
  return { ancho: Math.round(ancho * escala), alto: Math.round(alto * escala) };
}

/**
 * Dibuja el sello al pie. Barra oscura semitransparente con dos lineas:
 * sitio arriba, fecha/hora/coordenada abajo.
 */
function sellar(ctx, ancho, alto, sello) {
  const escala = ancho / 1600;
  const cuerpo = Math.max(13, Math.round(22 * escala));
  const alturaBarra = Math.round(cuerpo * 3.4);
  const margen = Math.round(cuerpo * 0.7);

  ctx.save();
  ctx.fillStyle = 'rgba(11, 59, 36, 0.82)';   // verde bosque de la marca
  ctx.fillRect(0, alto - alturaBarra, ancho, alturaBarra);

  ctx.fillStyle = '#ffffff';
  ctx.textBaseline = 'top';

  ctx.font = '700 ' + cuerpo + "px 'Inter', system-ui, sans-serif";
  ctx.fillText(recortar(ctx, sello.sitio || 'CAMCA', ancho - margen * 2), margen, alto - alturaBarra + margen * 0.6);

  ctx.font = '400 ' + Math.round(cuerpo * 0.82) + "px 'Inter', system-ui, sans-serif";
  ctx.fillStyle = 'rgba(255,255,255,0.88)';
  ctx.fillText(recortar(ctx, sello.linea2 || '', ancho - margen * 2), margen, alto - alturaBarra + margen * 0.6 + cuerpo * 1.35);

  ctx.restore();
}

function recortar(ctx, texto, anchoMax) {
  let t = String(texto);
  if (ctx.measureText(t).width <= anchoMax) return t;
  while (t.length > 4 && ctx.measureText(t + '…').width > anchoMax) t = t.slice(0, -1);
  return t + '…';
}

async function sha256(blob) {
  const buf = await blob.arrayBuffer();
  const hash = await crypto.subtle.digest('SHA-256', buf);
  return [...new Uint8Array(hash)].map((b) => b.toString(16).padStart(2, '0')).join('');
}

/**
 * Procesa una imagen (File, Blob o ImageBitmap) y devuelve el blob listo.
 * @param {object} sello {sitio, linea2}
 */
export async function procesar(origen, sello = {}) {
  const bitmap = await aBitmap(origen);
  const { ancho, alto } = medidas(bitmap.width, bitmap.height);

  const canvas = document.createElement('canvas');
  canvas.width = ancho;
  canvas.height = alto;
  const ctx = canvas.getContext('2d');
  ctx.imageSmoothingQuality = 'high';
  ctx.drawImage(bitmap, 0, 0, ancho, alto);
  bitmap.close?.();

  sellar(ctx, ancho, alto, sello);

  const { mime, ext } = formatoSoportado();
  const blob = await new Promise((r) => canvas.toBlob(r, mime, CALIDAD));
  if (!blob) throw new Error('No se pudo procesar la imagen.');

  return { blob, mime, ext, ancho, alto, sha256: await sha256(blob) };
}

/**
 * Camara en vivo. En una computadora abre la webcam; en un telefono, la
 * camara trasera. Devuelve un controlador para que la pantalla decida cuando
 * disparar y cuando cerrar.
 */
export async function abrirCamara(video) {
  if (!navigator.mediaDevices?.getUserMedia) {
    throw new Error('Este navegador no permite usar la cámara. Subí la foto desde un archivo.');
  }

  let flujo;
  try {
    flujo = await navigator.mediaDevices.getUserMedia({
      video: {
        // 'environment' es la trasera en un telefono; en una computadora con
        // una sola webcam se ignora y usa la que hay.
        facingMode: { ideal: 'environment' },
        width: { ideal: 1920 },
        height: { ideal: 1080 },
      },
      audio: false,
    });
  } catch (e) {
    // Mensajes utiles: "NotAllowedError" no le dice nada a un supervisor.
    if (e.name === 'NotAllowedError' || e.name === 'SecurityError') {
      throw new Error('El navegador bloqueó la cámara. Habilitala en el candado de la barra de direcciones, o subí la foto desde un archivo.');
    }
    if (e.name === 'NotFoundError' || e.name === 'OverconstrainedError') {
      throw new Error('No se encontró ninguna cámara. Subí la foto desde un archivo.');
    }
    throw new Error('No se pudo abrir la cámara. Subí la foto desde un archivo.');
  }

  video.srcObject = flujo;
  video.setAttribute('playsinline', '');
  await video.play().catch(() => {});

  return {
    async disparar(sello) {
      const canvas = document.createElement('canvas');
      canvas.width = video.videoWidth;
      canvas.height = video.videoHeight;
      canvas.getContext('2d').drawImage(video, 0, 0);
      const crudo = await new Promise((r) => canvas.toBlob(r, 'image/png'));
      return procesar(crudo, sello);
    },
    cerrar() {
      // Sin esto la luz de la webcam queda encendida y el usuario cree que lo
      // siguen grabando. Es una falta de respeto evitable.
      for (const pista of flujo.getTracks()) pista.stop();
      video.srcObject = null;
    },
  };
}

/** Espacio libre, para frenar antes de llenar el disco. */
export async function hayEspacio(bytesNecesarios = 5 * 1024 * 1024) {
  try {
    if (!navigator.storage?.estimate) return true;
    const e = await navigator.storage.estimate();
    const libre = (e.quota ?? 0) - (e.usage ?? 0);
    return libre > bytesNecesarios;
  } catch {
    return true;
  }
}
