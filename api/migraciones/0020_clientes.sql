-- Maestro de clientes normalizado. Obj. 3, paso F3.1.
--
-- Los 49 clientes del dataset entraron como PROVISORIOS (0003): nombres
-- tomados del campo "responde" de los prototipos, que mezcla razon social con
-- nombre de contacto. No se puede facturar a un nombre de contacto: hace falta
-- la razon social, el CUIT y la condicion frente al IVA.
--
-- Normalizar es trabajo de CAMCA (H8), no de codigo. Lo que el codigo pone es:
--   · el CUIT validado con su digito verificador, y uno solo por cliente vivo;
--   · la fusion de duplicados ("Terusi" / "Terusi Costanera" / "Terusi UCC")
--     con confirmacion humana y REVERSIBLE: se anota que se movio, para poder
--     devolverlo exactamente.
--
-- Lo que la fusion NO toca: los documentos ya emitidos. Un R28 o un remito
-- congelaron el cliente que tenian (S4bis); cambiarles el cliente despues
-- seria reescribir un papel que alguien ya tiene en la mano.

ALTER TABLE cliente
  ADD COLUMN razon_social      VARCHAR(160) NULL AFTER nombre,
  ADD COLUMN tipo              ENUM('empresa','persona') NULL AFTER razon_social,
  ADD COLUMN condicion_iva     ENUM('responsable_inscripto','monotributo','exento','consumidor_final') NULL AFTER cuit,
  ADD COLUMN domicilio_fiscal  VARCHAR(255) NULL AFTER condicion_iva,
  ADD COLUMN email_facturacion VARCHAR(160) NULL AFTER domicilio_fiscal,
  ADD COLUMN activo            TINYINT(1)   NOT NULL DEFAULT 1 AFTER fusionado_en,
  ADD COLUMN actualizado_utc   DATETIME     NULL AFTER creado_utc,
  -- El CUIT de un cliente VIVO es unico, y lo garantiza la base: dos clientes
  -- activos con el mismo CUIT son el mismo cliente, y es justo la clase de
  -- duplicado que despues factura dos veces. Mismo patron que
  -- registro.unico_activo: PHP lo pone al guardar y lo vacia al fusionar.
  ADD COLUMN cuit_activo       VARCHAR(13)  NULL AFTER cuit,
  ADD UNIQUE KEY uq_cuit_activo (cuit_activo);

-- Cada fusion, con lo que movio. Deshacer devuelve EXACTAMENTE esos sitios,
-- ni uno mas: los que se hayan dado de alta en el destino despues quedan donde
-- estan.
CREATE TABLE IF NOT EXISTS cliente_fusion (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  origen_id     INT UNSIGNED NOT NULL,
  destino_id    INT UNSIGNED NOT NULL,
  motivo        VARCHAR(255) NOT NULL,
  sitios_json   TEXT         NOT NULL,
  usuario_id    INT UNSIGNED NULL,
  creado_utc    DATETIME     NOT NULL,
  deshecha_utc  DATETIME     NULL,
  deshecha_por  INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_origen (origen_id),
  KEY idx_destino (destino_id),
  CONSTRAINT fk_fus_origen  FOREIGN KEY (origen_id)  REFERENCES cliente(id),
  CONSTRAINT fk_fus_destino FOREIGN KEY (destino_id) REFERENCES cliente(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FIN
