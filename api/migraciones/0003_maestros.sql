-- Datos maestros: clientes, sitios y vehiculos.

-- Los clientes del dataset vienen del campo "responde" de los prototipos, que
-- mezcla razon social con nombre de contacto ("Terusi", "Rotar SAS",
-- "Tassi/Cuadra"). Entran como PROVISORIOS y sin CUIT a proposito: normalizar
-- quien es empresa y quien es persona es trabajo de CAMCA, no de codigo, y
-- hasta que se haga esta tabla no sirve para facturar (Obj. 3, Fase 2).
CREATE TABLE IF NOT EXISTS cliente (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre       VARCHAR(160) NOT NULL,
  cuit         VARCHAR(13)  NULL,
  provisorio   TINYINT(1)   NOT NULL DEFAULT 1,
  fusionado_en INT UNSIGNED NULL,
  creado_utc   DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_nombre (nombre),
  KEY idx_cuit (cuit)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- H7 — El radio de geocerca y la permanencia son POR SITIO, no constantes
-- globales: un frente de obra de Los Azules no es una garita. El prototipo
-- usaba 50 m / 90 s para todo y en un predio grande no dispara nunca.
--
-- geo_calidad marca los sitios cuya coordenada no es confiable. La auditoria
-- del dataset encontro tres casos que un chofer tiene que corregir en campo:
--   · "Centro Odontologico" figura en dos coordenadas a 1,8 km
--   · "Barrio Aimara" y "Frente Industrias Melo" comparten coordenada exacta
--     siendo lugares distintos (Lote 45 vs Ruta 20): es un error de carga
--   · dos sitios tienen solo 3 decimales (~111 m de error, mas que el radio)
CREATE TABLE IF NOT EXISTS sitio (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo          VARCHAR(20)  NOT NULL,
  nombre          VARCHAR(160) NOT NULL,
  direccion       VARCHAR(255) NULL,
  cliente_id      INT UNSIGNED NULL,
  lat             DECIMAL(10,7) NOT NULL,
  lon             DECIMAL(10,7) NOT NULL,
  radio_m         SMALLINT UNSIGNED NOT NULL DEFAULT 50,
  permanencia_seg SMALLINT UNSIGNED NOT NULL DEFAULT 90,
  geo_calidad     ENUM('buena','sospechosa','sin_verificar') NOT NULL DEFAULT 'buena',
  geo_nota        VARCHAR(255) NULL,
  -- Sitios que comparten coordenada y que el GPS no puede distinguir.
  -- Mismo cluster = la app pide desambiguar a mano.
  cluster_id      INT UNSIGNED NULL,
  seed_key        VARCHAR(190) NULL,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  creado_utc      DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_codigo (codigo),
  KEY idx_cluster (cluster_id),
  KEY idx_geo (geo_calidad),
  CONSTRAINT fk_sitio_cliente FOREIGN KEY (cliente_id) REFERENCES cliente(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vehiculo (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  patente      VARCHAR(12)  NOT NULL,
  descripcion  VARCHAR(120) NULL,
  activo       TINYINT(1)   NOT NULL DEFAULT 1,
  creado_utc   DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_patente (patente)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inventario unitario de banios y modulos. Se deja VACIA a proposito: el alta
-- unitaria es precondicion del motor de facturacion (Obj. 3) y no de Fase 0.
CREATE TABLE IF NOT EXISTS activo (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tipo         ENUM('bano','modulo_sanitario','modulo_habitacional','garita','biodigestor') NOT NULL,
  identificador VARCHAR(40) NOT NULL,
  sitio_id     INT UNSIGNED NULL,
  creado_utc   DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_identificador (tipo, identificador),
  CONSTRAINT fk_activo_sitio FOREIGN KEY (sitio_id) REFERENCES sitio(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FIN
