-- Conciliacion telefono vs. flota, parada por parada. Obj. 1, paso F1.5.
--
-- En Fase 0 el origen de una marca era UNA palabra: 'flota', 'telefono',
-- 'manual' o 'ninguno'. Alcanzaba para pintar la pantalla del chofer, pero no
-- para responder la pregunta que aparece cuando el cliente discute: "¿como
-- saben que estuvieron ahi?".
--
-- Ahora cada parada ejecutada queda clasificada en una de cinco clases:
--
--   doble          las dos fuentes coinciden. Es la evidencia mas fuerte.
--   solo_flota     el camion estuvo; el telefono no lo pudo corroborar
--                  (pantalla apagada, sin bateria, app cerrada).
--   solo_telefono  el telefono estuvo; esa unidad no reporta GPS o no hay
--                  posiciones de flota en esa ventana.
--   contradiccion  UNA DICE QUE SI Y LA OTRA QUE NO. Es el caso que importa:
--                  no se promedia ni se elige la que conviene, se marca.
--   sin_evidencia  ninguna de las dos puede afirmar nada. Tambien se dice.
--
-- La regla de fondo, que viene de la correccion de disenio de Fase 0: el
-- arribo AUTORITATIVO sale del rastro de flota, porque corre en el servidor y
-- no depende de que el telefono tenga pantalla encendida ni bateria. El dwell
-- del telefono CORROBORA, y solo es fuente cuando la flota no cubre esa unidad.

ALTER TABLE parada_ejecucion
  ADD COLUMN conciliacion ENUM(
    'pendiente','doble','solo_flota','solo_telefono','contradiccion','sin_evidencia'
  ) NOT NULL DEFAULT 'pendiente' AFTER evidencia_doble;

ALTER TABLE parada_ejecucion
  ADD COLUMN arribo_flota_utc DATETIME NULL AFTER arribo_utc;

ALTER TABLE parada_ejecucion
  ADD COLUMN permanencia_flota_seg SMALLINT UNSIGNED NULL AFTER arribo_flota_utc;

ALTER TABLE parada_ejecucion
  ADD COLUMN conciliado_utc DATETIME NULL AFTER conciliacion;

ALTER TABLE parada_ejecucion
  ADD KEY idx_conciliacion (conciliacion, jornada_id);

-- El detalle de cada conciliacion, para poder explicarla sin volver a
-- calcularla. Una fila por parada conciliada; se reescribe si se vuelve a
-- correr, porque el rastro de flota puede llegar tarde.
CREATE TABLE IF NOT EXISTS conciliacion_detalle (
  parada_id          BIGINT UNSIGNED NOT NULL,
  jornada_id         BIGINT UNSIGNED NOT NULL,
  clase              VARCHAR(20)  NOT NULL,
  -- Lo que dijo cada fuente, por separado y sin mezclar.
  flota_estuvo       TINYINT(1)   NOT NULL DEFAULT 0,
  flota_desde_utc    DATETIME     NULL,
  flota_hasta_utc    DATETIME     NULL,
  flota_segundos     SMALLINT UNSIGNED NULL,
  flota_fixes        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  flota_distancia_m  SMALLINT UNSIGNED NULL,
  telefono_estuvo    TINYINT(1)   NOT NULL DEFAULT 0,
  telefono_desde_utc DATETIME     NULL,
  -- Cuanto se separan las dos horas de arribo, cuando las dos existen.
  desfase_seg        INT          NULL,
  -- Por que se clasifico asi, en castellano, para poder mostrarlo.
  explicacion        VARCHAR(255) NOT NULL,
  -- Parametros con los que se calculo, para que una conciliacion vieja se
  -- pueda interpretar aunque el sitio haya cambiado de radio despues.
  radio_m            SMALLINT UNSIGNED NOT NULL,
  permanencia_seg    SMALLINT UNSIGNED NOT NULL,
  calculado_utc      DATETIME     NOT NULL,
  PRIMARY KEY (parada_id),
  KEY idx_jornada (jornada_id, clase),
  CONSTRAINT fk_cd_parada  FOREIGN KEY (parada_id)  REFERENCES parada_ejecucion(id),
  CONSTRAINT fk_cd_jornada FOREIGN KEY (jornada_id) REFERENCES jornada(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FIN
