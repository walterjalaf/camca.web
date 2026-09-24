-- Conformidad del cliente, y el arreglo de la idempotencia de negocio.
-- Obj. 2, paso F2.2.
--
-- ------------------------------------------------------------------
-- 1. LA RONDA DE UNA PARADA
-- ------------------------------------------------------------------
-- evento_sync tenia UNIQUE (jornada_id, parada_orden, tipo): la clave de
-- negocio, por si el telefono pierde el uuid de un evento y lo regenera.
--
-- Pero con esa clave, una parada cerrada, REABIERTA y vuelta a cerrar manda
-- dos 'parada_cerrada' con la misma clave. El segundo se tomaba como
-- duplicado, se le confirmaba al telefono —que lo sacaba de la cola— y NUNCA
-- se aplicaba: en el servidor la parada quedaba 'planificada' (por la
-- reapertura) y en el telefono 'ejecutada'. Un trabajo hecho que la oficina
-- veia sin hacer, sin ningun aviso en ningun lado.
--
-- La ronda es cuantas veces se reabrio la parada antes de este evento. La
-- lleva el telefono (que es el que sabe el orden en que el chofer hizo las
-- cosas) y el servidor la devuelve en /dia para que un telefono que se
-- reinstala siga contando desde donde iba. Un evento regenerado conserva su
-- ronda, asi que la proteccion original sigue intacta.
--
-- Cambiar el indice no borra ni altera un solo dato: relaja la unicidad para
-- que entren los eventos legitimos que antes se perdian.

ALTER TABLE evento_sync
  ADD COLUMN ronda SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER parada_orden;

ALTER TABLE evento_sync
  DROP INDEX uq_negocio,
  ADD UNIQUE KEY uq_negocio (jornada_id, parada_orden, tipo, ronda);

-- ------------------------------------------------------------------
-- 2. CONFORMIDAD DEL CLIENTE
-- ------------------------------------------------------------------
-- Lo que dijo quien recibio el servicio en el sitio: conforme o no, quien
-- era, y por que si no estuvo conforme. Se captura en el telefono, SIN
-- SENIAL, junto con la firma, y viaja en el evento de cierre de la parada.
--
-- Una fila por cierre. Si la parada se reabre, la conformidad de ese cierre
-- deja de estar vigente pero NO se borra: un cliente que firmo conforme y
-- despues la parada se reabrio es un hecho que alguien puede tener que
-- explicar.
--
-- receptor_nombre y receptor_documento son datos personales (Ley 25.326):
-- van en el remito, que es del cliente, y NUNCA en la verificacion publica.

CREATE TABLE IF NOT EXISTS conformidad (
  id                 BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  parada_id          BIGINT UNSIGNED  NOT NULL,
  jornada_id         BIGINT UNSIGNED  NOT NULL,
  -- El evento de cierre que la trajo. Idempotencia: el mismo lote reenviado
  -- no duplica la conformidad.
  evento_uuid        CHAR(36)         NOT NULL,
  ronda              SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  resultado          ENUM('conforme','rechazado') NOT NULL,
  receptor_nombre    VARCHAR(120)     NULL,
  receptor_documento VARCHAR(30)      NULL,
  observaciones      VARCHAR(500)     NULL,
  motivo_rechazo     VARCHAR(255)     NULL,
  -- La evidencia de la firma, por uuid: puede llegar DESPUES que el evento
  -- (viaja por el carril lento de adjuntos).
  firma_uuid         CHAR(36)         NULL,
  vigente            TINYINT(1)       NOT NULL DEFAULT 1,
  ocurrido_utc       DATETIME         NOT NULL,
  recibido_utc       DATETIME         NOT NULL,
  dispositivo_id     INT UNSIGNED     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_evento (evento_uuid),
  KEY idx_parada (parada_id, vigente),
  CONSTRAINT fk_conf_parada  FOREIGN KEY (parada_id)  REFERENCES parada_ejecucion(id),
  CONSTRAINT fk_conf_jornada FOREIGN KEY (jornada_id) REFERENCES jornada(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FIN
