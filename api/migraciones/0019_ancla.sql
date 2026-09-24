-- Ancla diaria de las cadenas. Obj. 2, paso F2.5.
--
-- POR QUE HACE FALTA, SI YA HAY FIRMA:
--
-- La firma impide que alguien SIN la clave reescriba la historia. Pero la
-- clave vive en el servidor (S10): quien se lleve el servidor entero se lleva
-- tambien la clave, y con ella puede reescribir un remito viejo, recalcular
-- todos los eslabones siguientes y volver a firmarlos. La cadena resultante
-- verifica perfecta.
--
-- Lo que no puede reescribir es lo que ya SALIO del servidor. Una vez por dia
-- se toma la cabeza de cada cadena (la de remitos y la de auditoria), se
-- firma, y viaja dentro del backup cifrado que se guarda fuera del proveedor
-- y por mail a quien custodia. Si mas tarde la historia se reescribe, la
-- huella anclada deja de aparecer en la cadena, y el verificador lo dice.

CREATE TABLE IF NOT EXISTS ancla (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  fecha            DATE         NOT NULL,
  -- JSON canonico: exactamente lo que se firma.
  contenido        TEXT         NOT NULL,
  cabeza_remito    CHAR(64)     NULL,
  cabeza_auditoria CHAR(64)     NULL,
  firma            VARCHAR(100) NULL,
  firma_alg        VARCHAR(20)  NOT NULL DEFAULT 'ninguna',
  clave_id         CHAR(16)     NULL,
  creado_utc       DATETIME     NOT NULL,
  mail_enviado_utc DATETIME     NULL,
  en_backup_utc    DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fecha (fecha)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FIN
