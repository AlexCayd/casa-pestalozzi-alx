-- Despliegue aditivo M1 para instalaciones multinegocio existentes.
-- No altera productos, ofertas, imágenes, saldos ni asignaciones de usuarios.
INSERT INTO facultades(clave,solo_global,descripcion)
VALUES ('comercial.identidad.editar',1,'Editar identidades comerciales compartidas; no concede precios locales.')
ON DUPLICATE KEY UPDATE solo_global=VALUES(solo_global),descripcion=VALUES(descripcion);
