-- Rastro de flota (Wialon). Segunda fuente de evidencia, INDEPENDIENTE del
-- telefono: es la red de seguridad si el celular se rompe con la jornada
-- adentro, y es la fuente autoritativa del arribo.

CREATE TABLE IF NOT EXISTS gps_unidad (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  wialon_id   BIGINT UNSIGNED NOT NULL,
  nombre      VARCHAR(120) NOT NULL,
  vehiculo_id INT UNSIGNED NULL,
  reporta_odo TINYINT(1)   NULL,
  activa      TINYINT(1)   NOT NULL DEFAULT 1,
  creado_utc  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wialon (wialon_id),
  CONSTRAINT fk_gpsu_vehiculo FOREIGN KEY (vehiculo_id) REFERENCES vehiculo(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- reporta_odo arranca en NULL = todavia no se sabe. El prototipo lo midio en
-- UNA unidad (29,5 km por odometro contra 32,2 por haversine: el haversine
-- infla por ruido). Si una unidad no reporta odo, sus km son ESTIMADOS y hay
-- que decirlo asi, no presentarlos como dato de odometro.

-- UNIQUE (unidad, ts) hace el poll idempotente: correrlo dos veces no agrega
-- filas, que es lo que permite reintentar sin pensar.
CREATE TABLE IF NOT EXISTS gps_posicion (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  unidad_id INT UNSIGNED NOT NULL,
  ts_utc    DATETIME     NOT NULL,
  lat       DECIMAL(10,7) NOT NULL,
  lon       DECIMAL(10,7) NOT NULL,
  velocidad SMALLINT UNSIGNED NULL,
  curso     SMALLINT UNSIGNED NULL,
  satelites TINYINT UNSIGNED NULL,
  odo_m     BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_unidad_ts (unidad_id, ts_utc),
  KEY idx_ts (ts_utc),
  CONSTRAINT fk_gpsp_unidad FOREIGN KEY (unidad_id) REFERENCES gps_unidad(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quien miro que camion. La posicion de un camion con un chofer adentro es
-- dato personal (Ley 25.326): el acceso se registra.
CREATE TABLE IF NOT EXISTS gps_acceso_log (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id INT UNSIGNED NOT NULL,
  unidad_id  INT UNSIGNED NULL,
  creado_utc DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_usuario (usuario_id, creado_utc)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Estado del enlace con Wialon, para que la app no le mienta al chofer
-- cuando el rastreo esta caido (token vencido = codigos 7 y 8).
CREATE TABLE IF NOT EXISTS gps_estado (
  id            TINYINT UNSIGNED NOT NULL DEFAULT 1,
  sid           VARCHAR(64)  NULL,
  sid_utc       DATETIME     NULL,
  ultimo_ok_utc DATETIME     NULL,
  ultimo_error  VARCHAR(255) NULL,
  backoff_hasta DATETIME     NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FIN
