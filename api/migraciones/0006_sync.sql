-- Cola de sincronizacion y evidencias.
--
-- Idempotencia DOBLE:
--   uuid             = clave tecnica; el mismo evento reenviado no duplica
--   clave de negocio = (jornada, orden de parada, tipo), por si se pierde el uuid
--
-- Se clavea por ORDEN DE PARADA y no por sitio_id a proposito: un mismo sitio
-- puede servirse dos veces en la misma jornada (pasa con los lotes que
-- comparten centroide de barrio), y con sitio_id la segunda chocaria contra
-- el UNIQUE y se descartaria como duplicado. El orden es unico dentro de la
-- jornada y no tiene ese problema.
-- El mismo lote enviado tres veces tiene que producir UNA fila.

CREATE TABLE IF NOT EXISTS evento_sync (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid           CHAR(36)     NOT NULL,
  jornada_id     BIGINT UNSIGNED NULL,
  sitio_id       INT UNSIGNED NULL,
  parada_orden   SMALLINT UNSIGNED NULL,
  dispositivo_id INT UNSIGNED NULL,
  tipo           VARCHAR(40)  NOT NULL,
  payload        LONGTEXT     NOT NULL,
  ocurrido_utc   DATETIME     NOT NULL,
  recibido_utc   DATETIME     NOT NULL,
  delta_reloj_ms INT          NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_uuid (uuid),
  UNIQUE KEY uq_negocio (jornada_id, parada_orden, tipo),
  KEY idx_jornada (jornada_id),
  KEY idx_recibido (recibido_utc)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- delta_reloj_ms guarda el desfase del reloj del telefono respecto del
-- servidor. Un telefono con la hora corrida hace que las horas de arribo
-- mientan, y eso tiene que poder verse en vez de descubrirse en una auditoria.

-- Los binarios NUNCA viven en public_html: el sync FTP borra en destino lo
-- que no esta en dist/, y ademas serian descargables por URL. Van a
-- camca_priv/evidencias/AAAA/MM/ y los sirve un endpoint PHP con sesion.
CREATE TABLE IF NOT EXISTS evidencia (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid                   CHAR(36)     NOT NULL,
  parada_id              BIGINT UNSIGNED NULL,
  jornada_id             BIGINT UNSIGNED NULL,
  tipo                   ENUM('foto','firma','nota') NOT NULL,
  ruta_relativa          VARCHAR(255) NULL,
  sha256                 CHAR(64)     NULL,
  bytes                  INT UNSIGNED NOT NULL DEFAULT 0,
  bytes_esperados        INT UNSIGNED NULL,
  mime                   VARCHAR(60)  NULL,
  offset_bytes           INT UNSIGNED NOT NULL DEFAULT 0,
  completa               TINYINT(1)   NOT NULL DEFAULT 0,
  externo_confirmado_utc DATETIME     NULL,
  origen_borrado_utc     DATETIME     NULL,
  creado_utc             DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_uuid (uuid),
  KEY idx_parada (parada_id),
  KEY idx_jornada (jornada_id, tipo),
  KEY idx_externo (externo_confirmado_utc)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- offset_bytes: la subida por trozos es reanudable y el offset autoritativo
-- lo dice el SERVIDOR en toda respuesta. Si lo decidiera el cliente, un
-- reintento a mitad de trozo duplica bytes y el sha256 final no cierra.
--
-- externo_confirmado_utc: hasta que el backup cifrado fuera del proveedor
-- no confirme que se llevo esta evidencia, NO se puede archivar ni borrar.

-- FIN
