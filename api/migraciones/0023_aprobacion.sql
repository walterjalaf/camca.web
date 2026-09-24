-- Aprobacion, cierre y ajustes de la facturacion. Obj. 3, paso F3.4.
--
-- UNA PROPUESTA APROBADA NO SE TOCA MAS. Toma numero propio (PF-A-000001),
-- sin huecos, del mismo Numerador que el R28 y el remito, y guarda la huella
-- SHA-256 de su contenido canonico. La API rechaza toda modificacion (y deja
-- constancia del intento en la auditoria); si alguien la altera por la base,
-- la huella deja de coincidir y el verificador lo dice.
--
-- LO QUE HAYA QUE CORREGIR VA POR NOTA DE CREDITO O DE DEBITO, que son
-- documentos nuevos, con su propio numero (NC-A / ND-A), su motivo y su
-- huella, y que tampoco se editan. Una NC no puede dejar el saldo de la
-- propuesta por debajo de cero.

ALTER TABLE factura_propuesta
  ADD COLUMN serie          VARCHAR(10) NULL AFTER id,
  ADD COLUMN numero_seq     INT UNSIGNED NULL AFTER serie,
  ADD COLUMN numero         VARCHAR(30) NULL AFTER numero_seq,
  ADD COLUMN contenido_sha  CHAR(64)    NULL AFTER omitidos_json;

ALTER TABLE factura_propuesta
  ADD UNIQUE KEY uq_fp_numero (serie, numero_seq);

CREATE TABLE IF NOT EXISTS factura_ajuste (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  propuesta_id   BIGINT UNSIGNED NOT NULL,
  tipo           ENUM('credito','debito') NOT NULL,
  serie          VARCHAR(10)  NOT NULL DEFAULT 'A',
  numero_seq     INT UNSIGNED NOT NULL,
  numero         VARCHAR(30)  NOT NULL,
  motivo         VARCHAR(255) NOT NULL,
  iva_alicuota_pb INT UNSIGNED NOT NULL,
  neto_cent      BIGINT       NOT NULL,
  iva_cent       BIGINT       NOT NULL,
  total_cent     BIGINT       NOT NULL,
  contenido_sha  CHAR(64)     NOT NULL,
  creado_utc     DATETIME     NOT NULL,
  creado_por     INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fa_numero (numero),
  KEY idx_propuesta (propuesta_id),
  CONSTRAINT fk_fa_propuesta FOREIGN KEY (propuesta_id) REFERENCES factura_propuesta(id),
  CONSTRAINT ck_fa_montos    CHECK (neto_cent > 0 AND iva_cent >= 0 AND total_cent = neto_cent + iva_cent)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS factura_ajuste_linea (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ajuste_id    BIGINT UNSIGNED NOT NULL,
  orden        INT UNSIGNED NOT NULL,
  descripcion  VARCHAR(255) NOT NULL,
  neto_cent    BIGINT       NOT NULL,
  -- El remito al que se refiere, si se refiere a uno (una visita que se
  -- cobro y no correspondia). NULL para un ajuste general.
  remito_id    BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fal_orden (ajuste_id, orden),
  CONSTRAINT fk_fal_ajuste FOREIGN KEY (ajuste_id) REFERENCES factura_ajuste(id),
  CONSTRAINT fk_fal_remito FOREIGN KEY (remito_id) REFERENCES remito(id),
  CONSTRAINT ck_fal_monto  CHECK (neto_cent > 0)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Las series se crean aca y no en la primera emision (ver 0013).
INSERT INTO numerador (tipo, serie, ultimo, actualizado_utc)
VALUES ('PF', 'A', 0, UTC_TIMESTAMP()), ('NC', 'A', 0, UTC_TIMESTAMP()), ('ND', 'A', 0, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE tipo = tipo;

-- FIN
