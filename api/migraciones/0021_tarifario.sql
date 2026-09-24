-- Tarifario. Obj. 3, paso F3.2.
--
-- UNA TARIFA NO SE EDITA NUNCA. Se cierra con una fecha y se carga la
-- siguiente, igual que los formularios (0010). Si el precio de marzo se
-- pudiera corregir en abril, la propuesta de facturacion de marzo diria una
-- cosa y la tabla otra, y nadie podria explicar la diferencia.
--
-- IMPORTES EN CENTAVOS ENTEROS (BIGINT). Nunca DECIMAL ni FLOAT en PHP: un
-- 0,1 + 0,2 que da 0,30000000000000004 en una factura es un reclamo.
--
-- PRECIOS SIN IVA. El IVA se calcula en la propuesta (F3.3) segun la
-- condicion del cliente; guardarlo aca lo dejaria desactualizado el dia que
-- cambie la alicuota.

-- Lo que se puede facturar. Los tres primeros salen de las opciones del R23
-- (0011); cualquier otro se agrega aca sin tocar codigo.
CREATE TABLE IF NOT EXISTS servicio (
  codigo      VARCHAR(30)  NOT NULL,
  nombre      VARCHAR(120) NOT NULL,
  unidad      VARCHAR(30)  NOT NULL,
  unidad_plural VARCHAR(30) NOT NULL,
  activo      TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (codigo)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO servicio (codigo, nombre, unidad, unidad_plural) VALUES
  ('limpieza', 'Limpieza y mantenimiento de baño químico', 'baño', 'baños'),
  ('entrega',  'Entrega de baño químico', 'baño', 'baños'),
  ('retiro',   'Retiro de baño químico', 'baño', 'baños')
ON DUPLICATE KEY UPDATE codigo = codigo;

-- cliente_id NULL = la lista general, que vale para quien no tiene tarifa
-- propia. vigente_hasta NULL = sin fecha de cierre todavia; es INCLUSIVA.
CREATE TABLE IF NOT EXISTS tarifa (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cliente_id           INT UNSIGNED NULL,
  servicio_codigo      VARCHAR(30)  NOT NULL,
  precio_unitario_cent BIGINT       NOT NULL,
  -- Lo minimo que se cobra por visita aunque la cantidad sea chica.
  minimo_cent          BIGINT       NOT NULL DEFAULT 0,
  -- Adicionales: por km recorrido fuera del radio y por hora de espera.
  adicional_km_cent    BIGINT       NOT NULL DEFAULT 0,
  adicional_hora_cent  BIGINT       NOT NULL DEFAULT 0,
  vigente_desde        DATE         NOT NULL,
  vigente_hasta        DATE         NULL,
  nota                 VARCHAR(255) NULL,
  creado_por           INT UNSIGNED NULL,
  creado_utc           DATETIME     NOT NULL,
  cerrado_por          INT UNSIGNED NULL,
  cerrado_utc          DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_busqueda (servicio_codigo, cliente_id, vigente_desde),
  CONSTRAINT fk_tar_cliente  FOREIGN KEY (cliente_id)      REFERENCES cliente(id),
  CONSTRAINT fk_tar_servicio FOREIGN KEY (servicio_codigo) REFERENCES servicio(codigo),
  CONSTRAINT ck_tar_montos   CHECK (precio_unitario_cent >= 0 AND minimo_cent >= 0
                                    AND adicional_km_cent >= 0 AND adicional_hora_cent >= 0),
  CONSTRAINT ck_tar_fechas   CHECK (vigente_hasta IS NULL OR vigente_hasta >= vigente_desde)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FIN
