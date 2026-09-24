-- La jornada: lo EJECUTADO contra lo planificado. Nucleo del Objetivo 1.
--
-- La jornada la MATERIALIZA UN CRON la noche anterior, expandiendo la
-- plantilla del dia. Sin eso no existe la fila planificada contra la cual
-- medir el desvio, y si el telefono del chofer se rompe antes de sincronizar
-- el supervisor ve un dia vacio, indistinguible de "el chofer no salio".

CREATE TABLE IF NOT EXISTS jornada (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  fecha         DATE         NOT NULL,
  ruta_id       INT UNSIGNED NOT NULL,
  chofer_id     INT UNSIGNED NULL,
  vehiculo_id   INT UNSIGNED NULL,
  estado        ENUM('planificada','en_curso','cerrada','cerrada_confirmada') NOT NULL DEFAULT 'planificada',
  abierta_utc   DATETIME     NULL,
  cerrada_utc   DATETIME     NULL,
  km_odo        DECIMAL(7,1) NULL,
  km_hav        DECIMAL(7,1) NULL,
  km_fuente     ENUM('odometro','haversine','ninguna') NOT NULL DEFAULT 'ninguna',
  r23_numero    VARCHAR(30)  NULL,
  r28_numero    VARCHAR(30)  NULL,
  creado_utc    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fecha_ruta (fecha, ruta_id),
  KEY idx_chofer (chofer_id, fecha),
  KEY idx_estado (estado, fecha),
  CONSTRAINT fk_jor_ruta     FOREIGN KEY (ruta_id)     REFERENCES ruta_plantilla(id),
  CONSTRAINT fk_jor_chofer   FOREIGN KEY (chofer_id)   REFERENCES usuario(id),
  CONSTRAINT fk_jor_vehiculo FOREIGN KEY (vehiculo_id) REFERENCES vehiculo(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS parada_ejecucion (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  jornada_id      BIGINT UNSIGNED NOT NULL,
  sitio_id        INT UNSIGNED NOT NULL,
  orden           SMALLINT UNSIGNED NOT NULL,
  cantidad_plan   SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  cantidad_real   SMALLINT UNSIGNED NULL,
  estado          ENUM('planificada','ejecutada','no_ejecutada') NOT NULL DEFAULT 'planificada',
  motivo          VARCHAR(255) NULL,
  arribo_utc      DATETIME     NULL,
  cierre_utc      DATETIME     NULL,
  origen          ENUM('flota','telefono','manual','ninguno') NOT NULL DEFAULT 'ninguno',
  evidencia_doble TINYINT(1)   NOT NULL DEFAULT 0,
  precision_m     SMALLINT UNSIGNED NULL,
  edad_fix_seg    SMALLINT UNSIGNED NULL,
  ambiguo         TINYINT(1)   NOT NULL DEFAULT 0,
  cluster_id      INT UNSIGNED NULL,
  bloqueada       TINYINT(1)   NOT NULL DEFAULT 0,
  actualizado_utc DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_jornada_orden (jornada_id, orden),
  KEY idx_sitio (sitio_id),
  KEY idx_estado (jornada_id, estado),
  CONSTRAINT fk_pe_jornada FOREIGN KEY (jornada_id) REFERENCES jornada(id),
  CONSTRAINT fk_pe_sitio   FOREIGN KEY (sitio_id)   REFERENCES sitio(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NOTA DE DISEÑO sobre la columna `origen`, que sostiene toda la evidencia:
-- el arribo autoritativo sale del rastro de FLOTA (corre en el servidor, no
-- depende de la pantalla ni de la bateria del telefono). El dwell del
-- telefono CORROBORA, y solo es fuente cuando la flota no cubre esa unidad.
-- El prototipo hacia lo contrario y por eso fallaba con el celular en el
-- bolsillo: en iOS el JS se suspende en segundo plano y en Android
-- watchPosition deja de entregar fixes con la pantalla apagada.

-- FIN
