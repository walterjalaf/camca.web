-- Certificacion de remitos. Obj. 2, paso F2.3.
--
-- UNA CADENA DE ESLABONES, UNO POR CADA HECHO SOBRE UN REMITO:
--
--   emision    lo que el remito dice (su numero, la huella de su contenido,
--              la de su emisor, su codigo de verificacion y cuando salio)
--   anulacion  que se anulo, cuando y la huella del motivo
--
-- Cada eslabon lleva la huella del anterior y su propia huella, y esa huella
-- va firmada con Ed25519. Consecuencias, que son el punto de todo esto:
--
--   - Tocar un byte del contenido de un remito viejo deja de coincidir con la
--     huella que su eslabon guardo al emitirlo.
--   - Recalcular esa huella para taparlo cambia el eslabon, y el siguiente
--     sigue apuntando a la huella vieja: la cadena se corta AHI.
--   - Rehacer la cadena entera desde ese punto exige volver a firmar cada
--     eslabon, y eso exige la clave privada. Y aun con la clave, la cabeza
--     de todos los dias ya salio del servidor (el ancla, F2.5).
--
-- Un remito anulado que alguien vuelve a poner como emitido en la base no
-- tiene como borrar su eslabon de anulacion sin cortar la cadena.

-- Registro INFORMATIVO de las claves publicas con que se firmo. El verificador
-- NO confia en esta tabla (ver Firma.php): confia en la configuracion. Esta
-- tabla es para poder publicarlas.
CREATE TABLE IF NOT EXISTS firma_clave (
  clave_id     CHAR(16)    NOT NULL,
  algoritmo    VARCHAR(20) NOT NULL,
  publica      VARCHAR(100) NOT NULL,
  creada_utc   DATETIME    NOT NULL,
  retirada_utc DATETIME    NULL,
  PRIMARY KEY (clave_id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS remito_eslabon (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  remito_id   BIGINT UNSIGNED NOT NULL,
  tipo        ENUM('emision','anulacion') NOT NULL,
  -- JSON canonico: es EXACTAMENTE lo que se hashea. Se guarda y no se
  -- reconstruye, para que el verificador compare contra lo que se firmo.
  contenido   TEXT         NOT NULL,
  hash_prev   CHAR(64)     NULL,
  hash        CHAR(64)     NOT NULL,
  -- Ed25519 en base64 (88 caracteres). NULL si se emitio sin clave: ese
  -- remito existe y esta encadenado, pero no es un certificado.
  firma       VARCHAR(100) NULL,
  firma_alg   VARCHAR(20)  NOT NULL DEFAULT 'ninguna',
  clave_id    CHAR(16)     NULL,
  creado_utc  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hash (hash),
  UNIQUE KEY uq_remito_tipo (remito_id, tipo),
  CONSTRAINT fk_esl_remito FOREIGN KEY (remito_id) REFERENCES remito(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- El codigo que lleva el QR (F2.4). Se genera al emitir y entra en el
-- eslabon de emision: cambiarlo despues rompe la cadena igual que cambiar
-- el contenido. 10 caracteres Crockford = 50 bits: no se adivina.
ALTER TABLE remito
  ADD COLUMN codigo_verificacion CHAR(10) NULL AFTER numero_seq;

ALTER TABLE remito
  ADD UNIQUE KEY uq_codigo (codigo_verificacion);

-- FIN
