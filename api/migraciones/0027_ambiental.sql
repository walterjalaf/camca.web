-- Control operacional ambiental: disposición de efluentes de baños químicos.
-- Obj. 5, paso F4.2.
--
-- El circuito real: el camión desagota los baños de la ruta y lleva lo
-- recolectado a una planta de tratamiento habilitada, que da un manifiesto
-- (el comprobante de la descarga). Lo que ISO 14001 §8.1 pide es poder
-- mostrar, para cada servicio, a dónde fue a parar lo que se sacó.
--
--  1. LA PLANTA TIENE QUE ESTAR HABILITADA EL DÍA DE LA DESCARGA. Se guarda el
--     número de habilitación y su vencimiento; una descarga en una planta
--     vencida se rechaza (Ambiental::registrar), no se "anota con aviso".
--
--  2. UN MANIFIESTO SE USA UNA SOLA VEZ POR PLANTA. `manifiesto_clave` es
--     "planta:manifiesto" mientras la descarga está vigente y NULL si se anula:
--     la clave única la sostiene la base, y anular no borra la historia.
--
--  3. LA DESCARGA SE ATA A LAS JORNADAS QUE VACÍA. Una descarga puede cubrir
--     varias jornadas (dos rutas cortas en un viaje) y una jornada puede
--     repartirse en dos descargas (el camión se llenó a media ruta).

CREATE TABLE IF NOT EXISTS amb_planta (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre             VARCHAR(120) NOT NULL,
  operador           VARCHAR(120) NULL,
  habilitacion       VARCHAR(60)  NOT NULL,
  habilitacion_vence DATE         NULL,
  direccion          VARCHAR(200) NULL,
  activa             TINYINT(1)   NOT NULL DEFAULT 1,
  creado_por         INT UNSIGNED NULL,
  creado_utc         DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_nombre (nombre)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS amb_disposicion (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  fecha             DATE         NOT NULL,
  planta_id         INT UNSIGNED NOT NULL,
  vehiculo_id       INT UNSIGNED NULL,
  litros            INT UNSIGNED NOT NULL,
  manifiesto        VARCHAR(60)  NOT NULL,
  manifiesto_clave  VARCHAR(80)  NULL,
  observaciones     VARCHAR(255) NULL,
  estado            ENUM('vigente','anulada') NOT NULL DEFAULT 'vigente',
  anulada_motivo    VARCHAR(255) NULL,
  anulada_utc       DATETIME     NULL,
  registrada_por    INT UNSIGNED NULL,
  creado_utc        DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_manifiesto (manifiesto_clave),
  KEY idx_fecha (fecha),
  CONSTRAINT fk_ambd_planta   FOREIGN KEY (planta_id)   REFERENCES amb_planta(id),
  CONSTRAINT fk_ambd_vehiculo FOREIGN KEY (vehiculo_id) REFERENCES vehiculo(id),
  CONSTRAINT ck_ambd_litros   CHECK (litros BETWEEN 1 AND 60000),
  CONSTRAINT ck_ambd_clave    CHECK ((estado = 'vigente') = (manifiesto_clave IS NOT NULL))
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS amb_disposicion_jornada (
  disposicion_id  INT UNSIGNED    NOT NULL,
  jornada_id      BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (disposicion_id, jornada_id),
  KEY idx_jornada (jornada_id),
  CONSTRAINT fk_ambdj_disp    FOREIGN KEY (disposicion_id) REFERENCES amb_disposicion(id),
  CONSTRAINT fk_ambdj_jornada FOREIGN KEY (jornada_id)     REFERENCES jornada(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FIN
