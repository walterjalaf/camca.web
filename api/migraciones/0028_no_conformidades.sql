-- No conformidades y acciones correctivas (ISO 14001 §10.2). Obj. 5, paso F4.3.
--
-- El ciclo:
--
--   abierta ──(análisis de causa)──▶ en_tratamiento ──(acciones cumplidas)──▶ en_verificacion
--                                        ▲                                          │
--                                        └──────────── no fue eficaz ───────────────┤
--                                                                                    └── eficaz ──▶ cerrada
--
--  1. NUMERACIÓN FORMAL, SIN HUECOS: SGA-NC-000001 por el mismo numerador que
--     los R28 y los remitos (F1.4). Una anulada conserva su número.
--
--  2. NO HAY ACCIÓN CUMPLIDA SIN EVIDENCIA. La base lo sostiene con un CHECK:
--     una acción no pasa a «cumplida» sin el texto de qué se hizo (y, si se
--     quiere, el archivo que lo prueba, guardado por su huella).
--
--  3. NO SE CIERRA SIN VERIFICAR LA EFICACIA. Cerrar exige decir cómo se
--     comprobó que la causa no volvió a aparecer; si no fue eficaz, vuelve a
--     tratamiento y pide otra acción correctiva.

CREATE TABLE IF NOT EXISTS sga_nc (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  numero_seq       INT UNSIGNED NOT NULL,
  numero           VARCHAR(30)  NOT NULL,
  origen           ENUM('ambiental','desvio','auditoria','reclamo','documento','otro') NOT NULL,
  origen_ref       VARCHAR(80)  NULL,
  titulo           VARCHAR(160) NOT NULL,
  descripcion      VARCHAR(2000) NOT NULL,
  gravedad         ENUM('menor','mayor') NOT NULL DEFAULT 'menor',
  detectada_fecha  DATE         NOT NULL,
  detectada_por    INT UNSIGNED NULL,
  responsable_id   INT UNSIGNED NULL,
  estado           ENUM('abierta','en_tratamiento','en_verificacion','cerrada','anulada') NOT NULL DEFAULT 'abierta',
  causa            VARCHAR(2000) NULL,
  verificacion     VARCHAR(2000) NULL,
  eficaz           TINYINT(1)   NULL,
  cerrada_por      INT UNSIGNED NULL,
  cerrada_utc      DATETIME     NULL,
  anulada_motivo   VARCHAR(255) NULL,
  creado_utc       DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_numero (numero),
  UNIQUE KEY uq_seq (numero_seq),
  KEY idx_estado (estado),
  CONSTRAINT fk_nc_detecta FOREIGN KEY (detectada_por)  REFERENCES usuario(id),
  CONSTRAINT fk_nc_resp    FOREIGN KEY (responsable_id) REFERENCES usuario(id),
  CONSTRAINT fk_nc_cierra  FOREIGN KEY (cerrada_por)    REFERENCES usuario(id),
  CONSTRAINT ck_nc_cierre  CHECK (estado <> 'cerrada' OR (verificacion IS NOT NULL AND eficaz = 1 AND causa IS NOT NULL))
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sga_nc_accion (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nc_id              INT UNSIGNED NOT NULL,
  tipo               ENUM('correccion','correctiva') NOT NULL,
  descripcion        VARCHAR(1000) NOT NULL,
  responsable_id     INT UNSIGNED NULL,
  vence              DATE         NOT NULL,
  estado             ENUM('pendiente','cumplida','descartada') NOT NULL DEFAULT 'pendiente',
  evidencia          VARCHAR(2000) NULL,
  evidencia_sha256   CHAR(64)     NULL,
  evidencia_nombre   VARCHAR(160) NULL,
  evidencia_mime     VARCHAR(40)  NULL,
  evidencia_bytes    INT UNSIGNED NULL,
  cumplida_por       INT UNSIGNED NULL,
  cumplida_utc       DATETIME     NULL,
  descartada_motivo  VARCHAR(255) NULL,
  -- Una correctiva cumplida cuya verificación dio «no eficaz» queda como
  -- historia, marcada, y ya no alcanza para volver a verificar.
  sin_efecto         TINYINT(1)   NOT NULL DEFAULT 0,
  creado_por         INT UNSIGNED NULL,
  creado_utc         DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_nc (nc_id, estado),
  KEY idx_vence (estado, vence),
  CONSTRAINT fk_nca_nc   FOREIGN KEY (nc_id)          REFERENCES sga_nc(id),
  CONSTRAINT fk_nca_resp FOREIGN KEY (responsable_id) REFERENCES usuario(id),
  CONSTRAINT ck_nca_evid CHECK (estado <> 'cumplida' OR (evidencia IS NOT NULL AND cumplida_utc IS NOT NULL))
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FIN
