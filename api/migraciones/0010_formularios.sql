-- R23 y R28: definicion versionada y registros emitidos. Obj. 1, paso F1.2.
--
-- LA REGLA QUE ORDENA TODO ESTO:
--
--   Un registro emitido es una FOTO, no una consulta.
--
-- Si el R28 se armara leyendo parada_ejecucion cada vez que alguien lo abre,
-- corregir una cantidad tres semanas despues cambiaria —en silencio— un
-- documento que el cliente ya firmo. Un documento que cambia solo no sirve
-- como evidencia, y el Objetivo 2 entero (remito, conformidad, certificacion)
-- se apoya sobre esto.
--
-- De ahi salen las dos tablas:
--   formulario_def  QUE campos lleva el formulario, por version
--   registro        QUE valores tuvo ESTE documento, congelados al emitir
--
-- El contenido exacto de los formularios es de CAMCA, no nuestro: es el
-- supuesto con mas chances de estar equivocado (S4). Por eso se versiona desde
-- el dia uno en vez de dejarlo para cuando haga falta.

-- ------------------------------------------------------------------
-- Definicion de formulario
-- ------------------------------------------------------------------
-- Una fila por (tipo, version). NUNCA se edita una fila existente: cambiar el
-- formulario es insertar la version siguiente. El sha del esquema esta para
-- detectar justamente eso — que alguien haya editado in situ.
CREATE TABLE IF NOT EXISTS formulario_def (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tipo         ENUM('R23','R28') NOT NULL,
  version      SMALLINT UNSIGNED NOT NULL,
  nombre       VARCHAR(120) NOT NULL,
  -- Esquema de campos. JSON en TEXT y no en columna JSON nativa: Hostinger
  -- compartido puede estar en MariaDB 10.x, donde JSON es un alias de
  -- LONGTEXT y las funciones difieren. El parseo lo hace PHP.
  esquema_json MEDIUMTEXT   NOT NULL,
  esquema_sha  CHAR(64)     NOT NULL,
  -- Solo una version vigente por tipo. Las viejas NO se borran: siguen siendo
  -- la definicion de los documentos emitidos con ellas.
  vigente      TINYINT(1)   NOT NULL DEFAULT 0,
  nota         VARCHAR(255) NULL,
  creado_utc   DATETIME     NOT NULL,
  creado_por   INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tipo_version (tipo, version),
  KEY idx_vigente (tipo, vigente),
  CONSTRAINT fk_fdef_usuario FOREIGN KEY (creado_por) REFERENCES usuario(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- Registro emitido
-- ------------------------------------------------------------------
-- numero queda NULL hasta F1.4, que le pone el correlativo sin huecos. Un
-- borrador no consume numero: el numero se toma al emitir y un anulado NO lo
-- libera.
--
-- emisor_json congela la razon social con la que se emitio (S3): cambiar la
-- configuracion no puede reescribir la cabecera de documentos viejos.
CREATE TABLE IF NOT EXISTS registro (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tipo           ENUM('R23','R28') NOT NULL,
  formulario_id  INT UNSIGNED NOT NULL,
  numero         VARCHAR(30)  NULL,
  serie          VARCHAR(10)  NOT NULL DEFAULT 'A',
  estado         ENUM('borrador','emitido','anulado') NOT NULL DEFAULT 'borrador',

  -- De donde salio. Son punteros de TRAZABILIDAD, no la fuente del contenido:
  -- el contenido esta en datos_json.
  jornada_id     BIGINT UNSIGNED NULL,
  parada_id      BIGINT UNSIGNED NULL,
  cliente_id     INT UNSIGNED NULL,
  sitio_id       INT UNSIGNED NULL,
  fecha          DATE         NOT NULL,

  datos_json     MEDIUMTEXT   NOT NULL,
  datos_sha      CHAR(64)     NOT NULL,
  emisor_json    TEXT         NULL,

  emitido_utc    DATETIME     NULL,
  emitido_por    INT UNSIGNED NULL,
  anulado_utc    DATETIME     NULL,
  anulado_por    INT UNSIGNED NULL,
  anulado_motivo VARCHAR(255) NULL,
  -- Un anulado se reemplaza por otro registro. Queda el rastro de cual.
  reemplaza_a    BIGINT UNSIGNED NULL,
  creado_utc     DATETIME     NOT NULL,

  -- Un solo registro VIVO por (tipo, parada). La garantia la da la base y no
  -- solo el PHP: dos emisiones simultaneas del mismo R28 son exactamente la
  -- clase de duplicado que despues aparece en una factura.
  --
  -- Se implementa con una columna que PHP pone en 'R28-1234' al emitir y
  -- vuelve a NULL al anular, en vez de una columna generada con UNIQUE: los
  -- NULL no colisionan en un indice unico de MySQL, y esto funciona igual en
  -- cualquier version del motor. El preflight todavia no dijo cual corre en
  -- Hostinger, y una migracion que falla alla planta el cron.
  unico_activo   VARCHAR(48)  NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_numero (tipo, serie, numero),
  UNIQUE KEY uq_activo (unico_activo),
  KEY idx_jornada (jornada_id, tipo),
  KEY idx_parada (parada_id),
  KEY idx_cliente_fecha (cliente_id, fecha),
  KEY idx_estado (tipo, estado, fecha),
  CONSTRAINT fk_reg_form    FOREIGN KEY (formulario_id) REFERENCES formulario_def(id),
  CONSTRAINT fk_reg_jornada FOREIGN KEY (jornada_id)    REFERENCES jornada(id),
  CONSTRAINT fk_reg_parada  FOREIGN KEY (parada_id)     REFERENCES parada_ejecucion(id),
  CONSTRAINT fk_reg_cliente FOREIGN KEY (cliente_id)    REFERENCES cliente(id),
  CONSTRAINT fk_reg_sitio   FOREIGN KEY (sitio_id)      REFERENCES sitio(id),
  CONSTRAINT fk_reg_emisor  FOREIGN KEY (emitido_por)   REFERENCES usuario(id),
  CONSTRAINT fk_reg_anula   FOREIGN KEY (anulado_por)   REFERENCES usuario(id),
  CONSTRAINT fk_reg_reempl  FOREIGN KEY (reemplaza_a)   REFERENCES registro(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Las evidencias que respaldan un registro. Se anotan por id y por sha: si
-- manianna alguien reemplaza un archivo de evidencia, el registro lo delata.
CREATE TABLE IF NOT EXISTS registro_evidencia (
  registro_id  BIGINT UNSIGNED NOT NULL,
  evidencia_id BIGINT UNSIGNED NOT NULL,
  sha256       CHAR(64) NULL,
  PRIMARY KEY (registro_id, evidencia_id),
  KEY idx_evidencia (evidencia_id),
  CONSTRAINT fk_re_registro  FOREIGN KEY (registro_id)  REFERENCES registro(id),
  CONSTRAINT fk_re_evidencia FOREIGN KEY (evidencia_id) REFERENCES evidencia(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FIN
