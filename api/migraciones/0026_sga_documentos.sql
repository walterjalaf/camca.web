-- Documentos controlados del sistema de gestión ambiental (ISO 14001 §7.5.3).
-- Obj. 5, paso F4.1.
--
-- Lo que la norma pide de un documento controlado, y cómo lo sostiene la base:
--
--  1. UNA SOLA VERSIÓN VIGENTE. La vigente lleva el id de su documento en
--     `vigente` (UNIQUE); al aprobar la siguiente, la anterior lo pierde en la
--     misma transacción. Dos aprobaciones simultáneas no pueden dejar dos
--     vigentes: la segunda choca contra la clave, no contra un SELECT previo.
--
--  2. LA HISTORIA NO SE BORRA. Cada versión queda con quién la elaboró, quién
--     la aprobó y cuándo, qué cambió y el archivo exacto (sha256). Una versión
--     obsoleta se sigue pudiendo abrir: es la que regía el día de un incidente.
--
--  3. EL ARCHIVO ES INMUTABLE. Se guarda por su huella fuera de public_html;
--     una versión enviada a revisión ya no acepta otro archivo.
--
--  4. VENCIMIENTO. Toda versión vigente tiene fecha de próxima revisión
--     (`revisar_antes`); pasada esa fecha el documento figura vencido hasta
--     que se revise, aunque el texto no haya cambiado.
--
--  5. DISTRIBUCIÓN. Quién tiene que conocer cada versión, y cuándo tomó
--     conocimiento (él mismo en el panel, o registrado por la oficina si se
--     le entregó en mano).

CREATE TABLE IF NOT EXISTS sga_documento (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo          VARCHAR(30)  NOT NULL,
  titulo          VARCHAR(160) NOT NULL,
  tipo            ENUM('politica','manual','procedimiento','instructivo','registro','plan','externo') NOT NULL,
  proceso         VARCHAR(80)  NULL,
  responsable_id  INT UNSIGNED NULL,
  revision_meses  TINYINT UNSIGNED NOT NULL DEFAULT 12,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  retirado_motivo VARCHAR(255) NULL,
  creado_por      INT UNSIGNED NULL,
  creado_utc      DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_codigo (codigo),
  CONSTRAINT fk_sgad_resp FOREIGN KEY (responsable_id) REFERENCES usuario(id),
  CONSTRAINT ck_sgad_meses CHECK (revision_meses BETWEEN 1 AND 60)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sga_version (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  documento_id    INT UNSIGNED NOT NULL,
  version         SMALLINT UNSIGNED NOT NULL,
  estado          ENUM('borrador','en_revision','vigente','obsoleta','descartada') NOT NULL DEFAULT 'borrador',
  cambios         VARCHAR(1000) NOT NULL,
  archivo_sha256  CHAR(64)     NULL,
  archivo_bytes   INT UNSIGNED NULL,
  archivo_nombre  VARCHAR(160) NULL,
  elaborado_por   INT UNSIGNED NULL,
  elaborado_utc   DATETIME     NOT NULL,
  enviado_utc     DATETIME     NULL,
  aprobado_por    INT UNSIGNED NULL,
  aprobado_utc    DATETIME     NULL,
  vigente_desde   DATE         NULL,
  revisar_antes   DATE         NULL,
  obsoleta_utc    DATETIME     NULL,
  devuelta_motivo VARCHAR(255) NULL,
  vigente         INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_doc_version (documento_id, version),
  UNIQUE KEY uq_vigente (vigente),
  KEY idx_estado (estado, revisar_antes),
  CONSTRAINT fk_sgav_doc   FOREIGN KEY (documento_id)  REFERENCES sga_documento(id),
  CONSTRAINT fk_sgav_elab  FOREIGN KEY (elaborado_por) REFERENCES usuario(id),
  CONSTRAINT fk_sgav_aprob FOREIGN KEY (aprobado_por)  REFERENCES usuario(id),
  CONSTRAINT ck_sgav_vig   CHECK ((estado = 'vigente') = (vigente IS NOT NULL))
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lista de distribución del documento (a quién le llega cada versión nueva).
CREATE TABLE IF NOT EXISTS sga_destinatario (
  documento_id  INT UNSIGNED NOT NULL,
  usuario_id    INT UNSIGNED NOT NULL,
  PRIMARY KEY (documento_id, usuario_id),
  CONSTRAINT fk_sgadest_doc FOREIGN KEY (documento_id) REFERENCES sga_documento(id),
  CONSTRAINT fk_sgadest_usr FOREIGN KEY (usuario_id)   REFERENCES usuario(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Toma de conocimiento de cada versión vigente, por persona.
CREATE TABLE IF NOT EXISTS sga_distribucion (
  version_id     BIGINT UNSIGNED NOT NULL,
  usuario_id     INT UNSIGNED NOT NULL,
  asignada_utc   DATETIME     NOT NULL,
  leida_utc      DATETIME     NULL,
  registrada_por INT UNSIGNED NULL,
  PRIMARY KEY (version_id, usuario_id),
  KEY idx_usuario (usuario_id, leida_utc),
  CONSTRAINT fk_sgadis_ver FOREIGN KEY (version_id) REFERENCES sga_version(id),
  CONSTRAINT fk_sgadis_usr FOREIGN KEY (usuario_id) REFERENCES usuario(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FIN
