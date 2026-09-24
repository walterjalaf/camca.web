-- Maquina de estados del trabajo. Obj. 1, paso F1.3.
--
-- DOS ESTADOS QUE NO SON EL MISMO, Y POR ESO SON DOS COLUMNAS:
--
--   parada_ejecucion.estado  lo que paso EN EL CAMPO
--                            planificada / ejecutada / no_ejecutada
--                            Lo escribe el chofer desde el telefono.
--
--   parada_ejecucion.flujo   donde esta ese trabajo en el CIRCUITO
--                            planificado -> ... -> facturado
--                            Lo mueve la oficina, y cada movimiento queda.
--
-- Meterlos en una sola columna parece mas prolijo y es un error: el chofer
-- marca "ejecutada" arriba del cerro y sin senial, y la oficina verifica y
-- factura semanas despues. Si fueran la misma columna, sincronizar una jornada
-- vieja pisaria el estado administrativo de un trabajo ya facturado.

ALTER TABLE parada_ejecucion
  ADD COLUMN flujo ENUM(
    'planificado','asignado','en_curso','ejecutado','verificado',
    'certificado','facturable','facturado','reprogramado','anulado'
  ) NOT NULL DEFAULT 'planificado' AFTER estado;

ALTER TABLE parada_ejecucion
  ADD KEY idx_flujo (flujo, jornada_id);

-- Historial completo. No se borra nunca y no se actualiza nunca: una fila por
-- movimiento. Es lo que permite reconstruir por que un trabajo esta donde
-- esta, que es la pregunta que aparece cuando el cliente discute una factura.
--
-- El motivo es OBLIGATORIO en los movimientos que van para atras o sacan el
-- trabajo del circuito, y lo exige el PHP y no la base: aca se guarda tambien
-- el historial de los movimientos normales, que no llevan motivo.
CREATE TABLE IF NOT EXISTS trabajo_transicion (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  parada_id    BIGINT UNSIGNED NOT NULL,
  jornada_id   BIGINT UNSIGNED NOT NULL,
  desde        VARCHAR(20)  NOT NULL,
  hacia        VARCHAR(20)  NOT NULL,
  motivo       VARCHAR(255) NULL,
  usuario_id   INT UNSIGNED NULL,
  -- Con que se justifico el movimiento: numero de registro, id de conciliacion,
  -- lo que corresponda en cada paso. JSON canonico.
  contexto     TEXT         NULL,
  -- Eslabon de auditoria que dejo este movimiento, para poder cruzarlos.
  auditoria_hash CHAR(64)   NULL,
  creado_utc   DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_parada (parada_id, id),
  KEY idx_jornada (jornada_id),
  KEY idx_hacia (hacia, creado_utc),
  CONSTRAINT fk_tt_parada  FOREIGN KEY (parada_id)  REFERENCES parada_ejecucion(id),
  CONSTRAINT fk_tt_jornada FOREIGN KEY (jornada_id) REFERENCES jornada(id),
  CONSTRAINT fk_tt_usuario FOREIGN KEY (usuario_id) REFERENCES usuario(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FIN
