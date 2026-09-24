-- Plantilla de ruta semanal: lo PLANIFICADO. Es el Registro 23 en embrion.
-- El numero formal de R23 y su emision son Fase 1; aca queda la estructura.

CREATE TABLE IF NOT EXISTS ruta_plantilla (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  dia_semana  TINYINT UNSIGNED NOT NULL,   -- 1=lunes .. 6=sabado (ISO)
  nombre      VARCHAR(80)  NOT NULL,
  color       CHAR(7)      NOT NULL,
  km_estimado DECIMAL(6,1) NOT NULL DEFAULT 0,
  activa      TINYINT(1)   NOT NULL DEFAULT 1,
  creado_utc  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dia (dia_semana)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS parada_plantilla (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ruta_id       INT UNSIGNED NOT NULL,
  orden         SMALLINT UNSIGNED NOT NULL,
  sitio_id      INT UNSIGNED NOT NULL,
  cantidad      SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  responsable   VARCHAR(120) NULL,
  activa        TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ruta_orden (ruta_id, orden),
  KEY idx_sitio (sitio_id),
  CONSTRAINT fk_pp_ruta  FOREIGN KEY (ruta_id)  REFERENCES ruta_plantilla(id),
  CONSTRAINT fk_pp_sitio FOREIGN KEY (sitio_id) REFERENCES sitio(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La base operativa. Sale del objeto `base` de los prototipos.
CREATE TABLE IF NOT EXISTS base_operativa (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre     VARCHAR(120) NOT NULL,
  direccion  VARCHAR(255) NULL,
  lat        DECIMAL(10,7) NOT NULL,
  lon        DECIMAL(10,7) NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FIN
