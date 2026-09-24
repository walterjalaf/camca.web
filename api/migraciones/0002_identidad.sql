-- Identidad: usuarios, dispositivos enrolados y sesiones.

CREATE TABLE IF NOT EXISTS usuario (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  rol           ENUM('chofer','supervisor','admin','cliente') NOT NULL,
  nombre        VARCHAR(120) NOT NULL,
  legajo        VARCHAR(30)  NULL,
  email         VARCHAR(190) NULL,
  -- Para supervisor/admin. El chofer no usa contrasenia: entra con PIN
  -- sobre dispositivo enrolado (tipear un email con guantes a 3.000 m
  -- es inviable, y el PIN solo vale con el telefono en la mano).
  pass_hash     VARCHAR(255) NULL,
  cliente_id    INT UNSIGNED NULL,
  activo        TINYINT(1)   NOT NULL DEFAULT 1,
  creado_utc    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_email (email),
  UNIQUE KEY uq_legajo (legajo),
  KEY idx_rol (rol, activo)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La credencial real del chofer es el par {secreto de dispositivo, PIN}.
-- El PIN de 6 digitos es debil por si solo; lo que lo hace aceptable es que
-- nunca se evalua sin el telefono enrolado en la mano.
CREATE TABLE IF NOT EXISTS dispositivo (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id      INT UNSIGNED NOT NULL,
  etiqueta        VARCHAR(80)  NULL,
  secreto_hash    CHAR(64)     NOT NULL,
  pin_hash        VARCHAR(255) NOT NULL,
  intentos_pin    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  bloqueado_hasta DATETIME     NULL,
  plataforma      VARCHAR(40)  NULL,
  enrolado_utc    DATETIME     NOT NULL,
  visto_utc       DATETIME     NULL,
  revocado_utc    DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_secreto (secreto_hash),
  KEY idx_usuario (usuario_id, revocado_utc),
  CONSTRAINT fk_disp_usuario FOREIGN KEY (usuario_id) REFERENCES usuario(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Codigos de enrolamiento. Alfanumericos cortos y DICTABLES por telefono o
-- VHF: reponer un celular roto no puede depender de que dos personas tengan
-- senial y pantalla al mismo tiempo a las 6 de la maniana en Tamberias.
CREATE TABLE IF NOT EXISTS codigo_enrolamiento (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo        VARCHAR(20)  NOT NULL,
  usuario_id    INT UNSIGNED NOT NULL,
  emitido_por   INT UNSIGNED NULL,
  emitido_utc   DATETIME     NOT NULL,
  expira_utc    DATETIME     NOT NULL,
  usado_utc     DATETIME     NULL,
  dispositivo_id INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_codigo (codigo),
  KEY idx_usuario (usuario_id, usado_utc),
  CONSTRAINT fk_cod_usuario FOREIGN KEY (usuario_id) REFERENCES usuario(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- El token se guarda HASHEADO: un dump robado no entrega sesiones usables.
CREATE TABLE IF NOT EXISTS sesion (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id     INT UNSIGNED NOT NULL,
  dispositivo_id INT UNSIGNED NULL,
  token_hash     CHAR(64)     NOT NULL,
  csrf           CHAR(32)     NOT NULL,
  creada_utc     DATETIME     NOT NULL,
  expira_utc     DATETIME     NOT NULL,
  visto_utc      DATETIME     NULL,
  revocada_utc   DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_token (token_hash),
  KEY idx_usuario (usuario_id, revocada_utc),
  KEY idx_dispositivo (dispositivo_id, revocada_utc),
  CONSTRAINT fk_ses_usuario FOREIGN KEY (usuario_id) REFERENCES usuario(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS intento_login (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  identidad   VARCHAR(190) NOT NULL,
  ip_hash     CHAR(16)     NOT NULL,
  exito       TINYINT(1)   NOT NULL,
  creado_utc  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_identidad (identidad, creado_utc)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FIN
