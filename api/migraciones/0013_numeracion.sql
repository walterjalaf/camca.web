-- Numeracion formal de R23 y R28. Obj. 1, paso F1.4.
--
-- POR QUE UN CONTADOR PROPIO Y NO AUTO_INCREMENT:
--
-- AUTO_INCREMENT deja huecos por diseno. Una transaccion que aborta consume
-- el numero igual, y MySQL no lo devuelve. Para un id interno eso da lo mismo;
-- para un correlativo que despues alguien tiene que explicar —"por que falta
-- el R28-A-000042"— no da lo mismo en absoluto.
--
-- El contador se toma con SELECT ... FOR UPDATE ADENTRO de la misma
-- transaccion que inserta el registro. Eso serializa las emisiones de una
-- misma serie, que es exactamente lo que se quiere: es el precio de que no
-- haya huecos, y es barato porque emitir no es una operacion de alta
-- frecuencia.
--
-- Un registro ANULADO conserva su numero. No se reutiliza nunca: un numero
-- que vuelve a aparecer en otro documento es peor que un hueco.

CREATE TABLE IF NOT EXISTS numerador (
  tipo            VARCHAR(10)  NOT NULL,
  serie           VARCHAR(10)  NOT NULL,
  ultimo          INT UNSIGNED NOT NULL DEFAULT 0,
  actualizado_utc DATETIME     NOT NULL,
  PRIMARY KEY (tipo, serie)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La secuencia como entero, aparte del numero formateado. El detector de
-- huecos tiene que poder comparar con aritmetica y no parseando cadenas.
ALTER TABLE registro
  ADD COLUMN numero_seq INT UNSIGNED NULL AFTER numero;

ALTER TABLE registro
  ADD UNIQUE KEY uq_seq (tipo, serie, numero_seq);

-- Las series conocidas se crean acá y no en la primera emisión. Crear la fila
-- adentro de la transaccion caliente obliga a un INSERT sobre una clave que
-- quiza ya existe, y ese es el camino al deadlock: el motor toma lock
-- compartido y despues lo sube a exclusivo, asi que dos emisiones simultaneas
-- se esperan mutuamente. Con la fila ya creada, siguiente() hace un unico
-- UPDATE que toma el exclusivo de una.
INSERT INTO numerador (tipo, serie, ultimo, actualizado_utc)
VALUES ('R23', 'A', 0, UTC_TIMESTAMP()), ('R28', 'A', 0, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE tipo = tipo;

-- FIN
