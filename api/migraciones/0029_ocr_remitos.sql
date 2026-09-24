-- Piloto de OCR de remitos en papel. Obj. 5, paso F4.4.
--
-- Foto → extracción con la API de Claude del lado del servidor → BORRADOR →
-- validación humana obligatoria → registro. La regla que ordena todo:
--
--   NADA SE AUTO-CONFIRMA. La extracción sólo puede dejar un borrador o un
--   error. Un remito en papel entra al registro (`remito_papel`) únicamente
--   por la validación de una persona con sesión, que ve la foto al lado y
--   confirma o corrige cada campo. La base lo sostiene: `remito_papel` exige
--   quién lo validó, y un `ocr_remito` validado exige su registro.
--
-- Se guarda lo que el piloto tiene que medir: qué devolvió el modelo, qué
-- corrigió la persona (campo por campo), cuántos tokens usó y cuánto costó.

CREATE TABLE IF NOT EXISTS ocr_remito (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  archivo_sha256     CHAR(64)     NOT NULL,
  archivo_mime       VARCHAR(20)  NOT NULL,
  archivo_bytes      INT UNSIGNED NOT NULL,
  archivo_nombre     VARCHAR(160) NULL,
  estado             ENUM('pendiente','borrador','error','validado','descartado') NOT NULL DEFAULT 'pendiente',
  intentos           TINYINT UNSIGNED NOT NULL DEFAULT 0,
  modelo             VARCHAR(60)  NULL,
  tokens_entrada     INT UNSIGNED NULL,
  tokens_salida      INT UNSIGNED NULL,
  -- Millonésimas de dólar: un remito cuesta centavos, y el piloto suma cientos.
  costo_usd_micros   INT UNSIGNED NULL,
  duracion_ms        INT UNSIGNED NULL,
  extraido_json      TEXT         NULL,
  error_texto        VARCHAR(500) NULL,
  corregidos_json    TEXT         NULL,
  descartado_motivo  VARCHAR(255) NULL,
  subido_por         INT UNSIGNED NULL,
  subido_utc         DATETIME     NOT NULL,
  validado_por       INT UNSIGNED NULL,
  validado_utc       DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_estado (estado, subido_utc),
  KEY idx_sha (archivo_sha256),
  CONSTRAINT fk_ocr_subio  FOREIGN KEY (subido_por)   REFERENCES usuario(id),
  CONSTRAINT fk_ocr_valido FOREIGN KEY (validado_por) REFERENCES usuario(id),
  CONSTRAINT ck_ocr_humano CHECK (estado <> 'validado' OR (validado_por IS NOT NULL AND validado_utc IS NOT NULL))
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- El registro: un remito en papel ya validado por una persona.
CREATE TABLE IF NOT EXISTS remito_papel (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ocr_id          INT UNSIGNED NOT NULL,
  numero_papel    VARCHAR(30)  NULL,
  fecha           DATE         NOT NULL,
  cliente         VARCHAR(160) NOT NULL,
  cliente_id      INT UNSIGNED NULL,
  sitio           VARCHAR(200) NULL,
  servicio        VARCHAR(40)  NOT NULL,
  cantidad        SMALLINT UNSIGNED NOT NULL,
  receptor        VARCHAR(120) NULL,
  firmado         TINYINT(1)   NOT NULL,
  observaciones   VARCHAR(500) NULL,
  validado_por    INT UNSIGNED NOT NULL,
  creado_utc      DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ocr (ocr_id),
  KEY idx_fecha (fecha),
  CONSTRAINT fk_rp_ocr     FOREIGN KEY (ocr_id)       REFERENCES ocr_remito(id),
  CONSTRAINT fk_rp_cliente FOREIGN KEY (cliente_id)   REFERENCES cliente(id),
  CONSTRAINT fk_rp_valido  FOREIGN KEY (validado_por) REFERENCES usuario(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FIN
