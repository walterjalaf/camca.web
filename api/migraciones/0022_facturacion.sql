-- Propuesta de facturacion. Obj. 3, paso F3.3.
--
-- QUE ES: lo que CAMCA le propone facturarle a UN cliente por un periodo,
-- armado SOLO con trabajo certificado (remito vivo, conforme, firmado y en la
-- cadena). No es la factura: la factura fiscal la emite el sistema contable
-- de CAMCA (supuesto S6). Esto es lo que la oficina revisa, aprueba (F3.4) y
-- exporta (F3.5).
--
-- TRES REGLAS, CADA UNA CON SU MECANISMO EN LA BASE, NO SOLO EN PHP:
--
--  1. NADA SE FACTURA DOS VECES. factura_linea_remito.remito_vivo repite el
--     remito_id mientras la propuesta esta viva y vuelve a NULL si se descarta.
--     El UNIQUE sobre esa columna hace que dos propuestas armadas a la vez no
--     puedan llevarse el mismo remito aunque las dos pasen el chequeo de PHP.
--
--  2. TODA LINEA TIENE REMITO. Las lineas se insertan en la misma transaccion
--     que sus remitos, y el verificador (Facturacion::verificar) lo recorre.
--
--  3. LA SUMA CIERRA. neto_cent es la suma de las lineas, iva_cent sale de
--     ese neto una sola vez y total_cent = neto + iva. Los CHECK impiden
--     guardar una cabecera que no cierre consigo misma.
--
-- IMPORTES EN CENTAVOS ENTEROS, como el tarifario (0021).

CREATE TABLE IF NOT EXISTS factura_propuesta (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cliente_id       INT UNSIGNED NOT NULL,
  desde            DATE         NOT NULL,
  hasta            DATE         NOT NULL,
  estado           ENUM('borrador','aprobada','descartada') NOT NULL DEFAULT 'borrador',
  -- A si el cliente es responsable inscripto (IVA discriminado); B en otro caso.
  tipo_comprobante CHAR(1)      NOT NULL,
  -- Los datos del cliente al armarla: razon social, CUIT, condicion de IVA,
  -- domicilio. Una propuesta aprobada no cambia si despues se corrige el cliente.
  cliente_json     TEXT         NOT NULL,
  -- Alicuota en centesimos de punto: 2100 = 21 %.
  iva_alicuota_pb  INT UNSIGNED NOT NULL,
  neto_cent        BIGINT       NOT NULL,
  iva_cent         BIGINT       NOT NULL,
  total_cent       BIGINT       NOT NULL,
  -- Lo que se dejo afuera y por que (sin tarifa, sin cantidad): la oficina
  -- tiene que verlo, no enterarse cuando el cliente pregunta.
  omitidos_json    TEXT         NOT NULL,
  creado_utc       DATETIME     NOT NULL,
  creado_por       INT UNSIGNED NULL,
  aprobada_utc     DATETIME     NULL,
  aprobada_por     INT UNSIGNED NULL,
  descartada_utc   DATETIME     NULL,
  descartada_por   INT UNSIGNED NULL,
  descartada_motivo VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY idx_cliente (cliente_id, desde),
  CONSTRAINT fk_fp_cliente FOREIGN KEY (cliente_id) REFERENCES cliente(id),
  CONSTRAINT ck_fp_periodo CHECK (hasta >= desde),
  CONSTRAINT ck_fp_tipo    CHECK (tipo_comprobante IN ('A', 'B')),
  CONSTRAINT ck_fp_montos  CHECK (neto_cent >= 0 AND iva_cent >= 0 AND total_cent = neto_cent + iva_cent)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Una linea por visita: el minimo de la tarifa es POR VISITA, asi que sumar
-- visitas en una sola linea esconderia donde se aplico.
CREATE TABLE IF NOT EXISTS factura_linea (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  propuesta_id     BIGINT UNSIGNED NOT NULL,
  orden            INT UNSIGNED NOT NULL,
  servicio_codigo  VARCHAR(30)  NOT NULL,
  descripcion      VARCHAR(255) NOT NULL,
  fecha            DATE         NOT NULL,
  cantidad         INT UNSIGNED NOT NULL,
  tarifa_id        INT UNSIGNED NOT NULL,
  unitario_cent    BIGINT       NOT NULL,
  minimo_aplicado  TINYINT(1)   NOT NULL DEFAULT 0,
  neto_cent        BIGINT       NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_orden (propuesta_id, orden),
  CONSTRAINT fk_fl_propuesta FOREIGN KEY (propuesta_id) REFERENCES factura_propuesta(id),
  CONSTRAINT fk_fl_servicio  FOREIGN KEY (servicio_codigo) REFERENCES servicio(codigo),
  CONSTRAINT fk_fl_tarifa    FOREIGN KEY (tarifa_id) REFERENCES tarifa(id),
  CONSTRAINT ck_fl_montos    CHECK (unitario_cent >= 0 AND neto_cent >= 0)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS factura_linea_remito (
  linea_id      BIGINT UNSIGNED NOT NULL,
  remito_id     BIGINT UNSIGNED NOT NULL,
  propuesta_id  BIGINT UNSIGNED NOT NULL,
  -- = remito_id mientras la propuesta no este descartada; NULL despues.
  remito_vivo   BIGINT UNSIGNED NULL,
  PRIMARY KEY (linea_id, remito_id),
  UNIQUE KEY uq_remito_vivo (remito_vivo),
  KEY idx_remito (remito_id),
  KEY idx_propuesta (propuesta_id),
  CONSTRAINT fk_flr_linea   FOREIGN KEY (linea_id)     REFERENCES factura_linea(id),
  CONSTRAINT fk_flr_remito  FOREIGN KEY (remito_id)    REFERENCES remito(id),
  CONSTRAINT fk_flr_prop    FOREIGN KEY (propuesta_id) REFERENCES factura_propuesta(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FIN
