-- Infraestructura: limites de tasa, latido de crons y cadena de auditoria.
-- ROW_FORMAT=DYNAMIC explicito en todas las tablas: si el default del
-- servidor fuera COMPACT, los indices sobre VARCHAR largos tiran error 1071.

CREATE TABLE IF NOT EXISTS rate_bucket (
  clave           VARCHAR(190) NOT NULL,
  ventana_inicio  DATETIME     NOT NULL,
  contador        INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (clave),
  KEY idx_ventana (ventana_inicio)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS heartbeat (
  tarea      VARCHAR(60)  NOT NULL,
  visto_utc  DATETIME     NOT NULL,
  detalle    VARCHAR(255) NULL,
  PRIMARY KEY (tarea)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cadena de auditoria. hash_prev/hash la encadenan: alterar una fila vieja
-- invalida todas las posteriores, y el hash cabeza del dia se ancla fuera
-- del servidor. firma_alg queda en 'ninguna' durante toda la Fase 0 — la
-- firma asimetrica verificable por terceros es Fase 1 / Objetivo 2, y hasta
-- entonces NADA de esto se llama "certificado".
CREATE TABLE IF NOT EXISTS auditoria (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entidad     VARCHAR(60)  NOT NULL,
  entidad_id  BIGINT UNSIGNED NULL,
  accion      VARCHAR(60)  NOT NULL,
  usuario_id  INT UNSIGNED NULL,
  contenido   LONGTEXT     NOT NULL,
  hash_prev   CHAR(64)     NULL,
  hash        CHAR(64)     NOT NULL,
  firma_alg   VARCHAR(20)  NOT NULL DEFAULT 'ninguna',
  firma       TEXT         NULL,
  creado_utc  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hash (hash),
  KEY idx_entidad (entidad, entidad_id),
  KEY idx_creado (creado_utc)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FIN
