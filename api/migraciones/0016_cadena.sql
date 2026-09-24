-- Cabeza de cada cadena de hashes. Obj. 2, pasos F2.1 (arreglo) y F2.3.
--
-- EL PROBLEMA QUE ARREGLA:
--
-- Hash::auditar() tomaba el eslabon previo con
--   SELECT hash FROM auditoria ORDER BY id DESC LIMIT 1 FOR UPDATE
-- Un FOR UPDATE que recorre el final de un indice no bloquea solo esa fila:
-- toma next-key locks, que incluyen el hueco hasta el supremo, y el INSERT
-- que viene despues necesita un insert-intention lock sobre ese mismo hueco.
-- Dos escritores simultaneos se quedan cada uno esperando al otro. Con 50
-- emisiones concurrentes la prueba de F1.4 fallaba en 3 de cada 6 corridas,
-- y como auditar() no se reintentaba, el documento quedaba emitido y el
-- proceso informaba un error.
--
-- LA SOLUCION es la misma que ya usa el numerador (0013): una fila por cadena
-- que se bloquea por clave primaria. Un lock de fila exacto, sin huecos, y la
-- cabeza se lee de ahi con la version mas reciente.
--
-- Sirve para las dos cadenas que existen desde F2.3: la de auditoria y la de
-- remitos certificados.

CREATE TABLE IF NOT EXISTS cadena (
  nombre          VARCHAR(20)     NOT NULL,
  ultimo_hash     CHAR(64)        NULL,
  ultimo_id       BIGINT UNSIGNED NULL,
  actualizado_utc DATETIME        NOT NULL,
  PRIMARY KEY (nombre)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La cadena de auditoria ya existe: su cabeza es el ultimo eslabon guardado.
-- Sobre una base vacia queda en NULL, que es exactamente el "sin previo" del
-- primer eslabon.
INSERT INTO cadena (nombre, ultimo_hash, ultimo_id, actualizado_utc)
SELECT 'auditoria',
       (SELECT hash FROM auditoria ORDER BY id DESC LIMIT 1),
       (SELECT MAX(id) FROM auditoria),
       UTC_TIMESTAMP()
ON DUPLICATE KEY UPDATE nombre = nombre;

INSERT INTO cadena (nombre, ultimo_hash, ultimo_id, actualizado_utc)
VALUES ('remito', NULL, NULL, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre = nombre;

-- FIN
