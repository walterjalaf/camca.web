-- Consultas del formulario publico.
--
-- Contexto del bug que esto arregla: contacto.astro hacia POST a "/" siguiendo
-- la convencion de Netlify Forms, pero produccion es Hostinger. Verificado con
-- curl: POST / devuelve 200 porque sirve el index.html estatico. Como fetch
-- solo rechaza ante error de red, el catch nunca corria y el visitante SIEMPRE
-- veia el mensaje de exito. Ninguna consulta llego nunca a CAMCA.
--
-- Por eso la fila se INSERTA SIEMPRE antes de intentar el mail: si el SMTP
-- falla, la consulta ya quedo guardada y un cron la reintenta. Nunca mas se
-- pierde una consulta por un problema de correo.

CREATE TABLE IF NOT EXISTS consulta_web (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre       VARCHAR(120) NOT NULL,
  email        VARCHAR(190) NULL,
  telefono     VARCHAR(30)  NULL,
  empresa      VARCHAR(160) NULL,
  servicio     VARCHAR(80)  NULL,
  mensaje      TEXT         NOT NULL,
  estado       ENUM('pendiente','enviado','fallido','spam') NOT NULL DEFAULT 'pendiente',
  intentos     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ultimo_error VARCHAR(255) NULL,
  ip_hash      CHAR(16)     NULL,
  user_agent   VARCHAR(255) NULL,
  creado_utc   DATETIME     NOT NULL,
  enviado_utc  DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_estado (estado, creado_utc),
  KEY idx_creado (creado_utc)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- El honeypot y la trampa de tiempo DEGRADAN a estado spam en vez de
-- rechazar: un falso positivo no puede hacer desaparecer a un cliente real.

-- FIN
