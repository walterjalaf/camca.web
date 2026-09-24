// ============================================================
// Diálogo de "no se hizo".
//
// El motivo es OBLIGATORIO: es el dato que después reclama el cliente cuando
// pregunta por qué no se pasó, y es la mitad de la vista de desvíos del
// Objetivo 1. Una parada sin motivo es un agujero en el informe.
//
// Los motivos frecuentes van como botones porque escribir en una camioneta,
// con una mano y apurado, es exactamente lo que hace que nadie ponga el
// motivo. Con un toque siempre queda algo cargado, y el campo libre está para
// lo que no entra en la lista.
// ============================================================

const $ = (id) => document.getElementById(id);

// Salidos de los motivos reales de esta operación: portones, obras y montaña.
const FRECUENTES = [
  'Portón cerrado',
  'No había nadie',
  'Camino cortado',
  'Sin acceso al sector',
  'El cliente pidió pasar otro día',
  'Vehículo bloqueando el acceso',
  'Ya lo había hecho otro móvil',
];

let resolver = null;

export function pedirMotivo(parada) {
  return new Promise((r) => {
    resolver = r;
    $('mot-sub').textContent = parada.nombre + (parada.direccion ? ' · ' + parada.direccion : '');
    $('mot-texto').value = '';
    $('mot-error').textContent = '';
    pintarChips();
    $('dialogo-motivo').hidden = false;
    $('mot-texto').focus();
  });
}

function pintarChips() {
  const cont = $('mot-rapidos');
  cont.innerHTML = '';
  for (const m of FRECUENTES) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'motivo-chip';
    b.textContent = m;
    b.setAttribute('aria-pressed', 'false');
    b.onclick = () => {
      // Se escribe en el campo en vez de reemplazarlo: así el chofer puede
      // elegir uno y agregarle el detalle ("Portón cerrado, avisé a Terusi").
      const campo = $('mot-texto');
      campo.value = campo.value.trim() ? campo.value.trim() + '. ' + m : m;
      for (const otro of cont.children) otro.setAttribute('aria-pressed', 'false');
      b.setAttribute('aria-pressed', 'true');
      $('mot-error').textContent = '';
      campo.focus();
    };
    cont.appendChild(b);
  }
}

function cerrar(valor) {
  $('dialogo-motivo').hidden = true;
  const r = resolver;
  resolver = null;
  r?.(valor);
}

export function montarMotivo() {
  $('mot-cancelar')?.addEventListener('click', () => cerrar(null));

  $('dialogo-motivo')?.addEventListener('click', (ev) => {
    if (ev.target.id === 'dialogo-motivo') cerrar(null);
  });

  document.addEventListener('keydown', (ev) => {
    if (ev.key === 'Escape' && resolver) cerrar(null);
  });

  $('mot-confirmar')?.addEventListener('click', () => {
    const motivo = $('mot-texto').value.trim();
    if (!motivo) {
      $('mot-error').textContent = 'Hace falta el motivo: sin eso no se puede cerrar el día.';
      $('mot-texto').focus();
      return;
    }
    if (motivo.length > 255) {
      $('mot-error').textContent = 'Demasiado largo: resumilo en 255 caracteres.';
      return;
    }
    cerrar(motivo);
  });
}
