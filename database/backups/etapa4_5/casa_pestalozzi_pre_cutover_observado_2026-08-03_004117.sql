-- MySQL dump 10.13  Distrib 9.7.1, for Win64 (x86_64)
--
-- Host: localhost    Database: casa-pestalozzi
-- ------------------------------------------------------
-- Server version	9.7.1

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Current Database: `casa-pestalozzi`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `casa-pestalozzi` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci */ /*!80016 DEFAULT ENCRYPTION='N' */;

USE `casa-pestalozzi`;

--
-- Table structure for table `areas_produccion`
--

DROP TABLE IF EXISTS `areas_produccion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `areas_produccion` (
  `id` tinyint unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(60) NOT NULL,
  `slug` varchar(20) NOT NULL,
  `color` varchar(10) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `areas_produccion`
--

LOCK TABLES `areas_produccion` WRITE;
/*!40000 ALTER TABLE `areas_produccion` DISABLE KEYS */;
INSERT INTO `areas_produccion` VALUES (1,'Barra de Café','cafe','#7b5e3a'),(2,'Barra de Jugos','jugos','#e8a920'),(3,'Cocina','cocina','#b03a2e'),(4,'Horno Napolitano','horno','#1a5276');
/*!40000 ALTER TABLE `areas_produccion` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `categorias`
--

DROP TABLE IF EXISTS `categorias`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `categorias` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(40) NOT NULL,
  `img` varchar(200) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `categorias`
--

LOCK TABLES `categorias` WRITE;
/*!40000 ALTER TABLE `categorias` DISABLE KEYS */;
INSERT INTO `categorias` VALUES (1,'Desayunos','build/images/comida-4.webp',1),(2,'Entradas','build/images/comida-9.webp',1),(3,'Sopas & Cremas','build/images/comida-7.webp',1),(4,'Pastas','build/images/mejor-2.webp',1),(5,'Platos Fuertes','build/images/mejor-6.webp',1),(6,'Ensaladas','build/images/comida-2.webp',1),(7,'Pizzas','build/images/pizza-3.webp',1),(8,'Para Picar','build/images/comida-6.webp',1),(9,'Café & Bebidas','build/images/comida-1.webp',1),(10,'Jugos & Smoothies','build/images/comida-2.webp',1);
/*!40000 ALTER TABLE `categorias` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `configuracion_anuncio`
--

DROP TABLE IF EXISTS `configuracion_anuncio`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `configuracion_anuncio` (
  `id` tinyint unsigned NOT NULL,
  `mensaje` varchar(255) NOT NULL DEFAULT '',
  `tipo` enum('evento','promocion','novedad_menu','aviso_operativo') NOT NULL DEFAULT 'evento',
  `activo` tinyint(1) NOT NULL DEFAULT '0',
  `fecha_inicio` datetime DEFAULT NULL,
  `fecha_fin` datetime DEFAULT NULL,
  `texto_enlace` varchar(80) DEFAULT NULL,
  `url_enlace` varchar(500) DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_configuracion_anuncio_usuario` (`updated_by`),
  CONSTRAINT `fk_configuracion_anuncio_usuario` FOREIGN KEY (`updated_by`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `configuracion_anuncio`
--

LOCK TABLES `configuracion_anuncio` WRITE;
/*!40000 ALTER TABLE `configuracion_anuncio` DISABLE KEYS */;
INSERT INTO `configuracion_anuncio` VALUES (1,'Test','evento',0,NULL,NULL,NULL,NULL,NULL,'2026-08-03 06:27:25');
/*!40000 ALTER TABLE `configuracion_anuncio` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `excepciones_operacion`
--

DROP TABLE IF EXISTS `excepciones_operacion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `excepciones_operacion` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `fecha` date NOT NULL,
  `tipo` enum('cerrado','horario_especial') NOT NULL,
  `motivo` varchar(160) DEFAULT NULL,
  `hora_apertura` time DEFAULT NULL,
  `hora_cierre` time DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `updated_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_excepciones_operacion_fecha` (`fecha`),
  KEY `fk_excepciones_operacion_usuario` (`updated_by`),
  KEY `idx_excepciones_fecha_activo` (`fecha`,`activo`),
  CONSTRAINT `fk_excepciones_operacion_usuario` FOREIGN KEY (`updated_by`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `excepciones_operacion`
--

LOCK TABLES `excepciones_operacion` WRITE;
/*!40000 ALTER TABLE `excepciones_operacion` DISABLE KEYS */;
INSERT INTO `excepciones_operacion` VALUES (1,'2026-11-29','cerrado','Cierre de prueba',NULL,NULL,1,NULL,'2026-08-03 06:27:25',NULL),(2,'2026-12-02','horario_especial','Horario especial de prueba','14:00:00','21:00:00',1,NULL,'2026-08-03 06:27:25',NULL);
/*!40000 ALTER TABLE `excepciones_operacion` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `feedback`
--

DROP TABLE IF EXISTS `feedback`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `feedback` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `token_id` int unsigned DEFAULT NULL,
  `ticket_id` int DEFAULT NULL,
  `calidad_sabor` tinyint unsigned NOT NULL,
  `atencion_mesero` tinyint unsigned NOT NULL,
  `tiempo_espera` tinyint unsigned NOT NULL,
  `experiencia_global` tinyint unsigned NOT NULL,
  `comentario` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `token_id` (`token_id`),
  CONSTRAINT `feedback_ibfk_1` FOREIGN KEY (`token_id`) REFERENCES `feedback_tokens` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=73 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `feedback`
--

LOCK TABLES `feedback` WRITE;
/*!40000 ALTER TABLE `feedback` DISABLE KEYS */;
INSERT INTO `feedback` VALUES (1,NULL,1,5,5,4,5,'Todo excelente, el salmón estaba delicioso y el servicio muy atento.','2026-06-18 20:50:00'),(2,NULL,2,4,5,3,4,'Muy rica la comida, aunque tardó un poco en llegar.','2026-06-18 21:30:00'),(3,NULL,3,5,4,4,5,'Celebramos un cumpleaños y quedamos encantados. Volveremos.','2026-06-19 04:00:00'),(4,NULL,5,5,5,5,5,'Experiencia impecable de principio a fin. El filete, espectacular.','2026-06-19 04:15:00'),(5,NULL,8,3,4,2,3,'La hamburguesa buena, pero esperamos demasiado por la cuenta.','2026-06-18 22:10:00'),(6,NULL,2,4,4,4,4,'Buen ambiente y sazón. Repetiría el jugo verde.','2026-06-18 21:35:00'),(7,NULL,1,5,5,5,5,'El mejor desayuno de la Del Valle, sin duda.','2026-06-18 20:55:00'),(8,NULL,3,2,3,2,2,'La pizza llegó fría y tardaron en atendernos.','2026-06-19 04:05:00'),(9,NULL,3,2,4,3,3,'La Pizza Milano llegó tibia; de sabor bien, pero fría le resta mucho.','2026-06-20 03:40:00'),(10,NULL,5,3,4,3,3,'El filete pedido término medio llegó casi bien cocido. La próxima lo cuidaré.','2026-06-20 03:55:00'),(11,NULL,NULL,2,3,3,2,'Los chilaquiles llegaron aguados y el huevo frío. Esperábamos más.','2026-06-20 16:15:00'),(12,NULL,3,5,5,4,5,'El Rib Eye estaba en su punto perfecto, jugoso y caliente. Excelente cocina.','2026-06-21 03:10:00'),(13,NULL,8,4,4,2,3,'Comida muy buena, pero esperamos casi 20 minutos para que trajeran la cuenta.','2026-06-19 22:20:00'),(14,NULL,2,4,5,2,3,'Todo rico, aunque cobrar con tarjeta tomó mucho tiempo. La terminal fallaba.','2026-06-20 21:05:00'),(15,NULL,NULL,5,5,2,4,'Amamos el lugar, pero el cierre de cuenta en hora pico es eterno.','2026-06-20 21:40:00'),(16,NULL,NULL,4,3,2,3,'Sábado lleno: la comida tardó más de 40 minutos en salir.','2026-06-21 03:30:00'),(17,NULL,NULL,4,4,2,3,'Rico todo, pero tardaron mucho en tomarnos la orden al inicio.','2026-06-21 20:10:00'),(18,NULL,2,3,2,3,3,'Tuvimos que pedir el agua y los cubiertos dos veces. Faltó seguimiento a la mesa.','2026-06-21 20:25:00'),(19,NULL,5,5,5,5,5,'Ricardo, nuestro mesero, fue atentísimo y nos recomendó de maravilla. ¡Un lujo!','2026-06-22 03:15:00'),(20,NULL,NULL,4,2,4,3,'La comida bien, pero el mesero se veía saturado y algo cortante.','2026-06-22 21:30:00'),(21,NULL,1,5,5,5,5,'Nos reconocieron como clientes frecuentes y hasta una cortesía nos dieron. ¡Gracias!','2026-06-22 15:40:00'),(22,NULL,NULL,5,5,4,4,'Comida deliciosa, pero la música estaba tan alta que costaba conversar.','2026-06-23 03:50:00'),(23,NULL,NULL,4,4,4,3,'Buen lugar, aunque el aire acondicionado estaba muy frío en la zona del ventanal.','2026-06-23 20:20:00'),(24,NULL,3,4,5,4,5,'La terraza es hermosa y muy tranquila para comer en familia.','2026-06-23 21:10:00'),(25,NULL,NULL,4,4,4,3,'La comida bien, pero los baños necesitaban atención a media tarde.','2026-06-23 23:05:00'),(26,NULL,NULL,4,4,4,3,'Sabor bueno, pero las porciones se me hicieron chicas para el precio.','2026-06-24 20:45:00'),(27,NULL,8,5,5,4,5,'La Hamburguesa de la Casa vale cada peso. Enorme y sabrosa.','2026-06-24 21:20:00'),(28,NULL,1,3,4,4,3,'El cappuccino llegó tibio y sin mucha espuma. El desayuno sí muy rico.','2026-06-24 15:30:00'),(29,NULL,2,5,4,4,5,'El jugo verde y el café de olla, espectaculares. Mi desayuno favorito.','2026-06-25 15:15:00'),(30,NULL,NULL,4,5,4,4,'Todo muy rico, pero me gustaría ver más opciones vegetarianas y sin gluten.','2026-06-25 20:35:00'),(31,NULL,NULL,5,5,4,4,'La comida excelente; ojalá tuvieran más variedad de postres.','2026-06-26 03:25:00'),(32,NULL,NULL,3,3,3,2,'Nos trajeron un platillo equivocado y tuvimos que esperar a que lo corrigieran.','2026-06-26 21:00:00'),(33,NULL,NULL,5,4,3,4,'Reservamos pero la mesa no estaba lista a la hora acordada. Lo demás, muy bien.','2026-06-27 03:10:00'),(34,NULL,3,5,5,5,5,'Fuimos con niños y los atendieron increíble. Menú infantil por favor.','2026-06-27 20:15:00'),(35,NULL,5,5,5,4,5,'Festejamos un aniversario y todo fue perfecto. El postre de cortesía, un detallazo.','2026-06-28 03:40:00'),(36,NULL,NULL,5,5,5,5,'De lo mejor de la Del Valle. Volveremos muy pronto, todo impecable.','2026-06-28 21:30:00'),(37,NULL,1,5,5,4,5,'Todo excelente, el salmón estaba delicioso y el servicio muy atento.','2026-06-18 20:50:00'),(38,NULL,2,4,5,3,4,'Muy rica la comida, aunque tardó un poco en llegar.','2026-06-18 21:30:00'),(39,NULL,3,5,4,4,5,'Celebramos un cumpleaños y quedamos encantados. Volveremos.','2026-06-19 04:00:00'),(40,NULL,5,5,5,5,5,'Experiencia impecable de principio a fin. El filete, espectacular.','2026-06-19 04:15:00'),(41,NULL,8,3,4,2,3,'La hamburguesa buena, pero esperamos demasiado por la cuenta.','2026-06-18 22:10:00'),(42,NULL,2,4,4,4,4,'Buen ambiente y sazón. Repetiría el jugo verde.','2026-06-18 21:35:00'),(43,NULL,1,5,5,5,5,'El mejor desayuno de la Del Valle, sin duda.','2026-06-18 20:55:00'),(44,NULL,3,2,3,2,2,'La pizza llegó fría y tardaron en atendernos.','2026-06-19 04:05:00'),(45,NULL,3,2,4,3,3,'La Pizza Milano llegó tibia; de sabor bien, pero fría le resta mucho.','2026-06-20 03:40:00'),(46,NULL,5,3,4,3,3,'El filete pedido término medio llegó casi bien cocido. La próxima lo cuidaré.','2026-06-20 03:55:00'),(47,NULL,NULL,2,3,3,2,'Los chilaquiles llegaron aguados y el huevo frío. Esperábamos más.','2026-06-20 16:15:00'),(48,NULL,3,5,5,4,5,'El Rib Eye estaba en su punto perfecto, jugoso y caliente. Excelente cocina.','2026-06-21 03:10:00'),(49,NULL,8,4,4,2,3,'Comida muy buena, pero esperamos casi 20 minutos para que trajeran la cuenta.','2026-06-19 22:20:00'),(50,NULL,2,4,5,2,3,'Todo rico, aunque cobrar con tarjeta tomó mucho tiempo. La terminal fallaba.','2026-06-20 21:05:00'),(51,NULL,NULL,5,5,2,4,'Amamos el lugar, pero el cierre de cuenta en hora pico es eterno.','2026-06-20 21:40:00'),(52,NULL,NULL,4,3,2,3,'Sábado lleno: la comida tardó más de 40 minutos en salir.','2026-06-21 03:30:00'),(53,NULL,NULL,4,4,2,3,'Rico todo, pero tardaron mucho en tomarnos la orden al inicio.','2026-06-21 20:10:00'),(54,NULL,2,3,2,3,3,'Tuvimos que pedir el agua y los cubiertos dos veces. Faltó seguimiento a la mesa.','2026-06-21 20:25:00'),(55,NULL,5,5,5,5,5,'Ricardo, nuestro mesero, fue atentísimo y nos recomendó de maravilla. ¡Un lujo!','2026-06-22 03:15:00'),(56,NULL,NULL,4,2,4,3,'La comida bien, pero el mesero se veía saturado y algo cortante.','2026-06-22 21:30:00'),(57,NULL,1,5,5,5,5,'Nos reconocieron como clientes frecuentes y hasta una cortesía nos dieron. ¡Gracias!','2026-06-22 15:40:00'),(58,NULL,NULL,5,5,4,4,'Comida deliciosa, pero la música estaba tan alta que costaba conversar.','2026-06-23 03:50:00'),(59,NULL,NULL,4,4,4,3,'Buen lugar, aunque el aire acondicionado estaba muy frío en la zona del ventanal.','2026-06-23 20:20:00'),(60,NULL,3,4,5,4,5,'La terraza es hermosa y muy tranquila para comer en familia.','2026-06-23 21:10:00'),(61,NULL,NULL,4,4,4,3,'La comida bien, pero los baños necesitaban atención a media tarde.','2026-06-23 23:05:00'),(62,NULL,NULL,4,4,4,3,'Sabor bueno, pero las porciones se me hicieron chicas para el precio.','2026-06-24 20:45:00'),(63,NULL,8,5,5,4,5,'La Hamburguesa de la Casa vale cada peso. Enorme y sabrosa.','2026-06-24 21:20:00'),(64,NULL,1,3,4,4,3,'El cappuccino llegó tibio y sin mucha espuma. El desayuno sí muy rico.','2026-06-24 15:30:00'),(65,NULL,2,5,4,4,5,'El jugo verde y el café de olla, espectaculares. Mi desayuno favorito.','2026-06-25 15:15:00'),(66,NULL,NULL,4,5,4,4,'Todo muy rico, pero me gustaría ver más opciones vegetarianas y sin gluten.','2026-06-25 20:35:00'),(67,NULL,NULL,5,5,4,4,'La comida excelente; ojalá tuvieran más variedad de postres.','2026-06-26 03:25:00'),(68,NULL,NULL,3,3,3,2,'Nos trajeron un platillo equivocado y tuvimos que esperar a que lo corrigieran.','2026-06-26 21:00:00'),(69,NULL,NULL,5,4,3,4,'Reservamos pero la mesa no estaba lista a la hora acordada. Lo demás, muy bien.','2026-06-27 03:10:00'),(70,NULL,3,5,5,5,5,'Fuimos con niños y los atendieron increíble. Menú infantil por favor.','2026-06-27 20:15:00'),(71,NULL,5,5,5,4,5,'Festejamos un aniversario y todo fue perfecto. El postre de cortesía, un detallazo.','2026-06-28 03:40:00'),(72,NULL,NULL,5,5,5,5,'De lo mejor de la Del Valle. Volveremos muy pronto, todo impecable.','2026-06-28 21:30:00');
/*!40000 ALTER TABLE `feedback` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `feedback_tokens`
--

DROP TABLE IF EXISTS `feedback_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `feedback_tokens` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ticket_id` int NOT NULL,
  `token` varchar(64) NOT NULL,
  `usado` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  UNIQUE KEY `uq_feedback_token_ticket` (`ticket_id`),
  CONSTRAINT `feedback_tokens_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `feedback_tokens`
--

LOCK TABLES `feedback_tokens` WRITE;
/*!40000 ALTER TABLE `feedback_tokens` DISABLE KEYS */;
INSERT INTO `feedback_tokens` VALUES (1,126,'5b775d0637b63b27787d5142bdeaed4f',0,'2026-08-03 06:29:04'),(2,125,'682e2622417ece77e2d499531ac328cc',0,'2026-08-03 06:29:08'),(3,127,'0de050964efdc5fdebf64733f5f1a4e7',0,'2026-08-03 06:29:14');
/*!40000 ALTER TABLE `feedback_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `gastos_fijos`
--

DROP TABLE IF EXISTS `gastos_fijos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `gastos_fijos` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(120) NOT NULL,
  `categoria` enum('renta','servicios','nomina','insumos','otros') NOT NULL DEFAULT 'otros',
  `monto` decimal(12,2) NOT NULL DEFAULT '0.00',
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gastos_fijos`
--

LOCK TABLES `gastos_fijos` WRITE;
/*!40000 ALTER TABLE `gastos_fijos` DISABLE KEYS */;
INSERT INTO `gastos_fijos` VALUES (1,'Renta del local','renta',45000.00,1,'2026-08-03 06:27:26','2026-08-03 06:27:26'),(2,'Luz (CFE)','servicios',8000.00,1,'2026-08-03 06:27:26','2026-08-03 06:27:26'),(3,'Agua','servicios',2500.00,1,'2026-08-03 06:27:26','2026-08-03 06:27:26'),(4,'Gas','servicios',4000.00,1,'2026-08-03 06:27:26','2026-08-03 06:27:26'),(5,'Internet y teléfono','servicios',1200.00,1,'2026-08-03 06:27:26','2026-08-03 06:27:26'),(6,'Nómina','nomina',60000.00,1,'2026-08-03 06:27:26','2026-08-03 06:27:26'),(7,'Renta del local','renta',45000.00,1,'2026-08-03 06:27:26','2026-08-03 06:27:26'),(8,'Luz (CFE)','servicios',8000.00,1,'2026-08-03 06:27:26','2026-08-03 06:27:26'),(9,'Agua','servicios',2500.00,1,'2026-08-03 06:27:26','2026-08-03 06:27:26'),(10,'Gas','servicios',4000.00,1,'2026-08-03 06:27:26','2026-08-03 06:27:26'),(11,'Internet y teléfono','servicios',1200.00,1,'2026-08-03 06:27:26','2026-08-03 06:27:26'),(12,'Nómina','nomina',60000.00,1,'2026-08-03 06:27:26','2026-08-03 06:27:26');
/*!40000 ALTER TABLE `gastos_fijos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `horarios_operacion`
--

DROP TABLE IF EXISTS `horarios_operacion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `horarios_operacion` (
  `id` tinyint unsigned NOT NULL AUTO_INCREMENT,
  `dia_semana` tinyint unsigned NOT NULL,
  `abierto` tinyint(1) NOT NULL DEFAULT '1',
  `hora_apertura` time DEFAULT NULL,
  `hora_cierre` time DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_horarios_operacion_dia` (`dia_semana`),
  KEY `fk_horarios_operacion_usuario` (`updated_by`),
  CONSTRAINT `fk_horarios_operacion_usuario` FOREIGN KEY (`updated_by`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `horarios_operacion`
--

LOCK TABLES `horarios_operacion` WRITE;
/*!40000 ALTER TABLE `horarios_operacion` DISABLE KEYS */;
INSERT INTO `horarios_operacion` VALUES (1,0,1,'08:30:00','19:00:00',1,'2026-08-03 06:28:18'),(2,1,1,'00:00:00','22:00:00',1,'2026-08-03 06:28:18'),(3,2,1,'08:30:00','22:00:00',1,'2026-08-03 06:28:18'),(4,3,1,'08:30:00','22:00:00',1,'2026-08-03 06:28:18'),(5,4,1,'08:30:00','22:00:00',1,'2026-08-03 06:28:18'),(6,5,1,'08:30:00','22:00:00',1,'2026-08-03 06:28:18'),(7,6,1,'08:30:00','22:00:00',1,'2026-08-03 06:28:18');
/*!40000 ALTER TABLE `horarios_operacion` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `impresoras`
--

DROP TABLE IF EXISTS `impresoras`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `impresoras` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(60) NOT NULL,
  `area_id` tinyint unsigned DEFAULT NULL,
  `rol` enum('comanda','cuenta') NOT NULL DEFAULT 'comanda',
  `conexion` enum('red','windows') NOT NULL DEFAULT 'red',
  `host` varchar(64) NOT NULL,
  `puerto` int NOT NULL DEFAULT '9100',
  `dispositivo` varchar(120) DEFAULT NULL,
  `ancho` tinyint NOT NULL DEFAULT '48',
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `area_id` (`area_id`),
  CONSTRAINT `impresoras_ibfk_1` FOREIGN KEY (`area_id`) REFERENCES `areas_produccion` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `impresoras`
--

LOCK TABLES `impresoras` WRITE;
/*!40000 ALTER TABLE `impresoras` DISABLE KEYS */;
/*!40000 ALTER TABLE `impresoras` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ingredientes`
--

DROP TABLE IF EXISTS `ingredientes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ingredientes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(120) NOT NULL,
  `unidad` enum('g','kg','ml','l','pza') NOT NULL DEFAULT 'g',
  `stock` decimal(12,3) NOT NULL DEFAULT '0.000',
  `stock_minimo` decimal(12,3) NOT NULL DEFAULT '0.000',
  `costo` decimal(10,4) NOT NULL DEFAULT '0.0000',
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ingredientes`
--

LOCK TABLES `ingredientes` WRITE;
/*!40000 ALTER TABLE `ingredientes` DISABLE KEYS */;
INSERT INTO `ingredientes` VALUES (1,'Café molido','g',5000.000,500.000,0.3000,1,'2026-08-03 06:27:26','2026-08-03 06:27:26'),(2,'Agua','ml',100000.000,5000.000,0.0001,1,'2026-08-03 06:27:26','2026-08-03 06:27:26'),(3,'Leche','ml',2100.000,3000.000,0.0250,1,'2026-08-03 06:27:26','2026-08-03 06:27:26'),(4,'Chocolate en polvo','g',250.000,400.000,0.2000,1,'2026-08-03 06:27:26','2026-08-03 06:27:26'),(5,'Azúcar','g',8000.000,800.000,0.0300,1,'2026-08-03 06:27:26','2026-08-03 06:27:26'),(6,'Canela','g',30.000,50.000,0.5000,1,'2026-08-03 06:27:26','2026-08-03 06:27:26'),(7,'Piloncillo','g',3000.000,300.000,0.0600,1,'2026-08-03 06:27:26','2026-08-03 06:27:26'),(8,'Fruta de temporada','g',420.000,600.000,0.0400,1,'2026-08-03 06:27:26','2026-08-03 06:27:26'),(9,'Hielo','g',20000.000,1000.000,0.0050,1,'2026-08-03 06:27:26','2026-08-03 06:27:26');
/*!40000 ALTER TABLE `ingredientes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `menu`
--

DROP TABLE IF EXISTS `menu`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `menu` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(120) NOT NULL,
  `descripcion` text NOT NULL,
  `precio` decimal(10,2) NOT NULL,
  `tag` varchar(60) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `categoria_id` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_menu_nombre` (`nombre`),
  KEY `categoria_id` (`categoria_id`),
  CONSTRAINT `menu_ibfk_1` FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=131 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `menu`
--

LOCK TABLES `menu` WRITE;
/*!40000 ALTER TABLE `menu` DISABLE KEYS */;
INSERT INTO `menu` VALUES (1,'Enmoladas','Rellenas de pollo (70 gr.) con láminas de plátano macho, crema, queso y aros de cebolla bañadas en mole negro de Oaxaca.',240.00,'Especialidad C.P.',1,1),(2,'Enchiladas Suizas','Enchiladas verdes rellenas de pollo (70 gr.), gratinadas con queso gouda, crema y aros de cebolla.',220.00,NULL,1,1),(3,'Cecina y Huevo con Chorizo','Cecina (130 gr.), huevos revueltos (2 pzas) con chorizo, acompañados de frijoles refritos con queso.',220.00,NULL,1,1),(4,'Cazuela Cascabel','3 huevos estrellados o revueltos en salsa de chile cascabel, queso oaxaca gratinado, aguacate y una rebanada de pan hogaza.',220.00,NULL,1,1),(5,'Sopes con Cecina o Arrachera','3 sopes hechos a mano con frijoles, lechuga, crema, queso y cecina (130 gr.). Cambio de proteína con arrachera (150 gr.) +$40.',220.00,NULL,1,1),(6,'Enfrijoladas','Rellenas de huevo revuelto, bañadas con salsa de frijol, chorizo, crema y queso.',220.00,NULL,1,1),(7,'Huevos al Parmesano','2 huevos estrellados acompañados con espárragos blanqueados, arúgula, tocino y parmesano rallado.',210.00,'Brunch',1,1),(8,'Omelette Fitness','Claras de huevo (2 pzas), espinaca, queso de cabra y láminas de aguacate.',190.00,NULL,1,1),(9,'Toast de Salmón Ahumado','Pan brioche, crema ácida, salmón ahumado (70 gr.), ajonjolí, 1 huevo estrellado, espárragos y aguacate.',230.00,'Estrella',1,1),(10,'Pan Francés Estilo C.P.','Base de pan brioche con crema dulce, frutos rojos y miel de maple.',210.00,'Dulce',1,1),(11,'Huevos Módena','2 huevos revueltos o estrellados con tocino, queso parmesano y arúgula.',190.00,NULL,1,1),(12,'Huevos Italianos','2 huevos en omelette, jamón serrano, láminas de queso parmesano y arúgula.',190.00,NULL,1,1),(13,'Huevos Pamplona','2 huevos en omelette con chorizo español de pamplona, arúgula y queso mozarella fresco.',190.00,NULL,1,1),(14,'Huevos al Sano','2 huevos en omelette con jamón de pavo, arúgula, queso mozarella fresco y jitomate cherry.',190.00,NULL,1,1),(15,'Huevos al Gusto','Rancheros, a la mexicana, divorciados, al albañil, con tocino, con chorizo o con jamón.',180.00,NULL,1,1),(16,'Molletes','4 piezas de pan baguette con frijoles y queso manchego, acompañado de pico de gallo.',100.00,NULL,1,1),(17,'Casa Pestalozzi','½ orden de chilaquiles (40 gr.) con salsa al gusto, crema, queso y 2 huevos revueltos.',180.00,NULL,1,1),(18,'Chilaquiles','Verdes, rojos o salsa de la casa, con pollo (30 gr.) o huevo (1 pza), queso, crema y cebolla morada. Con arrachera +$90 · con cecina +$65.',180.00,NULL,1,1),(19,'Baguette de Jamón Serrano','Jamón serrano, láminas de parmesano, casse de jitomate y arúgula.',220.00,NULL,1,1),(20,'Baguette de Magret de Pollo','Pollo a la plancha con queso gouda, rodajas de jitomate, mix de lechuga y aderezo cipriani.',220.00,NULL,1,1),(21,'Baguette con Arrachera','Arrachera (150 gr.), cremoso de aguacate con un toque de chipotle y mix de lechugas.',230.00,NULL,1,1),(22,'Croissant con Jamón de Pavo','Pechuga de pavo (120 gr.), queso gouda, aderezo cipriani, jitomate y mix de lechugas.',165.00,NULL,1,1),(23,'Croissant con Huevo y Estragón','2 pzas de huevo revuelto con estragón y mix de lechugas.',140.00,NULL,1,1),(24,'Baguette de Cochinita','Cochinita (150 gr.), cebolla encurtida y habanero.',210.00,NULL,1,1),(25,'Plato de Fruta Mixta','Fruta de temporada.',110.00,NULL,1,1),(26,'Copa Antioxidante','Fresa, frambuesa, mora y zarzamora con yogurt y granola hecha en casa.',130.00,NULL,1,1),(27,'Aros de Calamar','Empanizados, aderezo cipriani, chiles cuaresmeños y limón eureka.',210.00,'Especialidad C.P.',1,2),(28,'Tostadas de Atún','3 tostaditas con cubos de atún marinado en salsa oriental, cremoso de aguacate y poro.',195.00,'Especialidad C.P.',1,2),(29,'Torreta de Salmón','Salmón ahumado, queso cabra, aguacate, jitomate con aderezo de pesto de albahaca.',220.00,'Especialidad C.P.',1,2),(30,'Tiradito de Atún','Láminas de atún, aceite de chile, mayonesa spicy, toronja y eneldo.',210.00,NULL,1,2),(31,'Carpaccio de Salmón','Finas láminas de salmón ahumado, arúgula, queso parmesano, alcaparras, limón eureka y jitomate cherry.',180.00,NULL,1,2),(32,'Camarones al Ajillo','Salteados al olivo, ajo, peperoncino con pan de baguette.',210.00,NULL,1,2),(33,'Espárragos al Horno','Queso gouda, tocino con reducción de balsámico.',180.00,NULL,1,2),(34,'Queso Burrata con Jitomates Cherrys','Queso burrata con jitomates cherrys al horno, aceite de oliva, poro y hojas de albahaca.',210.00,NULL,1,2),(35,'Crema del Día','Nuestras cremas y sopas son elaboradas por temporada y en nuestros especiales de fin de semana. Pregunta al mesero por la opción del día.',180.00,'Temporada',1,3),(36,'Sopa Especial de Fin de Semana','Receta de la casa, elaborada con ingredientes frescos de temporada. Disponible sábados y domingos.',180.00,'Fin de semana',1,3),(37,'Fetuccini a los Cuatro Quesos y Camarones','Queso brie, parmesano, queso crema y queso gouda.',280.00,'Especialidad C.P.',1,4),(38,'Lasagna de Filete de Res','Cocción a baja temperatura por 3 horas con ingredientes 100% italianos.',280.00,'Especialidad C.P.',1,4),(39,'Rigatoni al Limón con Camarones y Parmesano','Camarones salteados con vino blanco, mantequilla, ralladura de limón eureka y toque de albahaca.',280.00,'Estrella',1,4),(40,'Spaguetti a l\'Arrabbiata con Camarones y Parmesano','Salsa de pomodoro con peperoncino.',280.00,NULL,1,4),(41,'Spaguetti a la Boloñesa','Cocción a baja temperatura por 3 horas con ingredientes 100% italianos.',280.00,NULL,1,4),(42,'Spaguetti al Pomodoro y Parmesano','Pasta, salsa de jitomate y parmesano.',190.00,NULL,1,4),(43,'Filete de Res en su Jugo','Filete de res importado en su jugo con puré de papa rústico y espárragos al horno.',320.00,'Especialidad C.P.',1,5),(44,'Salmón al Horno','Salmón noruego sazonado con ajo y aceite de oliva. Acompaña con media orden de pasta o ensalada.',295.00,NULL,1,5),(45,'Hamburguesa de la Casa','Carne wagyu, pan brioche hecho en C.P., cebolla caramelizada, queso cheddar, mayonesa ahumada, pepinillo encurtido. Acompaña con papas gajo.',260.00,'Especialidad C.P.',1,5),(46,'Atún Sellado','Atún importado, sellado en costra de pistache, aderezo cipriani. Acompaña con mix de lechugas.',285.00,NULL,1,5),(47,'Tacos de Cochinita','Tres tacos de tortilla de maíz hechas a mano, frijol, cebolla y habanero encurtido.',210.00,'Especialidad C.P.',1,5),(48,'Tacos de Vacío','Vacío importado, tortillas hechas a mano, salsa de piña con habanero y aguacate.',210.00,NULL,1,5),(49,'Tacos de Camarón Rebozados','Tres tortillas de harina, camarones rebozados, col morada y aderezo de chipotle.',240.00,NULL,1,5),(50,'Vacío en Escalopas','Vacío importado en escalopas, arúgula, láminas de parmesano y reducción de bálsamico.',280.00,'Especialidad C.P.',1,5),(51,'New York (450 grs.)','Carne calidad choice angus, cebollitas asadas, chiles toreados y papas a la francesa.',785.00,'Premium',1,5),(52,'Rib Eye (450 grs.)','Carne calidad choice angus, cebollitas asadas, chiles toreados y papas a la francesa.',785.00,'Premium',1,5),(53,'Frutos Rojos','Mix de lechugas, frambuesas, zarzamoras, fresas, queso cabra, nuez y reducción de balsámico.',210.00,NULL,1,6),(54,'Ciruela Betabel','Mix de lechugas, ciruela y betabel sazonado con estragón, queso burrata y almendras horneadas.',210.00,'Especialidad C.P.',1,6),(55,'Magret de Pollo','Pechuga de pollo prensada, lechuga baby asada, almendras horneadas con aderezo de queso.',210.00,'Especialidad C.P.',1,6),(56,'Jamón Serrano con Perlas de Melón','Mix de lechugas, perlas de melón, jamón serrano, nuez y reducción de balsámico.',210.00,NULL,1,6),(57,'Pasta Corta con Pollo','Mix de lechuga con cremoso de aguacate y almendras horneadas.',210.00,NULL,1,6),(58,'Margarita','Pomodoro, mozzarella y albahaca.',190.00,NULL,1,7),(59,'Burrata','Pomodoro, burrata, prosciutto y arúgula.',260.00,'Favorita',1,7),(60,'Milano','Pomodoro, mozzarella, jitomates cherrys, salami y láminas de parmesano.',260.00,NULL,1,7),(61,'Camarones a los 4 Quesos','Salsa de 4 quesos, queso mozzarella y camarones.',260.00,NULL,1,7),(62,'Mix de 3 Brusquetas','Jamón serrano, queso brie, anchoas.',160.00,'3 piezas',1,8),(63,'Aceitunas Temperadas con Aceite de Chile','Aceitunas verdes en aceite de chiles.',160.00,NULL,1,8),(64,'Tabla Mixta','Queso parmesano, brie, manchego, chorizo salamanca, semillas, frutos rojos.',320.00,NULL,1,8),(65,'Papas a la Francesa con Parmesano','Papas a la francesa con queso parmesano rallado.',160.00,NULL,1,8);
/*!40000 ALTER TABLE `menu` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mesas`
--

DROP TABLE IF EXISTS `mesas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `mesas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `numero` int NOT NULL,
  `nombre` varchar(60) NOT NULL,
  `tipo` enum('mesa','barra','especial') NOT NULL DEFAULT 'mesa',
  `capacidad` int NOT NULL DEFAULT '4',
  `pos_x` decimal(5,2) NOT NULL DEFAULT '0.00',
  `pos_y` decimal(5,2) NOT NULL DEFAULT '0.00',
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `reservable` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `numero` (`numero`)
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mesas`
--

LOCK TABLES `mesas` WRITE;
/*!40000 ALTER TABLE `mesas` DISABLE KEYS */;
INSERT INTO `mesas` VALUES (1,1,'Mesa 1','mesa',4,29.00,88.00,1,1),(2,2,'Mesa 2','mesa',4,8.00,70.00,1,1),(3,3,'Mesa 3','mesa',4,29.00,51.00,1,1),(4,4,'Mesa 4','mesa',4,8.00,51.00,1,1),(5,5,'Mesa 5','mesa',4,8.00,29.00,1,1),(6,6,'Mesa 6','mesa',4,45.00,29.00,1,1),(7,7,'Mesa 7','mesa',4,83.00,29.00,1,1),(8,8,'Mesa 8','mesa',4,83.00,8.00,1,1),(9,9,'Mesa 9','mesa',4,54.00,8.00,1,1),(10,10,'Mesa 10','mesa',4,29.00,8.00,1,1),(11,11,'Mesa 11','mesa',4,8.00,8.00,1,1),(12,12,'Barra Blanca','barra',8,62.00,51.00,1,0),(13,13,'Caja','especial',0,41.00,70.00,1,0),(14,14,'Llevar','especial',0,58.00,70.00,1,0),(15,15,'Barra Roja','barra',6,83.00,70.00,1,0),(16,16,'Barra Roja 2','barra',6,83.00,88.00,1,0);
/*!40000 ALTER TABLE `mesas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `movimientos_inventario`
--

DROP TABLE IF EXISTS `movimientos_inventario`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `movimientos_inventario` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ingrediente_id` int unsigned NOT NULL,
  `tipo` enum('venta','cancelacion','ajuste') NOT NULL,
  `cantidad` decimal(12,3) NOT NULL,
  `ticket_item_id` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mi_ing` (`ingrediente_id`),
  KEY `idx_mi_ti` (`ticket_item_id`),
  CONSTRAINT `movimientos_inventario_ibfk_1` FOREIGN KEY (`ingrediente_id`) REFERENCES `ingredientes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `movimientos_inventario`
--

LOCK TABLES `movimientos_inventario` WRITE;
/*!40000 ALTER TABLE `movimientos_inventario` DISABLE KEYS */;
/*!40000 ALTER TABLE `movimientos_inventario` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `producto_componentes`
--

DROP TABLE IF EXISTS `producto_componentes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `producto_componentes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `producto_id` int unsigned NOT NULL,
  `tipo` enum('ingrediente','subreceta') NOT NULL,
  `ref_id` int unsigned NOT NULL,
  `cantidad` decimal(12,3) NOT NULL DEFAULT '0.000',
  PRIMARY KEY (`id`),
  KEY `idx_pc_producto` (`producto_id`),
  CONSTRAINT `producto_componentes_ibfk_1` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=47 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `producto_componentes`
--

LOCK TABLES `producto_componentes` WRITE;
/*!40000 ALTER TABLE `producto_componentes` DISABLE KEYS */;
INSERT INTO `producto_componentes` VALUES (1,66,'subreceta',1,60.000),(2,66,'ingrediente',2,90.000),(4,67,'subreceta',1,60.000),(5,67,'ingrediente',3,120.000),(7,69,'ingrediente',1,15.000),(8,69,'ingrediente',2,200.000),(9,69,'ingrediente',7,25.000),(10,69,'ingrediente',6,2.000),(14,71,'ingrediente',4,30.000),(15,71,'ingrediente',3,200.000),(16,71,'ingrediente',5,10.000),(17,72,'ingrediente',8,120.000),(18,72,'ingrediente',2,250.000),(19,72,'ingrediente',5,20.000),(20,72,'ingrediente',9,100.000),(24,66,'subreceta',1,60.000),(25,66,'ingrediente',2,90.000),(27,67,'subreceta',1,60.000),(28,67,'ingrediente',3,120.000),(30,69,'ingrediente',1,15.000),(31,69,'ingrediente',2,200.000),(32,69,'ingrediente',7,25.000),(33,69,'ingrediente',6,2.000),(37,71,'ingrediente',4,30.000),(38,71,'ingrediente',3,200.000),(39,71,'ingrediente',5,10.000),(40,72,'ingrediente',8,120.000),(41,72,'ingrediente',2,250.000),(42,72,'ingrediente',5,20.000),(43,72,'ingrediente',9,100.000);
/*!40000 ALTER TABLE `producto_componentes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `productos`
--

DROP TABLE IF EXISTS `productos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `productos` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(120) NOT NULL,
  `descripcion` text,
  `categoria_id` int NOT NULL,
  `precio` decimal(8,2) NOT NULL,
  `tag` varchar(60) DEFAULT NULL,
  `area_id` tinyint unsigned NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_productos_nombre` (`nombre`),
  KEY `idx_productos_cat_activo` (`categoria_id`,`activo`),
  KEY `area_id` (`area_id`),
  CONSTRAINT `productos_ibfk_1` FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`),
  CONSTRAINT `productos_ibfk_2` FOREIGN KEY (`area_id`) REFERENCES `areas_produccion` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=129 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `productos`
--

LOCK TABLES `productos` WRITE;
/*!40000 ALTER TABLE `productos` DISABLE KEYS */;
INSERT INTO `productos` VALUES (1,'Enmoladas','Rellenas de pollo (70 gr.) con láminas de plátano macho, crema, queso y aros de cebolla bañadas en mole negro de Oaxaca.',1,240.00,'Especialidad C.P.',3,1),(2,'Enchiladas Suizas','Enchiladas verdes rellenas de pollo (70 gr.), gratinadas con queso gouda, crema y aros de cebolla.',1,220.00,NULL,3,1),(3,'Cecina y Huevo con Chorizo','Cecina (130 gr.), huevos revueltos (2 pzas) con chorizo, acompañados de frijoles refritos con queso.',1,220.00,NULL,3,1),(4,'Cazuela Cascabel','3 huevos estrellados o revueltos en salsa de chile cascabel, queso oaxaca gratinado, aguacate y una rebanada de pan hogaza.',1,220.00,NULL,3,1),(5,'Sopes con Cecina o Arrachera','3 sopes hechos a mano con frijoles, lechuga, crema, queso y cecina (130 gr.). Cambio de proteína con arrachera (150 gr.) +$40.',1,220.00,NULL,3,1),(6,'Enfrijoladas','Rellenas de huevo revuelto, bañadas con salsa de frijol, chorizo, crema y queso.',1,220.00,NULL,3,1),(7,'Huevos al Parmesano','2 huevos estrellados acompañados con espárragos blanqueados, arúgula, tocino y parmesano rallado.',1,210.00,'Brunch',3,1),(8,'Omelette Fitness','Claras de huevo (2 pzas), espinaca, queso de cabra y láminas de aguacate.',1,190.00,NULL,3,1),(9,'Toast de Salmón Ahumado','Pan brioche, crema ácida, salmón ahumado (70 gr.), ajonjolí, 1 huevo estrellado, espárragos y aguacate.',1,230.00,'Estrella',3,1),(10,'Pan Francés Estilo C.P.','Base de pan brioche con crema dulce, frutos rojos y miel de maple.',1,210.00,'Dulce',3,1),(11,'Huevos Módena','2 huevos revueltos o estrellados con tocino, queso parmesano y arúgula.',1,190.00,NULL,3,1),(12,'Huevos Italianos','2 huevos en omelette, jamón serrano, láminas de queso parmesano y arúgula.',1,190.00,NULL,3,1),(13,'Huevos Pamplona','2 huevos en omelette con chorizo español de pamplona, arúgula y queso mozarella fresco.',1,190.00,NULL,3,1),(14,'Huevos al Sano','2 huevos en omelette con jamón de pavo, arúgula, queso mozarella fresco y jitomate cherry.',1,190.00,NULL,3,1),(15,'Huevos al Gusto','Rancheros, a la mexicana, divorciados, al albañil, con tocino, con chorizo o con jamón.',1,180.00,NULL,3,1),(16,'Molletes','4 piezas de pan baguette con frijoles y queso manchego, acompañado de pico de gallo.',1,100.00,NULL,3,1),(17,'Casa Pestalozzi','½ orden de chilaquiles (40 gr.) con salsa al gusto, crema, queso y 2 huevos revueltos.',1,180.00,NULL,3,1),(18,'Chilaquiles','Verdes, rojos o salsa de la casa, con pollo (30 gr.) o huevo (1 pza), queso, crema y cebolla morada. Con arrachera +$90 · con cecina +$65.',1,180.00,NULL,3,1),(19,'Baguette de Jamón Serrano','Jamón serrano, láminas de parmesano, casse de jitomate y arúgula.',1,220.00,NULL,3,1),(20,'Baguette de Magret de Pollo','Pollo a la plancha con queso gouda, rodajas de jitomate, mix de lechuga y aderezo cipriani.',1,220.00,NULL,3,1),(21,'Baguette con Arrachera','Arrachera (150 gr.), cremoso de aguacate con un toque de chipotle y mix de lechugas.',1,230.00,NULL,3,1),(22,'Croissant con Jamón de Pavo','Pechuga de pavo (120 gr.), queso gouda, aderezo cipriani, jitomate y mix de lechugas.',1,165.00,NULL,3,1),(23,'Croissant con Huevo y Estragón','2 pzas de huevo revuelto con estragón y mix de lechugas.',1,140.00,NULL,3,1),(24,'Baguette de Cochinita','Cochinita (150 gr.), cebolla encurtida y habanero.',1,210.00,NULL,3,1),(25,'Plato de Fruta Mixta','Fruta de temporada.',1,110.00,NULL,2,1),(26,'Copa Antioxidante','Fresa, frambuesa, mora y zarzamora con yogurt y granola hecha en casa.',1,130.00,NULL,2,1),(27,'Aros de Calamar','Empanizados, aderezo cipriani, chiles cuaresmeños y limón eureka.',2,210.00,'Especialidad C.P.',3,1),(28,'Tostadas de Atún','3 tostaditas con cubos de atún marinado en salsa oriental, cremoso de aguacate y poro.',2,195.00,'Especialidad C.P.',3,1),(29,'Torreta de Salmón','Salmón ahumado, queso cabra, aguacate, jitomate con aderezo de pesto de albahaca.',2,220.00,'Especialidad C.P.',3,1),(30,'Tiradito de Atún','Láminas de atún, aceite de chile, mayonesa spicy, toronja y eneldo.',2,210.00,NULL,3,1),(31,'Carpaccio de Salmón','Finas láminas de salmón ahumado, arúgula, queso parmesano, alcaparras, limón eureka y jitomate cherry.',2,180.00,NULL,3,1),(32,'Camarones al Ajillo','Salteados al olivo, ajo, peperoncino con pan de baguette.',2,210.00,NULL,3,1),(33,'Espárragos al Horno','Queso gouda, tocino con reducción de balsámico.',2,180.00,NULL,4,1),(34,'Queso Burrata con Jitomates Cherrys','Queso burrata con jitomates cherrys al horno, aceite de oliva, poro y hojas de albahaca.',2,210.00,NULL,4,1),(35,'Crema del Día','Nuestras cremas y sopas son elaboradas por temporada y en nuestros especiales de fin de semana. Pregunta al mesero por la opción del día.',3,180.00,'Temporada',3,1),(36,'Sopa Especial de Fin de Semana','Receta de la casa, elaborada con ingredientes frescos de temporada. Disponible sábados y domingos.',3,180.00,'Fin de semana',3,1),(37,'Fetuccini a los Cuatro Quesos y Camarones','Queso brie, parmesano, queso crema y queso gouda.',4,280.00,'Especialidad C.P.',3,1),(38,'Lasagna de Filete de Res','Cocción a baja temperatura por 3 horas con ingredientes 100% italianos.',4,280.00,'Especialidad C.P.',3,1),(39,'Rigatoni al Limón con Camarones y Parmesano','Camarones salteados con vino blanco, mantequilla, ralladura de limón eureka y toque de albahaca.',4,280.00,'Estrella',3,1),(40,'Spaguetti a l\'Arrabbiata con Camarones y Parmesano','Salsa de pomodoro con peperoncino.',4,280.00,NULL,3,1),(41,'Spaguetti a la Boloñesa','Cocción a baja temperatura por 3 horas con ingredientes 100% italianos.',4,280.00,NULL,3,1),(42,'Spaguetti al Pomodoro y Parmesano','Pasta, salsa de jitomate y parmesano.',4,190.00,NULL,3,1),(43,'Filete de Res en su Jugo','Filete de res importado en su jugo con puré de papa rústico y espárragos al horno.',5,320.00,'Especialidad C.P.',3,1),(44,'Salmón al Horno','Salmón noruego sazonado con ajo y aceite de oliva. Acompaña con media orden de pasta o ensalada.',5,295.00,NULL,3,1),(45,'Hamburguesa de la Casa','Carne wagyu, pan brioche hecho en C.P., cebolla caramelizada, queso cheddar, mayonesa ahumada, pepinillo encurtido. Acompaña con papas gajo.',5,260.00,'Especialidad C.P.',3,1),(46,'Atún Sellado','Atún importado, sellado en costra de pistache, aderezo cipriani. Acompaña con mix de lechugas.',5,285.00,NULL,3,1),(47,'Tacos de Cochinita','Tres tacos de tortilla de maíz hechas a mano, frijol, cebolla y habanero encurtido.',5,210.00,'Especialidad C.P.',3,1),(48,'Tacos de Vacío','Vacío importado, tortillas hechas a mano, salsa de piña con habanero y aguacate.',5,210.00,NULL,3,1),(49,'Tacos de Camarón Rebozados','Tres tortillas de harina, camarones rebozados, col morada y aderezo de chipotle.',5,240.00,NULL,3,1),(50,'Vacío en Escalopas','Vacío importado en escalopas, arúgula, láminas de parmesano y reducción de bálsamico.',5,280.00,'Especialidad C.P.',3,1),(51,'New York (450 grs.)','Carne calidad choice angus, cebollitas asadas, chiles toreados y papas a la francesa.',5,785.00,'Premium',3,1),(52,'Rib Eye (450 grs.)','Carne calidad choice angus, cebollitas asadas, chiles toreados y papas a la francesa.',5,785.00,'Premium',3,1),(53,'Frutos Rojos','Mix de lechugas, frambuesas, zarzamoras, fresas, queso cabra, nuez y reducción de balsámico.',6,210.00,NULL,3,1),(54,'Ciruela Betabel','Mix de lechugas, ciruela y betabel sazonado con estragón, queso burrata y almendras horneadas.',6,210.00,'Especialidad C.P.',3,1),(55,'Magret de Pollo','Pechuga de pollo prensada, lechuga baby asada, almendras horneadas con aderezo de queso.',6,210.00,'Especialidad C.P.',3,1),(56,'Jamón Serrano con Perlas de Melón','Mix de lechugas, perlas de melón, jamón serrano, nuez y reducción de balsámico.',6,210.00,NULL,3,1),(57,'Pasta Corta con Pollo','Mix de lechuga con cremoso de aguacate y almendras horneadas.',6,210.00,NULL,3,1),(58,'Margarita','Pomodoro, mozzarella y albahaca.',7,190.00,NULL,4,1),(59,'Burrata','Pomodoro, burrata, prosciutto y arúgula.',7,260.00,'Favorita',4,1),(60,'Milano','Pomodoro, mozzarella, jitomates cherrys, salami y láminas de parmesano.',7,260.00,NULL,4,1),(61,'Camarones a los 4 Quesos','Salsa de 4 quesos, queso mozzarella y camarones.',7,260.00,NULL,4,1),(62,'Mix de 3 Brusquetas','Jamón serrano, queso brie, anchoas.',8,160.00,'3 piezas',3,1),(63,'Aceitunas Temperadas con Aceite de Chile','Aceitunas verdes en aceite de chiles.',8,160.00,NULL,3,1),(64,'Tabla Mixta','Queso parmesano, brie, manchego, chorizo salamanca, semillas, frutos rojos.',8,320.00,NULL,3,1),(65,'Papas a la Francesa con Parmesano','Papas a la francesa con queso parmesano rallado.',8,160.00,NULL,3,1),(66,'Café Americano','Café Americano.',9,65.00,NULL,1,1),(67,'Cappuccino','Cappuccino.',9,75.00,NULL,1,1),(68,'Latte','Latte.',9,80.00,NULL,1,1),(69,'Café de Olla','Café de Olla.',9,65.00,NULL,1,1),(70,'Té / Infusión','Té / Infusión.',9,65.00,NULL,1,1),(71,'Chocolate Caliente','Chocolate Caliente.',9,80.00,NULL,1,1),(72,'Agua Fresca','Agua Fresca.',9,60.00,NULL,1,1),(73,'Refresco','Refresco.',9,55.00,NULL,1,1),(74,'Jugo de Naranja','Jugo de Naranja.',10,85.00,NULL,2,1),(75,'Jugo Verde','Jugo Verde.',10,95.00,NULL,2,1),(76,'Limonada Natural','Limonada Natural.',10,75.00,NULL,2,1),(77,'Smoothie de Fresa','Smoothie de Fresa.',10,100.00,NULL,2,1),(78,'Agua de Coco','Agua de Coco.',10,90.00,NULL,2,1);
/*!40000 ALTER TABLE `productos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `reportes_sistema`
--

DROP TABLE IF EXISTS `reportes_sistema`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reportes_sistema` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `usuario_id` int DEFAULT NULL,
  `modulo` varchar(60) DEFAULT NULL,
  `titulo` varchar(120) NOT NULL,
  `descripcion` text NOT NULL,
  `ruta_origen` varchar(255) DEFAULT NULL,
  `navegador` enum('chrome','edge','firefox','safari','otro') DEFAULT NULL,
  `navegador_otro` varchar(80) DEFAULT NULL,
  `estado` enum('nuevo','en_revision','resuelto','descartado') NOT NULL DEFAULT 'nuevo',
  `resuelto_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_reportes_sistema_usuario` (`usuario_id`),
  KEY `idx_reportes_estado_fecha` (`estado`,`created_at`),
  KEY `idx_reportes_modulo` (`modulo`),
  CONSTRAINT `fk_reportes_sistema_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `reportes_sistema`
--

LOCK TABLES `reportes_sistema` WRITE;
/*!40000 ALTER TABLE `reportes_sistema` DISABLE KEYS */;
/*!40000 ALTER TABLE `reportes_sistema` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `reservacion_mesas`
--

DROP TABLE IF EXISTS `reservacion_mesas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reservacion_mesas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `reservacion_id` int NOT NULL,
  `mesa_id` int NOT NULL,
  `orden` tinyint unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reservacion_mesa` (`reservacion_id`,`mesa_id`),
  UNIQUE KEY `uq_reservacion_orden` (`reservacion_id`,`orden`),
  KEY `idx_rm_mesa` (`mesa_id`),
  KEY `idx_rm_reservacion` (`reservacion_id`),
  CONSTRAINT `fk_reservacion_mesas_mesa` FOREIGN KEY (`mesa_id`) REFERENCES `mesas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reservacion_mesas_reservacion` FOREIGN KEY (`reservacion_id`) REFERENCES `reservaciones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=79 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `reservacion_mesas`
--

LOCK TABLES `reservacion_mesas` WRITE;
/*!40000 ALTER TABLE `reservacion_mesas` DISABLE KEYS */;
INSERT INTO `reservacion_mesas` VALUES (1,13,1,1,'2026-08-03 06:27:26'),(2,14,2,1,'2026-08-03 06:27:26'),(3,15,3,1,'2026-08-03 06:27:26'),(4,16,4,1,'2026-08-03 06:27:26'),(5,17,1,1,'2026-08-03 06:27:26'),(6,18,1,1,'2026-08-03 06:27:26'),(7,18,2,2,'2026-08-03 06:27:26'),(8,18,3,3,'2026-08-03 06:27:26'),(9,18,4,4,'2026-08-03 06:27:26'),(10,18,5,5,'2026-08-03 06:27:26'),(11,18,6,6,'2026-08-03 06:27:26'),(12,18,7,7,'2026-08-03 06:27:26'),(13,18,8,8,'2026-08-03 06:27:26'),(14,18,9,9,'2026-08-03 06:27:26'),(15,18,10,10,'2026-08-03 06:27:26'),(16,18,11,11,'2026-08-03 06:27:26'),(17,19,1,1,'2026-08-03 06:27:26'),(18,20,5,1,'2026-08-03 06:27:26'),(19,20,11,2,'2026-08-03 06:27:26'),(20,21,8,1,'2026-08-03 06:27:26'),(21,21,9,2,'2026-08-03 06:27:26'),(22,21,10,3,'2026-08-03 06:27:26'),(23,22,1,1,'2026-08-03 06:27:26'),(24,22,2,2,'2026-08-03 06:27:26'),(25,22,3,3,'2026-08-03 06:27:26'),(26,22,4,4,'2026-08-03 06:27:26'),(27,23,2,1,'2026-08-03 06:27:26'),(28,24,2,1,'2026-08-03 06:27:26'),(29,25,3,1,'2026-08-03 06:27:26'),(30,26,4,1,'2026-08-03 06:27:26'),(31,27,5,1,'2026-08-03 06:27:26'),(32,27,6,2,'2026-08-03 06:27:26'),(33,28,6,1,'2026-08-03 06:27:26'),(34,29,7,1,'2026-08-03 06:27:26'),(35,30,9,1,'2026-08-03 06:27:26'),(36,31,10,1,'2026-08-03 06:27:26'),(37,32,11,1,'2026-08-03 06:27:26'),(75,65,2,1,'2026-08-03 06:29:56'),(76,65,4,2,'2026-08-03 06:29:56'),(77,65,5,3,'2026-08-03 06:29:56'),(78,66,1,1,'2026-08-03 06:31:57');
/*!40000 ALTER TABLE `reservacion_mesas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `reservaciones`
--

DROP TABLE IF EXISTS `reservaciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reservaciones` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `contacto_tipo` enum('email','telefono','ninguno') NOT NULL DEFAULT 'ninguno',
  `contacto` varchar(150) DEFAULT NULL,
  `fecha` date NOT NULL,
  `hora` time NOT NULL,
  `comensales` int unsigned NOT NULL DEFAULT '2',
  `nota` text,
  `comentario_admin` text,
  `origen` enum('landing','admin') NOT NULL,
  `request_token` varchar(64) DEFAULT NULL,
  `hold_expires_at` datetime DEFAULT NULL,
  `estado` enum('pendiente_verificacion','confirmada','en_curso','completada','cancelada','no_show','expirada','reemplazada') NOT NULL DEFAULT 'pendiente_verificacion',
  `reemplaza_reservacion_id` int DEFAULT NULL,
  `estado_changed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reservaciones_request_token` (`request_token`),
  KEY `idx_reservaciones_fecha_estado_hora` (`fecha`,`estado`,`hora`),
  KEY `idx_reservaciones_contacto_horario` (`contacto_tipo`,`contacto`,`fecha`,`hora`,`estado`),
  KEY `idx_reservaciones_retenciones_vencidas` (`estado`,`hold_expires_at`),
  KEY `idx_reservaciones_reemplazo` (`reemplaza_reservacion_id`),
  CONSTRAINT `fk_reservacion_reemplazada` FOREIGN KEY (`reemplaza_reservacion_id`) REFERENCES `reservaciones` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_reservaciones_comensales` CHECK ((`comensales` > 0)),
  CONSTRAINT `chk_reservaciones_contacto` CHECK ((((`contacto_tipo` = _utf8mb4'ninguno') and (`contacto` is null)) or ((`contacto_tipo` in (_utf8mb4'email',_utf8mb4'telefono')) and (`contacto` is not null) and (trim(`contacto`) <> _utf8mb4'')))),
  CONSTRAINT `chk_reservaciones_retencion_vencimiento` CHECK (((`estado` <> _utf8mb4'pendiente_verificacion') or (`hold_expires_at` is not null)))
) ENGINE=InnoDB AUTO_INCREMENT=67 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `reservaciones`
--

LOCK TABLES `reservaciones` WRITE;
/*!40000 ALTER TABLE `reservaciones` DISABLE KEYS */;
INSERT INTO `reservaciones` VALUES (1,'Límite Una','email','limite.una@example.test','2026-11-30','13:00:00',2,'Una activa',NULL,'admin','fx-limite-una-0001',NULL,'confirmada',NULL,'2026-11-30 12:00:00','2026-08-03 06:27:26',NULL),(2,'Límite Cuatro 1','email','limite.cuatro@example.test','2026-11-30','14:30:00',2,'',NULL,'admin','fx-limite-cuatro-01',NULL,'confirmada',NULL,'2026-11-30 12:00:00','2026-08-03 06:27:26',NULL),(3,'Límite Cuatro 2','email','limite.cuatro@example.test','2026-12-01','15:00:00',2,'',NULL,'admin','fx-limite-cuatro-02',NULL,'confirmada',NULL,'2026-11-30 12:00:00','2026-08-03 06:27:26',NULL),(4,'Límite Cuatro 3','email','limite.cuatro@example.test','2026-12-02','16:00:00',2,'',NULL,'admin','fx-limite-cuatro-03',NULL,'confirmada',NULL,'2026-11-30 12:00:00','2026-08-03 06:27:26',NULL),(5,'Límite Cuatro 4','email','limite.cuatro@example.test','2026-12-03','17:00:00',2,'',NULL,'admin','fx-limite-cuatro-04',NULL,'confirmada',NULL,'2026-11-30 12:00:00','2026-08-03 06:27:26',NULL),(6,'Límite Cinco 1','email','limite.cinco@example.test','2026-11-30','13:30:00',2,'',NULL,'admin','fx-limite-cinco-01',NULL,'confirmada',NULL,'2026-11-30 12:00:00','2026-08-03 06:27:26',NULL),(7,'Límite Cinco 2','email','limite.cinco@example.test','2026-11-30','15:00:00',2,'',NULL,'admin','fx-limite-cinco-02',NULL,'confirmada',NULL,'2026-11-30 12:00:00','2026-08-03 06:27:26',NULL),(8,'Límite Cinco 3','email','limite.cinco@example.test','2026-12-01','16:30:00',2,'',NULL,'admin','fx-limite-cinco-03',NULL,'confirmada',NULL,'2026-11-30 12:00:00','2026-08-03 06:27:26',NULL),(9,'Límite Cinco 4','email','limite.cinco@example.test','2026-12-02','18:00:00',2,'',NULL,'admin','fx-limite-cinco-04',NULL,'confirmada',NULL,'2026-11-30 12:00:00','2026-08-03 06:27:26',NULL),(10,'Límite Cinco 5','email','limite.cinco@example.test','2026-12-03','19:30:00',2,'',NULL,'admin','fx-limite-cinco-05',NULL,'confirmada',NULL,'2026-11-30 12:00:00','2026-08-03 06:27:26',NULL),(11,'Identidad Teléfono','telefono','+525544442026','2026-12-03','18:30:00',3,'Contacto canónico',NULL,'landing','fx-contacto-tel-001',NULL,'confirmada',NULL,'2026-11-30 12:00:00','2026-08-03 06:27:26',NULL),(12,'Histórica','email','historial@example.test','2026-11-27','18:00:00',2,'',NULL,'admin','fx-historica-000001',NULL,'completada',NULL,'2026-11-27 19:30:00','2026-08-03 06:27:26',NULL),(13,'Retención Vigente','email','hold.vigente@example.test','2026-11-30','17:30:00',2,'',NULL,'landing','fx-hold-vigente-001','2026-11-30 12:05:00','pendiente_verificacion',NULL,'2026-11-30 12:00:00','2026-08-03 06:27:26',NULL),(14,'Retención Vencida','email','hold.vencida@example.test','2026-11-30','18:00:00',2,'',NULL,'landing','fx-hold-vencida-001','2026-11-30 11:59:59','pendiente_verificacion',NULL,'2026-11-30 12:00:00','2026-08-03 06:27:26',NULL),(15,'Modificable','email','modificar@example.test','2026-11-30','18:30:00',2,'Mover a otra hora',NULL,'admin','fx-modificable-0001',NULL,'confirmada',NULL,'2026-11-30 12:00:00','2026-08-03 06:27:26',NULL),(16,'Cancelable','email','cancelar@example.test','2026-11-30','19:00:00',2,'',NULL,'admin','fx-cancelable-0001',NULL,'confirmada',NULL,'2026-11-30 12:00:00','2026-08-03 06:27:26',NULL),(17,'Sin Capacidad','email','sin.capacidad@example.test','2026-12-01','13:00:00',2,'Conservar al fallar modificación',NULL,'admin','fx-sin-capacidad-01',NULL,'confirmada',NULL,'2026-11-30 12:00:00','2026-08-03 06:27:26',NULL),(18,'Bloqueo Total','email','bloqueo@example.test','2026-12-01','20:00:00',44,'Ocupa todas las mesas',NULL,'admin','fx-bloqueo-total-01',NULL,'confirmada',NULL,'2026-11-30 12:00:00','2026-08-03 06:27:26',NULL),(19,'Una Mesa','email','una.mesa@example.test','2026-11-30','13:00:00',2,'',NULL,'admin','fx-una-mesa-000001',NULL,'confirmada',NULL,'2026-11-30 12:00:00','2026-08-03 06:27:26',NULL),(20,'Dos Mesas','email','dos.mesas@example.test','2026-11-30','14:30:00',6,'',NULL,'admin','fx-dos-mesas-00001',NULL,'confirmada',NULL,'2026-11-30 12:00:00','2026-08-03 06:27:26',NULL),(21,'Tres Mesas','email','tres.mesas@example.test','2026-11-30','16:00:00',10,'',NULL,'admin','fx-tres-mesas-0001',NULL,'confirmada',NULL,'2026-11-30 12:00:00','2026-08-03 06:27:26',NULL),(22,'Cuatro Mesas Administrativa','email','cuatro.mesas@example.test','2026-12-03','20:00:00',13,'Supera el límite público',NULL,'admin','fx-cuatro-mesas-001',NULL,'confirmada',NULL,'2026-11-30 12:00:00','2026-08-03 06:27:26',NULL),(23,'Consecutiva A','email','consecutiva@example.test','2026-12-03','13:00:00',2,'',NULL,'admin','fx-consecutiva-a-01',NULL,'confirmada',NULL,'2026-11-30 12:00:00','2026-08-03 06:27:26',NULL),(24,'Consecutiva B','email','consecutiva@example.test','2026-12-03','15:00:00',2,'',NULL,'admin','fx-consecutiva-b-01',NULL,'confirmada',NULL,'2026-11-30 12:00:00','2026-08-03 06:27:26',NULL),(25,'POS Confirmada','email','pos.confirmada@example.test','2026-11-30','19:30:00',2,'',NULL,'admin','fx-pos-confirmada-01',NULL,'confirmada',NULL,'2026-11-30 12:00:00','2026-08-03 06:27:26',NULL),(26,'POS Convertida','email','pos.convertida@example.test','2026-11-30','20:00:00',2,'',NULL,'admin','fx-pos-convertida-000001',NULL,'confirmada',NULL,'2026-11-30 19:50:00','2026-08-03 06:27:26',NULL),(27,'POS En Curso','email','pos.encurso@example.test','2026-11-30','20:00:00',6,'',NULL,'admin','fx-pos-encurso-001',NULL,'en_curso',NULL,'2026-11-30 20:00:00','2026-08-03 06:27:26',NULL),(28,'POS Completada','email','pos.completa@example.test','2026-11-27','18:00:00',2,'',NULL,'admin','fx-pos-completa-001',NULL,'completada',NULL,'2026-11-27 19:30:00','2026-08-03 06:27:26',NULL),(29,'POS Tolerancia','email','pos.tolerancia@example.test','2026-11-30','20:30:00',2,'',NULL,'admin','fx-pos-tolerancia-1',NULL,'confirmada',NULL,'2026-11-30 12:00:00','2026-08-03 06:27:26',NULL),(30,'POS No Show','email','pos.noshow@example.test','2026-11-30','19:00:00',2,'',NULL,'admin','fx-pos-noshow-0001',NULL,'no_show',NULL,'2026-11-30 12:00:00','2026-08-03 06:27:26',NULL),(31,'Reserva Futura','email','pos.futura@example.test','2026-12-01','13:00:00',2,'Advertencia de reserva próxima',NULL,'admin','fx-pos-futura-00001',NULL,'confirmada',NULL,'2026-11-30 12:00:00','2026-08-03 06:27:26',NULL),(32,'Horario Afectado','email','horario@example.test','2026-11-30','21:00:00',2,'Conflicto al adelantar el cierre',NULL,'admin','fx-horario-afectado',NULL,'confirmada',NULL,'2026-11-30 12:00:00','2026-08-03 06:27:26',NULL),(65,'Leonardo Velasco Ojeda','email','leonardo.velasco.oj@gmail.com','2026-08-03','00:30:00',12,'',NULL,'landing','76ab5f74b58ad3a7fb3b02e820f47a27b8c29cd83c1926d4e5d969ddb5ec1513','2026-08-03 00:44:56','confirmada',NULL,'2026-08-03 00:29:58','2026-08-03 06:29:56','2026-08-03 06:29:58'),(66,'Leonardo Velasco Ojeda','email','leonardo.velasco.oj@gmail.com','2026-08-03','01:30:00',3,'',NULL,'landing','f0f3f1929287e107078c21de76084171faedd3e5321fa9fca53621f1b4d1859a',NULL,'confirmada',NULL,'2026-08-03 00:31:57','2026-08-03 06:31:57',NULL);
/*!40000 ALTER TABLE `reservaciones` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `trg_reservaciones_no_auto_reemplazo_insert` BEFORE INSERT ON `reservaciones` FOR EACH ROW BEGIN
  IF NEW.reemplaza_reservacion_id IS NOT NULL
     AND NEW.id IS NOT NULL
     AND NEW.reemplaza_reservacion_id = NEW.id THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Una reservacion no puede reemplazarse a si misma';
  END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `trg_reservaciones_no_auto_reemplazo_update` BEFORE UPDATE ON `reservaciones` FOR EACH ROW BEGIN
  IF NEW.reemplaza_reservacion_id IS NOT NULL
     AND NEW.reemplaza_reservacion_id = NEW.id THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Una reservacion no puede reemplazarse a si misma';
  END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `subreceta_ingredientes`
--

DROP TABLE IF EXISTS `subreceta_ingredientes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subreceta_ingredientes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `subreceta_id` int unsigned NOT NULL,
  `ingrediente_id` int unsigned NOT NULL,
  `cantidad` decimal(12,3) NOT NULL DEFAULT '0.000',
  PRIMARY KEY (`id`),
  KEY `ingrediente_id` (`ingrediente_id`),
  KEY `idx_si_sub` (`subreceta_id`),
  CONSTRAINT `subreceta_ingredientes_ibfk_1` FOREIGN KEY (`subreceta_id`) REFERENCES `subrecetas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `subreceta_ingredientes_ibfk_2` FOREIGN KEY (`ingrediente_id`) REFERENCES `ingredientes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subreceta_ingredientes`
--

LOCK TABLES `subreceta_ingredientes` WRITE;
/*!40000 ALTER TABLE `subreceta_ingredientes` DISABLE KEYS */;
INSERT INTO `subreceta_ingredientes` VALUES (1,1,1,18.000),(2,1,2,60.000),(3,1,1,18.000),(4,1,2,60.000);
/*!40000 ALTER TABLE `subreceta_ingredientes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subrecetas`
--

DROP TABLE IF EXISTS `subrecetas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subrecetas` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(120) NOT NULL,
  `unidad` enum('g','kg','ml','l','pza') NOT NULL DEFAULT 'g',
  `rendimiento` decimal(12,3) NOT NULL DEFAULT '1.000',
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subrecetas`
--

LOCK TABLES `subrecetas` WRITE;
/*!40000 ALTER TABLE `subrecetas` DISABLE KEYS */;
INSERT INTO `subrecetas` VALUES (1,'Shot de espresso','ml',60.000,1,'2026-08-03 06:27:26','2026-08-03 06:27:26');
/*!40000 ALTER TABLE `subrecetas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ticket_items`
--

DROP TABLE IF EXISTS `ticket_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ticket_id` int NOT NULL,
  `nombre` varchar(120) NOT NULL,
  `precio` decimal(8,2) NOT NULL,
  `categoria` varchar(60) NOT NULL,
  `area_id` tinyint unsigned NOT NULL,
  `comensal` tinyint unsigned DEFAULT NULL,
  `cantidad` tinyint unsigned NOT NULL DEFAULT '1',
  `nota` varchar(280) DEFAULT NULL,
  `estado` enum('enviado','en_preparacion','listo','entregado','cancelado') NOT NULL DEFAULT 'enviado',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_area_estado` (`area_id`,`estado`),
  KEY `idx_ti_ticket` (`ticket_id`),
  CONSTRAINT `ticket_items_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ticket_items_ibfk_2` FOREIGN KEY (`area_id`) REFERENCES `areas_produccion` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=201 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ticket_items`
--

LOCK TABLES `ticket_items` WRITE;
/*!40000 ALTER TABLE `ticket_items` DISABLE KEYS */;
INSERT INTO `ticket_items` VALUES (1,1,'Toast de Salmón Ahumado',230.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-06-18 20:10:00'),(2,1,'Cappuccino',75.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-06-18 20:10:00'),(3,2,'Enchiladas Suizas',220.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-06-18 20:45:00'),(4,2,'Jugo Verde',95.00,'Jugos & Smoothies',2,NULL,2,NULL,'entregado','2026-06-18 20:45:00'),(5,2,'Café Americano',65.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-06-18 20:52:00'),(6,3,'Rib Eye (450 grs.)',785.00,'Platos Fuertes',3,NULL,1,NULL,'entregado','2026-06-19 02:15:00'),(7,3,'Milano',260.00,'Pizzas',4,NULL,2,NULL,'entregado','2026-06-19 02:15:00'),(8,3,'Camarones al Ajillo',210.00,'Entradas',3,NULL,1,NULL,'entregado','2026-06-19 02:15:00'),(9,3,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,4,NULL,'entregado','2026-06-19 02:16:00'),(10,5,'Filete de Res en su Jugo',320.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-06-19 03:10:00'),(11,5,'Queso Burrata con Jitomates Cherrys',210.00,'Entradas',4,NULL,1,NULL,'entregado','2026-06-19 03:10:00'),(12,8,'Hamburguesa de la Casa',260.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-06-18 21:20:00'),(13,8,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-06-18 21:20:00'),(14,8,'Refresco',55.00,'Café & Bebidas',1,NULL,4,NULL,'entregado','2026-06-18 21:21:00'),(15,9,'Café Americano',65.00,'Café & Bebidas',1,NULL,2,NULL,'enviado','2026-08-03 06:25:25'),(16,9,'Molletes',100.00,'Desayunos',3,NULL,1,NULL,'enviado','2026-08-03 06:25:25'),(17,10,'Rigatoni al Limón con Camarones y Parmesano',280.00,'Pastas',3,NULL,2,NULL,'entregado','2026-08-03 05:48:25'),(18,10,'Milano',260.00,'Pizzas',4,NULL,1,NULL,'listo','2026-08-03 05:49:25'),(19,10,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,4,NULL,'entregado','2026-08-03 05:48:25'),(20,11,'Chilaquiles',180.00,'Desayunos',3,NULL,2,NULL,'en_preparacion','2026-08-03 06:11:25'),(21,11,'Jugo Verde',95.00,'Jugos & Smoothies',2,NULL,3,NULL,'entregado','2026-08-03 06:11:25'),(22,11,'Cappuccino',75.00,'Café & Bebidas',1,NULL,1,NULL,'listo','2026-08-03 06:12:25'),(23,12,'Toast de Salmón Ahumado',230.00,'Desayunos',3,NULL,2,NULL,'enviado','2026-08-03 06:22:25'),(24,12,'Jugo de Naranja',85.00,'Jugos & Smoothies',2,NULL,2,NULL,'enviado','2026-08-03 06:22:25'),(25,13,'Filete de Res en su Jugo',320.00,'Platos Fuertes',3,NULL,2,NULL,'en_preparacion','2026-08-03 05:56:25'),(26,13,'Aros de Calamar',210.00,'Entradas',3,NULL,1,NULL,'entregado','2026-08-03 05:56:25'),(27,13,'Refresco',55.00,'Café & Bebidas',1,NULL,4,NULL,'entregado','2026-08-03 05:57:25'),(28,14,'Burrata',260.00,'Pizzas',4,NULL,2,NULL,'en_preparacion','2026-08-03 06:07:25'),(29,14,'Tabla Mixta',320.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-08-03 06:07:25'),(30,14,'Agua Fresca',60.00,'Café & Bebidas',1,NULL,4,NULL,'entregado','2026-08-03 06:08:25'),(31,15,'Rib Eye (450 grs.)',785.00,'Platos Fuertes',3,NULL,1,NULL,'entregado','2026-08-03 05:44:25'),(32,15,'Vacío en Escalopas',280.00,'Platos Fuertes',3,NULL,1,NULL,'entregado','2026-08-03 05:44:25'),(33,15,'Espárragos al Horno',180.00,'Entradas',4,NULL,1,NULL,'listo','2026-08-03 05:45:25'),(34,15,'Café de Olla',65.00,'Café & Bebidas',1,NULL,3,NULL,'entregado','2026-08-03 05:47:25'),(35,4,'Chilaquiles',180.00,'Desayunos',3,NULL,2,NULL,'en_preparacion','2026-08-03 06:17:25'),(36,4,'Café de Olla',65.00,'Café & Bebidas',1,NULL,2,NULL,'enviado','2026-08-03 06:17:25'),(37,16,'Salmón al Horno',295.00,'Platos Fuertes',3,NULL,1,NULL,'listo','2026-08-03 06:00:25'),(38,16,'Frutos Rojos',210.00,'Ensaladas',3,NULL,1,NULL,'entregado','2026-08-03 06:00:25'),(39,16,'Té / Infusión',65.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-08-03 06:01:25'),(40,17,'Mix de 3 Brusquetas',160.00,'Para Picar',3,NULL,2,NULL,'enviado','2026-08-03 06:23:25'),(41,17,'Smoothie de Fresa',100.00,'Jugos & Smoothies',2,NULL,2,NULL,'enviado','2026-08-03 06:23:25'),(42,17,'Latte',80.00,'Café & Bebidas',1,NULL,2,NULL,'enviado','2026-08-03 06:24:25'),(43,6,'Burrata',260.00,'Pizzas',4,NULL,2,NULL,'en_preparacion','2026-08-03 06:02:25'),(44,6,'Aros de Calamar',210.00,'Entradas',3,NULL,1,NULL,'listo','2026-08-03 06:02:25'),(45,6,'Agua de Coco',90.00,'Jugos & Smoothies',2,NULL,4,NULL,'entregado','2026-08-03 06:03:25'),(46,18,'Camarones a los 4 Quesos',260.00,'Pizzas',4,NULL,2,NULL,'en_preparacion','2026-08-03 06:14:25'),(47,18,'Aceitunas Temperadas con Aceite de Chile',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-08-03 06:14:25'),(48,18,'Cappuccino',75.00,'Café & Bebidas',1,NULL,5,NULL,'listo','2026-08-03 06:15:25'),(49,19,'Café Americano',65.00,'Café & Bebidas',1,NULL,1,NULL,'enviado','2026-08-03 06:26:25'),(50,19,'Croissant con Jamón de Pavo',165.00,'Desayunos',3,NULL,1,NULL,'enviado','2026-08-03 06:26:25'),(51,20,'Baguette de Cochinita',210.00,'Desayunos',3,NULL,2,NULL,'en_preparacion','2026-08-03 06:19:25'),(52,20,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,1,NULL,'enviado','2026-08-03 06:19:25'),(53,20,'Agua de Coco',90.00,'Jugos & Smoothies',2,NULL,1,NULL,'listo','2026-08-03 06:20:25'),(54,21,'Hamburguesa de la Casa',260.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-08-03 05:53:25'),(55,21,'Tacos de Cochinita',210.00,'Platos Fuertes',3,NULL,1,NULL,'entregado','2026-08-03 05:53:25'),(56,21,'Chocolate Caliente',80.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-08-03 05:54:25'),(57,21,'Margarita',190.00,'Pizzas',4,NULL,1,NULL,'listo','2026-08-03 05:54:25'),(58,22,'Lasagna de Filete de Res',280.00,'Pastas',3,NULL,2,NULL,'en_preparacion','2026-08-03 06:05:25'),(59,22,'Carpaccio de Salmón',180.00,'Entradas',3,NULL,1,NULL,'entregado','2026-08-03 06:05:25'),(60,22,'Copa Antioxidante',130.00,'Desayunos',2,NULL,1,NULL,'entregado','2026-08-03 06:06:25'),(61,101,'Enmoladas',240.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-05-08 15:10:00'),(62,101,'Enchiladas Suizas',220.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-05-08 15:10:00'),(63,101,'Cappuccino',75.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-05-08 15:11:00'),(64,102,'Enmoladas',240.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-05-22 15:40:00'),(65,102,'Enchiladas Suizas',220.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-05-22 15:40:00'),(66,103,'Enmoladas',240.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-06-05 15:10:00'),(67,103,'Café Americano',65.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-06-05 15:11:00'),(68,104,'Spaguetti a la Boloñesa',280.00,'Pastas',3,NULL,2,NULL,'entregado','2026-05-11 20:10:00'),(69,104,'Mix de 3 Brusquetas',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-05-11 20:10:00'),(70,104,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,4,NULL,'entregado','2026-05-11 20:11:00'),(71,105,'Spaguetti a la Boloñesa',280.00,'Pastas',3,NULL,1,NULL,'entregado','2026-05-27 20:10:00'),(72,105,'Mix de 3 Brusquetas',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-05-27 20:10:00'),(73,106,'Spaguetti a la Boloñesa',280.00,'Pastas',3,NULL,2,NULL,'entregado','2026-06-10 19:40:00'),(74,106,'Crema del Día',180.00,'Sopas & Cremas',3,NULL,1,NULL,'entregado','2026-06-10 19:40:00'),(75,107,'Margarita',190.00,'Pizzas',4,NULL,3,NULL,'entregado','2026-05-09 19:10:00'),(76,107,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-05-09 19:10:00'),(77,108,'Margarita',190.00,'Pizzas',4,NULL,2,NULL,'entregado','2026-05-30 19:40:00'),(78,108,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-05-30 19:40:00'),(79,109,'Margarita',190.00,'Pizzas',4,NULL,3,NULL,'entregado','2026-06-13 19:10:00'),(80,109,'Milano',260.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-06-13 19:10:00'),(81,110,'Rib Eye (450 grs.)',785.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-05-15 01:10:00'),(82,110,'Aceitunas Temperadas con Aceite de Chile',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-05-15 01:10:00'),(83,111,'Rib Eye (450 grs.)',785.00,'Platos Fuertes',3,NULL,1,NULL,'entregado','2026-05-29 01:40:00'),(84,111,'Aceitunas Temperadas con Aceite de Chile',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-05-29 01:40:00'),(85,112,'Rib Eye (450 grs.)',785.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-06-12 01:10:00'),(86,112,'Filete de Res en su Jugo',320.00,'Platos Fuertes',3,NULL,1,NULL,'entregado','2026-06-12 01:10:00'),(87,113,'Frutos Rojos',210.00,'Ensaladas',3,NULL,1,NULL,'entregado','2026-05-15 21:10:00'),(88,113,'Crema del Día',180.00,'Sopas & Cremas',3,NULL,1,NULL,'entregado','2026-05-15 21:10:00'),(89,114,'Frutos Rojos',210.00,'Ensaladas',3,NULL,1,NULL,'entregado','2026-05-29 21:40:00'),(90,114,'Crema del Día',180.00,'Sopas & Cremas',3,NULL,1,NULL,'entregado','2026-05-29 21:40:00'),(91,115,'Frutos Rojos',210.00,'Ensaladas',3,NULL,2,NULL,'entregado','2026-06-12 21:10:00'),(92,115,'Jugo Verde',95.00,'Jugos & Smoothies',2,NULL,2,NULL,'entregado','2026-06-12 21:11:00'),(93,116,'Tabla Mixta',320.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-05-17 02:10:00'),(94,116,'Burrata',260.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-05-17 02:10:00'),(95,117,'Tabla Mixta',320.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-06-01 02:40:00'),(96,117,'Burrata',260.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-06-01 02:40:00'),(97,118,'Tabla Mixta',320.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-06-15 02:10:00'),(98,118,'Aros de Calamar',210.00,'Entradas',3,NULL,1,NULL,'entregado','2026-06-15 02:10:00'),(99,1,'Toast de Salmón Ahumado',230.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-06-18 20:10:00'),(100,1,'Cappuccino',75.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-06-18 20:10:00'),(101,2,'Enchiladas Suizas',220.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-06-18 20:45:00'),(102,2,'Jugo Verde',95.00,'Jugos & Smoothies',2,NULL,2,NULL,'entregado','2026-06-18 20:45:00'),(103,2,'Café Americano',65.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-06-18 20:52:00'),(104,3,'Rib Eye (450 grs.)',785.00,'Platos Fuertes',3,NULL,1,NULL,'entregado','2026-06-19 02:15:00'),(105,3,'Milano',260.00,'Pizzas',4,NULL,2,NULL,'entregado','2026-06-19 02:15:00'),(106,3,'Camarones al Ajillo',210.00,'Entradas',3,NULL,1,NULL,'entregado','2026-06-19 02:15:00'),(107,3,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,4,NULL,'entregado','2026-06-19 02:16:00'),(108,5,'Filete de Res en su Jugo',320.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-06-19 03:10:00'),(109,5,'Queso Burrata con Jitomates Cherrys',210.00,'Entradas',4,NULL,1,NULL,'entregado','2026-06-19 03:10:00'),(110,8,'Hamburguesa de la Casa',260.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-06-18 21:20:00'),(111,8,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-06-18 21:20:00'),(112,8,'Refresco',55.00,'Café & Bebidas',1,NULL,4,NULL,'entregado','2026-06-18 21:21:00'),(113,9,'Café Americano',65.00,'Café & Bebidas',1,NULL,2,NULL,'enviado','2026-08-03 06:25:26'),(114,9,'Molletes',100.00,'Desayunos',3,NULL,1,NULL,'enviado','2026-08-03 06:25:26'),(115,10,'Rigatoni al Limón con Camarones y Parmesano',280.00,'Pastas',3,NULL,2,NULL,'entregado','2026-08-03 05:48:26'),(116,10,'Milano',260.00,'Pizzas',4,NULL,1,NULL,'listo','2026-08-03 05:49:26'),(117,10,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,4,NULL,'entregado','2026-08-03 05:48:26'),(118,11,'Chilaquiles',180.00,'Desayunos',3,NULL,2,NULL,'en_preparacion','2026-08-03 06:11:26'),(119,11,'Jugo Verde',95.00,'Jugos & Smoothies',2,NULL,3,NULL,'entregado','2026-08-03 06:11:26'),(120,11,'Cappuccino',75.00,'Café & Bebidas',1,NULL,1,NULL,'listo','2026-08-03 06:12:26'),(121,12,'Toast de Salmón Ahumado',230.00,'Desayunos',3,NULL,2,NULL,'enviado','2026-08-03 06:22:26'),(122,12,'Jugo de Naranja',85.00,'Jugos & Smoothies',2,NULL,2,NULL,'enviado','2026-08-03 06:22:26'),(123,13,'Filete de Res en su Jugo',320.00,'Platos Fuertes',3,NULL,2,NULL,'en_preparacion','2026-08-03 05:56:26'),(124,13,'Aros de Calamar',210.00,'Entradas',3,NULL,1,NULL,'entregado','2026-08-03 05:56:26'),(125,13,'Refresco',55.00,'Café & Bebidas',1,NULL,4,NULL,'entregado','2026-08-03 05:57:26'),(126,14,'Burrata',260.00,'Pizzas',4,NULL,2,NULL,'en_preparacion','2026-08-03 06:07:26'),(127,14,'Tabla Mixta',320.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-08-03 06:07:26'),(128,14,'Agua Fresca',60.00,'Café & Bebidas',1,NULL,4,NULL,'entregado','2026-08-03 06:08:26'),(129,15,'Rib Eye (450 grs.)',785.00,'Platos Fuertes',3,NULL,1,NULL,'entregado','2026-08-03 05:44:26'),(130,15,'Vacío en Escalopas',280.00,'Platos Fuertes',3,NULL,1,NULL,'entregado','2026-08-03 05:44:26'),(131,15,'Espárragos al Horno',180.00,'Entradas',4,NULL,1,NULL,'listo','2026-08-03 05:45:26'),(132,15,'Café de Olla',65.00,'Café & Bebidas',1,NULL,3,NULL,'entregado','2026-08-03 05:47:26'),(133,4,'Chilaquiles',180.00,'Desayunos',3,NULL,2,NULL,'en_preparacion','2026-08-03 06:17:26'),(134,4,'Café de Olla',65.00,'Café & Bebidas',1,NULL,2,NULL,'enviado','2026-08-03 06:17:26'),(135,16,'Salmón al Horno',295.00,'Platos Fuertes',3,NULL,1,NULL,'listo','2026-08-03 06:00:26'),(136,16,'Frutos Rojos',210.00,'Ensaladas',3,NULL,1,NULL,'entregado','2026-08-03 06:00:26'),(137,16,'Té / Infusión',65.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-08-03 06:01:26'),(138,17,'Mix de 3 Brusquetas',160.00,'Para Picar',3,NULL,2,NULL,'enviado','2026-08-03 06:23:26'),(139,17,'Smoothie de Fresa',100.00,'Jugos & Smoothies',2,NULL,2,NULL,'enviado','2026-08-03 06:23:26'),(140,17,'Latte',80.00,'Café & Bebidas',1,NULL,2,NULL,'enviado','2026-08-03 06:24:26'),(141,6,'Burrata',260.00,'Pizzas',4,NULL,2,NULL,'en_preparacion','2026-08-03 06:02:26'),(142,6,'Aros de Calamar',210.00,'Entradas',3,NULL,1,NULL,'listo','2026-08-03 06:02:26'),(143,6,'Agua de Coco',90.00,'Jugos & Smoothies',2,NULL,4,NULL,'entregado','2026-08-03 06:03:26'),(144,18,'Camarones a los 4 Quesos',260.00,'Pizzas',4,NULL,2,NULL,'en_preparacion','2026-08-03 06:14:26'),(145,18,'Aceitunas Temperadas con Aceite de Chile',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-08-03 06:14:26'),(146,18,'Cappuccino',75.00,'Café & Bebidas',1,NULL,5,NULL,'listo','2026-08-03 06:15:26'),(147,19,'Café Americano',65.00,'Café & Bebidas',1,NULL,1,NULL,'enviado','2026-08-03 06:26:26'),(148,19,'Croissant con Jamón de Pavo',165.00,'Desayunos',3,NULL,1,NULL,'enviado','2026-08-03 06:26:26'),(149,20,'Baguette de Cochinita',210.00,'Desayunos',3,NULL,2,NULL,'en_preparacion','2026-08-03 06:19:26'),(150,20,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,1,NULL,'enviado','2026-08-03 06:19:26'),(151,20,'Agua de Coco',90.00,'Jugos & Smoothies',2,NULL,1,NULL,'listo','2026-08-03 06:20:26'),(152,21,'Hamburguesa de la Casa',260.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-08-03 05:53:26'),(153,21,'Tacos de Cochinita',210.00,'Platos Fuertes',3,NULL,1,NULL,'entregado','2026-08-03 05:53:26'),(154,21,'Chocolate Caliente',80.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-08-03 05:54:26'),(155,21,'Margarita',190.00,'Pizzas',4,NULL,1,NULL,'listo','2026-08-03 05:54:26'),(156,22,'Lasagna de Filete de Res',280.00,'Pastas',3,NULL,2,NULL,'en_preparacion','2026-08-03 06:05:26'),(157,22,'Carpaccio de Salmón',180.00,'Entradas',3,NULL,1,NULL,'entregado','2026-08-03 06:05:26'),(158,22,'Copa Antioxidante',130.00,'Desayunos',2,NULL,1,NULL,'entregado','2026-08-03 06:06:26'),(159,101,'Enmoladas',240.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-05-08 15:10:00'),(160,101,'Enchiladas Suizas',220.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-05-08 15:10:00'),(161,101,'Cappuccino',75.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-05-08 15:11:00'),(162,102,'Enmoladas',240.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-05-22 15:40:00'),(163,102,'Enchiladas Suizas',220.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-05-22 15:40:00'),(164,103,'Enmoladas',240.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-06-05 15:10:00'),(165,103,'Café Americano',65.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-06-05 15:11:00'),(166,104,'Spaguetti a la Boloñesa',280.00,'Pastas',3,NULL,2,NULL,'entregado','2026-05-11 20:10:00'),(167,104,'Mix de 3 Brusquetas',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-05-11 20:10:00'),(168,104,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,4,NULL,'entregado','2026-05-11 20:11:00'),(169,105,'Spaguetti a la Boloñesa',280.00,'Pastas',3,NULL,1,NULL,'entregado','2026-05-27 20:10:00'),(170,105,'Mix de 3 Brusquetas',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-05-27 20:10:00'),(171,106,'Spaguetti a la Boloñesa',280.00,'Pastas',3,NULL,2,NULL,'entregado','2026-06-10 19:40:00'),(172,106,'Crema del Día',180.00,'Sopas & Cremas',3,NULL,1,NULL,'entregado','2026-06-10 19:40:00'),(173,107,'Margarita',190.00,'Pizzas',4,NULL,3,NULL,'entregado','2026-05-09 19:10:00'),(174,107,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-05-09 19:10:00'),(175,108,'Margarita',190.00,'Pizzas',4,NULL,2,NULL,'entregado','2026-05-30 19:40:00'),(176,108,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-05-30 19:40:00'),(177,109,'Margarita',190.00,'Pizzas',4,NULL,3,NULL,'entregado','2026-06-13 19:10:00'),(178,109,'Milano',260.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-06-13 19:10:00'),(179,110,'Rib Eye (450 grs.)',785.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-05-15 01:10:00'),(180,110,'Aceitunas Temperadas con Aceite de Chile',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-05-15 01:10:00'),(181,111,'Rib Eye (450 grs.)',785.00,'Platos Fuertes',3,NULL,1,NULL,'entregado','2026-05-29 01:40:00'),(182,111,'Aceitunas Temperadas con Aceite de Chile',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-05-29 01:40:00'),(183,112,'Rib Eye (450 grs.)',785.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-06-12 01:10:00'),(184,112,'Filete de Res en su Jugo',320.00,'Platos Fuertes',3,NULL,1,NULL,'entregado','2026-06-12 01:10:00'),(185,113,'Frutos Rojos',210.00,'Ensaladas',3,NULL,1,NULL,'entregado','2026-05-15 21:10:00'),(186,113,'Crema del Día',180.00,'Sopas & Cremas',3,NULL,1,NULL,'entregado','2026-05-15 21:10:00'),(187,114,'Frutos Rojos',210.00,'Ensaladas',3,NULL,1,NULL,'entregado','2026-05-29 21:40:00'),(188,114,'Crema del Día',180.00,'Sopas & Cremas',3,NULL,1,NULL,'entregado','2026-05-29 21:40:00'),(189,115,'Frutos Rojos',210.00,'Ensaladas',3,NULL,2,NULL,'entregado','2026-06-12 21:10:00'),(190,115,'Jugo Verde',95.00,'Jugos & Smoothies',2,NULL,2,NULL,'entregado','2026-06-12 21:11:00'),(191,116,'Tabla Mixta',320.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-05-17 02:10:00'),(192,116,'Burrata',260.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-05-17 02:10:00'),(193,117,'Tabla Mixta',320.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-06-01 02:40:00'),(194,117,'Burrata',260.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-06-01 02:40:00'),(195,118,'Tabla Mixta',320.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-06-15 02:10:00'),(196,118,'Aros de Calamar',210.00,'Entradas',3,NULL,1,NULL,'entregado','2026-06-15 02:10:00'),(197,126,'Enmoladas',240.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-08-03 06:28:33'),(198,125,'Enmoladas',240.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-08-03 06:28:39'),(199,127,'Enmoladas',240.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-08-03 06:28:42'),(200,126,'Enmoladas',240.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-08-03 06:28:48');
/*!40000 ALTER TABLE `ticket_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ticket_mesas`
--

DROP TABLE IF EXISTS `ticket_mesas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_mesas` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ticket_id` int NOT NULL,
  `mesa_id` int NOT NULL,
  `orden` tinyint unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ticket_mesa` (`ticket_id`,`mesa_id`),
  UNIQUE KEY `uq_ticket_orden` (`ticket_id`,`orden`),
  KEY `idx_ticket_mesas_mesa` (`mesa_id`),
  CONSTRAINT `fk_ticket_mesas_mesa` FOREIGN KEY (`mesa_id`) REFERENCES `mesas` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ticket_mesas_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=97 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ticket_mesas`
--

LOCK TABLES `ticket_mesas` WRITE;
/*!40000 ALTER TABLE `ticket_mesas` DISABLE KEYS */;
INSERT INTO `ticket_mesas` VALUES (1,1,1,1,'2026-08-03 06:27:25'),(2,2,3,1,'2026-08-03 06:27:25'),(3,3,6,1,'2026-08-03 06:27:25'),(4,5,2,1,'2026-08-03 06:27:25'),(5,7,5,1,'2026-08-03 06:27:25'),(6,8,7,1,'2026-08-03 06:27:25'),(7,9,1,1,'2026-08-03 06:27:25'),(8,10,2,1,'2026-08-03 06:27:25'),(9,11,3,1,'2026-08-03 06:27:25'),(10,12,4,1,'2026-08-03 06:27:25'),(11,13,5,1,'2026-08-03 06:27:25'),(12,14,6,1,'2026-08-03 06:27:25'),(13,15,7,1,'2026-08-03 06:27:25'),(14,4,8,1,'2026-08-03 06:27:25'),(15,16,9,1,'2026-08-03 06:27:25'),(16,17,10,1,'2026-08-03 06:27:25'),(17,6,11,1,'2026-08-03 06:27:25'),(18,18,12,1,'2026-08-03 06:27:25'),(19,19,13,1,'2026-08-03 06:27:25'),(20,20,14,1,'2026-08-03 06:27:25'),(21,21,15,1,'2026-08-03 06:27:25'),(22,22,16,1,'2026-08-03 06:27:25'),(23,101,5,1,'2026-08-03 06:27:25'),(24,102,5,1,'2026-08-03 06:27:25'),(25,103,1,1,'2026-08-03 06:27:25'),(26,104,3,1,'2026-08-03 06:27:25'),(27,105,3,1,'2026-08-03 06:27:25'),(28,106,4,1,'2026-08-03 06:27:25'),(29,107,6,1,'2026-08-03 06:27:25'),(30,108,6,1,'2026-08-03 06:27:25'),(31,109,7,1,'2026-08-03 06:27:25'),(32,110,2,1,'2026-08-03 06:27:25'),(33,111,2,1,'2026-08-03 06:27:25'),(34,112,4,1,'2026-08-03 06:27:25'),(35,113,8,1,'2026-08-03 06:27:25'),(36,114,8,1,'2026-08-03 06:27:25'),(37,115,9,1,'2026-08-03 06:27:25'),(38,116,11,1,'2026-08-03 06:27:25'),(39,117,10,1,'2026-08-03 06:27:25'),(40,118,11,1,'2026-08-03 06:27:25'),(41,119,5,1,'2026-08-03 06:27:26'),(42,119,6,2,'2026-08-03 06:27:26'),(43,120,6,1,'2026-08-03 06:27:26'),(44,121,10,1,'2026-08-03 06:27:26'),(45,122,1,1,'2026-08-03 06:27:26'),(46,122,11,2,'2026-08-03 06:27:26'),(90,125,10,1,'2026-08-03 06:27:26'),(91,126,1,1,'2026-08-03 06:27:26'),(92,126,11,2,'2026-08-03 06:27:26'),(93,127,8,1,'2026-08-03 06:27:37'),(94,127,9,2,'2026-08-03 06:27:37'),(95,128,1,1,'2026-08-03 06:32:24'),(96,128,3,2,'2026-08-03 06:32:24');
/*!40000 ALTER TABLE `ticket_mesas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ticket_pagos`
--

DROP TABLE IF EXISTS `ticket_pagos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_pagos` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ticket_id` int NOT NULL,
  `comensal` tinyint unsigned NOT NULL,
  `metodo_pago` enum('efectivo','tarjeta') NOT NULL,
  `monto` decimal(8,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tp_ticket` (`ticket_id`),
  CONSTRAINT `ticket_pagos_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ticket_pagos`
--

LOCK TABLES `ticket_pagos` WRITE;
/*!40000 ALTER TABLE `ticket_pagos` DISABLE KEYS */;
/*!40000 ALTER TABLE `ticket_pagos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tickets`
--

DROP TABLE IF EXISTS `tickets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tickets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `comensales` int NOT NULL DEFAULT '1',
  `nombre` varchar(120) DEFAULT NULL,
  `hora_apertura` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `closed_at` datetime DEFAULT NULL,
  `hora_cierre` datetime DEFAULT NULL,
  `estado` enum('abierto','cerrado','cancelado') NOT NULL DEFAULT 'abierto',
  `metodo_pago` enum('efectivo','tarjeta','dividido') DEFAULT NULL,
  `propina` decimal(8,2) NOT NULL DEFAULT '0.00',
  `reservacion_id` int DEFAULT NULL,
  `mesero_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ticket_reservacion` (`reservacion_id`),
  KEY `mesero_id` (`mesero_id`),
  KEY `idx_ticket_estado` (`estado`),
  KEY `idx_ticket_reservacion` (`reservacion_id`),
  CONSTRAINT `tickets_ibfk_1` FOREIGN KEY (`reservacion_id`) REFERENCES `reservaciones` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tickets_ibfk_2` FOREIGN KEY (`mesero_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=129 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tickets`
--

LOCK TABLES `tickets` WRITE;
/*!40000 ALTER TABLE `tickets` DISABLE KEYS */;
INSERT INTO `tickets` VALUES (1,2,'Camila Estrada','2026-08-01 14:05:00','2026-08-01 15:35:00','2026-08-01 15:35:00','cerrado','tarjeta',129.20,NULL,3),(2,4,'Javier Montiel','2026-07-31 14:40:00','2026-07-31 16:10:00','2026-07-31 16:10:00','cerrado','efectivo',182.40,NULL,4),(3,6,'Familia Guerrero','2026-07-30 20:10:00','2026-07-30 21:40:00','2026-07-30 21:40:00','cerrado','tarjeta',617.10,NULL,3),(4,2,'Sofía Pedraza','2026-07-29 00:15:25','2026-07-29 01:45:25','2026-07-29 01:45:25','cerrado',NULL,0.00,NULL,NULL),(5,4,'Nicolás Andrade','2026-07-28 21:05:00','2026-07-28 22:35:00','2026-07-28 22:35:00','cerrado','tarjeta',204.00,NULL,4),(6,4,'Fernanda & Roque','2026-07-27 00:00:25','2026-07-27 01:30:25','2026-07-27 01:30:25','cerrado',NULL,0.00,NULL,NULL),(7,3,'Mesa 5','2026-06-18 16:00:00',NULL,NULL,'cancelado','efectivo',0.00,NULL,NULL),(8,4,'Grupo Torres','2026-07-25 15:15:00','2026-07-25 16:45:00','2026-07-25 16:45:00','cerrado','efectivo',169.60,NULL,6),(9,2,'Ana Villalobos','2026-07-24 00:24:25','2026-07-24 01:54:25','2026-07-24 01:54:25','cerrado',NULL,0.00,NULL,NULL),(10,4,'Renata Ibáñez','2026-07-23 23:46:25','2026-07-24 01:16:25','2026-07-24 01:16:25','cerrado',NULL,0.00,NULL,NULL),(11,3,'Javier Montiel','2026-07-22 00:09:25','2026-07-22 01:39:25','2026-07-22 01:39:25','cerrado',NULL,0.00,NULL,NULL),(12,2,'Diego Lozano','2026-07-21 00:20:25','2026-07-21 01:50:25','2026-07-21 01:50:25','cerrado',NULL,0.00,NULL,NULL),(13,4,'Familia Cuevas','2026-07-20 23:54:25','2026-07-21 01:24:25','2026-07-21 01:24:25','cerrado',NULL,0.00,NULL,NULL),(14,4,'Familia Guerrero','2026-07-19 00:05:25','2026-07-19 01:35:25','2026-07-19 01:35:25','cerrado',NULL,0.00,NULL,NULL),(15,3,'Grupo Salinas','2026-07-18 23:42:25','2026-07-19 01:12:25','2026-07-19 01:12:25','cerrado',NULL,0.00,NULL,NULL),(16,2,'Mauricio Trejo','2026-07-17 23:58:25','2026-07-18 01:28:25','2026-07-18 01:28:25','cerrado',NULL,0.00,NULL,NULL),(17,4,'Lucía Bermúdez','2026-07-16 00:22:25','2026-07-16 01:52:25','2026-07-16 01:52:25','cerrado',NULL,0.00,NULL,NULL),(18,5,'Grupo Peralta','2026-07-15 00:12:25','2026-07-15 01:42:25','2026-07-15 01:42:25','cerrado',NULL,0.00,NULL,NULL),(19,1,'Caja','2026-07-14 00:26:25','2026-07-14 01:56:25','2026-07-14 01:56:25','cerrado',NULL,0.00,NULL,NULL),(20,1,'Llevar','2026-07-13 00:18:25','2026-07-13 01:48:25','2026-07-13 01:48:25','cerrado',NULL,0.00,NULL,NULL),(21,4,'Tomás Iriarte','2026-07-12 23:51:25','2026-07-13 01:21:25','2026-07-13 01:21:25','cerrado',NULL,0.00,NULL,NULL),(22,3,'Paulina Cortés','2026-07-11 00:03:25','2026-07-11 01:33:25','2026-07-11 01:33:25','cerrado',NULL,0.00,NULL,NULL),(101,2,'Camila Estrada','2026-06-20 09:05:00','2026-06-20 10:35:00','2026-06-20 10:35:00','cerrado','tarjeta',207.40,NULL,3),(102,2,'Camila Estrada','2026-06-19 09:35:00','2026-06-19 11:05:00','2026-06-19 11:05:00','cerrado','tarjeta',156.40,NULL,3),(103,2,'Camila Estrada','2026-06-18 09:05:00','2026-06-18 10:35:00','2026-06-18 10:35:00','cerrado','tarjeta',103.70,NULL,3),(104,4,'Javier Montiel','2026-06-17 14:05:00','2026-06-17 15:35:00','2026-06-17 15:35:00','cerrado','efectivo',346.80,NULL,3),(105,3,'Javier Montiel','2026-06-16 14:05:00','2026-06-16 15:35:00','2026-06-16 15:35:00','cerrado','tarjeta',149.60,NULL,3),(106,4,'Javier Montiel','2026-06-15 13:35:00','2026-06-15 15:05:00','2026-06-15 15:05:00','cerrado','efectivo',251.60,NULL,3),(107,6,'Familia Guerrero','2026-06-14 13:05:00','2026-06-14 14:35:00','2026-06-14 14:35:00','cerrado','tarjeta',213.60,NULL,4),(108,5,'Familia Guerrero','2026-06-13 13:35:00','2026-06-13 15:05:00','2026-06-13 15:05:00','cerrado','tarjeta',129.60,NULL,4),(109,6,'Familia Guerrero','2026-06-12 13:05:00','2026-06-12 14:35:00','2026-06-12 14:35:00','cerrado','tarjeta',199.20,NULL,4),(110,4,'Nicolas Andrade','2026-06-11 19:05:00','2026-06-11 20:35:00','2026-06-11 20:35:00','cerrado','tarjeta',415.20,NULL,4),(111,2,'Nicolas Andrade','2026-06-10 19:35:00','2026-06-10 21:05:00','2026-06-10 21:05:00','cerrado','tarjeta',226.80,NULL,4),(112,4,'Nicolas Andrade','2026-06-09 19:05:00','2026-06-09 20:35:00','2026-06-09 20:35:00','cerrado','tarjeta',453.60,NULL,4),(113,2,'Sofia Pedraza','2026-06-08 15:05:00','2026-06-08 16:35:00','2026-06-08 16:35:00','cerrado','efectivo',62.40,NULL,6),(114,2,'Sofia Pedraza','2026-06-07 15:35:00','2026-06-07 17:05:00','2026-06-07 17:05:00','cerrado','tarjeta',62.40,NULL,6),(115,3,'Sofia Pedraza','2026-06-06 15:05:00','2026-06-06 16:35:00','2026-06-06 16:35:00','cerrado','tarjeta',97.60,NULL,6),(116,2,'Fernanda & Roque','2026-08-02 20:05:00','2026-08-02 21:35:00','2026-08-02 21:35:00','cerrado','tarjeta',92.80,NULL,6),(117,4,'Fernanda & Roque','2026-08-01 20:35:00','2026-08-01 22:05:00','2026-08-01 22:05:00','cerrado','tarjeta',144.00,NULL,6),(118,2,'Fernanda & Roque','2026-07-31 20:05:00','2026-07-31 21:35:00','2026-07-31 21:35:00','cerrado','tarjeta',84.80,NULL,6),(119,6,'POS En Curso','2026-11-30 20:00:00','2026-11-30 12:00:00',NULL,'cerrado',NULL,0.00,27,NULL),(120,2,'POS Completada','2026-11-27 18:00:00','2026-11-27 19:30:00',NULL,'cerrado','efectivo',0.00,28,NULL),(121,2,'Walk-in Una Mesa','2026-07-28 20:10:00','2026-07-28 21:40:00','2026-07-28 21:40:00','cerrado',NULL,0.00,NULL,NULL),(122,6,'Walk-in Varias Mesas','2026-07-27 20:15:00','2026-07-27 21:45:00','2026-07-27 21:45:00','cerrado',NULL,0.00,NULL,NULL),(125,2,'Walk-in Una Mesa','2026-11-30 20:10:00','2026-08-03 00:29:08','2026-08-03 00:29:08','cerrado','efectivo',0.00,NULL,NULL),(126,6,'Walk-in Varias Mesas','2026-11-30 20:15:00','2026-08-03 00:29:04','2026-08-03 00:29:04','cerrado','efectivo',0.00,NULL,NULL),(127,2,NULL,'2026-08-03 00:27:37','2026-08-03 00:29:14','2026-08-03 00:29:14','cerrado','efectivo',0.00,NULL,NULL),(128,2,NULL,'2026-08-03 00:32:24',NULL,NULL,'abierto',NULL,0.00,NULL,NULL);
/*!40000 ALTER TABLE `tickets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `usuarios`
--

DROP TABLE IF EXISTS `usuarios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `usuarios` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `nombre` varchar(120) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `nip_hash` varchar(255) DEFAULT NULL,
  `rol` enum('admin','observer','waiter','cashier') NOT NULL DEFAULT 'observer',
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `usuarios`
--

LOCK TABLES `usuarios` WRITE;
/*!40000 ALTER TABLE `usuarios` DISABLE KEYS */;
INSERT INTO `usuarios` VALUES (1,'admin_demo','Administrador Demo','$2y$12$qH/BVO2OPCYRbt7rUfYtIecXWTXOSk8hxWavaadrcfbwEnIHsXXd.',NULL,'admin',1,'2026-08-03 06:27:25',NULL),(2,'observador1','Observador General','$2y$10$wH8Lm8rMjYpPqOQF3AbY3eGy7PV8wzg6kgAYZ3i.E2oQ1FjiZ3Xj2','$2y$12$cn/3L8mkab6QsELxVwjUY.l9X32LeGBtHW0r0MKQEW/LH9doaPgoa','observer',1,'2026-08-03 06:27:25','2026-08-03 06:27:25'),(3,'mesero1','Carlos Hernández','$2y$10$wH8Lm8rMjYpPqOQF3AbY3eGy7PV8wzg6kgAYZ3i.E2oQ1FjiZ3Xj2','$2y$12$Jkhr3umCEYaNQY4OSGedgOu5eHImaGx1PtjXSMY9hXn3Zqu1OmReW','waiter',1,'2026-08-03 06:27:25','2026-08-03 06:27:25'),(4,'mesero2','Valeria Ríos','$2y$10$wH8Lm8rMjYpPqOQF3AbY3eGy7PV8wzg6kgAYZ3i.E2oQ1FjiZ3Xj2',NULL,'waiter',1,'2026-08-03 06:27:25',NULL),(5,'cajero1','Mariana López','$2y$10$wH8Lm8rMjYpPqOQF3AbY3eGy7PV8wzg6kgAYZ3i.E2oQ1FjiZ3Xj2','$2y$12$bb8wu.UY6FK8vBzU4E5X6uAZq3lZwzfSOn4kXcG9vRuV9eFMXF1MW','cashier',1,'2026-08-03 06:27:25','2026-08-03 06:27:25'),(6,'mesero3','Emilio Cárdenas','$2y$10$wH8Lm8rMjYpPqOQF3AbY3eGy7PV8wzg6kgAYZ3i.E2oQ1FjiZ3Xj2',NULL,'waiter',1,'2026-08-03 06:27:25',NULL),(7,'mesero_inactivo','Daniel Torres','$2y$10$wH8Lm8rMjYpPqOQF3AbY3eGy7PV8wzg6kgAYZ3i.E2oQ1FjiZ3Xj2',NULL,'waiter',0,'2026-08-03 06:27:25',NULL);
/*!40000 ALTER TABLE `usuarios` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `verificaciones_contacto`
--

DROP TABLE IF EXISTS `verificaciones_contacto`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `verificaciones_contacto` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `reservacion_id` int DEFAULT NULL,
  `contacto_tipo` enum('email','telefono') NOT NULL,
  `contacto` varchar(150) NOT NULL,
  `codigo_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `attempts` tinyint unsigned NOT NULL DEFAULT '0',
  `used_at` datetime DEFAULT NULL,
  `invalidated_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_verificacion_contacto` (`contacto_tipo`,`contacto`,`created_at`),
  KEY `idx_verificacion_reservacion` (`reservacion_id`),
  KEY `idx_verificacion_expiracion` (`expires_at`),
  CONSTRAINT `fk_verificacion_reservacion` FOREIGN KEY (`reservacion_id`) REFERENCES `reservaciones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `verificaciones_contacto`
--

LOCK TABLES `verificaciones_contacto` WRITE;
/*!40000 ALTER TABLE `verificaciones_contacto` DISABLE KEYS */;
INSERT INTO `verificaciones_contacto` VALUES (1,65,'email','leonardo.velasco.oj@gmail.com','$2y$10$9hEmcIgmM3hTPrTFF2Q5peLKl6EZ/1w7aRf7Bf/eREe7JRufGi8Ne','2026-08-03 00:34:56',0,'2026-08-03 00:29:58',NULL,'2026-08-03 06:29:56');
/*!40000 ALTER TABLE `verificaciones_contacto` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping events for database 'casa-pestalozzi'
--

--
-- Dumping routines for database 'casa-pestalozzi'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-08-03  0:41:17
