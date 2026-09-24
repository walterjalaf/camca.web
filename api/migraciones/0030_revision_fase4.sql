-- Revisión adversarial de la Fase 4. Sólo agrega (las migraciones 0026 a
-- 0029 ya pueden estar aplicadas y no se tocan).

-- S16 de verdad: aprobar lo pide a otra persona que la que ELABORÓ la
-- versión, pero también que la que subió el PDF y la que la envió a
-- revisión. Sin esto, alguien subía su propio archivo a una versión que
-- había abierto otro y lo aprobaba él mismo.
ALTER TABLE sga_version ADD COLUMN archivo_por INT UNSIGNED NULL AFTER archivo_nombre;
ALTER TABLE sga_version ADD COLUMN enviado_por INT UNSIGNED NULL AFTER enviado_utc;

-- OCR: el número del remito en papel, normalizado («0001-00004512» y
-- «1-4512» son el mismo), único por fecha. La base es la que impide dos
-- registros del mismo papel validados a la vez desde dos fotos.
ALTER TABLE remito_papel ADD COLUMN numero_norm VARCHAR(40) NULL AFTER numero_papel;
ALTER TABLE remito_papel ADD UNIQUE KEY uq_numero_fecha (numero_norm, fecha);

-- OCR: la foto se RESERVA antes de llamar a la API («procesando», con desde
-- cuándo). Sin esto, dos reprocesos a la vez hacían dos llamadas pagas, y
-- una foto cuyo proceso murió quedaba trabada. Se agrega un valor al final
-- del ENUM: los que había no cambian.
ALTER TABLE ocr_remito MODIFY estado ENUM('pendiente','borrador','error','validado','descartado','procesando') NOT NULL DEFAULT 'pendiente';
ALTER TABLE ocr_remito ADD COLUMN procesando_desde DATETIME NULL AFTER intentos;

-- La serie de las no conformidades se crea acá y no en la primera emisión
-- (ver 0013): dos altas simultáneas en una base nueva se trababan.
INSERT INTO numerador (tipo, serie, ultimo, actualizado_utc)
VALUES ('SGA', 'NC', 0, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE tipo = tipo;

-- FIN
