-- Asignacion y traslados. Obj. 4, paso F3.7.
--
-- UNA CUADRILLA, UN VEHICULO Y UNA PERSONA TRABAJAN EN UN SOLO LUGAR POR DIA.
-- Las tres cosas las sostiene la base con claves unicas "que se apagan": la
-- asignacion viva lleva "cuadrilla:fecha" y "vehiculo:fecha"; cancelarla las
-- vuelve NULL y libera el dia. Las personas se copian a asignacion_persona al
-- planificar, con su propia clave "persona:fecha": si alguien cambia de
-- cuadrilla despues, no puede terminar asignado dos veces el mismo dia.
--
-- UNA CAMPANA (Los Azules: cuatro dias en altura) es un paquete de
-- asignaciones que se crean todas o ninguna, mas los equipos que se llevan,
-- reservados por el periodo: un bano no puede estar reservado para dos
-- campanas que se pisan.

CREATE TABLE IF NOT EXISTS campana (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre        VARCHAR(120) NOT NULL,
  cliente_id    INT UNSIGNED NULL,
  sitio_id      INT UNSIGNED NULL,
  desde         DATE         NOT NULL,
  hasta         DATE         NOT NULL,
  cuadrilla_id  INT UNSIGNED NOT NULL,
  vehiculo_id   INT UNSIGNED NULL,
  estado        ENUM('planificada','cancelada') NOT NULL DEFAULT 'planificada',
  nota          VARCHAR(255) NULL,
  cancelada_motivo VARCHAR(255) NULL,
  creado_por    INT UNSIGNED NULL,
  creado_utc    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_fechas (desde, hasta),
  CONSTRAINT fk_camp_cliente   FOREIGN KEY (cliente_id)   REFERENCES cliente(id),
  CONSTRAINT fk_camp_sitio     FOREIGN KEY (sitio_id)     REFERENCES sitio(id),
  CONSTRAINT fk_camp_cuadrilla FOREIGN KEY (cuadrilla_id) REFERENCES cuadrilla(id),
  CONSTRAINT fk_camp_vehiculo  FOREIGN KEY (vehiculo_id)  REFERENCES vehiculo(id),
  CONSTRAINT ck_camp_fechas    CHECK (hasta >= desde)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS asignacion (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  fecha           DATE         NOT NULL,
  cuadrilla_id    INT UNSIGNED NOT NULL,
  vehiculo_id     INT UNSIGNED NULL,
  jornada_id      BIGINT UNSIGNED NULL,
  campana_id      INT UNSIGNED NULL,
  estado          ENUM('planificada','cancelada') NOT NULL DEFAULT 'planificada',
  nota            VARCHAR(255) NULL,
  cuadrilla_dia   VARCHAR(40)  NULL,
  vehiculo_dia    VARCHAR(40)  NULL,
  jornada_viva    BIGINT UNSIGNED NULL,
  cancelada_motivo VARCHAR(255) NULL,
  creado_por      INT UNSIGNED NULL,
  creado_utc      DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cuadrilla_dia (cuadrilla_dia),
  UNIQUE KEY uq_vehiculo_dia (vehiculo_dia),
  UNIQUE KEY uq_jornada_viva (jornada_viva),
  KEY idx_fecha (fecha),
  KEY idx_campana (campana_id),
  CONSTRAINT fk_asig_cuadrilla FOREIGN KEY (cuadrilla_id) REFERENCES cuadrilla(id),
  CONSTRAINT fk_asig_vehiculo  FOREIGN KEY (vehiculo_id)  REFERENCES vehiculo(id),
  CONSTRAINT fk_asig_jornada   FOREIGN KEY (jornada_id)   REFERENCES jornada(id),
  CONSTRAINT fk_asig_campana   FOREIGN KEY (campana_id)   REFERENCES campana(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS asignacion_persona (
  asignacion_id  BIGINT UNSIGNED NOT NULL,
  persona_id     INT UNSIGNED NOT NULL,
  fecha          DATE         NOT NULL,
  persona_dia    VARCHAR(40)  NULL,
  PRIMARY KEY (asignacion_id, persona_id),
  UNIQUE KEY uq_persona_dia (persona_dia),
  CONSTRAINT fk_ap_asig    FOREIGN KEY (asignacion_id) REFERENCES asignacion(id),
  CONSTRAINT fk_ap_persona FOREIGN KEY (persona_id)    REFERENCES persona(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campana_activo (
  campana_id  INT UNSIGNED NOT NULL,
  activo_id   INT UNSIGNED NOT NULL,
  PRIMARY KEY (campana_id, activo_id),
  KEY idx_activo (activo_id),
  CONSTRAINT fk_ca_campana FOREIGN KEY (campana_id) REFERENCES campana(id),
  CONSTRAINT fk_ca_activo  FOREIGN KEY (activo_id)  REFERENCES activo(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FIN
