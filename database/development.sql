-- Entorno de desarrollo multinegocio 3A.
-- Los fixtures históricos del esquema mononegocio quedan deliberadamente fuera:
-- sus IDs, campos polimórficos y existencias maestras no pertenecen al contrato.
-- Este archivo no debe correr sobre una base con datos que se quieran conservar.
-- Crear fixtures nuevos, desechables y acotados por runner, en scripts/tests.
-- No siembra usuarios, credenciales, productos ficticios ni inventario sin bitácora.
SET NAMES utf8mb4;
