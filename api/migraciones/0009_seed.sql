-- Seed del dataset real de CAMCA, extraido de docs/nuevo_desarrollo/panel_chofer.html
-- GENERADO POR tools/gen_seed_sql.mjs — no editar a mano.
--
-- 65 paradas sobre 60 sitios unicos,
-- 78 banios por semana, 202.6 km por semana.
-- 5 sitios con coordenada sospechosa y 3 clusters
-- que el GPS no puede distinguir (la app pide desambiguar a mano).

INSERT INTO base_operativa (id, nombre, direccion, lat, lon)
VALUES (1, 'Base Operativa FRAM SRL', 'Calle Centenario 1138 Este, Chimbas', -31.4776582, -68.5171013)
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon);

-- La unidad del prototipo. Es de la empresa (CAMCA y FRAM SRL son la
-- misma), asi que el rastro de flota sirve como segunda evidencia.
INSERT INTO vehiculo (patente, descripcion, activo, creado_utc)
VALUES ('AH102HY', 'Camioneta de servicio', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE descripcion=VALUES(descripcion);

-- Clientes PROVISORIOS: salen del campo "responde" del prototipo, que
-- mezcla razon social con nombre de contacto. Sin CUIT a proposito.
-- Normalizarlos (y decidir si Terusi / Terusi Costanera / Terusi UCC son
-- uno solo) es trabajo de CAMCA y es precondicion del motor de
-- facturacion, que es Objetivo 3 y va en Fase 2.
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Adaro Claudio', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Alfredo Zunino', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Arce', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Carolina Zegaib', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Claudio Marin', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Dario Naveda', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Diego Gatica', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Federico Fernadez', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Gonzalo Alcoba', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Gonzalo Zalazar', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Grazziano', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Grynszpan Daniel', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Jofre', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Jorge Fuentes', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Juan Cruz Ramos', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Juan Manuel Macchi', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Juan Vega', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Julio Hernandez', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('La Profecia', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Leopoldo Mendez', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Lozano (Martin)', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Marcos Goland', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Mathieu Guillot', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Minetech Mut', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Mut', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Nimbus', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Oliga', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Pablo', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Pablo Perez', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Pedro Varas', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Pranamar', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Rocio Zungri', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Rom', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Romera Jorge', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Romina Plana', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Rotar SAS', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Sanchez', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Sanz', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Sergio Lozano', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Sergio Lozano / Juan Fernandez', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Tassi/Cuadra', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Terusi', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Terusi Costanera', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Terusi UCC', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Toro', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Ureña Construcciones', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Varela', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Vila', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);
INSERT INTO cliente (nombre, provisorio, creado_utc) VALUES ('Zeballos Laura', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE provisorio=VALUES(provisorio);

-- Sitios. radio_m y permanencia_seg son POR SITIO (H7): un frente de
-- obra grande no es una garita, y con 50 m / 90 s fijos el auto-tildado
-- no dispara nunca en un predio grande.
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0001', 'Centro Odontológico', 'Calle Agustín Gómez', -31.5513301, -68.5110205, 50, 90, 'buena', NULL, NULL, '-31.5513301|-68.5110205|Centro Odontológico|Calle Agustín Gómez', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0002', 'Barrio Profesional', 'Rivadavia', -31.5464695, -68.5292323, 50, 90, 'buena', NULL, NULL, '-31.5464695|-68.5292323|Barrio Profesional|Rivadavia', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0003', 'Barrio Jardín Policial', 'MPC', -31.54539, -68.56323, 50, 90, 'buena', NULL, NULL, '-31.54539|-68.56323|Barrio Jardín Policial|MPC', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0004', 'Barrio Buena Esperanza', 'Lote 47', -31.5562805, -68.5851166, 50, 90, 'buena', NULL, NULL, '-31.5562805|-68.5851166|Barrio Buena Esperanza|Lote 47', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0005', 'B. San Juan de los Olivos', 'Lote 164', -31.55288, -68.59159, 50, 90, 'buena', NULL, 1, '-31.55288|-68.59159|B. San Juan de los Olivos|Lote 164', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0006', 'B. San Juan de los Olivos', 'Lote 17', -31.55288, -68.59159, 50, 90, 'buena', NULL, 1, '-31.55288|-68.59159|B. San Juan de los Olivos|Lote 17', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0007', 'San Juan de los Olivos', 'Lote 118, Rivadavia', -31.5536675, -68.594454, 50, 90, 'buena', NULL, NULL, '-31.5536675|-68.594454|San Juan de los Olivos|Lote 118, Rivadavia', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0008', 'SJ. Olivos', 'Rep. del Líbano y Rastreador Calivar', -31.54964, -68.59402, 50, 90, 'buena', NULL, NULL, '-31.54964|-68.59402|SJ. Olivos|Rep. del Líbano y Rastreador Calivar', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0009', 'Zonda', 'Lavado', -31.54461, -68.71286, 50, 90, 'buena', NULL, NULL, '-31.54461|-68.71286|Zonda|Lavado', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0010', 'Villa Tacu', 'Calle Sancassani', -31.527, -68.71272, 50, 90, 'sospechosa', 'coordenada con 3 decimales (unos 111 m de error, mayor que el radio de 50 m)', NULL, '-31.527|-68.71272|Villa Tacu|Calle Sancassani', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0011', 'Rastreador Calivar Nte.', NULL, -31.52715, -68.5951, 50, 90, 'buena', NULL, NULL, '-31.52715|-68.5951|Rastreador Calivar Nte.|', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0012', 'CAMCA', 'Rivadavia', -31.5280245, -68.5916377, 50, 90, 'buena', NULL, NULL, '-31.5280245|-68.5916377|CAMCA|Rivadavia', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0013', 'SUM UCC', 'Ignacio de la Rozas y Calivar', -31.53998, -68.58612, 50, 90, 'buena', 'la misma coordenada aparece con nombres distintos: UCC / SUM UCC', NULL, '-31.53998|-68.58612|UCC|Ignacio de la Rozas y Calivar', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0014', 'Maradona Nte', NULL, -31.50245, -68.5471, 50, 90, 'buena', NULL, NULL, '-31.50245|-68.5471|Maradona Nte|', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0015', 'Zunino', 'Sta Fe (entre Salta y España)', -31.5398153, -68.5361196, 50, 90, 'buena', NULL, NULL, '-31.5398153|-68.5361196|Zunino|Sta Fe (entre Salta y España)', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0016', 'EPET N°4', 'España (entre Córdoba y Gral Paz)', -31.5374197, -68.5303947, 50, 90, 'buena', NULL, NULL, '-31.5374197|-68.5303947|EPET N°4|España (entre Córdoba y Gral Paz)', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0017', 'Capital', 'Mendoza y Brasil', -31.54386, -68.52556, 50, 90, 'buena', NULL, NULL, '-31.54386|-68.52556|Capital|Mendoza y Brasil', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0018', 'Barrio Huarpes', 'Calle 6 entre Celani y Frías Sur', -31.59575, -68.56079, 50, 90, 'buena', NULL, NULL, '-31.59575|-68.56079|Barrio Huarpes|Calle 6 entre Celani y Frías Sur', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0019', 'Reciclados Plásticos', 'Parque Industrial de Pocito, Maurin entre 6 y 7', -31.61076, -68.53402, 50, 90, 'buena', NULL, NULL, '-31.61076|-68.53402|Reciclados Plásticos|Parque Industrial de Pocito, Maurin entre 6 y 7', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0020', 'B° Las Lomas', 'Calle 5 y Ruta 40', -31.59702, -68.52063, 50, 90, 'buena', NULL, NULL, '-31.59702|-68.52063|B° Las Lomas|Calle 5 y Ruta 40', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0021', 'Calle Remedios de Escalada y Delgado', 'Calle Remedios de Escalada y Delgado', -31.56514, -68.50022, 50, 90, 'buena', NULL, NULL, '-31.56514|-68.50022|Calle Remedios de Escalada y Delgado|Calle Remedios de Escalada y Delgado', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0022', 'Cerca del Club UVT', 'Calle Abraham Tapia', -31.56153, -68.51272, 50, 90, 'buena', NULL, NULL, '-31.56153|-68.51272|Cerca del Club UVT|Calle Abraham Tapia', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0023', 'Barrio Tierras del Este', 'Rawson, M A Casa 3', -31.55408, -68.50694, 50, 90, 'buena', NULL, NULL, '-31.55408|-68.50694|Barrio Tierras del Este|Rawson, M A Casa 3', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0024', 'Fábrica', 'Sta Lucía, Lib y Colón', -31.5335, -68.4985, 50, 90, 'buena', NULL, NULL, '-31.5335|-68.4985|Fábrica|Sta Lucía, Lib y Colón', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0025', 'Costanera y Bonduel', 'Chimbas', -31.487981, -68.56776, 50, 90, 'buena', NULL, NULL, '-31.487981|-68.56776|Costanera y Bonduel|Chimbas', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0026', 'Parador del Ciclista', 'Marquesado', -31.5234278, -68.6173137, 50, 90, 'buena', NULL, NULL, '-31.5234278|-68.6173137|Parador del Ciclista|Marquesado', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0027', 'Terrazas del Oeste', 'Marquesado', -31.52417, -68.60581, 50, 90, 'buena', NULL, NULL, '-31.52417|-68.60581|Terrazas del Oeste|Marquesado', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0028', 'Av. Lib. San Martín (O) 3901, al lado de West Padel', 'Av. Lib. San Martín (O) 3901, al lado de West Padel', -31.52845, -68.57924, 50, 90, 'buena', NULL, NULL, '-31.52845|-68.57924|Av. Lib. San Martín (O) 3901, al lado de West Padel|Av. Lib. San Martín (O) 3901, al lado de West Padel', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0029', 'Loteo La Plaza', 'Pasteur y Pasaje Posleman', -31.52321, -68.57359, 50, 90, 'buena', NULL, NULL, '-31.52321|-68.57359|Loteo La Plaza|Pasteur y Pasaje Posleman', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0030', 'Libertador', 'Libertador', -31.52871, -68.56367, 50, 90, 'buena', NULL, NULL, '-31.52871|-68.56367|Libertador|Libertador', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0031', 'Calle Girasoles y José Martí', 'Calle Girasoles y José Martí', -31.54865, -68.54596, 50, 90, 'buena', NULL, NULL, '-31.54865|-68.54596|Calle Girasoles y José Martí|Calle Girasoles y José Martí', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0032', 'Detrás Toyota', 'Detrás Toyota', -31.54837, -68.50954, 50, 90, 'buena', NULL, NULL, '-31.54837|-68.50954|Detrás Toyota|Detrás Toyota', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0033', 'Calle 9 de Julio entre Pueyrredon y Aristóbulo del Valle', 'Calle 9 de Julio entre Pueyrredon y Aristóbulo del Valle', -31.5417302, -68.5091968, 50, 90, 'buena', NULL, NULL, '-31.5417302|-68.5091968|Calle 9 de Julio entre Pueyrredon y Aristóbulo del Valle|Calle 9 de Julio entre Pueyrredon y Aristóbulo del Valle', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0034', 'Centro', 'Laprida y Tucumán', -31.53529, -68.5231058, 50, 90, 'buena', NULL, NULL, '-31.53529|-68.5231058|Centro|Laprida y Tucumán', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0035', 'Rioja y Chile', 'Rioja y Chile', -31.52643, -68.52287, 50, 90, 'buena', NULL, NULL, '-31.52643|-68.52287|Rioja y Chile|Rioja y Chile', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0036', 'Maipú al lado del club Huarpes', 'Maipú al lado del club Huarpes', -31.52909, -68.542, 50, 90, 'sospechosa', 'coordenada con 3 decimales (unos 111 m de error, mayor que el radio de 50 m)', NULL, '-31.52909|-68.542|Maipú al lado del club Huarpes|Maipú al lado del club Huarpes', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0037', '25 de Mayo y Zavalla', '25 de Mayo y Zavalla', -31.5275148, -68.54918, 50, 90, 'buena', NULL, NULL, '-31.5275148|-68.54918|25 de Mayo y Zavalla|25 de Mayo y Zavalla', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0038', 'Atrás de Todo Obra', 'Calle Corrientes s/n', -31.51301, -68.51318, 50, 90, 'buena', NULL, NULL, '-31.51301|-68.51318|Atrás de Todo Obra|Calle Corrientes s/n', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0039', 'La Profecia', 'Libertador 2337 Este', -31.5339147, -68.4931551, 50, 90, 'buena', NULL, NULL, '-31.5339147|-68.4931551|La Profecia|Libertador 2337 Este', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0040', 'Esquina Sauce', NULL, -31.54317, -68.48797, 50, 90, 'buena', NULL, NULL, '-31.54317|-68.48797|Esquina Sauce|', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0041', 'Mut', 'Cerca Yaguar', -31.55133, -68.49475, 50, 90, 'buena', NULL, NULL, '-31.55133|-68.49475|Mut|Cerca Yaguar', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0042', 'Callejón Honduras frente a Joy Park', 'Callejón Honduras frente a Joy Park', -31.56039, -68.48239, 50, 90, 'buena', NULL, NULL, '-31.56039|-68.48239|Callejón Honduras frente a Joy Park|Callejón Honduras frente a Joy Park', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0043', 'Barrio Aimara', 'Lote 45', -31.56002, -68.46282, 50, 90, 'sospechosa', 'comparte coordenada con Frente Industrias Melo, que es otro lugar: probable error de carga', 2, '-31.56002|-68.46282|Barrio Aimara|Lote 45', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0044', 'Frente Industrias Melo', 'Ruta 20', -31.56002, -68.46282, 50, 90, 'sospechosa', 'comparte coordenada con Barrio Aimara, que es otro lugar: probable error de carga', 2, '-31.56002|-68.46282|Frente Industrias Melo|Ruta 20', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0045', 'Planetario', 'SUM La Legua', -31.54807, -68.4782, 50, 90, 'buena', NULL, NULL, '-31.54807|-68.4782|Planetario|SUM La Legua', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0046', 'Barrio Pedernal', 'Balcarce 525 Sur Lote 6', -31.53769, -68.46992, 50, 90, 'buena', NULL, NULL, '-31.53769|-68.46992|Barrio Pedernal|Balcarce 525 Sur Lote 6', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0047', 'Natania XXIV', 'M C Casa 11', -31.53456, -68.47678, 50, 90, 'buena', NULL, NULL, '-31.53456|-68.47678|Natania XXIV|M C Casa 11', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0048', 'La Ernestina', 'Lote 42', -31.5275768, -68.4795418, 50, 90, 'buena', NULL, 3, '-31.5275768|-68.4795418|La Ernestina|Lote 42', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0049', 'La Ernestina', 'Lote 51', -31.5275768, -68.4795418, 50, 90, 'buena', NULL, 3, '-31.5275768|-68.4795418|La Ernestina|Lote 51', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0050', 'Minetech', 'Benavídez y Ruta 40', -31.5070171, -68.5180288, 50, 90, 'buena', NULL, NULL, '-31.5070171|-68.5180288|Minetech|Benavídez y Ruta 40', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0051', 'Centro Odontológico', 'Agustín Gómez 468 Oeste', -31.55328, -68.53031, 50, 90, 'sospechosa', 'Centro Odontológico figura en 2 coordenadas distintas: hay que verificar cual es la correcta', NULL, '-31.55328|-68.53031|Centro Odontológico|Agustín Gómez 468 Oeste', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0052', 'Frente a San Francisco Hogar', 'Boulevard y Mendoza, Rawson', -31.5840877, -68.537493, 50, 90, 'buena', NULL, NULL, '-31.5840877|-68.537493|Frente a San Francisco Hogar|Boulevard y Mendoza, Rawson', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0053', 'Barrio Privado Prados del Sur', 'Mendoza y Calle 8', -31.62283, -68.55186, 50, 90, 'buena', NULL, NULL, '-31.62283|-68.55186|Barrio Privado Prados del Sur|Mendoza y Calle 8', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0054', 'Frente a Escuela Jorge Washington', 'Lemos y 6 (esquina)', -31.59737, -68.55589, 50, 90, 'buena', NULL, NULL, '-31.59737|-68.55589|Frente a Escuela Jorge Washington|Lemos y 6 (esquina)', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0055', 'Barrio Mudap', 'Proyectada IX Mzna K Lote 8', -31.51209, -68.59091, 50, 90, 'buena', NULL, NULL, '-31.51209|-68.59091|Barrio Mudap|Proyectada IX Mzna K Lote 8', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0056', 'Parque Industrial de Chimbas', 'Lote 54', -31.50192, -68.58276, 50, 90, 'buena', NULL, NULL, '-31.50192|-68.58276|Parque Industrial de Chimbas|Lote 54', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0057', 'Verduleria Santa Cruz', 'Centenario y Ruta', -31.4788227, -68.520225, 50, 90, 'buena', NULL, NULL, '-31.4788227|-68.520225|Verduleria Santa Cruz|Centenario y Ruta', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0058', 'Kiosco', 'Al lado empresa, Centenario', -31.4787697, -68.5207646, 50, 90, 'buena', NULL, NULL, '-31.4787697|-68.5207646|Kiosco|Al lado empresa, Centenario', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0059', 'Loteo Nazareno', 'Lote A, Calle Proyectada 1', -31.50331, -68.54813, 50, 90, 'buena', NULL, NULL, '-31.50331|-68.54813|Loteo Nazareno|Lote A, Calle Proyectada 1', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);
INSERT INTO sitio (codigo, nombre, direccion, lat, lon, radio_m, permanencia_seg, geo_calidad, geo_nota, cluster_id, seed_key, activo, creado_utc) VALUES ('SIT-0060', 'Benavídez entre Angualasto y San Juan Nte', 'Benavídez entre Angualasto y San Juan Nte', -31.49851, -68.47828, 50, 90, 'buena', NULL, NULL, '-31.49851|-68.47828|Benavídez entre Angualasto y San Juan Nte|Benavídez entre Angualasto y San Juan Nte', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), direccion=VALUES(direccion), lat=VALUES(lat), lon=VALUES(lon), geo_calidad=VALUES(geo_calidad), geo_nota=VALUES(geo_nota), cluster_id=VALUES(cluster_id);

-- Plantilla de ruta semanal: lo PLANIFICADO. Un cron la expande cada
-- noche en la jornada del dia siguiente, que es lo que permite medir el
-- desvio planificado contra ejecutado (Objetivo 1).
INSERT INTO ruta_plantilla (dia_semana, nombre, color, km_estimado, activa, creado_utc) VALUES (1, 'Lunes', '#e63946', 52.8, 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), color=VALUES(color), km_estimado=VALUES(km_estimado);
INSERT INTO ruta_plantilla (dia_semana, nombre, color, km_estimado, activa, creado_utc) VALUES (2, 'Martes', '#2a9d8f', 36.5, 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), color=VALUES(color), km_estimado=VALUES(km_estimado);
INSERT INTO ruta_plantilla (dia_semana, nombre, color, km_estimado, activa, creado_utc) VALUES (3, 'Miércoles', '#457b9d', 35.2, 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), color=VALUES(color), km_estimado=VALUES(km_estimado);
INSERT INTO ruta_plantilla (dia_semana, nombre, color, km_estimado, activa, creado_utc) VALUES (4, 'Jueves', '#f4a261', 24.1, 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), color=VALUES(color), km_estimado=VALUES(km_estimado);
INSERT INTO ruta_plantilla (dia_semana, nombre, color, km_estimado, activa, creado_utc) VALUES (5, 'Viernes', '#9b5de5', 38.9, 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), color=VALUES(color), km_estimado=VALUES(km_estimado);
INSERT INTO ruta_plantilla (dia_semana, nombre, color, km_estimado, activa, creado_utc) VALUES (6, 'Sábado', '#00b4d8', 15.1, 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), color=VALUES(color), km_estimado=VALUES(km_estimado);

-- Lunes: 13 paradas, 52.8 km
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 1, s.id, 4, 'Terusi', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0001' WHERE r.dia_semana = 1
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 2, s.id, 1, 'Romera Jorge', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0002' WHERE r.dia_semana = 1
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 3, s.id, 1, NULL, 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0003' WHERE r.dia_semana = 1
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 4, s.id, 1, 'Pedro Varas', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0004' WHERE r.dia_semana = 1
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 5, s.id, 1, 'Julio Hernandez', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0005' WHERE r.dia_semana = 1
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 6, s.id, 1, 'Juan Manuel Macchi', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0006' WHERE r.dia_semana = 1
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 7, s.id, 1, 'Tassi/Cuadra', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0007' WHERE r.dia_semana = 1
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 8, s.id, 1, 'Leopoldo Mendez', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0008' WHERE r.dia_semana = 1
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 9, s.id, 1, NULL, 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0009' WHERE r.dia_semana = 1
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 10, s.id, 1, 'Rom', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0010' WHERE r.dia_semana = 1
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 11, s.id, 1, NULL, 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0011' WHERE r.dia_semana = 1
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 12, s.id, 1, 'Rotar SAS', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0012' WHERE r.dia_semana = 1
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 13, s.id, 1, 'Terusi', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0013' WHERE r.dia_semana = 1
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
-- Martes: 11 paradas, 36.5 km
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 1, s.id, 1, 'Claudio Marin', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0014' WHERE r.dia_semana = 2
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 2, s.id, 1, 'Alfredo Zunino', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0015' WHERE r.dia_semana = 2
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 3, s.id, 2, 'Terusi', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0016' WHERE r.dia_semana = 2
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 4, s.id, 1, 'Grynszpan Daniel', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0017' WHERE r.dia_semana = 2
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 5, s.id, 1, 'Marcos Goland', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0018' WHERE r.dia_semana = 2
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 6, s.id, 1, 'Sergio Lozano', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0019' WHERE r.dia_semana = 2
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 7, s.id, 1, 'Toro', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0020' WHERE r.dia_semana = 2
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 8, s.id, 1, 'Juan Cruz Ramos', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0021' WHERE r.dia_semana = 2
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 9, s.id, 1, 'Romina Plana', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0022' WHERE r.dia_semana = 2
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 10, s.id, 1, 'Adaro Claudio', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0023' WHERE r.dia_semana = 2
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 11, s.id, 1, 'La Profecia', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0024' WHERE r.dia_semana = 2
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
-- Miércoles: 14 paradas, 35.2 km
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 1, s.id, 1, 'Terusi Costanera', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0025' WHERE r.dia_semana = 3
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 2, s.id, 1, 'Dario Naveda', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0026' WHERE r.dia_semana = 3
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 3, s.id, 1, 'Gonzalo Alcoba', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0027' WHERE r.dia_semana = 3
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 4, s.id, 1, 'Jofre', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0028' WHERE r.dia_semana = 3
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 5, s.id, 1, 'Alfredo Zunino', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0029' WHERE r.dia_semana = 3
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 6, s.id, 1, 'Oliga', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0030' WHERE r.dia_semana = 3
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 7, s.id, 1, 'Sergio Lozano / Juan Fernandez', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0031' WHERE r.dia_semana = 3
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 8, s.id, 4, 'Terusi', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0001' WHERE r.dia_semana = 3
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 9, s.id, 1, 'Grazziano', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0032' WHERE r.dia_semana = 3
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 10, s.id, 1, 'Nimbus', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0033' WHERE r.dia_semana = 3
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 11, s.id, 1, 'Ureña Construcciones', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0034' WHERE r.dia_semana = 3
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 12, s.id, 1, 'Varela', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0035' WHERE r.dia_semana = 3
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 13, s.id, 1, 'Rom', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0036' WHERE r.dia_semana = 3
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 14, s.id, 1, 'Carolina Zegaib', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0037' WHERE r.dia_semana = 3
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
-- Jueves: 12 paradas, 24.1 km
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 1, s.id, 1, 'Mut', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0038' WHERE r.dia_semana = 4
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 2, s.id, 1, 'La Profecia', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0039' WHERE r.dia_semana = 4
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 3, s.id, 1, 'Mathieu Guillot', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0040' WHERE r.dia_semana = 4
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 4, s.id, 1, 'Mut', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0041' WHERE r.dia_semana = 4
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 5, s.id, 1, 'Pablo Perez', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0042' WHERE r.dia_semana = 4
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 6, s.id, 1, 'Diego Gatica', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0043' WHERE r.dia_semana = 4
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 7, s.id, 1, 'Vila', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0044' WHERE r.dia_semana = 4
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 8, s.id, 1, 'Terusi', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0045' WHERE r.dia_semana = 4
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 9, s.id, 1, 'Jorge Fuentes', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0046' WHERE r.dia_semana = 4
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 10, s.id, 1, 'Zeballos Laura', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0047' WHERE r.dia_semana = 4
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 11, s.id, 1, 'Gonzalo Zalazar', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0048' WHERE r.dia_semana = 4
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 12, s.id, 1, 'Rocio Zungri', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0049' WHERE r.dia_semana = 4
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
-- Viernes: 11 paradas, 38.9 km
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 1, s.id, 1, 'Minetech Mut', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0050' WHERE r.dia_semana = 5
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 2, s.id, 5, 'Terusi', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0016' WHERE r.dia_semana = 5
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 3, s.id, 2, 'Terusi', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0051' WHERE r.dia_semana = 5
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 4, s.id, 1, 'Lozano (Martin)', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0052' WHERE r.dia_semana = 5
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 5, s.id, 1, 'Sergio Lozano', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0019' WHERE r.dia_semana = 5
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 6, s.id, 1, 'Varela', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0053' WHERE r.dia_semana = 5
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 7, s.id, 1, 'Sanz', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0054' WHERE r.dia_semana = 5
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 8, s.id, 1, 'Terusi UCC', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0013' WHERE r.dia_semana = 5
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 9, s.id, 1, 'Federico Fernadez', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0055' WHERE r.dia_semana = 5
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 10, s.id, 2, 'Pranamar', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0056' WHERE r.dia_semana = 5
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 11, s.id, 1, 'Claudio Marin', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0014' WHERE r.dia_semana = 5
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
-- Sábado: 4 paradas, 15.1 km
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 1, s.id, 1, 'Sanchez', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0057' WHERE r.dia_semana = 6
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 2, s.id, 1, 'Pablo', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0058' WHERE r.dia_semana = 6
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 3, s.id, 1, 'Juan Vega', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0059' WHERE r.dia_semana = 6
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);
INSERT INTO parada_plantilla (ruta_id, orden, sitio_id, cantidad, responsable, activa) SELECT r.id, 4, s.id, 1, 'Arce', 1 FROM ruta_plantilla r JOIN sitio s ON s.codigo = 'SIT-0060' WHERE r.dia_semana = 6
ON DUPLICATE KEY UPDATE sitio_id=VALUES(sitio_id), cantidad=VALUES(cantidad), responsable=VALUES(responsable);

-- Enlace tentativo sitio -> cliente por el responsable mas frecuente.
-- Tentativo porque la tabla cliente es provisoria; se corrige cuando
-- CAMCA normalice quien es empresa y quien es contacto.
UPDATE sitio s JOIN (
  SELECT pp.sitio_id, MIN(c.id) AS cliente_id
    FROM parada_plantilla pp JOIN cliente c ON c.nombre = pp.responsable
   GROUP BY pp.sitio_id
) x ON x.sitio_id = s.id SET s.cliente_id = x.cliente_id WHERE s.cliente_id IS NULL;

-- FIN
