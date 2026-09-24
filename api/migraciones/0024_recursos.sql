-- Cuadrillas, personal y equipos. Obj. 4, paso F3.6.
--
-- DOS REGLAS QUE LA BASE SOSTIENE SOLA:
--
--  1. UN ACTIVO ESTA EN UN SOLO LUGAR. activo_ubicacion guarda la historia
--     (donde estuvo y desde cuando); la ubicacion abierta repite el activo_id
--     en `abierta`, y el UNIQUE sobre esa columna hace imposible que un bano
--     figure a la vez en dos obras, aunque dos personas lo muevan en el mismo
--     instante. El que factura por unidad necesita esto: un bano en dos
--     sitios se cobra dos veces.
--
--  2. UN DOCUMENTO VIGENTE POR TIPO. vencimiento guarda cada licencia, apto
--     medico, VTV o seguro que se cargo; el vigente lleva su clave en
--     `vigente` (UNIQUE) y al renovarlo el anterior la pierde. La historia no
--     se borra: un apto medico vencido el dia de un accidente es un dato.

ALTER TABLE activo
  ADD COLUMN estado ENUM('disponible','instalado','mantenimiento','baja') NOT NULL DEFAULT 'disponible' AFTER identificador,
  ADD COLUMN base_id INT UNSIGNED NULL AFTER sitio_id,
  ADD COLUMN nota VARCHAR(255) NULL AFTER base_id,
  ADD COLUMN actualizado_utc DATETIME NULL AFTER creado_utc;

ALTER TABLE activo
  ADD CONSTRAINT fk_activo_base FOREIGN KEY (base_id) REFERENCES base_operativa(id);

CREATE TABLE IF NOT EXISTS activo_ubicacion (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  activo_id   INT UNSIGNED NOT NULL,
  -- Exactamente uno de los dos: en un sitio de cliente o en una base propia.
  sitio_id    INT UNSIGNED NULL,
  base_id     INT UNSIGNED NULL,
  desde_utc   DATETIME     NOT NULL,
  hasta_utc   DATETIME     NULL,
  -- = activo_id mientras hasta_utc es NULL; NULL cuando se cierra.
  abierta     INT UNSIGNED NULL,
  motivo      VARCHAR(255) NULL,
  usuario_id  INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_abierta (abierta),
  KEY idx_activo (activo_id, desde_utc),
  KEY idx_sitio (sitio_id),
  CONSTRAINT fk_au_activo FOREIGN KEY (activo_id) REFERENCES activo(id),
  CONSTRAINT fk_au_sitio  FOREIGN KEY (sitio_id)  REFERENCES sitio(id),
  CONSTRAINT fk_au_base   FOREIGN KEY (base_id)   REFERENCES base_operativa(id),
  CONSTRAINT ck_au_lugar  CHECK ((sitio_id IS NULL) <> (base_id IS NULL)),
  CONSTRAINT ck_au_abierta CHECK ((hasta_utc IS NULL) = (abierta IS NOT NULL)),
  CONSTRAINT ck_au_orden  CHECK (hasta_utc IS NULL OR hasta_utc >= desde_utc)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Personal de la operacion. No todos usan la app (un ayudante no tiene
-- telefono enrolado); el que la usa se vincula con su usuario.
CREATE TABLE IF NOT EXISTS persona (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre        VARCHAR(120) NOT NULL,
  dni           VARCHAR(12)  NOT NULL,
  legajo        VARCHAR(30)  NULL,
  rol_operativo ENUM('chofer','ayudante','supervisor','mecanico','otro') NOT NULL,
  usuario_id    INT UNSIGNED NULL,
  activa        TINYINT(1)   NOT NULL DEFAULT 1,
  creado_utc    DATETIME     NOT NULL,
  actualizado_utc DATETIME   NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dni (dni),
  UNIQUE KEY uq_legajo (legajo),
  UNIQUE KEY uq_usuario (usuario_id),
  CONSTRAINT fk_per_usuario FOREIGN KEY (usuario_id) REFERENCES usuario(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vencimiento (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entidad       ENUM('persona','vehiculo') NOT NULL,
  entidad_id    INT UNSIGNED NOT NULL,
  tipo          VARCHAR(30)  NOT NULL,
  vence         DATE         NOT NULL,
  documento     VARCHAR(120) NULL,
  -- "persona:12:licencia" mientras es el vigente; NULL cuando se renueva.
  vigente       VARCHAR(80)  NULL,
  cargado_por   INT UNSIGNED NULL,
  creado_utc    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_vigente (vigente),
  KEY idx_entidad (entidad, entidad_id, tipo),
  KEY idx_vence (vence)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cuadrilla (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre      VARCHAR(80)  NOT NULL,
  activa      TINYINT(1)   NOT NULL DEFAULT 1,
  creado_utc  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_nombre (nombre)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Una persona esta en una sola cuadrilla a la vez (mismo mecanismo).
CREATE TABLE IF NOT EXISTS cuadrilla_miembro (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cuadrilla_id  INT UNSIGNED NOT NULL,
  persona_id    INT UNSIGNED NOT NULL,
  desde_utc     DATETIME     NOT NULL,
  hasta_utc     DATETIME     NULL,
  abierta       INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_miembro_abierto (abierta),
  KEY idx_cuadrilla (cuadrilla_id),
  CONSTRAINT fk_cm_cuadrilla FOREIGN KEY (cuadrilla_id) REFERENCES cuadrilla(id),
  CONSTRAINT fk_cm_persona   FOREIGN KEY (persona_id)   REFERENCES persona(id),
  CONSTRAINT ck_cm_abierta   CHECK ((hasta_utc IS NULL) = (abierta IS NOT NULL))
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FIN
