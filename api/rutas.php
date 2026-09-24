<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Tabla de rutas de la API v1.
 *
 * limite: ['ambito' => [maximo, segundos]].
 *   usuario/dispositivo  → el bucket correcto para rutas operativas (R4)
 *   ip                   → backstop con umbral ALTO: con CGNAT, seis choferes
 *                          de San Juan son una sola IP y un umbral bajo los
 *                          bloquearía a todos a las 6 de la mañana.
 */
return [
    // --- Públicas ---
    'GET /health'      => ['handler' => 'health',   'roles' => [Policy::PUBLICO]],
    'POST /contacto'   => ['handler' => 'contacto', 'roles' => [Policy::PUBLICO],
                           'csrf' => false, 'limite' => ['ip' => [20, 3600]]],

    // --- Identidad ---
    'POST /auth/pin'       => ['handler' => 'auth_pin', 'roles' => [Policy::PUBLICO], 'csrf' => false,
                               'limite' => ['ip' => [200, 3600]]],
    'POST /auth/clave'     => ['handler' => 'auth_clave', 'roles' => [Policy::PUBLICO], 'csrf' => false,
                               'limite' => ['ip' => [200, 3600]]],
    'POST /auth/salir'     => ['handler' => 'auth_sesion', 'roles' => [Policy::CHOFER, Policy::SUPERVISOR, Policy::CLIENTE]],
    'POST /dispositivo/reclamar' => ['handler' => 'dispositivo', 'roles' => [Policy::PUBLICO], 'csrf' => false,
                               'limite' => ['ip' => [60, 3600]]],
    'POST /dispositivo/codigo'   => ['handler' => 'dispositivo', 'roles' => [Policy::SUPERVISOR]],

    // --- Operación de campo ---
    'GET /dia'          => ['handler' => 'bootstrap_dia', 'roles' => [Policy::CHOFER],
                            'limite' => ['usuario' => [120, 3600]]],
    'POST /sync/lote'   => ['handler' => 'sync_batch',   'roles' => [Policy::CHOFER],
                            'limite' => ['dispositivo' => [600, 3600]]],
    'POST /sync/adjunto'=> ['handler' => 'sync_adjunto', 'roles' => [Policy::CHOFER],
                            'limite' => ['dispositivo' => [3000, 3600]]],
    'GET /sync/estado'  => ['handler' => 'sync_status',  'roles' => [Policy::CHOFER]],
    'POST /jornada/cerrar' => ['handler' => 'jornada',   'roles' => [Policy::CHOFER]],

    // --- Evidencias (viven fuera de public_html; las sirve PHP con sesión) ---
    'GET /evidencia/{uuid}' => ['handler' => 'evidencia', 'roles' => [Policy::CHOFER, Policy::SUPERVISOR]],

    // --- Supervisión ---
    'GET /jornadas'      => ['handler' => 'jornada', 'roles' => [Policy::SUPERVISOR]],
    'GET /flota'         => ['handler' => 'flota',   'roles' => [Policy::SUPERVISOR]],
    'GET /consultas'     => ['handler' => 'consultas', 'roles' => [Policy::SUPERVISOR]],
    // Recorre hasta 92 días de paradas y lo calcula al vuelo: el límite es
    // más bajo que el del resto a propósito.
    'GET /desvios'       => ['handler' => 'desvios', 'roles' => [Policy::SUPERVISOR],
                             'limite' => ['usuario' => [240, 3600]]],

    // --- Documentos R23 / R28 ---
    // Emitir consume numeracion correlativa, asi que no lo puede disparar un
    // chofer desde el telefono: es un acto administrativo.
    'POST /registro'       => ['handler' => 'registro', 'roles' => [Policy::SUPERVISOR]],
    'GET /registro/{id}'   => ['handler' => 'registro', 'roles' => [Policy::SUPERVISOR]],

    // --- Circuito del trabajo y remitos (Obj. 2) ---
    'GET /trabajos'        => ['handler' => 'trabajos', 'roles' => [Policy::SUPERVISOR]],
    'POST /trabajo/mover'  => ['handler' => 'trabajos', 'roles' => [Policy::SUPERVISOR]],
    // Emitir un remito consume numeracion y afirma algo ante el cliente: es un
    // acto de la oficina, igual que el R28.
    'POST /remito'         => ['handler' => 'remito', 'roles' => [Policy::SUPERVISOR]],
    'GET /remito/{id}'     => ['handler' => 'remito', 'roles' => [Policy::SUPERVISOR]],

    // --- Auditoría (F2.5) ---
    // Con ?verificar=1 recorre las dos cadenas enteras: límite más bajo.
    'GET /auditoria'       => ['handler' => 'auditoria', 'roles' => [Policy::SUPERVISOR],
                               'limite' => ['usuario' => [120, 3600]]],

    // --- Maestro de clientes (F3.1) ---
    'GET /clientes'                => ['handler' => 'clientes', 'roles' => [Policy::SUPERVISOR]],
    'POST /cliente'                => ['handler' => 'clientes', 'roles' => [Policy::SUPERVISOR]],
    // Fusionar y deshacer cambian a quién se le factura cada sitio: es de
    // administración, no de la coordinación del día.
    'POST /clientes/fusion'          => ['handler' => 'clientes', 'roles' => [Policy::ADMIN]],
    'POST /clientes/fusion/deshacer' => ['handler' => 'clientes', 'roles' => [Policy::ADMIN]],

    // --- Tarifario (F3.2) ---
    // Mirar y cotizar, la oficina; cargar y cerrar precios, administración.
    'GET /tarifas'         => ['handler' => 'tarifas', 'roles' => [Policy::SUPERVISOR]],
    'GET /tarifa/cotizar'  => ['handler' => 'tarifas', 'roles' => [Policy::SUPERVISOR]],
    'POST /tarifa'         => ['handler' => 'tarifas', 'roles' => [Policy::ADMIN]],
    'POST /tarifa/cerrar'  => ['handler' => 'tarifas', 'roles' => [Policy::ADMIN]],

    // --- Propuesta de facturación (F3.3) ---
    // Mirarla, la oficina; armarla y descartarla, administración: mueve plata.
    'GET /facturacion'            => ['handler' => 'facturacion', 'roles' => [Policy::SUPERVISOR]],
    'GET /facturacion/previa'     => ['handler' => 'facturacion', 'roles' => [Policy::SUPERVISOR]],
    // F3.5: la planilla para el sistema contable. Sólo lo aprobado; la baja administración.
    'GET /facturacion/exportar'   => ['handler' => 'facturacion', 'roles' => [Policy::ADMIN]],
    'GET /facturacion/{id}'       => ['handler' => 'facturacion', 'roles' => [Policy::SUPERVISOR]],
    'POST /facturacion'           => ['handler' => 'facturacion', 'roles' => [Policy::ADMIN]],
    'POST /facturacion/descartar' => ['handler' => 'facturacion', 'roles' => [Policy::ADMIN]],
    // F3.4: aprobar, quitar una línea de un borrador, notas de crédito y débito.
    'POST /facturacion/aprobar'   => ['handler' => 'facturacion', 'roles' => [Policy::ADMIN]],
    'POST /facturacion/quitar'    => ['handler' => 'facturacion', 'roles' => [Policy::ADMIN]],
    'POST /facturacion/ajuste'    => ['handler' => 'facturacion', 'roles' => [Policy::ADMIN]],

    // --- Cuadrillas, personal y equipos (F3.6) ---
    // La operación del día (mover equipos, cargar vencimientos, armar
    // cuadrillas) es de la coordinación; dar de alta personas, vehículos y
    // lotes de equipos, de administración.
    'GET /recursos'                     => ['handler' => 'recursos', 'roles' => [Policy::SUPERVISOR]],
    'POST /recursos/persona'            => ['handler' => 'recursos', 'roles' => [Policy::ADMIN]],
    'POST /recursos/vehiculo'           => ['handler' => 'recursos', 'roles' => [Policy::ADMIN]],
    'POST /recursos/activos'            => ['handler' => 'recursos', 'roles' => [Policy::ADMIN]],
    'POST /recursos/activo/mover'       => ['handler' => 'recursos', 'roles' => [Policy::SUPERVISOR]],
    'POST /recursos/activo/estado'      => ['handler' => 'recursos', 'roles' => [Policy::SUPERVISOR]],
    'POST /recursos/vencimiento'        => ['handler' => 'recursos', 'roles' => [Policy::SUPERVISOR]],
    'POST /recursos/cuadrilla'          => ['handler' => 'recursos', 'roles' => [Policy::SUPERVISOR]],
    'POST /recursos/cuadrilla/miembro'  => ['handler' => 'recursos', 'roles' => [Policy::SUPERVISOR]],
    'POST /recursos/cuadrilla/quitar'   => ['handler' => 'recursos', 'roles' => [Policy::SUPERVISOR]],

    // --- Asignación y campañas (F3.7) ---
    'GET /asignaciones'          => ['handler' => 'asignaciones', 'roles' => [Policy::SUPERVISOR]],
    'POST /asignacion'           => ['handler' => 'asignaciones', 'roles' => [Policy::SUPERVISOR]],
    'POST /asignacion/cancelar'  => ['handler' => 'asignaciones', 'roles' => [Policy::SUPERVISOR]],
    'POST /campana'              => ['handler' => 'asignaciones', 'roles' => [Policy::SUPERVISOR]],
    'POST /campana/cancelar'     => ['handler' => 'asignaciones', 'roles' => [Policy::SUPERVISOR]],

    // --- Dashboards de trazabilidad (F3.9) ---
    'GET /indicadores'           => ['handler' => 'indicadores', 'roles' => [Policy::SUPERVISOR]],

    // --- Portal del cliente (F3.10) ---
    // Sólo lo suyo. Un remito ajeno da 404, igual que uno que no existe.
    // Límite por identidad: quien está del otro lado no es de CAMCA, y cada
    // remito abierto arma un PDF y deja un eslabón de auditoría.
    'GET /portal'                => ['handler' => 'portal', 'roles' => [Policy::CLIENTE],
                                     'limite' => ['usuario' => [120, 3600]]],
    'GET /portal/remito/{id}'    => ['handler' => 'portal', 'roles' => [Policy::CLIENTE],
                                     'limite' => ['usuario' => [300, 3600]]],
    'POST /clientes/acceso'      => ['handler' => 'clientes', 'roles' => [Policy::ADMIN]],
    'POST /clientes/acceso/baja' => ['handler' => 'clientes', 'roles' => [Policy::ADMIN]],

    // --- Documentos controlados del SGA (F4.1, ISO 14001 §7.5.3) ---
    // Elaborar y distribuir, la coordinación; aprobar, devolver, confirmar la
    // vigencia y retirar, administración (la dirección aprueba). El chofer no
    // entra acá: su toma de conocimiento la registra la oficina.
    'GET /sga/documentos'                    => ['handler' => 'sga_documentos', 'roles' => [Policy::SUPERVISOR]],
    'GET /sga/documento/{id}'                => ['handler' => 'sga_documentos', 'roles' => [Policy::SUPERVISOR]],
    'POST /sga/documento'                    => ['handler' => 'sga_documentos', 'roles' => [Policy::SUPERVISOR]],
    'POST /sga/documento/{id}/version'       => ['handler' => 'sga_documentos', 'roles' => [Policy::SUPERVISOR]],
    'POST /sga/documento/{id}/destinatarios' => ['handler' => 'sga_documentos', 'roles' => [Policy::SUPERVISOR]],
    'POST /sga/documento/{id}/confirmar'     => ['handler' => 'sga_documentos', 'roles' => [Policy::ADMIN]],
    'POST /sga/documento/{id}/retirar'       => ['handler' => 'sga_documentos', 'roles' => [Policy::ADMIN]],
    'GET /sga/version/{id}/archivo'          => ['handler' => 'sga_documentos', 'roles' => [Policy::SUPERVISOR]],
    'POST /sga/version/{id}/archivo'         => ['handler' => 'sga_documentos', 'roles' => [Policy::SUPERVISOR],
                                                 'limite' => ['usuario' => [60, 3600]]],
    'POST /sga/version/{id}/enviar'          => ['handler' => 'sga_documentos', 'roles' => [Policy::SUPERVISOR]],
    'POST /sga/version/{id}/descartar'       => ['handler' => 'sga_documentos', 'roles' => [Policy::SUPERVISOR]],
    'POST /sga/version/{id}/conocimiento'    => ['handler' => 'sga_documentos', 'roles' => [Policy::SUPERVISOR]],
    'POST /sga/version/{id}/devolver'        => ['handler' => 'sga_documentos', 'roles' => [Policy::ADMIN]],
    'POST /sga/version/{id}/aprobar'         => ['handler' => 'sga_documentos', 'roles' => [Policy::ADMIN]],

    // --- Disposición de efluentes (F4.2, ISO 14001 §8.1) ---
    // Cargar la descarga con su manifiesto es de la coordinación del día;
    // anularla y dar de alta plantas habilitadas, de administración.
    'GET /ambiental'                  => ['handler' => 'ambiental', 'roles' => [Policy::SUPERVISOR],
                                          'limite' => ['usuario' => [240, 3600]]],
    'GET /ambiental/trazabilidad'     => ['handler' => 'ambiental', 'roles' => [Policy::SUPERVISOR],
                                          'limite' => ['usuario' => [240, 3600]]],
    'GET /ambiental/registro'         => ['handler' => 'ambiental', 'roles' => [Policy::SUPERVISOR],
                                          'limite' => ['usuario' => [120, 3600]]],
    'POST /ambiental/descarga'        => ['handler' => 'ambiental', 'roles' => [Policy::SUPERVISOR]],
    'POST /ambiental/descarga/anular' => ['handler' => 'ambiental', 'roles' => [Policy::ADMIN]],
    'POST /ambiental/planta'          => ['handler' => 'ambiental', 'roles' => [Policy::ADMIN]],

    // --- No conformidades y acciones correctivas (F4.3, ISO 14001 §10.2) ---
    // Abrir, analizar y trabajar las acciones, la coordinación; verificar la
    // eficacia (cerrar) y anular, la dirección.
    'GET /sga/nc'                        => ['handler' => 'no_conformidades', 'roles' => [Policy::SUPERVISOR]],
    'GET /sga/nc/{id}'                   => ['handler' => 'no_conformidades', 'roles' => [Policy::SUPERVISOR]],
    'POST /sga/nc'                       => ['handler' => 'no_conformidades', 'roles' => [Policy::SUPERVISOR]],
    'POST /sga/nc/{id}/causa'            => ['handler' => 'no_conformidades', 'roles' => [Policy::SUPERVISOR]],
    'POST /sga/nc/{id}/accion'           => ['handler' => 'no_conformidades', 'roles' => [Policy::SUPERVISOR]],
    'POST /sga/nc/{id}/verificacion'     => ['handler' => 'no_conformidades', 'roles' => [Policy::SUPERVISOR]],
    'POST /sga/nc/{id}/eficacia'         => ['handler' => 'no_conformidades', 'roles' => [Policy::ADMIN]],
    'POST /sga/nc/{id}/anular'           => ['handler' => 'no_conformidades', 'roles' => [Policy::ADMIN]],
    'GET /sga/nc/accion/{id}/archivo'    => ['handler' => 'no_conformidades', 'roles' => [Policy::SUPERVISOR]],
    'POST /sga/nc/accion/{id}/archivo'   => ['handler' => 'no_conformidades', 'roles' => [Policy::SUPERVISOR],
                                             'limite' => ['usuario' => [60, 3600]]],
    'POST /sga/nc/accion/{id}/cumplir'   => ['handler' => 'no_conformidades', 'roles' => [Policy::SUPERVISOR]],
    'POST /sga/nc/accion/{id}/descartar' => ['handler' => 'no_conformidades', 'roles' => [Policy::SUPERVISOR]],

    // --- Piloto de OCR de remitos en papel (F4.4) ---
    // Cada foto procesada cuesta dinero (la API de Claude): límite por usuario.
    'GET /ocr/remitos'                   => ['handler' => 'ocr', 'roles' => [Policy::SUPERVISOR]],
    'GET /ocr/remito/{id}'               => ['handler' => 'ocr', 'roles' => [Policy::SUPERVISOR]],
    'GET /ocr/remito/{id}/imagen'        => ['handler' => 'ocr', 'roles' => [Policy::SUPERVISOR]],
    'POST /ocr/remito'                   => ['handler' => 'ocr', 'roles' => [Policy::SUPERVISOR],
                                             'limite' => ['usuario' => [120, 3600]]],
    'POST /ocr/remito/{id}/reprocesar'   => ['handler' => 'ocr', 'roles' => [Policy::SUPERVISOR],
                                             'limite' => ['usuario' => [60, 3600]]],
    'POST /ocr/remito/{id}/validar'      => ['handler' => 'ocr', 'roles' => [Policy::SUPERVISOR]],
    'POST /ocr/remito/{id}/descartar'    => ['handler' => 'ocr', 'roles' => [Policy::SUPERVISOR]],

    // --- Usuarios y teléfonos (cierre, F4.6; deuda de la Fase 0) ---
    // Dar de alta y de baja, administración. Revocar un teléfono perdido, la
    // coordinación, en el acto. Cambiar la propia contraseña, cada uno.
    'GET /usuarios'              => ['handler' => 'usuarios', 'roles' => [Policy::SUPERVISOR]],
    'POST /usuario'              => ['handler' => 'usuarios', 'roles' => [Policy::ADMIN]],
    'POST /usuario/clave'        => ['handler' => 'usuarios', 'roles' => [Policy::ADMIN]],
    'POST /usuario/baja'         => ['handler' => 'usuarios', 'roles' => [Policy::ADMIN]],
    'POST /usuario/reactivar'    => ['handler' => 'usuarios', 'roles' => [Policy::ADMIN]],
    'POST /dispositivo/revocar'  => ['handler' => 'usuarios', 'roles' => [Policy::SUPERVISOR]],
    'POST /auth/clave/cambiar'   => ['handler' => 'usuarios', 'roles' => [Policy::SUPERVISOR, Policy::CLIENTE],
                                     'limite' => ['usuario' => [10, 3600]]],

    // --- Primera puesta en marcha (cierre, F4.6) ---
    // Sin sesión y sin CSRF porque todavía no hay base ni usuarios; lo protege
    // un token de un solo uso (sólo su sha256 viaja en el build), y en cuanto
    // existe camca_priv/config.php contesta 404 para siempre.
    'GET /instalar'  => ['handler' => 'instalar', 'roles' => [Policy::PUBLICO]],
    'POST /instalar' => ['handler' => 'instalar', 'roles' => [Policy::PUBLICO], 'csrf' => false],

    // --- Verificación pública (F2.4) ---
    // Sin login: la usa quien escanea el QR del papel. El límite por IP es el
    // backstop contra quien quiera recorrer códigos; con 50 bits al azar no
    // hay nada que recorrer, pero tampoco hay por qué dejarlo intentar.
    'GET /verificar/{codigo}' => ['handler' => 'verificar', 'roles' => [Policy::PUBLICO],
                                  'limite' => ['ip' => [120, 3600]]],
];
