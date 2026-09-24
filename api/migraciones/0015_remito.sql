-- Remito digital. Obj. 2, paso F2.1.
--
-- QUE ES UN REMITO ACA, Y QUE NO ES:
--
-- Es la constancia de lo que se le presto al cliente en UNA visita: que
-- servicio, cuantos banios, en que sitio, a que hora, con que evidencia y —desde
-- F2.2— quien lo recibio y si estuvo conforme. Es el papel que el cliente
-- firma y el que despues respalda la factura.
--
-- NO es un remito fiscal de traslado de bienes (RG 1415 / COT): no lleva CAI y
-- no ampara mercaderia en transito. Eso es el supuesto S9.
--
-- DE DONDE SALE:
--
-- Del R28 CONGELADO de la parada, no de las tablas vivas. Es la unica manera
-- de que el remito y el registro de ejecucion no puedan decir cosas distintas:
-- si el remito leyera parada_ejecucion y alguien corrigiera la cantidad entre
-- la emision de uno y otro, el cliente tendria en la mano dos papeles de CAMCA
-- con dos numeros distintos para el mismo trabajo.
--
-- Y solo de trabajo VERIFICADO: la oficina tiene que haber mirado la evidencia
-- antes de que salga un documento que el cliente firma.

CREATE TABLE IF NOT EXISTS remito (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  numero          VARCHAR(30)  NOT NULL,
  numero_seq      INT UNSIGNED NOT NULL,
  serie           VARCHAR(10)  NOT NULL DEFAULT 'A',
  estado          ENUM('emitido','anulado') NOT NULL DEFAULT 'emitido',
  -- Lo que dijo el cliente. Se congela al emitir, como todo lo demas: una
  -- conformidad que cambia despues de emitido el remito no es una conformidad.
  conformidad     ENUM('pendiente','conforme','rechazado') NOT NULL DEFAULT 'pendiente',

  parada_id       BIGINT UNSIGNED NOT NULL,
  jornada_id      BIGINT UNSIGNED NOT NULL,
  cliente_id      INT UNSIGNED NULL,
  sitio_id        INT UNSIGNED NOT NULL,
  -- El R28 del que se derivo. Trazabilidad, no fuente: el contenido esta en
  -- datos_json y el numero del R28 viaja adentro.
  registro_id     BIGINT UNSIGNED NOT NULL,
  fecha           DATE         NOT NULL,

  datos_json      MEDIUMTEXT   NOT NULL,
  datos_sha       CHAR(64)     NOT NULL,
  emisor_json     TEXT         NOT NULL,

  emitido_utc     DATETIME     NOT NULL,
  emitido_por     INT UNSIGNED NULL,
  anulado_utc     DATETIME     NULL,
  anulado_por     INT UNSIGNED NULL,
  anulado_motivo  VARCHAR(255) NULL,
  reemplaza_a     BIGINT UNSIGNED NULL,

  -- Un solo remito vivo por parada. Mismo mecanismo que registro.unico_activo:
  -- PHP lo pone al emitir y lo vuelve a NULL al anular.
  unico_activo    VARCHAR(48)  NULL,
  creado_utc      DATETIME     NOT NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_numero (serie, numero_seq),
  UNIQUE KEY uq_numero_txt (numero),
  UNIQUE KEY uq_activo (unico_activo),
  KEY idx_parada (parada_id),
  KEY idx_jornada (jornada_id),
  KEY idx_cliente_fecha (cliente_id, fecha),
  CONSTRAINT fk_rem_parada   FOREIGN KEY (parada_id)   REFERENCES parada_ejecucion(id),
  CONSTRAINT fk_rem_jornada  FOREIGN KEY (jornada_id)  REFERENCES jornada(id),
  CONSTRAINT fk_rem_cliente  FOREIGN KEY (cliente_id)  REFERENCES cliente(id),
  CONSTRAINT fk_rem_sitio    FOREIGN KEY (sitio_id)    REFERENCES sitio(id),
  CONSTRAINT fk_rem_registro FOREIGN KEY (registro_id) REFERENCES registro(id),
  CONSTRAINT fk_rem_emisor   FOREIGN KEY (emitido_por) REFERENCES usuario(id),
  CONSTRAINT fk_rem_anula    FOREIGN KEY (anulado_por) REFERENCES usuario(id),
  CONSTRAINT fk_rem_reempl   FOREIGN KEY (reemplaza_a) REFERENCES remito(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La serie se crea aca y no en la primera emision, por la misma razon que en
-- 0013: crear la fila adentro de la transaccion caliente es el camino al
-- deadlock que ya aparecio una vez.
INSERT INTO numerador (tipo, serie, ultimo, actualizado_utc)
VALUES ('REM', 'A', 0, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE tipo = tipo;

-- FIN
