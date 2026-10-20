-- MySQL dump 10.13  Distrib 8.0.46, for Linux (x86_64)
--
-- Host: localhost    Database: casa_pestalozzi
-- ------------------------------------------------------
-- Server version	8.0.46

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
INSERT INTO `areas_produccion` VALUES (1,'Barra de Café','cafe','#46bdc6'),(2,'Barra de Jugos','jugos','#f5b400'),(3,'Cocina','cocina','#e51022'),(4,'Horno Napolitano','horno','#4267ac');
/*!40000 ALTER TABLE `areas_produccion` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `buzon_notificaciones`
--

DROP TABLE IF EXISTS `buzon_notificaciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `buzon_notificaciones` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tipo` varchar(80) NOT NULL,
  `modulo` varchar(60) NOT NULL,
  `entidad_tipo` varchar(80) NOT NULL,
  `entidad_id` int unsigned NOT NULL,
  `prioridad` enum('normal','alta') NOT NULL DEFAULT 'normal',
  `requiere_accion` tinyint(1) NOT NULL DEFAULT '1',
  `visible_from` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `leida_at` datetime DEFAULT NULL,
  `cerrada_at` datetime DEFAULT NULL,
  `cerrada_por` int DEFAULT NULL,
  `cierre_motivo` varchar(120) DEFAULT NULL,
  `dedup_key` varchar(191) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_buzon_notificaciones_dedup` (`dedup_key`),
  KEY `fk_buzon_notificaciones_usuario` (`cerrada_por`),
  KEY `idx_buzon_notificaciones_visibles` (`cerrada_at`,`visible_from`),
  KEY `idx_buzon_notificaciones_tipo` (`tipo`,`cerrada_at`),
  KEY `idx_buzon_notificaciones_accion` (`cerrada_at`,`visible_from`,`requiere_accion`),
  KEY `idx_buzon_notificaciones_entidad` (`entidad_tipo`,`entidad_id`),
  CONSTRAINT `fk_buzon_notificaciones_usuario` FOREIGN KEY (`cerrada_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=14797 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `buzon_notificaciones`
--

LOCK TABLES `buzon_notificaciones` WRITE;
/*!40000 ALTER TABLE `buzon_notificaciones` DISABLE KEYS */;
INSERT INTO `buzon_notificaciones` VALUES (1,'reservacion_sin_asignacion_proxima','reservaciones','reservacion',115,'normal',1,'2026-09-07 14:31:44',NULL,'2026-09-07 21:11:31',NULL,'reservacion_ausencia_pendiente','reservacion_sin_asignacion_proxima:115','2026-09-07 20:30:11','2026-09-08 03:11:31'),(3,'reservacion_ausencia_pendiente','reservaciones','reservacion',115,'alta',1,'2026-10-12 23:27:56',NULL,NULL,NULL,NULL,'reservacion_ausencia_pendiente:115','2026-09-08 03:11:30','2026-10-13 05:27:56'),(4,'reservacion_ausencia_pendiente','reservaciones','reservacion',114,'alta',1,'2026-10-12 23:27:57',NULL,NULL,NULL,NULL,'reservacion_ausencia_pendiente:114','2026-09-08 03:11:31','2026-10-13 05:27:57'),(55,'reservacion_ausencia_pendiente','reservaciones','reservacion',23,'alta',1,'2026-10-12 23:27:57',NULL,NULL,NULL,NULL,'reservacion_ausencia_pendiente:23','2026-09-14 04:48:36','2026-10-13 05:27:57'),(56,'reservacion_ausencia_pendiente','reservaciones','reservacion',24,'alta',1,'2026-10-12 23:27:57',NULL,NULL,NULL,NULL,'reservacion_ausencia_pendiente:24','2026-09-14 04:48:36','2026-10-13 05:27:57'),(57,'reservacion_ausencia_pendiente','reservaciones','reservacion',5,'alta',1,'2026-10-12 23:27:57',NULL,NULL,NULL,NULL,'reservacion_ausencia_pendiente:5','2026-09-14 04:48:36','2026-10-13 05:27:57'),(58,'reservacion_ausencia_pendiente','reservaciones','reservacion',11,'alta',1,'2026-10-12 23:27:57',NULL,NULL,NULL,NULL,'reservacion_ausencia_pendiente:11','2026-09-14 04:48:36','2026-10-13 05:27:57'),(59,'reservacion_ausencia_pendiente','reservaciones','reservacion',10,'alta',1,'2026-10-12 23:27:57',NULL,NULL,NULL,NULL,'reservacion_ausencia_pendiente:10','2026-09-14 04:48:36','2026-10-13 05:27:57'),(60,'reservacion_ausencia_pendiente','reservaciones','reservacion',22,'alta',1,'2026-10-12 23:27:57',NULL,NULL,NULL,NULL,'reservacion_ausencia_pendiente:22','2026-09-14 04:48:36','2026-10-13 05:27:57');
/*!40000 ALTER TABLE `buzon_notificaciones` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `catas`
--

DROP TABLE IF EXISTS `catas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `catas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `titulo` varchar(120) NOT NULL,
  `descripcion` text,
  `fecha` date NOT NULL,
  `hora` time NOT NULL,
  `duracion_min` smallint unsigned NOT NULL DEFAULT '90',
  `precio` decimal(10,2) NOT NULL DEFAULT '0.00',
  `disponible` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_catas_agenda` (`fecha`,`hora`),
  CONSTRAINT `chk_catas_duracion` CHECK ((`duracion_min` > 0)),
  CONSTRAINT `chk_catas_precio` CHECK ((`precio` >= 0))
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `catas`
--

LOCK TABLES `catas` WRITE;
/*!40000 ALTER TABLE `catas` DISABLE KEYS */;
INSERT INTO `catas` VALUES (1,'Tintos de Guanajuato','Cinco etiquetas de la región con maridaje de quesos curados y pan de masa madre de la casa.','2026-09-19','19:30:00',90,850.00,1,'2026-09-07 20:28:20',NULL),(2,'Espumosos y postres','Cierre dulce: tres espumosos contra la carta de postres del chef.','2026-10-17','19:00:00',75,780.00,1,'2026-09-07 20:28:20',NULL),(3,'Blancos y mariscos','Recorrido por blancos frescos del Valle de Guadalupe junto a nuestra barra de mariscos.','2026-10-03','20:00:00',120,990.00,0,'2026-09-07 20:28:20',NULL),(4,'Cata de aceites de oliva','En preparación: aún sin fecha confirmada con el productor.','2026-11-06','18:30:00',60,640.00,0,'2026-09-07 20:28:20',NULL);
/*!40000 ALTER TABLE `catas` ENABLE KEYS */;
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
  `carta` enum('comida','maridaje') NOT NULL DEFAULT 'comida',
  `img` varchar(200) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `idx_categorias_carta` (`carta`,`id`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `categorias`
--

LOCK TABLES `categorias` WRITE;
/*!40000 ALTER TABLE `categorias` DISABLE KEYS */;
INSERT INTO `categorias` VALUES (1,'Desayunos','comida','build/images/comida-4.webp',1),(2,'Entradas','comida','build/images/comida-9.webp',1),(3,'Sopas & Cremas','comida','build/images/comida-7.webp',1),(4,'Pastas','comida','build/images/mejor-2.webp',1),(5,'Platos Fuertes','comida','build/images/mejor-6.webp',1),(6,'Ensaladas','comida','build/images/comida-2.webp',1),(7,'Pizzas','comida','build/images/pizza-3.webp',1),(8,'Para Picar','comida','build/images/comida-6.webp',1),(9,'Café & Bebidas','comida','build/images/comida-1.webp',1),(10,'Jugos & Smoothies','comida','build/images/comida-2.webp',1),(11,'Tablas de Degustación','maridaje','build/images/comida-6.webp',1),(12,'Cocktails','maridaje','build/images/comida-1.webp',1),(13,'Cervezas','maridaje','build/images/comida-9.webp',1),(14,'Bebidas sin Alcohol','maridaje','build/images/comida-2.webp',1);
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
INSERT INTO `configuracion_anuncio` VALUES (1,'Este sábado tendremos música en vivo a partir de las 19:00 h. Te esperamos.','evento',1,NULL,NULL,'Reservar mesa','/reservaciones',NULL,'2026-09-07 20:28:20');
/*!40000 ALTER TABLE `configuracion_anuncio` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `configuracion_modulos`
--

DROP TABLE IF EXISTS `configuracion_modulos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `configuracion_modulos` (
  `clave` varchar(40) NOT NULL,
  `visible` tinyint(1) NOT NULL DEFAULT '1',
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`clave`),
  KEY `fk_configuracion_modulos_usuario` (`updated_by`),
  CONSTRAINT `fk_configuracion_modulos_usuario` FOREIGN KEY (`updated_by`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `configuracion_modulos`
--

LOCK TABLES `configuracion_modulos` WRITE;
/*!40000 ALTER TABLE `configuracion_modulos` DISABLE KEYS */;
INSERT INTO `configuracion_modulos` VALUES ('analytics',1,1,'2026-10-11 22:02:52'),('area',1,1,'2026-10-11 22:02:48'),('catas',1,1,'2026-10-11 22:02:48'),('configuration',1,1,'2026-10-11 22:02:48'),('feedback',1,1,'2026-10-11 22:02:48'),('finanzas',1,1,'2026-10-11 22:02:48'),('inventario',1,1,'2026-10-11 22:02:48'),('menu',1,1,'2026-10-11 22:02:48'),('pdv',1,1,'2026-10-11 22:02:48'),('printers',1,1,'2026-10-11 22:02:48'),('productos',1,1,'2026-10-11 22:02:48'),('reservations',1,1,'2026-10-11 22:02:48'),('tickets',1,1,'2026-10-11 22:02:48'),('users',1,1,'2026-10-11 22:02:48');
/*!40000 ALTER TABLE `configuracion_modulos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `configuracion_pos`
--

DROP TABLE IF EXISTS `configuracion_pos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `configuracion_pos` (
  `id` tinyint unsigned NOT NULL,
  `mesero_editable` tinyint(1) NOT NULL DEFAULT '1',
  `impresion_activa` tinyint(1) NOT NULL DEFAULT '1',
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_configuracion_pos_usuario` (`updated_by`),
  CONSTRAINT `fk_configuracion_pos_usuario` FOREIGN KEY (`updated_by`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `configuracion_pos`
--

LOCK TABLES `configuracion_pos` WRITE;
/*!40000 ALTER TABLE `configuracion_pos` DISABLE KEYS */;
INSERT INTO `configuracion_pos` VALUES (1,1,0,1,'2026-10-02 23:11:49');
/*!40000 ALTER TABLE `configuracion_pos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `configuracion_reservaciones`
--

DROP TABLE IF EXISTS `configuracion_reservaciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `configuracion_reservaciones` (
  `id` tinyint unsigned NOT NULL,
  `recordatorio_dia_anterior_activo` tinyint(1) NOT NULL DEFAULT '0',
  `hora_recordatorio` time NOT NULL DEFAULT '18:00:00',
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_configuracion_reservaciones_usuario` (`updated_by`),
  CONSTRAINT `fk_configuracion_reservaciones_usuario` FOREIGN KEY (`updated_by`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `configuracion_reservaciones`
--

LOCK TABLES `configuracion_reservaciones` WRITE;
/*!40000 ALTER TABLE `configuracion_reservaciones` DISABLE KEYS */;
INSERT INTO `configuracion_reservaciones` VALUES (1,0,'18:00:00',NULL,'2026-09-07 20:17:48');
/*!40000 ALTER TABLE `configuracion_reservaciones` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `dias_reservacion`
--

DROP TABLE IF EXISTS `dias_reservacion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dias_reservacion` (
  `id` int NOT NULL AUTO_INCREMENT,
  `dia_semana` tinyint NOT NULL COMMENT '0=Dom 1=Lun 2=Mar 3=Mie 4=Jue 5=Vie 6=Sab',
  `nombre` varchar(20) NOT NULL,
  `hora_apertura` time NOT NULL,
  `hora_cierre` time NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dias_reservacion_dia_semana` (`dia_semana`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `dias_reservacion`
--

LOCK TABLES `dias_reservacion` WRITE;
/*!40000 ALTER TABLE `dias_reservacion` DISABLE KEYS */;
INSERT INTO `dias_reservacion` VALUES (1,0,'Domingo','08:30:00','19:00:00',1),(2,1,'Lunes','08:30:00','15:00:00',1),(3,2,'Martes','08:30:00','22:00:00',1),(4,3,'Miércoles','08:30:00','22:00:00',1),(5,4,'Jueves','08:30:00','22:00:00',1),(6,5,'Viernes','08:30:00','22:00:00',1),(7,6,'Sábado','08:30:00','22:00:00',1);
/*!40000 ALTER TABLE `dias_reservacion` ENABLE KEYS */;
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
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `excepciones_operacion`
--

LOCK TABLES `excepciones_operacion` WRITE;
/*!40000 ALTER TABLE `excepciones_operacion` DISABLE KEYS */;
INSERT INTO `excepciones_operacion` VALUES (1,'2026-09-09','horario_especial','Comida privada, abrimos más tarde','16:00:00','23:00:00',1,NULL,'2026-09-07 20:28:20','2026-09-07 20:28:20'),(2,'2026-09-12','horario_especial','Horario especial de prueba','14:00:00','21:00:00',1,NULL,'2026-09-07 20:28:20',NULL);
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
) ENGINE=InnoDB AUTO_INCREMENT=37 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `feedback`
--

LOCK TABLES `feedback` WRITE;
/*!40000 ALTER TABLE `feedback` DISABLE KEYS */;
INSERT INTO `feedback` VALUES (1,NULL,1,5,5,4,5,'Todo excelente, el salmón estaba delicioso y el servicio muy atento.','2026-06-18 14:50:00'),(2,NULL,2,4,5,3,4,'Muy rica la comida, aunque tardó un poco en llegar.','2026-06-18 15:30:00'),(3,NULL,3,5,4,4,5,'Celebramos un cumpleaños y quedamos encantados. Volveremos.','2026-06-18 22:00:00'),(4,NULL,5,5,5,5,5,'Experiencia impecable de principio a fin. El filete, espectacular.','2026-06-18 22:15:00'),(5,NULL,8,3,4,2,3,'La hamburguesa buena, pero esperamos demasiado por la cuenta.','2026-06-18 16:10:00'),(6,NULL,2,4,4,4,4,'Buen ambiente y sazón. Repetiría el jugo verde.','2026-06-18 15:35:00'),(7,NULL,1,5,5,5,5,'El mejor desayuno de la Del Valle, sin duda.','2026-06-18 14:55:00'),(8,NULL,3,2,3,2,2,'La pizza llegó fría y tardaron en atendernos.','2026-06-18 22:05:00'),(9,NULL,3,2,4,3,3,'La Pizza Milano llegó tibia; de sabor bien, pero fría le resta mucho.','2026-06-19 21:40:00'),(10,NULL,5,3,4,3,3,'El filete pedido término medio llegó casi bien cocido. La próxima lo cuidaré.','2026-06-19 21:55:00'),(11,NULL,NULL,2,3,3,2,'Los chilaquiles llegaron aguados y el huevo frío. Esperábamos más.','2026-06-20 10:15:00'),(12,NULL,3,5,5,4,5,'El Rib Eye estaba en su punto perfecto, jugoso y caliente. Excelente cocina.','2026-06-20 21:10:00'),(13,NULL,8,4,4,2,3,'Comida muy buena, pero esperamos casi 20 minutos para que trajeran la cuenta.','2026-06-19 16:20:00'),(14,NULL,2,4,5,2,3,'Todo rico, aunque cobrar con tarjeta tomó mucho tiempo. La terminal fallaba.','2026-06-20 15:05:00'),(15,NULL,NULL,5,5,2,4,'Amamos el lugar, pero el cierre de cuenta en hora pico es eterno.','2026-06-20 15:40:00'),(16,NULL,NULL,4,3,2,3,'Sábado lleno: la comida tardó más de 40 minutos en salir.','2026-06-20 21:30:00'),(17,NULL,NULL,4,4,2,3,'Rico todo, pero tardaron mucho en tomarnos la orden al inicio.','2026-06-21 14:10:00'),(18,NULL,2,3,2,3,3,'Tuvimos que pedir el agua y los cubiertos dos veces. Faltó seguimiento a la mesa.','2026-06-21 14:25:00'),(19,NULL,5,5,5,5,5,'Ricardo, nuestro mesero, fue atentísimo y nos recomendó de maravilla. ¡Un lujo!','2026-06-21 21:15:00'),(20,NULL,NULL,4,2,4,3,'La comida bien, pero el mesero se veía saturado y algo cortante.','2026-06-22 15:30:00'),(21,NULL,1,5,5,5,5,'Nos reconocieron como clientes frecuentes y hasta una cortesía nos dieron. ¡Gracias!','2026-06-22 09:40:00'),(22,NULL,NULL,5,5,4,4,'Comida deliciosa, pero la música estaba tan alta que costaba conversar.','2026-06-22 21:50:00'),(23,NULL,NULL,4,4,4,3,'Buen lugar, aunque el aire acondicionado estaba muy frío en la zona del ventanal.','2026-06-23 14:20:00'),(24,NULL,3,4,5,4,5,'La terraza es hermosa y muy tranquila para comer en familia.','2026-06-23 15:10:00'),(25,NULL,NULL,4,4,4,3,'La comida bien, pero los baños necesitaban atención a media tarde.','2026-06-23 17:05:00'),(26,NULL,NULL,4,4,4,3,'Sabor bueno, pero las porciones se me hicieron chicas para el precio.','2026-06-24 14:45:00'),(27,NULL,8,5,5,4,5,'La Hamburguesa de la Casa vale cada peso. Enorme y sabrosa.','2026-06-24 15:20:00'),(28,NULL,1,3,4,4,3,'El cappuccino llegó tibio y sin mucha espuma. El desayuno sí muy rico.','2026-06-24 09:30:00'),(29,NULL,2,5,4,4,5,'El jugo verde y el café de olla, espectaculares. Mi desayuno favorito.','2026-06-25 09:15:00'),(30,NULL,NULL,4,5,4,4,'Todo muy rico, pero me gustaría ver más opciones vegetarianas y sin gluten.','2026-06-25 14:35:00'),(31,NULL,NULL,5,5,4,4,'La comida excelente; ojalá tuvieran más variedad de postres.','2026-06-25 21:25:00'),(32,NULL,NULL,3,3,3,2,'Nos trajeron un platillo equivocado y tuvimos que esperar a que lo corrigieran.','2026-06-26 15:00:00'),(33,NULL,NULL,5,4,3,4,'Reservamos pero la mesa no estaba lista a la hora acordada. Lo demás, muy bien.','2026-06-26 21:10:00'),(34,NULL,3,5,5,5,5,'Fuimos con niños y los atendieron increíble. Menú infantil por favor.','2026-06-27 14:15:00'),(35,NULL,5,5,5,4,5,'Festejamos un aniversario y todo fue perfecto. El postre de cortesía, un detallazo.','2026-06-27 21:40:00'),(36,NULL,NULL,5,5,5,5,'De lo mejor de la Del Valle. Volveremos muy pronto, todo impecable.','2026-06-28 15:30:00');
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
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `feedback_tokens`
--

LOCK TABLES `feedback_tokens` WRITE;
/*!40000 ALTER TABLE `feedback_tokens` DISABLE KEYS */;
INSERT INTO `feedback_tokens` VALUES (1,121,'77a4abe15ae2e21c641ab163e4444315',0,'2026-09-30 15:18:39'),(6,506,'d23859006ff05144b2d25a8ede693164',0,'2026-10-05 03:07:12');
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
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gastos_fijos`
--

LOCK TABLES `gastos_fijos` WRITE;
/*!40000 ALTER TABLE `gastos_fijos` DISABLE KEYS */;
INSERT INTO `gastos_fijos` VALUES (1,'Renta del local','renta',45000.00,1,'2026-09-07 20:28:20','2026-09-07 20:28:20'),(2,'Luz (CFE)','servicios',8000.00,1,'2026-09-07 20:28:20','2026-09-07 20:28:20'),(3,'Agua','servicios',2500.00,1,'2026-09-07 20:28:20','2026-09-07 20:28:20'),(4,'Gas','servicios',4000.00,1,'2026-09-07 20:28:20','2026-09-07 20:28:20'),(5,'Internet y teléfono','servicios',1200.00,1,'2026-09-07 20:28:20','2026-09-07 20:28:20'),(6,'Nómina','nomina',60000.00,1,'2026-09-07 20:28:20','2026-09-07 20:28:20');
/*!40000 ALTER TABLE `gastos_fijos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `historial_precios`
--

DROP TABLE IF EXISTS `historial_precios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `historial_precios` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `entidad` enum('producto','ingrediente') NOT NULL,
  `ref_id` int unsigned NOT NULL,
  `precio_anterior` decimal(10,4) DEFAULT NULL,
  `precio_nuevo` decimal(10,4) NOT NULL,
  `motivo` enum('alta','edicion','proveedor') NOT NULL DEFAULT 'edicion',
  `proveedor_id` int unsigned DEFAULT NULL,
  `usuario_id` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_hp_proveedor` (`proveedor_id`),
  KEY `fk_hp_usuario` (`usuario_id`),
  KEY `idx_hp_entidad` (`entidad`,`ref_id`,`created_at`),
  CONSTRAINT `fk_hp_proveedor` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_hp_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `historial_precios`
--

LOCK TABLES `historial_precios` WRITE;
/*!40000 ALTER TABLE `historial_precios` DISABLE KEYS */;
INSERT INTO `historial_precios` VALUES (1,'ingrediente',1,NULL,0.2500,'alta',NULL,1,'2026-05-10 20:28:20'),(2,'ingrediente',1,0.2500,0.2800,'proveedor',1,1,'2026-07-09 20:28:20'),(3,'ingrediente',1,0.2800,0.3000,'edicion',NULL,1,'2026-08-24 20:28:20'),(4,'ingrediente',3,NULL,0.0200,'alta',NULL,1,'2026-05-10 20:28:20'),(5,'ingrediente',3,0.0200,0.0250,'edicion',NULL,1,'2026-08-08 20:28:20'),(6,'producto',1,NULL,45.0000,'alta',NULL,1,'2026-05-10 20:28:20'),(7,'producto',1,45.0000,50.0000,'edicion',NULL,1,'2026-07-24 20:28:20');
/*!40000 ALTER TABLE `historial_precios` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `horario_impacto_reservaciones`
--

DROP TABLE IF EXISTS `horario_impacto_reservaciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `horario_impacto_reservaciones` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `impacto_id` int unsigned NOT NULL,
  `reservacion_id` int NOT NULL,
  `estado` enum('pendiente_notificacion','notificacion_preparada','sin_contacto','atendida_manual','resuelta_por_cliente') NOT NULL,
  `notification_prepared_at` datetime DEFAULT NULL,
  `access_token_hash` char(64) DEFAULT NULL,
  `access_expires_at` datetime DEFAULT NULL,
  `access_invalidated_at` datetime DEFAULT NULL,
  `notification_attempts` smallint unsigned NOT NULL DEFAULT '0',
  `last_notification_at` datetime DEFAULT NULL,
  `notification_delivery_status` enum('pending','accepted','delivered','failed') NOT NULL DEFAULT 'pending',
  `notification_delivery_updated_at` datetime DEFAULT NULL,
  `resolved_by` int DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_horario_impacto_reservacion` (`impacto_id`,`reservacion_id`),
  KEY `fk_horario_impacto_reservaciones_reservacion` (`reservacion_id`),
  KEY `fk_horario_impacto_reservaciones_usuario` (`resolved_by`),
  KEY `idx_horario_impacto_reservaciones_estado` (`impacto_id`,`estado`),
  KEY `idx_horario_impacto_reservaciones_access` (`access_token_hash`,`access_expires_at`),
  KEY `idx_horario_impacto_reservaciones_delivery` (`notification_delivery_status`),
  CONSTRAINT `fk_horario_impacto_reservaciones_impacto` FOREIGN KEY (`impacto_id`) REFERENCES `horario_impactos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_horario_impacto_reservaciones_reservacion` FOREIGN KEY (`reservacion_id`) REFERENCES `reservaciones` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_horario_impacto_reservaciones_usuario` FOREIGN KEY (`resolved_by`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `horario_impacto_reservaciones`
--

LOCK TABLES `horario_impacto_reservaciones` WRITE;
/*!40000 ALTER TABLE `horario_impacto_reservaciones` DISABLE KEYS */;
/*!40000 ALTER TABLE `horario_impacto_reservaciones` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `horario_impactos`
--

DROP TABLE IF EXISTS `horario_impactos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `horario_impactos` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tipo_origen` varchar(40) NOT NULL,
  `origen_id` int unsigned DEFAULT NULL,
  `estado` enum('pendiente','resuelto') NOT NULL DEFAULT 'pendiente',
  `dedup_key` char(64) NOT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_horario_impactos_dedup` (`dedup_key`),
  KEY `fk_horario_impactos_usuario` (`created_by`),
  KEY `idx_horario_impactos_estado_fecha` (`estado`,`created_at`),
  CONSTRAINT `fk_horario_impactos_usuario` FOREIGN KEY (`created_by`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `horario_impactos`
--

LOCK TABLES `horario_impactos` WRITE;
/*!40000 ALTER TABLE `horario_impactos` DISABLE KEYS */;
/*!40000 ALTER TABLE `horario_impactos` ENABLE KEYS */;
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
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `horarios_operacion`
--

LOCK TABLES `horarios_operacion` WRITE;
/*!40000 ALTER TABLE `horarios_operacion` DISABLE KEYS */;
INSERT INTO `horarios_operacion` VALUES (1,0,1,'08:30:00','19:00:00',NULL,'2026-09-07 20:17:48'),(2,1,1,'13:00:00','22:00:00',NULL,'2026-09-07 20:17:48'),(3,2,1,'08:30:00','22:00:00',NULL,'2026-09-07 20:17:48'),(4,3,1,'08:30:00','22:00:00',NULL,'2026-09-07 20:17:48'),(5,4,1,'08:30:00','22:00:00',NULL,'2026-09-07 20:17:48'),(6,5,1,'08:30:00','22:00:00',NULL,'2026-09-07 20:17:48'),(7,6,1,'08:30:00','22:00:00',NULL,'2026-09-07 20:17:48');
/*!40000 ALTER TABLE `horarios_operacion` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `horarios_reservacion`
--

DROP TABLE IF EXISTS `horarios_reservacion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `horarios_reservacion` (
  `id` int NOT NULL AUTO_INCREMENT,
  `dia_id` int NOT NULL,
  `hora` time NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_horarios_reservacion_dia_hora` (`dia_id`,`hora`),
  CONSTRAINT `horarios_reservacion_ibfk_1` FOREIGN KEY (`dia_id`) REFERENCES `dias_reservacion` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=82 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `horarios_reservacion`
--

LOCK TABLES `horarios_reservacion` WRITE;
/*!40000 ALTER TABLE `horarios_reservacion` DISABLE KEYS */;
INSERT INTO `horarios_reservacion` VALUES (1,1,'09:00:00'),(2,1,'10:00:00'),(3,1,'11:00:00'),(4,1,'12:00:00'),(5,1,'13:00:00'),(6,1,'14:00:00'),(7,1,'15:00:00'),(8,1,'16:00:00'),(9,1,'17:00:00'),(10,1,'18:00:00'),(11,2,'09:00:00'),(12,2,'10:00:00'),(13,2,'11:00:00'),(14,2,'12:00:00'),(15,2,'13:00:00'),(16,2,'14:00:00'),(17,3,'09:00:00'),(18,3,'10:00:00'),(19,3,'11:00:00'),(20,3,'12:00:00'),(21,3,'13:00:00'),(22,3,'14:00:00'),(23,3,'15:00:00'),(24,3,'16:00:00'),(25,3,'17:00:00'),(26,3,'18:00:00'),(27,3,'19:00:00'),(28,3,'20:00:00'),(29,3,'21:00:00'),(30,4,'09:00:00'),(31,4,'10:00:00'),(32,4,'11:00:00'),(33,4,'12:00:00'),(34,4,'13:00:00'),(35,4,'14:00:00'),(36,4,'15:00:00'),(37,4,'16:00:00'),(38,4,'17:00:00'),(39,4,'18:00:00'),(40,4,'19:00:00'),(41,4,'20:00:00'),(42,4,'21:00:00'),(43,5,'09:00:00'),(44,5,'10:00:00'),(45,5,'11:00:00'),(46,5,'12:00:00'),(47,5,'13:00:00'),(48,5,'14:00:00'),(49,5,'15:00:00'),(50,5,'16:00:00'),(51,5,'17:00:00'),(52,5,'18:00:00'),(53,5,'19:00:00'),(54,5,'20:00:00'),(55,5,'21:00:00'),(56,6,'09:00:00'),(57,6,'10:00:00'),(58,6,'11:00:00'),(59,6,'12:00:00'),(60,6,'13:00:00'),(61,6,'14:00:00'),(62,6,'15:00:00'),(63,6,'16:00:00'),(64,6,'17:00:00'),(65,6,'18:00:00'),(66,6,'19:00:00'),(67,6,'20:00:00'),(68,6,'21:00:00'),(69,7,'09:00:00'),(70,7,'10:00:00'),(71,7,'11:00:00'),(72,7,'12:00:00'),(73,7,'13:00:00'),(74,7,'14:00:00'),(75,7,'15:00:00'),(76,7,'16:00:00'),(77,7,'17:00:00'),(78,7,'18:00:00'),(79,7,'19:00:00'),(80,7,'20:00:00'),(81,7,'21:00:00');
/*!40000 ALTER TABLE `horarios_reservacion` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `impresion_alertas`
--

DROP TABLE IF EXISTS `impresion_alertas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `impresion_alertas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `documento` enum('comanda','cuenta') NOT NULL,
  `motivo` enum('sin_impresora','sin_conexion','fallo_envio') NOT NULL,
  `ticket_id` int DEFAULT NULL,
  `mesa_nombre` varchar(60) DEFAULT NULL,
  `area_id` tinyint unsigned DEFAULT NULL,
  `area_nombre` varchar(60) DEFAULT NULL,
  `impresora_nombre` varchar(60) DEFAULT NULL,
  `detalle` varchar(255) DEFAULT NULL,
  `usuario_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atendida_at` datetime DEFAULT NULL,
  `atendida_por` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_impresion_alertas_ticket` (`ticket_id`),
  KEY `fk_impresion_alertas_usuario` (`usuario_id`),
  KEY `fk_impresion_alertas_atendio` (`atendida_por`),
  KEY `idx_impresion_alertas_pendientes` (`atendida_at`,`created_at`),
  CONSTRAINT `fk_impresion_alertas_atendio` FOREIGN KEY (`atendida_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_impresion_alertas_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_impresion_alertas_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `impresion_alertas`
--

LOCK TABLES `impresion_alertas` WRITE;
/*!40000 ALTER TABLE `impresion_alertas` DISABLE KEYS */;
INSERT INTO `impresion_alertas` VALUES (5,'comanda','sin_conexion',506,'Llevar',3,'Cocina','Comanda Cocina','Cannot initialise NetworkPrintConnector: Se produjo un error durante el intento de conexión ya que la parte conectada no respondió adecuadamente tras un periodo de tiempo, o bien se produjo un error en la conexión establecida ya que el host conectado no h',1,'2026-09-22 22:45:24','2026-09-22 22:49:20',1),(6,'comanda','sin_conexion',506,'Llevar',3,'Cocina','Comanda Cocina','Cannot initialise NetworkPrintConnector: Se produjo un error durante el intento de conexión ya que la parte conectada no respondió adecuadamente tras un periodo de tiempo, o bien se produjo un error en la conexión establecida ya que el host conectado no h',1,'2026-09-22 22:49:08','2026-09-22 22:49:18',1),(7,'cuenta','sin_conexion',121,'Mesa 10',NULL,NULL,'Cuenta Caja','Cannot initialise NetworkPrintConnector: Se produjo un error durante el intento de conexión ya que la parte conectada no respondió adecuadamente tras un periodo de tiempo, o bien se produjo un error en la conexión establecida ya que el host conectado no h',1,'2026-09-30 09:18:41','2026-09-30 09:18:53',1),(8,'comanda','sin_conexion',509,'Mesa 10',3,'Cocina','Comanda Cocina','Cannot initialise NetworkPrintConnector: Se produjo un error durante el intento de conexión ya que la parte conectada no respondió adecuadamente tras un periodo de tiempo, o bien se produjo un error en la conexión establecida ya que el host conectado no h',1,'2026-10-02 16:08:47','2026-10-02 17:11:18',1),(9,'comanda','sin_conexion',509,'Mesa 10',4,'Horno Napolitano','Comanda Horno','Cannot initialise NetworkPrintConnector: Se produjo un error durante el intento de conexión ya que la parte conectada no respondió adecuadamente tras un periodo de tiempo, o bien se produjo un error en la conexión establecida ya que el host conectado no h',1,'2026-10-02 16:08:47','2026-10-02 17:11:18',1),(10,'comanda','sin_conexion',509,'Mesa 10',1,'Barra de Café','Comanda Café','Cannot initialise NetworkPrintConnector: Se produjo un error durante el intento de conexión ya que la parte conectada no respondió adecuadamente tras un periodo de tiempo, o bien se produjo un error en la conexión establecida ya que el host conectado no h',1,'2026-10-02 16:08:47','2026-10-02 17:11:18',1),(11,'comanda','sin_conexion',509,'Mesa 10',3,'Cocina','Comanda Cocina','Cannot initialise NetworkPrintConnector: Se produjo un error durante el intento de conexión ya que la parte conectada no respondió adecuadamente tras un periodo de tiempo, o bien se produjo un error en la conexión establecida ya que el host conectado no h',1,'2026-10-02 17:11:28',NULL,NULL);
/*!40000 ALTER TABLE `impresion_alertas` ENABLE KEYS */;
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
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `impresoras`
--

LOCK TABLES `impresoras` WRITE;
/*!40000 ALTER TABLE `impresoras` DISABLE KEYS */;
INSERT INTO `impresoras` VALUES (1,'Comanda Café',1,'comanda','red','192.168.1.51',9100,NULL,32,1),(2,'Comanda Jugos',2,'comanda','red','192.168.1.52',9100,NULL,32,1),(3,'Comanda Cocina',3,'comanda','red','192.168.1.53',9100,NULL,48,1),(4,'Comanda Horno',4,'comanda','red','192.168.1.54',9100,NULL,48,1),(5,'Cuenta Caja',NULL,'cuenta','red','192.168.1.50',9100,NULL,48,1);
/*!40000 ALTER TABLE `impresoras` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ingrediente_proveedores`
--

DROP TABLE IF EXISTS `ingrediente_proveedores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ingrediente_proveedores` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ingrediente_id` int unsigned NOT NULL,
  `proveedor_id` int unsigned NOT NULL,
  `costo` decimal(10,4) NOT NULL DEFAULT '0.0000',
  `codigo` varchar(60) DEFAULT NULL,
  `preferente` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ing_prov` (`ingrediente_id`,`proveedor_id`),
  KEY `idx_ing_prov_proveedor` (`proveedor_id`),
  CONSTRAINT `fk_ing_prov_ingrediente` FOREIGN KEY (`ingrediente_id`) REFERENCES `ingredientes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ing_prov_proveedor` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ingrediente_proveedores`
--

LOCK TABLES `ingrediente_proveedores` WRITE;
/*!40000 ALTER TABLE `ingrediente_proveedores` DISABLE KEYS */;
INSERT INTO `ingrediente_proveedores` VALUES (1,1,1,0.2800,'CAF-MOL-1K',1,'2026-09-07 20:28:20','2026-09-07 20:28:20'),(2,1,3,0.3400,NULL,0,'2026-09-07 20:28:20','2026-09-07 20:28:20'),(3,3,2,0.0230,'LEC-ENT-1L',1,'2026-09-07 20:28:20','2026-09-07 20:28:20'),(4,3,3,0.0290,NULL,0,'2026-09-07 20:28:20','2026-09-07 20:28:20'),(5,5,3,0.0300,'AZU-EST-1K',1,'2026-09-07 20:28:20','2026-09-07 20:28:20');
/*!40000 ALTER TABLE `ingrediente_proveedores` ENABLE KEYS */;
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
) ENGINE=InnoDB AUTO_INCREMENT=35 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ingredientes`
--

LOCK TABLES `ingredientes` WRITE;
/*!40000 ALTER TABLE `ingredientes` DISABLE KEYS */;
INSERT INTO `ingredientes` VALUES (1,'Café molido','g',5000.000,500.000,0.3000,1,'2026-09-07 20:28:20','2026-09-07 20:28:20'),(2,'Agua','ml',100000.000,5000.000,0.0001,1,'2026-09-07 20:28:20','2026-09-07 20:28:20'),(3,'Leche','ml',2100.000,3000.000,0.0250,1,'2026-09-07 20:28:20','2026-09-07 20:28:20'),(4,'Chocolate en polvo','g',250.000,400.000,0.2000,1,'2026-09-07 20:28:20','2026-09-07 20:28:20'),(5,'Azúcar','g',8000.000,800.000,0.0300,1,'2026-09-07 20:28:20','2026-09-07 20:28:20'),(6,'Canela','g',30.000,50.000,0.5000,1,'2026-09-07 20:28:20','2026-09-07 20:28:20'),(7,'Piloncillo','g',3000.000,300.000,0.0600,1,'2026-09-07 20:28:20','2026-09-07 20:28:20'),(8,'Fruta de temporada','g',420.000,600.000,0.0400,1,'2026-09-07 20:28:20','2026-09-07 20:28:20'),(9,'Hielo','g',20000.000,1000.000,0.0050,1,'2026-09-07 20:28:20','2026-09-07 20:28:20'),(10,'Tortilla de maíz','pza',2000.000,300.000,0.8000,1,'2026-09-07 20:28:20','2026-09-23 04:31:08'),(11,'Pollo deshebrado','g',20000.000,3000.000,0.1800,1,'2026-09-07 20:28:20','2026-09-23 04:31:08'),(12,'Queso gouda','g',7800.000,1500.000,0.3500,1,'2026-09-07 20:28:20','2026-09-23 04:49:05'),(13,'Crema','ml',10000.000,2000.000,0.0600,1,'2026-09-07 20:28:20','2026-09-23 04:31:08'),(14,'Salsa verde','ml',8000.000,1500.000,0.0500,1,'2026-09-07 20:28:20','2026-09-07 20:28:20'),(15,'Rib eye','g',15000.000,3000.000,0.6500,1,'2026-09-07 20:28:20','2026-09-07 20:28:20'),(16,'Carne molida de res','g',12000.000,2500.000,0.2800,1,'2026-09-07 20:28:20','2026-09-07 20:28:20'),(17,'Pan de hamburguesa','pza',300.000,50.000,6.0000,1,'2026-09-07 20:28:20','2026-09-07 20:28:20'),(18,'Papa','g',30000.000,5000.000,0.0300,1,'2026-09-07 20:28:20','2026-09-07 20:28:20'),(19,'Queso parmesano','g',3870.000,800.000,0.4500,1,'2026-09-07 20:28:20','2026-09-23 04:49:05'),(20,'Salmón ahumado','g',3000.000,600.000,0.9000,1,'2026-09-07 20:28:20','2026-09-07 20:28:20'),(21,'Pan brioche','pza',200.000,40.000,8.0000,1,'2026-09-07 20:28:20','2026-09-07 20:28:20'),(22,'Aguacate','g',5940.000,1000.000,0.0800,1,'2026-09-07 20:28:20','2026-09-23 04:49:06'),(23,'Masa de pizza','pza',249.000,50.000,12.0000,1,'2026-09-07 20:28:20','2026-10-02 22:08:41'),(24,'Salsa de tomate','ml',8920.000,1500.000,0.0400,1,'2026-09-07 20:28:20','2026-10-02 22:08:41'),(25,'Queso mozzarella','g',9900.000,2000.000,0.3000,1,'2026-09-07 20:28:20','2026-10-02 22:08:41'),(26,'Mole negro','ml',6000.000,1000.000,0.1200,1,'2026-09-07 20:28:20','2026-09-23 04:31:08'),(27,'Frijol refrito','g',15000.000,2500.000,0.0200,1,'2026-09-07 20:28:20','2026-09-07 20:28:20'),(28,'Pasta seca','g',11850.000,2000.000,0.0500,1,'2026-09-07 20:28:20','2026-09-14 19:49:46'),(29,'Salsa boloñesa','ml',7800.000,1500.000,0.0900,1,'2026-09-07 20:28:20','2026-09-14 19:49:46'),(30,'Frutos rojos','g',4000.000,800.000,0.1500,1,'2026-09-07 20:28:20','2026-09-07 20:28:20'),(31,'Mezcla de lechugas','g',5000.000,1000.000,0.1000,1,'2026-09-07 20:28:20','2026-09-07 20:28:20'),(32,'Salmón fresco','g',4000.000,800.000,0.8500,1,'2026-09-07 20:28:20','2026-09-07 20:28:20'),(33,'Calamar','g',4000.000,800.000,0.5500,1,'2026-09-07 20:28:20','2026-09-07 20:28:20'),(34,'Filete de res','g',12000.000,2500.000,0.5500,1,'2026-09-07 20:28:20','2026-09-07 20:28:20');
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
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `categoria_id` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_menu_nombre` (`nombre`),
  KEY `categoria_id` (`categoria_id`),
  CONSTRAINT `menu_ibfk_1` FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `menu`
--

LOCK TABLES `menu` WRITE;
/*!40000 ALTER TABLE `menu` DISABLE KEYS */;
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
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mesas`
--

LOCK TABLES `mesas` WRITE;
/*!40000 ALTER TABLE `mesas` DISABLE KEYS */;
INSERT INTO `mesas` VALUES (1,1,'Mesa 1','mesa',4,30.00,88.00,1,1),(2,2,'Mesa 2','mesa',4,7.00,70.00,1,1),(3,3,'Mesa 3','mesa',4,30.00,51.00,1,1),(4,4,'Mesa 4','mesa',4,7.00,51.00,1,1),(5,5,'Mesa 5','mesa',4,7.00,29.00,1,1),(6,6,'Mesa 6','mesa',4,44.00,29.00,1,1),(7,7,'Mesa 7','mesa',4,88.00,29.00,1,1),(8,8,'Mesa 8','mesa',4,88.00,8.00,1,1),(9,9,'Mesa 9','mesa',4,56.00,8.00,1,1),(10,10,'Mesa 10','mesa',4,30.00,8.00,1,1),(11,11,'Mesa 11','mesa',4,7.00,8.00,1,1),(12,12,'Barra Blanca','barra',8,62.00,51.00,1,0),(13,13,'Caja','especial',0,33.00,70.00,1,0),(14,14,'Llevar','especial',0,57.00,70.00,1,0),(15,15,'Barra Roja','barra',6,88.00,70.00,1,0),(16,16,'Barra Roja 2','barra',6,88.00,88.00,1,0);
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
  `tipo` enum('venta','cancelacion','ajuste','entrada','merma') NOT NULL,
  `cantidad` decimal(12,3) NOT NULL,
  `motivo` varchar(40) DEFAULT NULL,
  `nota` varchar(255) DEFAULT NULL,
  `costo_unitario` decimal(10,4) DEFAULT NULL,
  `usuario_id` int DEFAULT NULL,
  `ticket_item_id` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_mi_usuario` (`usuario_id`),
  KEY `idx_mi_ing` (`ingrediente_id`),
  KEY `idx_mi_ti` (`ticket_item_id`),
  KEY `idx_mi_tipo_fecha` (`tipo`,`created_at`),
  CONSTRAINT `fk_mi_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `movimientos_inventario_ibfk_1` FOREIGN KEY (`ingrediente_id`) REFERENCES `ingredientes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=53 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `movimientos_inventario`
--

LOCK TABLES `movimientos_inventario` WRITE;
/*!40000 ALTER TABLE `movimientos_inventario` DISABLE KEYS */;
INSERT INTO `movimientos_inventario` VALUES (1,3,'merma',-1200.000,'caducidad','Se cortó el fin de semana largo',0.0250,NULL,NULL,'2026-09-05 20:28:20'),(2,8,'merma',-350.000,'dano','Caja golpeada en la entrega',0.0400,NULL,NULL,'2026-09-03 20:28:20'),(3,1,'merma',-180.000,'preparacion','Molienda mal calibrada',0.3000,NULL,NULL,'2026-08-29 20:28:20'),(4,4,'merma',-60.000,'derrame',NULL,0.2000,NULL,NULL,'2026-08-23 20:28:20'),(5,7,'merma',-400.000,'faltante','Diferencia contra el conteo físico',0.0600,NULL,NULL,'2026-08-14 20:28:20'),(36,12,'venta',-100.000,NULL,NULL,NULL,NULL,664,'2026-09-08 03:58:23'),(37,19,'venta',-50.000,NULL,NULL,NULL,NULL,664,'2026-09-08 03:58:23'),(38,22,'venta',-30.000,NULL,NULL,NULL,NULL,664,'2026-09-08 03:58:23'),(39,28,'venta',-150.000,NULL,NULL,NULL,NULL,666,'2026-09-14 19:49:46'),(40,29,'venta',-200.000,NULL,NULL,NULL,NULL,666,'2026-09-14 19:49:46'),(41,19,'venta',-30.000,NULL,NULL,NULL,NULL,666,'2026-09-14 19:49:46'),(47,12,'venta',-100.000,NULL,NULL,NULL,NULL,669,'2026-09-23 04:49:05'),(48,19,'venta',-50.000,NULL,NULL,NULL,NULL,669,'2026-09-23 04:49:05'),(49,22,'venta',-30.000,NULL,NULL,NULL,NULL,669,'2026-09-23 04:49:06'),(50,23,'venta',-1.000,NULL,NULL,NULL,NULL,672,'2026-10-02 22:08:41'),(51,24,'venta',-80.000,NULL,NULL,NULL,NULL,672,'2026-10-02 22:08:41'),(52,25,'venta',-100.000,NULL,NULL,NULL,NULL,672,'2026-10-02 22:08:41');
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
) ENGINE=InnoDB AUTO_INCREMENT=99 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `producto_componentes`
--

LOCK TABLES `producto_componentes` WRITE;
/*!40000 ALTER TABLE `producto_componentes` DISABLE KEYS */;
INSERT INTO `producto_componentes` VALUES (1,66,'subreceta',1,60.000),(2,66,'ingrediente',2,90.000),(4,67,'subreceta',1,60.000),(5,67,'ingrediente',3,120.000),(7,69,'ingrediente',1,15.000),(8,69,'ingrediente',2,200.000),(9,69,'ingrediente',7,25.000),(10,69,'ingrediente',6,2.000),(14,71,'ingrediente',4,30.000),(15,71,'ingrediente',3,200.000),(16,71,'ingrediente',5,10.000),(17,72,'ingrediente',8,120.000),(18,72,'ingrediente',2,250.000),(19,72,'ingrediente',5,20.000),(20,72,'ingrediente',9,100.000),(24,18,'ingrediente',10,10.000),(25,18,'ingrediente',11,50.000),(26,18,'ingrediente',14,80.000),(27,18,'ingrediente',13,40.000),(28,18,'ingrediente',12,40.000),(31,1,'ingrediente',10,6.000),(32,1,'ingrediente',11,70.000),(33,1,'ingrediente',26,120.000),(34,1,'ingrediente',13,30.000),(35,1,'ingrediente',12,25.000),(38,2,'ingrediente',10,6.000),(39,2,'ingrediente',11,70.000),(40,2,'ingrediente',14,100.000),(41,2,'ingrediente',12,60.000),(42,2,'ingrediente',13,40.000),(45,16,'ingrediente',27,100.000),(46,16,'ingrediente',12,60.000),(48,9,'ingrediente',21,1.000),(49,9,'ingrediente',20,70.000),(50,9,'ingrediente',22,40.000),(51,9,'ingrediente',13,20.000),(55,45,'ingrediente',16,180.000),(56,45,'ingrediente',17,1.000),(57,45,'ingrediente',12,30.000),(58,45,'ingrediente',18,120.000),(62,65,'ingrediente',18,250.000),(63,65,'ingrediente',19,30.000),(65,41,'ingrediente',28,150.000),(66,41,'ingrediente',29,200.000),(67,41,'ingrediente',19,30.000),(68,62,'ingrediente',21,1.000),(69,62,'ingrediente',19,20.000),(70,62,'ingrediente',22,30.000),(71,53,'ingrediente',30,120.000),(72,53,'ingrediente',31,80.000),(73,53,'ingrediente',19,20.000),(74,35,'ingrediente',13,150.000),(75,35,'ingrediente',18,60.000),(77,60,'ingrediente',23,1.000),(78,60,'ingrediente',24,80.000),(79,60,'ingrediente',25,120.000),(80,58,'ingrediente',23,1.000),(81,58,'ingrediente',24,80.000),(82,58,'ingrediente',25,100.000),(83,59,'ingrediente',23,1.000),(84,59,'ingrediente',24,70.000),(85,59,'ingrediente',25,130.000),(86,52,'ingrediente',15,450.000),(87,52,'ingrediente',18,150.000),(89,44,'ingrediente',32,200.000),(90,44,'ingrediente',22,40.000),(92,64,'ingrediente',12,100.000),(93,64,'ingrediente',19,50.000),(94,64,'ingrediente',22,30.000),(95,43,'ingrediente',34,250.000),(96,43,'ingrediente',18,100.000),(98,27,'ingrediente',33,150.000);
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
  `area_id` tinyint unsigned NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_productos_nombre` (`nombre`),
  KEY `idx_productos_cat_activo` (`categoria_id`,`activo`),
  KEY `idx_productos_carta` (`activo`,`categoria_id`),
  KEY `area_id` (`area_id`),
  CONSTRAINT `productos_ibfk_1` FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`),
  CONSTRAINT `productos_ibfk_2` FOREIGN KEY (`area_id`) REFERENCES `areas_produccion` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=236 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `productos`
--

LOCK TABLES `productos` WRITE;
/*!40000 ALTER TABLE `productos` DISABLE KEYS */;
INSERT INTO `productos` VALUES (1,'Enmoladas','Rellenas de pollo (70 gr.) con láminas de plátano macho, crema, queso y aros de cebolla bañadas en mole negro de Oaxaca.',1,240.00,3,1),(2,'Enchiladas Suizas','Enchiladas verdes rellenas de pollo (70 gr.), gratinadas con queso gouda, crema y aros de cebolla.',1,220.00,3,1),(3,'Cecina y Huevo con Chorizo','Cecina (130 gr.), huevos revueltos (2 pzas) con chorizo, acompañados de frijoles refritos con queso.',1,220.00,3,1),(4,'Cazuela Cascabel','3 huevos estrellados o revueltos en salsa de chile cascabel, queso oaxaca gratinado, aguacate y una rebanada de pan hogaza.',1,220.00,3,1),(5,'Sopes con Cecina o Arrachera','3 sopes hechos a mano con frijoles, lechuga, crema, queso y cecina (130 gr.). Cambio de proteína con arrachera (150 gr.) +$40.',1,220.00,3,1),(6,'Enfrijoladas','Rellenas de huevo revuelto, bañadas con salsa de frijol, chorizo, crema y queso.',1,220.00,3,1),(7,'Huevos al Parmesano','2 huevos estrellados acompañados con espárragos blanqueados, arúgula, tocino y parmesano rallado.',1,210.00,3,1),(8,'Omelette Fitness','Claras de huevo (2 pzas), espinaca, queso de cabra y láminas de aguacate.',1,190.00,3,1),(9,'Toast de Salmón Ahumado','Pan brioche, crema ácida, salmón ahumado (70 gr.), ajonjolí, 1 huevo estrellado, espárragos y aguacate.',1,230.00,3,1),(10,'Pan Francés Estilo C.P.','Base de pan brioche con crema dulce, frutos rojos y miel de maple.',1,210.00,3,1),(11,'Huevos Módena','2 huevos revueltos o estrellados con tocino, queso parmesano y arúgula.',1,190.00,3,1),(12,'Huevos Italianos','2 huevos en omelette, jamón serrano, láminas de queso parmesano y arúgula.',1,190.00,3,1),(13,'Huevos Pamplona','2 huevos en omelette con chorizo español de pamplona, arúgula y queso mozarella fresco.',1,190.00,3,1),(14,'Huevos al Sano','2 huevos en omelette con jamón de pavo, arúgula, queso mozarella fresco y jitomate cherry.',1,190.00,3,1),(15,'Huevos al Gusto','Rancheros, a la mexicana, divorciados, al albañil, con tocino, con chorizo o con jamón.',1,180.00,3,1),(16,'Molletes','4 piezas de pan baguette con frijoles y queso manchego, acompañado de pico de gallo.',1,100.00,3,1),(17,'Casa Pestalozzi','½ orden de chilaquiles (40 gr.) con salsa al gusto, crema, queso y 2 huevos revueltos.',1,180.00,3,1),(18,'Chilaquiles','Verdes, rojos o salsa de la casa, con pollo (30 gr.) o huevo (1 pza), queso, crema y cebolla morada. Con arrachera +$90 · con cecina +$65.',1,180.00,3,1),(19,'Baguette de Jamón Serrano','Jamón serrano, láminas de parmesano, casse de jitomate y arúgula.',1,220.00,3,1),(20,'Baguette de Magret de Pollo','Pollo a la plancha con queso gouda, rodajas de jitomate, mix de lechuga y aderezo cipriani.',1,220.00,3,1),(21,'Baguette con Arrachera','Arrachera (150 gr.), cremoso de aguacate con un toque de chipotle y mix de lechugas.',1,230.00,3,1),(22,'Croissant con Jamón de Pavo','Pechuga de pavo (120 gr.), queso gouda, aderezo cipriani, jitomate y mix de lechugas.',1,165.00,3,1),(23,'Croissant con Huevo y Estragón','2 pzas de huevo revuelto con estragón y mix de lechugas.',1,140.00,3,1),(24,'Baguette de Cochinita','Cochinita (150 gr.), cebolla encurtida y habanero.',1,210.00,3,1),(25,'Plato de Fruta Mixta','Fruta de temporada.',1,110.00,2,1),(26,'Copa Antioxidante','Fresa, frambuesa, mora y zarzamora con yogurt y granola hecha en casa.',1,130.00,2,1),(27,'Aros de Calamar','Empanizados, aderezo cipriani, chiles cuaresmeños y limón eureka.',2,210.00,3,1),(28,'Tostadas de Atún','3 tostaditas con cubos de atún marinado en salsa oriental, cremoso de aguacate y poro.',2,195.00,3,1),(29,'Torreta de Salmón','Salmón ahumado, queso cabra, aguacate, jitomate con aderezo de pesto de albahaca.',2,220.00,3,1),(30,'Tiradito de Atún','Láminas de atún, aceite de chile, mayonesa spicy, toronja y eneldo.',2,210.00,3,1),(31,'Carpaccio de Salmón','Finas láminas de salmón ahumado, arúgula, queso parmesano, alcaparras, limón eureka y jitomate cherry.',2,180.00,3,1),(32,'Camarones al Ajillo','Salteados al olivo, ajo, peperoncino con pan de baguette.',2,210.00,3,1),(33,'Espárragos al Horno','Queso gouda, tocino con reducción de balsámico.',2,180.00,4,1),(34,'Queso Burrata con Jitomates Cherrys','Queso burrata con jitomates cherrys al horno, aceite de oliva, poro y hojas de albahaca.',2,210.00,4,1),(35,'Crema del Día','Nuestras cremas y sopas son elaboradas por temporada y en nuestros especiales de fin de semana. Pregunta al mesero por la opción del día.',3,180.00,3,1),(36,'Sopa Especial de Fin de Semana','Receta de la casa, elaborada con ingredientes frescos de temporada. Disponible sábados y domingos.',3,180.00,3,1),(37,'Fetuccini a los Cuatro Quesos y Camarones','Queso brie, parmesano, queso crema y queso gouda.',4,280.00,3,1),(38,'Lasagna de Filete de Res','Cocción a baja temperatura por 3 horas con ingredientes 100% italianos.',4,280.00,3,1),(39,'Rigatoni al Limón con Camarones y Parmesano','Camarones salteados con vino blanco, mantequilla, ralladura de limón eureka y toque de albahaca.',4,280.00,3,1),(40,'Spaguetti a l\'Arrabbiata con Camarones y Parmesano','Salsa de pomodoro con peperoncino.',4,280.00,3,1),(41,'Spaguetti a la Boloñesa','Cocción a baja temperatura por 3 horas con ingredientes 100% italianos.',4,280.00,3,1),(42,'Spaguetti al Pomodoro y Parmesano','Pasta, salsa de jitomate y parmesano.',4,190.00,3,1),(43,'Filete de Res en su Jugo','Filete de res importado en su jugo con puré de papa rústico y espárragos al horno.',5,320.00,3,1),(44,'Salmón al Horno','Salmón noruego sazonado con ajo y aceite de oliva. Acompaña con media orden de pasta o ensalada.',5,295.00,3,1),(45,'Hamburguesa de la Casa','Carne wagyu, pan brioche hecho en C.P., cebolla caramelizada, queso cheddar, mayonesa ahumada, pepinillo encurtido. Acompaña con papas gajo.',5,260.00,3,1),(46,'Atún Sellado','Atún importado, sellado en costra de pistache, aderezo cipriani. Acompaña con mix de lechugas.',5,285.00,3,1),(47,'Tacos de Cochinita','Tres tacos de tortilla de maíz hechas a mano, frijol, cebolla y habanero encurtido.',5,210.00,3,1),(48,'Tacos de Vacío','Vacío importado, tortillas hechas a mano, salsa de piña con habanero y aguacate.',5,210.00,3,1),(49,'Tacos de Camarón Rebozados','Tres tortillas de harina, camarones rebozados, col morada y aderezo de chipotle.',5,240.00,3,1),(50,'Vacío en Escalopas','Vacío importado en escalopas, arúgula, láminas de parmesano y reducción de bálsamico.',5,280.00,3,1),(51,'New York (450 grs.)','Carne calidad choice angus, cebollitas asadas, chiles toreados y papas a la francesa.',5,785.00,3,1),(52,'Rib Eye (450 grs.)','Carne calidad choice angus, cebollitas asadas, chiles toreados y papas a la francesa.',5,785.00,3,1),(53,'Frutos Rojos','Mix de lechugas, frambuesas, zarzamoras, fresas, queso cabra, nuez y reducción de balsámico.',6,210.00,3,1),(54,'Ciruela Betabel','Mix de lechugas, ciruela y betabel sazonado con estragón, queso burrata y almendras horneadas.',6,210.00,3,1),(55,'Magret de Pollo','Pechuga de pollo prensada, lechuga baby asada, almendras horneadas con aderezo de queso.',6,210.00,3,1),(56,'Jamón Serrano con Perlas de Melón','Mix de lechugas, perlas de melón, jamón serrano, nuez y reducción de balsámico.',6,210.00,3,1),(57,'Pasta Corta con Pollo','Mix de lechuga con cremoso de aguacate y almendras horneadas.',6,210.00,3,1),(58,'Margarita','Pomodoro, mozzarella y albahaca.',7,190.00,4,1),(59,'Burrata','Pomodoro, burrata, prosciutto y arúgula.',7,260.00,4,1),(60,'Milano','Pomodoro, mozzarella, jitomates cherrys, salami y láminas de parmesano.',7,260.00,4,1),(61,'Camarones a los 4 Quesos','Salsa de 4 quesos, queso mozzarella y camarones.',7,260.00,4,1),(62,'Mix de 3 Brusquetas','Jamón serrano, queso brie, anchoas.',8,160.00,3,1),(63,'Aceitunas Temperadas con Aceite de Chile','Aceitunas verdes en aceite de chiles.',8,160.00,3,1),(64,'Tabla Mixta','Queso parmesano, brie, manchego, chorizo salamanca, semillas, frutos rojos.',8,320.00,3,1),(65,'Papas a la Francesa con Parmesano','Papas a la francesa con queso parmesano rallado.',8,160.00,3,1),(66,'Café Americano','Espresso doble alargado con agua caliente.',9,65.00,1,1),(67,'Cappuccino','Espresso, leche vaporizada y espuma de leche en partes iguales.',9,75.00,1,1),(68,'Latte','Espresso con leche vaporizada y una capa fina de espuma.',9,80.00,1,1),(69,'Café de Olla','Café de grano infusionado con piloncillo y canela.',9,65.00,1,1),(70,'Té / Infusión','Selección de tés negros, verdes e infusiones de hierbas.',9,65.00,1,1),(71,'Chocolate Caliente','Chocolate de mesa batido con leche caliente.',9,80.00,1,1),(72,'Agua Fresca','Agua fresca del día, preparada con fruta de temporada.',9,60.00,1,1),(73,'Refresco','Refresco embotellado. Consulta los sabores disponibles.',9,55.00,1,1),(74,'Jugo de Naranja','Naranja exprimida al momento, sin azúcar añadida.',10,85.00,2,1),(75,'Jugo Verde','Espinaca, apio, pepino, piña y jugo de naranja.',10,95.00,2,1),(76,'Limonada Natural','Limón exprimido al momento, con agua natural o mineral.',10,75.00,2,1),(77,'Smoothie de Fresa','Fresa, yogurt natural y un toque de miel.',10,100.00,2,1),(78,'Agua de Coco','Agua de coco natural, servida bien fría.',10,90.00,2,1),(207,'Regiones de Escocia','Tres whiskies escoceses de 30 ml: Arran 10 (Isle of Arran), GlenAllachie 12 (Speyside) y Ardbeg 10 (Islay).',11,450.00,1,1),(208,'Glenmorangie','Tabla Glenmorangie, 30 ml c/u: 14 (Ex-Bourbon & Ex-Port Casks), 15 (Ex-Bourbon & Ex-Sherry Casks) y 16 (Ex-Bourbon & Sweet White Wine Casks).',11,380.00,1,1),(209,'Los Grandes Clásicos','Tres clásicos de 30 ml: Cutty Sark (Blended Scotch), Glenfiddich 12 \"Original\" y Macallan 12 \"Double Cask\".',11,370.00,1,1),(210,'Clásicos del Tequila','Tres tequilas blancos de 30 ml: Tequileño Blanco, Cascahuín Blanco y Siete Leguas Blanco.',11,240.00,1,1),(211,'Cata Tequileño','Cata vertical de la casa Tequileño, 30 ml c/u: Blanco, Platinum y Reposado.',11,180.00,1,1),(212,'Gin Cocktails','Sabores: frutos rojos, lychee y pepino.',12,190.00,1,1),(213,'Gin Tonic','Ginebra, romero y limón eureka.',12,190.00,1,1),(214,'Margaritas','Sabores: maracuyá, clásica y frutos rojos.',12,190.00,1,1),(215,'Mezcalitas','Sabores: maracuyá, sandía y frutos rojos.',12,190.00,1,1),(216,'Aperol Spritz','Aperol, prosecco y naranja.',12,190.00,1,1),(217,'St. Germain Spritz','Licor de saúco, limón eureka y prosecco.',12,210.00,1,1),(218,'Pestalozzi Martini','Tito\'s Vodka, ginebra y vermouth blanco.',12,140.00,1,1),(219,'Pestalozzi Negroni','Campari, vermouth rosso y ginebra.',12,210.00,1,1),(220,'Tequila Negroni','Campari, vermouth rosso y Tequileño Blanco.',12,210.00,1,1),(221,'Batanga Tequileño','Receta auténtica: Tequileño Blanco, Coca-Cola, jugo de limón fresco y sal de mar.',12,120.00,1,1),(222,'Espresso Martini','Espresso, Tito\'s Vodka y Kahlúa.',12,210.00,1,1),(223,'Coctelería Clásica','Cuba Libre, Old Fashioned, Manhattan, Cosmopolitan, Mojito, Bloody Mary, entre otros. Pregunta a nuestro equipo.',12,190.00,1,1),(224,'Modelo Especial','México, 4.5% ABV, 355 ml.',13,80.00,1,1),(225,'Victoria','México, 4.0% ABV, 355 ml.',13,70.00,1,1),(226,'Estrella Galicia','España, 5.5% ABV, 355 ml.',13,70.00,1,1),(227,'1906','España, 6.5% ABV, 355 ml.',13,80.00,1,1),(228,'Estrella Galicia de Barril','España, 6.5% ABV, 355 ml.',13,90.00,1,1),(229,'Mocktails','Frutos rojos, maracuyá, sandía, entre otros sabores de temporada.',14,140.00,1,1),(230,'Piñada','Jugo de piña, leche evaporada y coco.',14,140.00,1,1),(231,'Naranjada/Limonada','Preparada con agua mineral o natural.',14,85.00,1,1),(232,'Suero de Limón','Jugo de limón, sal y agua mineral Topo Chico. Cambio a Perrier (330 ml) o Agua de Piedra (355 ml) +$15 · Agua de Piedra (650 ml) +$35.',14,70.00,1,1),(233,'Agua Mineral','Topo Chico (355 ml). Cambio a Perrier (330 ml) o Agua de Piedra (355 ml) +$15 · Agua de Piedra (650 ml) +$35.',14,50.00,1,1),(234,'Agua Natural','Agua de Piedra (355 ml). Presentación de 650 ml +$25.',14,45.00,1,1),(235,'Refrescos de Barra','Botella de 355 ml: Coca-Cola, Coca-Cola sin azúcar, Coca-Cola Light, Sprite y Mundet.',14,65.00,1,1);
/*!40000 ALTER TABLE `productos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `proveedores`
--

DROP TABLE IF EXISTS `proveedores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `proveedores` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(120) NOT NULL,
  `contacto` varchar(120) DEFAULT NULL,
  `telefono` varchar(30) DEFAULT NULL,
  `correo` varchar(120) DEFAULT NULL,
  `notas` text,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_proveedores_nombre` (`nombre`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `proveedores`
--

LOCK TABLES `proveedores` WRITE;
/*!40000 ALTER TABLE `proveedores` DISABLE KEYS */;
INSERT INTO `proveedores` VALUES (1,'Cafetalera del Sur','Rosa Mendez','5551234567','ventas@cafetaleradelsur.mx','Entrega martes y viernes. Pedido minimo 5 kg.',1,'2026-09-07 20:28:20','2026-09-07 20:28:20'),(2,'Lacteos La Vaquita','Jorge Ibanez','5559876543','pedidos@lavaquita.mx','Entrega diaria antes de las 8:00.',1,'2026-09-07 20:28:20','2026-09-07 20:28:20'),(3,'Abarrotes El Puente','Luis Farias','5555550101',NULL,'Surte lo que falte, mas caro pero sin minimo.',1,'2026-09-07 20:28:20','2026-09-07 20:28:20');
/*!40000 ALTER TABLE `proveedores` ENABLE KEYS */;
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
) ENGINE=InnoDB AUTO_INCREMENT=39 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `reservacion_mesas`
--

LOCK TABLES `reservacion_mesas` WRITE;
/*!40000 ALTER TABLE `reservacion_mesas` DISABLE KEYS */;
INSERT INTO `reservacion_mesas` VALUES (1,13,1,1,'2026-09-07 20:28:20'),(2,14,2,1,'2026-09-07 20:28:20'),(3,15,3,1,'2026-09-07 20:28:20'),(4,16,4,1,'2026-09-07 20:28:20'),(5,17,1,1,'2026-09-07 20:28:20'),(6,18,1,1,'2026-09-07 20:28:20'),(7,18,2,2,'2026-09-07 20:28:20'),(8,18,3,3,'2026-09-07 20:28:20'),(9,18,4,4,'2026-09-07 20:28:20'),(10,18,5,5,'2026-09-07 20:28:20'),(11,18,6,6,'2026-09-07 20:28:20'),(12,18,7,7,'2026-09-07 20:28:20'),(13,18,8,8,'2026-09-07 20:28:20'),(14,18,9,9,'2026-09-07 20:28:20'),(15,18,10,10,'2026-09-07 20:28:20'),(16,18,11,11,'2026-09-07 20:28:20'),(17,19,1,1,'2026-09-07 20:28:20'),(18,20,5,1,'2026-09-07 20:28:20'),(19,20,11,2,'2026-09-07 20:28:20'),(20,21,8,1,'2026-09-07 20:28:20'),(21,21,9,2,'2026-09-07 20:28:20'),(22,21,10,3,'2026-09-07 20:28:20'),(23,22,1,1,'2026-09-07 20:28:20'),(24,22,2,2,'2026-09-07 20:28:20'),(25,22,3,3,'2026-09-07 20:28:20'),(26,22,4,4,'2026-09-07 20:28:20'),(27,23,2,1,'2026-09-07 20:28:20'),(28,24,2,1,'2026-09-07 20:28:20'),(29,25,3,1,'2026-09-07 20:28:20'),(30,26,4,1,'2026-09-07 20:28:20'),(31,27,5,1,'2026-09-07 20:28:20'),(32,27,6,2,'2026-09-07 20:28:20'),(33,28,6,1,'2026-09-07 20:28:20'),(34,29,7,1,'2026-09-07 20:28:20'),(35,30,9,1,'2026-09-07 20:28:20'),(36,31,10,1,'2026-09-07 20:28:20'),(37,32,11,1,'2026-09-07 20:28:20');
/*!40000 ALTER TABLE `reservacion_mesas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `reservacion_recordatorios`
--

DROP TABLE IF EXISTS `reservacion_recordatorios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reservacion_recordatorios` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `reservacion_id` int NOT NULL,
  `reservacion_raiz_id` int NOT NULL,
  `tipo` enum('dia_anterior') NOT NULL DEFAULT 'dia_anterior',
  `dedup_key` varchar(191) NOT NULL,
  `access_token_hash` char(64) DEFAULT NULL,
  `access_expires_at` datetime DEFAULT NULL,
  `access_invalidated_at` datetime DEFAULT NULL,
  `notification_delivery_status` enum('pending','accepted','delivered','failed') NOT NULL DEFAULT 'pending',
  `notification_delivery_updated_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reservacion_recordatorios_dedup` (`dedup_key`),
  UNIQUE KEY `uq_reservacion_recordatorios_access` (`access_token_hash`),
  KEY `idx_reservacion_recordatorios_reservacion` (`reservacion_id`),
  KEY `idx_reservacion_recordatorios_raiz` (`reservacion_raiz_id`),
  KEY `idx_reservacion_recordatorios_delivery` (`notification_delivery_status`),
  CONSTRAINT `fk_reservacion_recordatorios_raiz` FOREIGN KEY (`reservacion_raiz_id`) REFERENCES `reservaciones` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_reservacion_recordatorios_reservacion` FOREIGN KEY (`reservacion_id`) REFERENCES `reservaciones` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `reservacion_recordatorios`
--

LOCK TABLES `reservacion_recordatorios` WRITE;
/*!40000 ALTER TABLE `reservacion_recordatorios` DISABLE KEYS */;
/*!40000 ALTER TABLE `reservacion_recordatorios` ENABLE KEYS */;
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
  `motivo_cancelacion` varchar(500) DEFAULT NULL,
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
) ENGINE=InnoDB AUTO_INCREMENT=144 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `reservaciones`
--

LOCK TABLES `reservaciones` WRITE;
/*!40000 ALTER TABLE `reservaciones` DISABLE KEYS */;
INSERT INTO `reservaciones` VALUES (1,'Límite Una','email','limite.una@example.test','2026-09-10','13:00:00',2,'Una activa',NULL,NULL,'admin','fx-limite-una-0001',NULL,'confirmada',NULL,'2026-09-10 12:00:00','2026-09-07 20:28:20',NULL),(2,'Límite Cuatro 1','email','limite.cuatro@example.test','2026-09-10','14:30:00',2,'',NULL,NULL,'admin','fx-limite-cuatro-01',NULL,'confirmada',NULL,'2026-09-10 12:00:00','2026-09-07 20:28:20',NULL),(3,'Límite Cuatro 2','email','limite.cuatro@example.test','2026-09-11','15:00:00',2,'',NULL,NULL,'admin','fx-limite-cuatro-02',NULL,'confirmada',NULL,'2026-09-10 12:00:00','2026-09-07 20:28:20',NULL),(4,'Límite Cuatro 3','email','limite.cuatro@example.test','2026-09-12','16:00:00',2,'',NULL,NULL,'admin','fx-limite-cuatro-03',NULL,'confirmada',NULL,'2026-09-10 12:00:00','2026-09-07 20:28:20',NULL),(5,'Límite Cuatro 4','email','limite.cuatro@example.test','2026-09-13','17:00:00',2,'',NULL,NULL,'admin','fx-limite-cuatro-04',NULL,'confirmada',NULL,'2026-09-10 12:00:00','2026-09-07 20:28:20',NULL),(6,'Límite Cinco 1','email','limite.cinco@example.test','2026-09-10','13:30:00',2,'',NULL,NULL,'admin','fx-limite-cinco-01',NULL,'confirmada',NULL,'2026-09-10 12:00:00','2026-09-07 20:28:20',NULL),(7,'Límite Cinco 2','email','limite.cinco@example.test','2026-09-10','15:00:00',2,'',NULL,NULL,'admin','fx-limite-cinco-02',NULL,'confirmada',NULL,'2026-09-10 12:00:00','2026-09-07 20:28:20',NULL),(8,'Límite Cinco 3','email','limite.cinco@example.test','2026-09-11','16:30:00',2,'',NULL,NULL,'admin','fx-limite-cinco-03',NULL,'confirmada',NULL,'2026-09-10 12:00:00','2026-09-07 20:28:20',NULL),(9,'Límite Cinco 4','email','limite.cinco@example.test','2026-09-12','18:00:00',2,'',NULL,NULL,'admin','fx-limite-cinco-04',NULL,'confirmada',NULL,'2026-09-10 12:00:00','2026-09-07 20:28:20',NULL),(10,'Límite Cinco 5','email','limite.cinco@example.test','2026-09-13','19:30:00',2,'',NULL,NULL,'admin','fx-limite-cinco-05',NULL,'confirmada',NULL,'2026-09-10 12:00:00','2026-09-07 20:28:20',NULL),(11,'Identidad Teléfono','telefono','+525544442026','2026-09-13','18:30:00',3,'Contacto canónico',NULL,NULL,'landing','fx-contacto-tel-001',NULL,'confirmada',NULL,'2026-09-10 12:00:00','2026-09-07 20:28:20',NULL),(12,'Histórica','email','historial@example.test','2026-09-07','18:00:00',2,'',NULL,NULL,'admin','fx-historica-000001',NULL,'completada',NULL,'2026-09-07 19:30:00','2026-09-07 20:28:20',NULL),(13,'Retención Vigente','email','hold.vigente@example.test','2026-09-10','17:30:00',2,'',NULL,NULL,'landing','fx-hold-vigente-001','2026-09-10 12:05:00','pendiente_verificacion',NULL,'2026-09-10 12:00:00','2026-09-07 20:28:20',NULL),(14,'Retención Vencida','email','hold.vencida@example.test','2026-09-10','18:00:00',2,'',NULL,NULL,'landing','fx-hold-vencida-001','2026-09-10 11:59:59','pendiente_verificacion',NULL,'2026-09-10 12:00:00','2026-09-07 20:28:20',NULL),(15,'Modificable','email','modificar@example.test','2026-09-10','18:30:00',2,'Mover a otra hora',NULL,NULL,'admin','fx-modificable-0001',NULL,'confirmada',NULL,'2026-09-10 12:00:00','2026-09-07 20:28:20',NULL),(16,'Cancelable','email','cancelar@example.test','2026-09-10','19:00:00',2,'',NULL,NULL,'admin','fx-cancelable-0001',NULL,'confirmada',NULL,'2026-09-10 12:00:00','2026-09-07 20:28:20',NULL),(17,'Sin Capacidad','email','sin.capacidad@example.test','2026-09-11','13:00:00',2,'Conservar al fallar modificación',NULL,NULL,'admin','fx-sin-capacidad-01',NULL,'confirmada',NULL,'2026-09-10 12:00:00','2026-09-07 20:28:20',NULL),(18,'Bloqueo Total','email','bloqueo@example.test','2026-09-11','20:00:00',44,'Ocupa todas las mesas',NULL,NULL,'admin','fx-bloqueo-total-01',NULL,'confirmada',NULL,'2026-09-10 12:00:00','2026-09-07 20:28:20',NULL),(19,'Una Mesa','email','una.mesa@example.test','2026-09-10','13:00:00',2,'',NULL,NULL,'admin','fx-una-mesa-000001',NULL,'confirmada',NULL,'2026-09-10 12:00:00','2026-09-07 20:28:20',NULL),(20,'Dos Mesas','email','dos.mesas@example.test','2026-09-10','14:30:00',6,'',NULL,NULL,'admin','fx-dos-mesas-00001',NULL,'confirmada',NULL,'2026-09-10 12:00:00','2026-09-07 20:28:20',NULL),(21,'Tres Mesas','email','tres.mesas@example.test','2026-09-10','16:00:00',10,'',NULL,NULL,'admin','fx-tres-mesas-0001',NULL,'confirmada',NULL,'2026-09-10 12:00:00','2026-09-07 20:28:20',NULL),(22,'Cuatro Mesas Administrativa','email','cuatro.mesas@example.test','2026-09-13','20:00:00',13,'Supera el límite público',NULL,NULL,'admin','fx-cuatro-mesas-001',NULL,'confirmada',NULL,'2026-09-10 12:00:00','2026-09-07 20:28:20',NULL),(23,'Consecutiva A','email','consecutiva@example.test','2026-09-13','13:00:00',2,'',NULL,NULL,'admin','fx-consecutiva-a-01',NULL,'confirmada',NULL,'2026-09-10 12:00:00','2026-09-07 20:28:20',NULL),(24,'Consecutiva B','email','consecutiva@example.test','2026-09-13','15:00:00',2,'',NULL,NULL,'admin','fx-consecutiva-b-01',NULL,'confirmada',NULL,'2026-09-10 12:00:00','2026-09-07 20:28:20',NULL),(25,'POS Confirmada','email','pos.confirmada@example.test','2026-09-10','19:30:00',2,'',NULL,NULL,'admin','fx-pos-confirmada-01',NULL,'confirmada',NULL,'2026-09-10 12:00:00','2026-09-07 20:28:20',NULL),(26,'POS Convertida','email','pos.convertida@example.test','2026-09-10','20:00:00',2,'',NULL,NULL,'admin','fx-pos-convertida-000001',NULL,'confirmada',NULL,'2026-09-10 19:50:00','2026-09-07 20:28:20',NULL),(27,'POS En Curso','email','pos.encurso@example.test','2026-09-10','20:00:00',6,'',NULL,NULL,'admin','fx-pos-encurso-001',NULL,'confirmada',NULL,'2026-09-13 23:09:29','2026-09-07 20:28:20','2026-09-14 05:09:29'),(28,'POS Completada','email','pos.completa@example.test','2026-09-07','18:00:00',2,'',NULL,NULL,'admin','fx-pos-completa-001',NULL,'completada',NULL,'2026-09-07 19:30:00','2026-09-07 20:28:20',NULL),(29,'POS Tolerancia','email','pos.tolerancia@example.test','2026-09-10','20:30:00',2,'',NULL,NULL,'admin','fx-pos-tolerancia-1',NULL,'confirmada',NULL,'2026-09-10 12:00:00','2026-09-07 20:28:20',NULL),(30,'POS No Show','email','pos.noshow@example.test','2026-09-10','19:00:00',2,'',NULL,NULL,'admin','fx-pos-noshow-0001',NULL,'no_show',NULL,'2026-09-10 12:00:00','2026-09-07 20:28:20',NULL),(31,'Reserva Futura','email','pos.futura@example.test','2026-09-11','13:00:00',2,'Advertencia de reserva próxima',NULL,NULL,'admin','fx-pos-futura-00001',NULL,'confirmada',NULL,'2026-09-10 12:00:00','2026-09-07 20:28:20',NULL),(32,'Horario Afectado','email','horario@example.test','2026-09-10','21:00:00',2,'Conflicto al adelantar el cierre',NULL,NULL,'admin','fx-horario-afectado',NULL,'confirmada',NULL,'2026-09-10 12:00:00','2026-09-07 20:28:20',NULL),(33,'Adriana Lozano','email','adriana.000@example.test','2026-08-09','13:00:00',2,'',NULL,NULL,'admin','fx-analytics-res-000',NULL,'cancelada',NULL,'2026-08-09 15:00:00','2026-09-07 20:28:20',NULL),(34,'Bruno Cerván','email','bruno.001@example.test','2026-08-09','20:00:00',4,'Alergia a nueces',NULL,NULL,'landing','fx-analytics-res-001',NULL,'completada',NULL,'2026-08-09 22:00:00','2026-09-07 20:28:20',NULL),(35,'Carla Ibáñez','email','carla.002@example.test','2026-08-10','21:00:00',2,'Aniversario',NULL,NULL,'admin','fx-analytics-res-002',NULL,'completada',NULL,'2026-08-10 23:00:00','2026-09-07 20:28:20',NULL),(36,'Diego Maldonado','email','diego.003@example.test','2026-08-10','19:00:00',6,'Celebración de trabajo',NULL,NULL,'landing','fx-analytics-res-003',NULL,'completada',NULL,'2026-08-10 21:00:00','2026-09-07 20:28:20',NULL),(37,'Elena Ferrer','email','elena.004@example.test','2026-08-10','14:00:00',3,'Mesa junto a ventana',NULL,NULL,'admin','fx-analytics-res-004',NULL,'completada',NULL,'2026-08-10 16:00:00','2026-09-07 20:28:20',NULL),(38,'Fabián Ortuño','email','fabian.005@example.test','2026-08-11','13:30:00',2,'Carriola',NULL,NULL,'landing','fx-analytics-res-005',NULL,'completada',NULL,'2026-08-11 15:30:00','2026-09-07 20:28:20',NULL),(39,'Gabriela Rentería','email','gabriela.006@example.test','2026-08-12','19:00:00',8,'Cliente frecuente',NULL,NULL,'admin','fx-analytics-res-006',NULL,'completada',NULL,'2026-08-12 21:00:00','2026-09-07 20:28:20',NULL),(40,'Héctor Salcedo','email','hector.007@example.test','2026-08-12','14:00:00',4,'',NULL,NULL,'landing','fx-analytics-res-007',NULL,'completada',NULL,'2026-08-12 16:00:00','2026-09-07 20:28:20',NULL),(41,'Irene Bustos','email','irene.008@example.test','2026-08-12','21:00:00',2,'Cumpleaños',NULL,NULL,'admin','fx-analytics-res-008',NULL,'completada',NULL,'2026-08-12 23:00:00','2026-09-07 20:28:20',NULL),(42,'Joaquín Nieto','email','joaquin.009@example.test','2026-08-12','19:00:00',5,'Silla alta para bebé',NULL,NULL,'landing','fx-analytics-res-009',NULL,'completada',NULL,'2026-08-12 21:00:00','2026-09-07 20:28:20',NULL),(43,'Karla Villalobos','email','karla.010@example.test','2026-08-13','14:00:00',2,'Terraza si hay',NULL,NULL,'admin','fx-analytics-res-010',NULL,'completada',NULL,'2026-08-13 16:00:00','2026-09-07 20:28:20',NULL),(44,'Leonardo Prats','email','leonardo.011@example.test','2026-08-13','21:00:00',4,'',NULL,NULL,'landing','fx-analytics-res-011',NULL,'completada',NULL,'2026-08-13 23:00:00','2026-09-07 20:28:20',NULL),(45,'Mariana Escobar','email','mariana.012@example.test','2026-08-13','19:00:00',2,'Alergia a nueces',NULL,NULL,'admin','fx-analytics-res-012',NULL,'completada',NULL,'2026-08-13 21:00:00','2026-09-07 20:28:20',NULL),(46,'Néstor Aguilar','email','nestor.013@example.test','2026-08-13','14:00:00',6,'Aniversario',NULL,NULL,'landing','fx-analytics-res-013',NULL,'cancelada',NULL,'2026-08-13 16:00:00','2026-09-07 20:28:20',NULL),(47,'Olivia Cardona','email','olivia.014@example.test','2026-08-13','21:00:00',3,'Celebración de trabajo',NULL,NULL,'admin','fx-analytics-res-014',NULL,'completada',NULL,'2026-08-13 23:00:00','2026-09-07 20:28:20',NULL),(48,'Patricio Rueda','email','patricio.015@example.test','2026-08-14','14:30:00',2,'Mesa junto a ventana',NULL,NULL,'landing','fx-analytics-res-015',NULL,'completada',NULL,'2026-08-14 16:30:00','2026-09-07 20:28:20',NULL),(49,'Quetzali Moreno','email','quetzali.016@example.test','2026-08-14','21:30:00',8,'Carriola',NULL,NULL,'admin','fx-analytics-res-016',NULL,'completada',NULL,'2026-08-14 23:30:00','2026-09-07 20:28:20',NULL),(50,'Rodrigo Bañuelos','email','rodrigo.017@example.test','2026-08-16','13:30:00',4,'Cliente frecuente',NULL,NULL,'landing','fx-analytics-res-017',NULL,'no_show',NULL,'2026-08-16 15:30:00','2026-09-07 20:28:20',NULL),(51,'Sofía Zamudio','email','sofia.018@example.test','2026-08-17','19:00:00',2,'',NULL,NULL,'admin','fx-analytics-res-018',NULL,'completada',NULL,'2026-08-17 21:00:00','2026-09-07 20:28:20',NULL),(52,'Tomás Iriarte','email','tomas.019@example.test','2026-08-17','14:00:00',5,'Cumpleaños',NULL,NULL,'landing','fx-analytics-res-019',NULL,'completada',NULL,'2026-08-17 16:00:00','2026-09-07 20:28:20',NULL),(53,'Ursula Pineda','email','ursula.020@example.test','2026-08-17','21:00:00',2,'Silla alta para bebé',NULL,NULL,'admin','fx-analytics-res-020',NULL,'completada',NULL,'2026-08-17 23:00:00','2026-09-07 20:28:20',NULL),(54,'Valentín Cortés','email','valentin.021@example.test','2026-08-18','20:30:00',4,'Terraza si hay',NULL,NULL,'landing','fx-analytics-res-021',NULL,'completada',NULL,'2026-08-18 22:30:00','2026-09-07 20:28:20',NULL),(55,'Ximena Arreola','email','ximena.022@example.test','2026-08-18','15:30:00',2,'',NULL,NULL,'admin','fx-analytics-res-022',NULL,'completada',NULL,'2026-08-18 17:30:00','2026-09-07 20:28:20',NULL),(56,'Yago Benítez','email','yago.023@example.test','2026-08-19','19:30:00',6,'Alergia a nueces',NULL,NULL,'landing','fx-analytics-res-023',NULL,'completada',NULL,'2026-08-19 21:30:00','2026-09-07 20:28:20',NULL),(57,'Zoé Márquez','email','zoe.024@example.test','2026-08-19','14:30:00',3,'Aniversario',NULL,NULL,'admin','fx-analytics-res-024',NULL,'completada',NULL,'2026-08-19 16:30:00','2026-09-07 20:28:20',NULL),(58,'Alonso Vergara','email','alonso.025@example.test','2026-08-19','21:30:00',2,'Celebración de trabajo',NULL,NULL,'landing','fx-analytics-res-025',NULL,'completada',NULL,'2026-08-19 23:30:00','2026-09-07 20:28:20',NULL),(59,'Brenda Quiroz','email','brenda.026@example.test','2026-08-19','19:30:00',8,'Mesa junto a ventana',NULL,NULL,'admin','fx-analytics-res-026',NULL,'cancelada',NULL,'2026-08-19 21:30:00','2026-09-07 20:28:20',NULL),(60,'Cristóbal Serna','email','cristobal.027@example.test','2026-08-20','14:30:00',4,'Carriola',NULL,NULL,'landing','fx-analytics-res-027',NULL,'completada',NULL,'2026-08-20 16:30:00','2026-09-07 20:28:20',NULL),(61,'Daniela Rojas','email','daniela.028@example.test','2026-08-20','21:30:00',2,'Cliente frecuente',NULL,NULL,'admin','fx-analytics-res-028',NULL,'completada',NULL,'2026-08-20 23:30:00','2026-09-07 20:28:20',NULL),(62,'Emiliano Tapia','email','emiliano.029@example.test','2026-08-20','19:30:00',5,'',NULL,NULL,'landing','fx-analytics-res-029',NULL,'completada',NULL,'2026-08-20 21:30:00','2026-09-07 20:28:20',NULL),(63,'Fernanda Olmos','email','fernanda.030@example.test','2026-08-20','14:30:00',2,'Cumpleaños',NULL,NULL,'admin','fx-analytics-res-030',NULL,'completada',NULL,'2026-08-20 16:30:00','2026-09-07 20:28:20',NULL),(64,'Gerardo Pantoja','email','gerardo.031@example.test','2026-08-20','21:30:00',4,'Silla alta para bebé',NULL,NULL,'landing','fx-analytics-res-031',NULL,'completada',NULL,'2026-08-20 23:30:00','2026-09-07 20:28:20',NULL),(65,'Helena Muñiz','email','helena.032@example.test','2026-08-20','19:30:00',2,'Terraza si hay',NULL,NULL,'admin','fx-analytics-res-032',NULL,'completada',NULL,'2026-08-20 21:30:00','2026-09-07 20:28:20',NULL),(66,'Ignacio Bravo','email','ignacio.033@example.test','2026-08-21','20:30:00',6,'',NULL,NULL,'landing','fx-analytics-res-033',NULL,'completada',NULL,'2026-08-21 22:30:00','2026-09-07 20:28:20',NULL),(67,'Julieta Ocampo','email','julieta.034@example.test','2026-08-21','15:30:00',3,'Alergia a nueces',NULL,NULL,'admin','fx-analytics-res-034',NULL,'no_show',NULL,'2026-08-21 17:30:00','2026-09-07 20:28:20',NULL),(68,'Kevin Alcaraz','email','kevin.035@example.test','2026-08-21','13:30:00',2,'Aniversario',NULL,NULL,'landing','fx-analytics-res-035',NULL,'completada',NULL,'2026-08-21 15:30:00','2026-09-07 20:28:20',NULL),(69,'Lucía Sandoval','email','lucia.036@example.test','2026-08-22','13:00:00',8,'Celebración de trabajo',NULL,NULL,'admin','fx-analytics-res-036',NULL,'completada',NULL,'2026-08-22 15:00:00','2026-09-07 20:28:20',NULL),(70,'Matías Grijalva','email','matias.037@example.test','2026-08-24','15:30:00',4,'Mesa junto a ventana',NULL,NULL,'landing','fx-analytics-res-037',NULL,'completada',NULL,'2026-08-24 17:30:00','2026-09-07 20:28:20',NULL),(71,'Natalia Espinoza','email','natalia.038@example.test','2026-08-24','13:30:00',2,'Carriola',NULL,NULL,'admin','fx-analytics-res-038',NULL,'completada',NULL,'2026-08-24 15:30:00','2026-09-07 20:28:20',NULL),(72,'Óscar Valadez','email','oscar.039@example.test','2026-08-25','14:30:00',5,'Cliente frecuente',NULL,NULL,'landing','fx-analytics-res-039',NULL,'cancelada',NULL,'2026-08-25 16:30:00','2026-09-07 20:28:20',NULL),(73,'Paola Zúñiga','email','paola.040@example.test','2026-08-25','21:30:00',2,'',NULL,NULL,'admin','fx-analytics-res-040',NULL,'completada',NULL,'2026-08-25 23:30:00','2026-09-07 20:28:20',NULL),(74,'Ramiro Cisneros','email','ramiro.041@example.test','2026-08-25','19:30:00',4,'Cumpleaños',NULL,NULL,'landing','fx-analytics-res-041',NULL,'completada',NULL,'2026-08-25 21:30:00','2026-09-07 20:28:20',NULL),(75,'Sergio Maldonado','email','sergio.042@example.test','2026-08-25','14:30:00',2,'Silla alta para bebé',NULL,NULL,'admin','fx-analytics-res-042',NULL,'completada',NULL,'2026-08-25 16:30:00','2026-09-07 20:28:20',NULL),(76,'Tania Vergara','email','tania.043@example.test','2026-08-25','21:30:00',6,'Terraza si hay',NULL,NULL,'landing','fx-analytics-res-043',NULL,'completada',NULL,'2026-08-25 23:30:00','2026-09-07 20:28:20',NULL),(77,'Ulises Fonseca','email','ulises.044@example.test','2026-08-26','15:00:00',3,'',NULL,NULL,'admin','fx-analytics-res-044',NULL,'completada',NULL,'2026-08-26 17:00:00','2026-09-07 20:28:20',NULL),(78,'Verónica Nájera','email','veronica.045@example.test','2026-08-26','13:00:00',2,'Alergia a nueces',NULL,NULL,'landing','fx-analytics-res-045',NULL,'completada',NULL,'2026-08-26 15:00:00','2026-09-07 20:28:20',NULL),(79,'Wendy Cabrera','email','wendy.046@example.test','2026-08-26','20:00:00',8,'Aniversario',NULL,NULL,'admin','fx-analytics-res-046',NULL,'completada',NULL,'2026-08-26 22:00:00','2026-09-07 20:28:20',NULL),(80,'Xavier Robledo','email','xavier.047@example.test','2026-08-26','15:00:00',4,'Celebración de trabajo',NULL,NULL,'landing','fx-analytics-res-047',NULL,'completada',NULL,'2026-08-26 17:00:00','2026-09-07 20:28:20',NULL),(81,'Yolanda Prieto','email','yolanda.048@example.test','2026-08-27','13:00:00',2,'Mesa junto a ventana',NULL,NULL,'admin','fx-analytics-res-048',NULL,'completada',NULL,'2026-08-27 15:00:00','2026-09-07 20:28:20',NULL),(82,'Zacarías Beltrán','email','zacarias.049@example.test','2026-08-27','20:00:00',5,'Carriola',NULL,NULL,'landing','fx-analytics-res-049',NULL,'completada',NULL,'2026-08-27 22:00:00','2026-09-07 20:28:20',NULL),(83,'Ana Sofía Lira','email','ana.050@example.test','2026-08-28','21:00:00',2,'Cliente frecuente',NULL,NULL,'admin','fx-analytics-res-050',NULL,'completada',NULL,'2026-08-28 23:00:00','2026-09-07 20:28:20',NULL),(84,'Braulio Mendieta','email','braulio.051@example.test','2026-08-28','19:00:00',4,'',NULL,NULL,'landing','fx-analytics-res-051',NULL,'no_show',NULL,'2026-08-28 21:00:00','2026-09-07 20:28:20',NULL),(85,'Cecilia Ynzunza','email','cecilia.052@example.test','2026-08-28','14:00:00',2,'Cumpleaños',NULL,NULL,'admin','fx-analytics-res-052',NULL,'cancelada',NULL,'2026-08-28 16:00:00','2026-09-07 20:28:20',NULL),(86,'Damián Portillo','email','damian.053@example.test','2026-08-29','13:30:00',6,'Silla alta para bebé',NULL,NULL,'landing','fx-analytics-res-053',NULL,'completada',NULL,'2026-08-29 15:30:00','2026-09-07 20:28:20',NULL),(87,'Estela Guardado','email','estela.054@example.test','2026-08-30','19:00:00',3,'Terraza si hay',NULL,NULL,'admin','fx-analytics-res-054',NULL,'completada',NULL,'2026-08-30 21:00:00','2026-09-07 20:28:20',NULL),(88,'Fausto Rivadeneira','email','fausto.055@example.test','2026-08-30','14:00:00',2,'',NULL,NULL,'landing','fx-analytics-res-055',NULL,'completada',NULL,'2026-08-30 16:00:00','2026-09-07 20:28:20',NULL),(89,'Gina Palomares','email','gina.056@example.test','2026-08-30','21:00:00',8,'Alergia a nueces',NULL,NULL,'admin','fx-analytics-res-056',NULL,'completada',NULL,'2026-08-30 23:00:00','2026-09-07 20:28:20',NULL),(90,'Hugo Villaseñor','email','hugo.057@example.test','2026-08-30','19:00:00',4,'Aniversario',NULL,NULL,'landing','fx-analytics-res-057',NULL,'completada',NULL,'2026-08-30 21:00:00','2026-09-07 20:28:20',NULL),(91,'Inés Carbajal','email','ines.058@example.test','2026-08-31','14:00:00',2,'Celebración de trabajo',NULL,NULL,'admin','fx-analytics-res-058',NULL,'completada',NULL,'2026-08-31 16:00:00','2026-09-07 20:28:20',NULL),(92,'Jonás Ledesma','email','jonas.059@example.test','2026-08-31','21:00:00',5,'Mesa junto a ventana',NULL,NULL,'landing','fx-analytics-res-059',NULL,'completada',NULL,'2026-08-31 23:00:00','2026-09-07 20:28:20',NULL),(93,'Katia Berrones','email','katia.060@example.test','2026-08-31','19:00:00',2,'Carriola',NULL,NULL,'admin','fx-analytics-res-060',NULL,'completada',NULL,'2026-08-31 21:00:00','2026-09-07 20:28:20',NULL),(94,'Luis Ángel Toledo','email','luis.061@example.test','2026-08-31','14:00:00',4,'Cliente frecuente',NULL,NULL,'landing','fx-analytics-res-061',NULL,'completada',NULL,'2026-08-31 16:00:00','2026-09-07 20:28:20',NULL),(95,'Miriam Cuéllar','email','miriam.062@example.test','2026-08-31','21:00:00',2,'',NULL,NULL,'admin','fx-analytics-res-062',NULL,'completada',NULL,'2026-08-31 23:00:00','2026-09-07 20:28:20',NULL),(96,'Nicolás Arámbula','email','nicolas.063@example.test','2026-08-31','19:00:00',6,'Cumpleaños',NULL,NULL,'landing','fx-analytics-res-063',NULL,'completada',NULL,'2026-08-31 21:00:00','2026-09-07 20:28:20',NULL),(97,'Odette Fierro','email','odette.064@example.test','2026-09-01','20:00:00',3,'Silla alta para bebé',NULL,NULL,'admin','fx-analytics-res-064',NULL,'completada',NULL,'2026-09-01 22:00:00','2026-09-07 20:28:20',NULL),(98,'Pablo Zepeda','email','pablo.065@example.test','2026-09-01','15:00:00',2,'Terraza si hay',NULL,NULL,'landing','fx-analytics-res-065',NULL,'cancelada',NULL,'2026-09-01 17:00:00','2026-09-07 20:28:20',NULL),(99,'Regina Alfaro','email','regina.066@example.test','2026-09-01','13:00:00',8,'',NULL,NULL,'admin','fx-analytics-res-066',NULL,'completada',NULL,'2026-09-01 15:00:00','2026-09-07 20:28:20',NULL),(100,'Samuel Íñiguez','email','samuel.067@example.test','2026-09-02','21:30:00',4,'Alergia a nueces',NULL,NULL,'landing','fx-analytics-res-067',NULL,'completada',NULL,'2026-09-02 23:30:00','2026-09-07 20:28:20',NULL),(101,'Tere Camarillo','email','tere.068@example.test','2026-09-02','19:30:00',2,'Aniversario',NULL,NULL,'admin','fx-analytics-res-068',NULL,'no_show',NULL,'2026-09-02 21:30:00','2026-09-07 20:28:20',NULL),(102,'Uriel Bermúdez','email','uriel.069@example.test','2026-09-04','20:30:00',5,'Celebración de trabajo',NULL,NULL,'landing','fx-analytics-res-069',NULL,'completada',NULL,'2026-09-04 22:30:00','2026-09-07 20:28:20',NULL),(103,'Vania Loera','email','vania.070@example.test','2026-09-04','15:30:00',2,'Mesa junto a ventana',NULL,NULL,'admin','fx-analytics-res-070',NULL,'completada',NULL,'2026-09-04 17:30:00','2026-09-07 20:28:20',NULL),(104,'Wilfrido Anaya','email','wilfrido.071@example.test','2026-09-04','13:30:00',4,'Carriola',NULL,NULL,'landing','fx-analytics-res-071',NULL,'completada',NULL,'2026-09-04 15:30:00','2026-09-07 20:28:20',NULL),(105,'Adriana Lozano','email','adriana.072@example.test','2026-09-05','13:00:00',2,'Cliente frecuente',NULL,NULL,'admin','fx-analytics-res-072',NULL,'completada',NULL,'2026-09-05 15:00:00','2026-09-07 20:28:20',NULL),(106,'Bruno Cerván','email','bruno.073@example.test','2026-09-05','20:00:00',6,'',NULL,NULL,'landing','fx-analytics-res-073',NULL,'completada',NULL,'2026-09-05 22:00:00','2026-09-07 20:28:20',NULL),(107,'Carla Ibáñez','email','carla.074@example.test','2026-09-05','15:00:00',3,'Cumpleaños',NULL,NULL,'admin','fx-analytics-res-074',NULL,'completada',NULL,'2026-09-05 17:00:00','2026-09-07 20:28:20',NULL),(108,'Diego Maldonado','email','diego.075@example.test','2026-09-05','13:00:00',2,'Silla alta para bebé',NULL,NULL,'landing','fx-analytics-res-075',NULL,'completada',NULL,'2026-09-05 15:00:00','2026-09-07 20:28:20',NULL),(109,'Elena Ferrer','email','elena.076@example.test','2026-09-05','20:00:00',8,'Terraza si hay',NULL,NULL,'admin','fx-analytics-res-076',NULL,'completada',NULL,'2026-09-05 22:00:00','2026-09-07 20:28:20',NULL),(110,'Fabián Ortuño','email','fabian.077@example.test','2026-09-06','13:30:00',4,'',NULL,NULL,'landing','fx-analytics-res-077',NULL,'confirmada',NULL,'2026-09-05 13:30:00','2026-09-07 20:28:20',NULL),(111,'Gabriela Rentería','email','gabriela.078@example.test','2026-09-06','20:30:00',2,'Alergia a nueces',NULL,NULL,'admin','fx-analytics-res-078',NULL,'confirmada',NULL,'2026-09-05 20:30:00','2026-09-07 20:28:20',NULL),(112,'Héctor Salcedo','email','hector.079@example.test','2026-09-06','15:30:00',5,'Aniversario',NULL,NULL,'landing','fx-analytics-res-079',NULL,'confirmada',NULL,'2026-09-05 15:30:00','2026-09-07 20:28:20',NULL),(113,'Irene Bustos','email','irene.080@example.test','2026-09-06','13:30:00',2,'Celebración de trabajo',NULL,NULL,'admin','fx-analytics-res-080',NULL,'confirmada',NULL,'2026-09-05 13:30:00','2026-09-07 20:28:20',NULL),(114,'Joaquín Nieto','email','joaquin.081@example.test','2026-09-07','20:30:00',4,'Mesa junto a ventana',NULL,NULL,'landing','fx-analytics-res-081',NULL,'confirmada',NULL,'2026-09-06 20:30:00','2026-09-07 20:28:20',NULL),(115,'Karla Villalobos','email','karla.082@example.test','2026-09-07','15:30:00',2,'Carriola',NULL,NULL,'admin','fx-analytics-res-082',NULL,'confirmada',NULL,'2026-09-06 15:30:00','2026-09-07 20:28:20',NULL),(116,'Gina Palomares','email','reserva.018@example.test','2026-08-29','13:00:00',6,'Cumpleaños',NULL,NULL,'landing','fx-revpash-res-018',NULL,'completada',NULL,'2026-08-29 15:00:00','2026-08-28 19:00:00',NULL),(117,'Nicolás Arámbula','email','reserva.019@example.test','2026-08-29','20:00:00',4,'Carriola',NULL,NULL,'admin','fx-revpash-res-019',NULL,'completada',NULL,'2026-08-29 22:00:00','2026-08-28 02:00:00',NULL),(118,'Vania Loera','email','reserva.020@example.test','2026-08-29','13:30:00',2,'Cliente frecuente',NULL,NULL,'landing','fx-revpash-res-020',NULL,'completada',NULL,'2026-08-29 15:30:00','2026-08-26 19:30:00',NULL),(119,'Cecilia Ynzunza','email','reserva.021@example.test','2026-08-29','13:00:00',5,'Alergia a nueces',NULL,NULL,'admin','fx-revpash-res-021',NULL,'no_show',NULL,'2026-08-29 15:00:00','2026-08-28 19:00:00',NULL),(120,'Joaquín Nieto','email','reserva.022@example.test','2026-08-29','20:00:00',3,'Aniversario',NULL,NULL,'landing','fx-revpash-res-022',NULL,'completada',NULL,'2026-08-29 22:00:00','2026-08-28 02:00:00',NULL),(121,'Quirino Ávila','email','reserva.023@example.test','2026-08-29','13:30:00',6,'Terraza si hay',NULL,NULL,'admin','fx-revpash-res-023',NULL,'cancelada',NULL,'2026-08-28 13:30:00','2026-08-26 19:30:00',NULL),(122,'Xavier Peña','email','reserva.024@example.test','2026-08-30','13:00:00',4,'',NULL,NULL,'landing','fx-revpash-res-024',NULL,'completada',NULL,'2026-08-30 15:00:00','2026-08-29 19:00:00',NULL),(123,'Familia Nava','email','reserva.025@example.test','2026-08-30','20:00:00',2,'Mesa junto a ventana',NULL,NULL,'admin','fx-revpash-res-025',NULL,'completada',NULL,'2026-08-30 22:00:00','2026-08-29 02:00:00',NULL),(124,'Carla Ibáñez','email','reserva.026@example.test','2026-08-30','13:30:00',5,'Cumpleaños',NULL,NULL,'landing','fx-revpash-res-026',NULL,'completada',NULL,'2026-08-30 15:30:00','2026-08-27 19:30:00',NULL),(125,'Jonás Ledesma','email','reserva.027@example.test','2026-08-30','13:00:00',3,'Carriola',NULL,NULL,'admin','fx-revpash-res-027',NULL,'completada',NULL,'2026-08-30 15:00:00','2026-08-29 19:00:00',NULL),(126,'Hugo Villaseñor','email','reserva.001@example.test','2026-08-31','14:00:00',5,'Mesa junto a ventana',NULL,NULL,'admin','fx-revpash-res-001',NULL,'completada',NULL,'2026-08-31 16:00:00','2026-08-29 20:00:00',NULL),(127,'Odette Fierro','email','reserva.002@example.test','2026-08-31','21:00:00',3,'Cumpleaños',NULL,NULL,'landing','fx-revpash-res-002',NULL,'completada',NULL,'2026-08-31 23:00:00','2026-08-29 03:00:00',NULL),(128,'Wilfrido Anaya','email','reserva.003@example.test','2026-09-01','21:00:00',6,'Carriola',NULL,NULL,'admin','fx-revpash-res-003',NULL,'completada',NULL,'2026-09-01 23:00:00','2026-09-01 03:00:00',NULL),(129,'Damián Portillo','email','reserva.004@example.test','2026-09-01','20:30:00',4,'Cliente frecuente',NULL,NULL,'landing','fx-revpash-res-004',NULL,'completada',NULL,'2026-09-01 22:30:00','2026-08-31 02:30:00',NULL),(130,'Karla Villalobos','email','reserva.005@example.test','2026-09-01','14:00:00',2,'Alergia a nueces',NULL,NULL,'admin','fx-revpash-res-005',NULL,'no_show',NULL,'2026-09-01 16:00:00','2026-08-29 20:00:00',NULL),(131,'Rita Peña','email','reserva.006@example.test','2026-09-02','13:00:00',5,'Aniversario',NULL,NULL,'landing','fx-revpash-res-006',NULL,'completada',NULL,'2026-09-02 15:00:00','2026-09-01 19:00:00',NULL),(132,'Yara Sol','email','reserva.007@example.test','2026-09-02','20:00:00',3,'Terraza si hay',NULL,NULL,'admin','fx-revpash-res-007',NULL,'cancelada',NULL,'2026-09-01 20:00:00','2026-09-01 02:00:00',NULL),(133,'Familia Robles','email','reserva.008@example.test','2026-09-02','13:30:00',6,'',NULL,NULL,'landing','fx-revpash-res-008',NULL,'completada',NULL,'2026-09-02 15:30:00','2026-08-30 19:30:00',NULL),(134,'Diego Nava','email','reserva.009@example.test','2026-09-03','21:00:00',4,'Mesa junto a ventana',NULL,NULL,'admin','fx-revpash-res-009',NULL,'completada',NULL,'2026-09-03 23:00:00','2026-09-03 03:00:00',NULL),(135,'Katia Berrones','email','reserva.010@example.test','2026-09-03','20:30:00',2,'Cumpleaños',NULL,NULL,'landing','fx-revpash-res-010',NULL,'completada',NULL,'2026-09-03 22:30:00','2026-09-02 02:30:00',NULL),(136,'Samuel Íñiguez','email','reserva.011@example.test','2026-09-03','14:00:00',5,'Carriola',NULL,NULL,'admin','fx-revpash-res-011',NULL,'completada',NULL,'2026-09-03 16:00:00','2026-08-31 20:00:00',NULL),(137,'Zacarías Beltrán','email','reserva.012@example.test','2026-09-03','21:00:00',3,'Cliente frecuente',NULL,NULL,'landing','fx-revpash-res-012',NULL,'completada',NULL,'2026-09-03 23:00:00','2026-09-03 03:00:00',NULL),(138,'Gael Ruan','email','reserva.013@example.test','2026-09-04','14:00:00',6,'Alergia a nueces',NULL,NULL,'admin','fx-revpash-res-013',NULL,'no_show',NULL,'2026-09-04 16:00:00','2026-09-02 20:00:00',NULL),(139,'Noa Cid','email','reserva.014@example.test','2026-09-04','21:00:00',4,'Aniversario',NULL,NULL,'landing','fx-revpash-res-014',NULL,'completada',NULL,'2026-09-04 23:00:00','2026-09-02 03:00:00',NULL),(140,'Ulises Nava','email','reserva.015@example.test','2026-09-04','20:30:00',2,'Terraza si hay',NULL,NULL,'admin','fx-revpash-res-015',NULL,'cancelada',NULL,'2026-09-03 20:30:00','2026-09-04 02:30:00',NULL),(141,'Grupo Ibarra','email','reserva.016@example.test','2026-09-04','14:00:00',5,'',NULL,NULL,'landing','fx-revpash-res-016',NULL,'completada',NULL,'2026-09-04 16:00:00','2026-09-02 20:00:00',NULL),(142,'Familia Cruz','email','reserva.017@example.test','2026-09-04','21:00:00',3,'Mesa junto a ventana',NULL,NULL,'admin','fx-revpash-res-017',NULL,'completada',NULL,'2026-09-04 23:00:00','2026-09-02 03:00:00',NULL);
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
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subreceta_ingredientes`
--

LOCK TABLES `subreceta_ingredientes` WRITE;
/*!40000 ALTER TABLE `subreceta_ingredientes` DISABLE KEYS */;
INSERT INTO `subreceta_ingredientes` VALUES (1,1,1,18.000),(2,1,2,60.000);
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
INSERT INTO `subrecetas` VALUES (1,'Shot de espresso','ml',60.000,1,'2026-09-07 20:28:20','2026-09-07 20:28:20');
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
) ENGINE=InnoDB AUTO_INCREMENT=695 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ticket_items`
--

LOCK TABLES `ticket_items` WRITE;
/*!40000 ALTER TABLE `ticket_items` DISABLE KEYS */;
INSERT INTO `ticket_items` VALUES (1,1,'Toast de Salmón Ahumado',230.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-06-18 14:10:00'),(2,1,'Cappuccino',75.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-06-18 14:10:00'),(3,2,'Enchiladas Suizas',220.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-06-18 14:45:00'),(4,2,'Jugo Verde',95.00,'Jugos & Smoothies',2,NULL,2,NULL,'entregado','2026-06-18 14:45:00'),(5,2,'Café Americano',65.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-06-18 14:52:00'),(6,3,'Rib Eye (450 grs.)',785.00,'Platos Fuertes',3,NULL,1,NULL,'entregado','2026-06-18 20:15:00'),(7,3,'Milano',260.00,'Pizzas',4,NULL,2,NULL,'entregado','2026-06-18 20:15:00'),(8,3,'Camarones al Ajillo',210.00,'Entradas',3,NULL,1,NULL,'entregado','2026-06-18 20:15:00'),(9,3,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,4,NULL,'entregado','2026-06-18 20:16:00'),(10,5,'Filete de Res en su Jugo',320.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-06-18 21:10:00'),(11,5,'Queso Burrata con Jitomates Cherrys',210.00,'Entradas',4,NULL,1,NULL,'entregado','2026-06-18 21:10:00'),(12,8,'Hamburguesa de la Casa',260.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-06-18 15:20:00'),(13,8,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-06-18 15:20:00'),(14,8,'Refresco',55.00,'Café & Bebidas',1,NULL,4,NULL,'entregado','2026-06-18 15:21:00'),(15,9,'Café Americano',65.00,'Café & Bebidas',1,NULL,2,NULL,'enviado','2026-09-07 20:26:19'),(16,9,'Molletes',100.00,'Desayunos',3,NULL,1,NULL,'enviado','2026-09-07 20:26:19'),(17,10,'Rigatoni al Limón con Camarones y Parmesano',280.00,'Pastas',3,NULL,2,NULL,'entregado','2026-09-07 19:49:19'),(18,10,'Milano',260.00,'Pizzas',4,NULL,1,NULL,'listo','2026-09-07 19:50:19'),(19,10,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,4,NULL,'entregado','2026-09-07 19:49:19'),(20,11,'Chilaquiles',180.00,'Desayunos',3,NULL,2,NULL,'en_preparacion','2026-09-07 20:12:19'),(21,11,'Jugo Verde',95.00,'Jugos & Smoothies',2,NULL,3,NULL,'entregado','2026-09-07 20:12:19'),(22,11,'Cappuccino',75.00,'Café & Bebidas',1,NULL,1,NULL,'listo','2026-09-07 20:13:19'),(23,12,'Toast de Salmón Ahumado',230.00,'Desayunos',3,NULL,2,NULL,'enviado','2026-09-07 20:23:19'),(24,12,'Jugo de Naranja',85.00,'Jugos & Smoothies',2,NULL,2,NULL,'enviado','2026-09-07 20:23:19'),(25,13,'Filete de Res en su Jugo',320.00,'Platos Fuertes',3,NULL,2,NULL,'en_preparacion','2026-09-07 19:57:19'),(26,13,'Aros de Calamar',210.00,'Entradas',3,NULL,1,NULL,'entregado','2026-09-07 19:57:19'),(27,13,'Refresco',55.00,'Café & Bebidas',1,NULL,4,NULL,'entregado','2026-09-07 19:58:19'),(28,14,'Burrata',260.00,'Pizzas',4,NULL,2,NULL,'en_preparacion','2026-09-07 20:08:19'),(29,14,'Tabla Mixta',320.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-09-07 20:08:19'),(30,14,'Agua Fresca',60.00,'Café & Bebidas',1,NULL,4,NULL,'entregado','2026-09-07 20:09:19'),(31,15,'Rib Eye (450 grs.)',785.00,'Platos Fuertes',3,NULL,1,NULL,'entregado','2026-09-07 19:45:19'),(32,15,'Vacío en Escalopas',280.00,'Platos Fuertes',3,NULL,1,NULL,'entregado','2026-09-07 19:45:19'),(33,15,'Espárragos al Horno',180.00,'Entradas',4,NULL,1,NULL,'listo','2026-09-07 19:46:19'),(34,15,'Café de Olla',65.00,'Café & Bebidas',1,NULL,3,NULL,'entregado','2026-09-07 19:48:19'),(35,4,'Chilaquiles',180.00,'Desayunos',3,NULL,2,NULL,'en_preparacion','2026-09-07 20:18:19'),(36,4,'Café de Olla',65.00,'Café & Bebidas',1,NULL,2,NULL,'enviado','2026-09-07 20:18:19'),(37,16,'Salmón al Horno',295.00,'Platos Fuertes',3,NULL,1,NULL,'listo','2026-09-07 20:01:19'),(38,16,'Frutos Rojos',210.00,'Ensaladas',3,NULL,1,NULL,'entregado','2026-09-07 20:01:19'),(39,16,'Té / Infusión',65.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-09-07 20:02:19'),(40,17,'Mix de 3 Brusquetas',160.00,'Para Picar',3,NULL,2,NULL,'enviado','2026-09-07 20:24:19'),(41,17,'Smoothie de Fresa',100.00,'Jugos & Smoothies',2,NULL,2,NULL,'enviado','2026-09-07 20:24:19'),(42,17,'Latte',80.00,'Café & Bebidas',1,NULL,2,NULL,'enviado','2026-09-07 20:25:19'),(43,6,'Burrata',260.00,'Pizzas',4,NULL,2,NULL,'en_preparacion','2026-09-07 20:03:19'),(44,6,'Aros de Calamar',210.00,'Entradas',3,NULL,1,NULL,'listo','2026-09-07 20:03:19'),(45,6,'Agua de Coco',90.00,'Jugos & Smoothies',2,NULL,4,NULL,'entregado','2026-09-07 20:04:19'),(46,18,'Camarones a los 4 Quesos',260.00,'Pizzas',4,NULL,2,NULL,'en_preparacion','2026-09-07 20:15:19'),(47,18,'Aceitunas Temperadas con Aceite de Chile',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-09-07 20:15:19'),(48,18,'Cappuccino',75.00,'Café & Bebidas',1,NULL,5,NULL,'listo','2026-09-07 20:16:19'),(49,19,'Café Americano',65.00,'Café & Bebidas',1,NULL,1,NULL,'enviado','2026-09-07 20:27:19'),(50,19,'Croissant con Jamón de Pavo',165.00,'Desayunos',3,NULL,1,NULL,'enviado','2026-09-07 20:27:19'),(51,20,'Baguette de Cochinita',210.00,'Desayunos',3,NULL,2,NULL,'en_preparacion','2026-09-07 20:20:19'),(52,20,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,1,NULL,'enviado','2026-09-07 20:20:19'),(53,20,'Agua de Coco',90.00,'Jugos & Smoothies',2,NULL,1,NULL,'listo','2026-09-07 20:21:19'),(54,21,'Hamburguesa de la Casa',260.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-09-07 19:54:19'),(55,21,'Tacos de Cochinita',210.00,'Platos Fuertes',3,NULL,1,NULL,'entregado','2026-09-07 19:54:19'),(56,21,'Chocolate Caliente',80.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-09-07 19:55:19'),(57,21,'Margarita',190.00,'Pizzas',4,NULL,1,NULL,'listo','2026-09-07 19:55:19'),(58,22,'Lasagna de Filete de Res',280.00,'Pastas',3,NULL,2,NULL,'en_preparacion','2026-09-07 20:06:19'),(59,22,'Carpaccio de Salmón',180.00,'Entradas',3,NULL,1,NULL,'entregado','2026-09-07 20:06:19'),(60,22,'Copa Antioxidante',130.00,'Desayunos',2,NULL,1,NULL,'entregado','2026-09-07 20:07:19'),(61,101,'Enmoladas',240.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-05-08 09:10:00'),(62,101,'Enchiladas Suizas',220.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-05-08 09:10:00'),(63,101,'Cappuccino',75.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-05-08 09:11:00'),(64,102,'Enmoladas',240.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-05-22 09:40:00'),(65,102,'Enchiladas Suizas',220.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-05-22 09:40:00'),(66,103,'Enmoladas',240.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-06-05 09:10:00'),(67,103,'Café Americano',65.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-06-05 09:11:00'),(68,104,'Spaguetti a la Boloñesa',280.00,'Pastas',3,NULL,2,NULL,'entregado','2026-05-11 14:10:00'),(69,104,'Mix de 3 Brusquetas',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-05-11 14:10:00'),(70,104,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,4,NULL,'entregado','2026-05-11 14:11:00'),(71,105,'Spaguetti a la Boloñesa',280.00,'Pastas',3,NULL,1,NULL,'entregado','2026-05-27 14:10:00'),(72,105,'Mix de 3 Brusquetas',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-05-27 14:10:00'),(73,106,'Spaguetti a la Boloñesa',280.00,'Pastas',3,NULL,2,NULL,'entregado','2026-06-10 13:40:00'),(74,106,'Crema del Día',180.00,'Sopas & Cremas',3,NULL,1,NULL,'entregado','2026-06-10 13:40:00'),(75,107,'Margarita',190.00,'Pizzas',4,NULL,3,NULL,'entregado','2026-05-09 13:10:00'),(76,107,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-05-09 13:10:00'),(77,108,'Margarita',190.00,'Pizzas',4,NULL,2,NULL,'entregado','2026-05-30 13:40:00'),(78,108,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-05-30 13:40:00'),(79,109,'Margarita',190.00,'Pizzas',4,NULL,3,NULL,'entregado','2026-06-13 13:10:00'),(80,109,'Milano',260.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-06-13 13:10:00'),(81,110,'Rib Eye (450 grs.)',785.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-05-14 19:10:00'),(82,110,'Aceitunas Temperadas con Aceite de Chile',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-05-14 19:10:00'),(83,111,'Rib Eye (450 grs.)',785.00,'Platos Fuertes',3,NULL,1,NULL,'entregado','2026-05-28 19:40:00'),(84,111,'Aceitunas Temperadas con Aceite de Chile',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-05-28 19:40:00'),(85,112,'Rib Eye (450 grs.)',785.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-06-11 19:10:00'),(86,112,'Filete de Res en su Jugo',320.00,'Platos Fuertes',3,NULL,1,NULL,'entregado','2026-06-11 19:10:00'),(87,113,'Frutos Rojos',210.00,'Ensaladas',3,NULL,1,NULL,'entregado','2026-05-15 15:10:00'),(88,113,'Crema del Día',180.00,'Sopas & Cremas',3,NULL,1,NULL,'entregado','2026-05-15 15:10:00'),(89,114,'Frutos Rojos',210.00,'Ensaladas',3,NULL,1,NULL,'entregado','2026-05-29 15:40:00'),(90,114,'Crema del Día',180.00,'Sopas & Cremas',3,NULL,1,NULL,'entregado','2026-05-29 15:40:00'),(91,115,'Frutos Rojos',210.00,'Ensaladas',3,NULL,2,NULL,'entregado','2026-06-12 15:10:00'),(92,115,'Jugo Verde',95.00,'Jugos & Smoothies',2,NULL,2,NULL,'entregado','2026-06-12 15:11:00'),(93,116,'Tabla Mixta',320.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-05-16 20:10:00'),(94,116,'Burrata',260.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-05-16 20:10:00'),(95,117,'Tabla Mixta',320.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-05-31 20:40:00'),(96,117,'Burrata',260.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-05-31 20:40:00'),(97,118,'Tabla Mixta',320.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-06-14 20:10:00'),(98,118,'Aros de Calamar',210.00,'Entradas',3,NULL,1,NULL,'entregado','2026-06-14 20:10:00'),(99,200,'Chilaquiles',180.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-08-11 15:20:00'),(100,200,'Café de Olla',65.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-08-11 15:20:00'),(101,201,'Chilaquiles',180.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-08-13 15:45:00'),(102,201,'Café de Olla',65.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-08-13 15:45:00'),(103,201,'Jugo Verde',95.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-08-13 15:45:00'),(104,202,'Chilaquiles',180.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-08-16 16:10:00'),(105,202,'Café de Olla',65.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-08-16 16:10:00'),(106,203,'Chilaquiles',180.00,'Desayunos',3,NULL,3,NULL,'entregado','2026-08-20 14:55:00'),(107,203,'Café de Olla',65.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-08-20 14:55:00'),(108,203,'Molletes',100.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-08-20 14:55:00'),(109,204,'Chilaquiles',180.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-08-25 15:35:00'),(110,204,'Café de Olla',65.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-08-25 15:35:00'),(111,205,'Chilaquiles',180.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-09-01 16:15:00'),(112,205,'Café de Olla',65.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-09-01 16:15:00'),(113,206,'Enmoladas',240.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-08-12 15:05:00'),(114,206,'Jugo Verde',95.00,'Jugos & Smoothies',2,NULL,2,NULL,'entregado','2026-08-12 15:05:00'),(115,207,'Enmoladas',240.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-08-18 15:25:00'),(116,207,'Jugo Verde',95.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-08-18 15:25:00'),(117,207,'Café Americano',65.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-08-18 15:25:00'),(118,208,'Enmoladas',240.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-08-26 16:05:00'),(119,208,'Jugo Verde',95.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-08-26 16:05:00'),(120,209,'Enmoladas',240.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-09-02 15:50:00'),(121,209,'Jugo Verde',95.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-09-02 15:50:00'),(122,210,'Toast de Salmón Ahumado',230.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-08-14 16:35:00'),(123,210,'Cappuccino',75.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-08-14 16:35:00'),(124,211,'Toast de Salmón Ahumado',230.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-08-19 15:15:00'),(125,211,'Cappuccino',75.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-08-19 15:15:00'),(126,212,'Toast de Salmón Ahumado',230.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-08-27 16:25:00'),(127,212,'Cappuccino',75.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-08-27 16:25:00'),(128,212,'Molletes',100.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-08-27 16:25:00'),(129,213,'Toast de Salmón Ahumado',230.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-09-04 15:40:00'),(130,213,'Cappuccino',75.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-09-04 15:40:00'),(131,214,'Enchiladas Suizas',220.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-08-15 15:30:00'),(132,214,'Café Americano',65.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-08-15 15:30:00'),(133,215,'Enchiladas Suizas',220.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-08-22 16:20:00'),(134,215,'Café Americano',65.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-08-22 16:20:00'),(135,216,'Enchiladas Suizas',220.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-09-03 15:55:00'),(136,216,'Café Americano',65.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-09-03 15:55:00'),(137,217,'Hamburguesa de la Casa',260.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-08-11 20:15:00'),(138,217,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-08-11 20:15:00'),(139,218,'Hamburguesa de la Casa',260.00,'Platos Fuertes',3,NULL,1,NULL,'entregado','2026-08-14 19:45:00'),(140,218,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-08-14 19:45:00'),(141,219,'Hamburguesa de la Casa',260.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-08-21 21:05:00'),(142,219,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-08-21 21:05:00'),(143,219,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,2,NULL,'entregado','2026-08-21 21:05:00'),(144,220,'Hamburguesa de la Casa',260.00,'Platos Fuertes',3,NULL,1,NULL,'entregado','2026-08-28 20:35:00'),(145,220,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-08-28 20:35:00'),(146,221,'Hamburguesa de la Casa',260.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-09-05 19:25:00'),(147,221,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-09-05 19:25:00'),(148,222,'Spaguetti a la Boloñesa',280.00,'Pastas',3,NULL,2,NULL,'entregado','2026-08-13 20:05:00'),(149,222,'Mix de 3 Brusquetas',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-08-13 20:05:00'),(150,223,'Spaguetti a la Boloñesa',280.00,'Pastas',3,NULL,1,NULL,'entregado','2026-08-24 21:15:00'),(151,223,'Mix de 3 Brusquetas',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-08-24 21:15:00'),(152,224,'Spaguetti a la Boloñesa',280.00,'Pastas',3,NULL,2,NULL,'entregado','2026-09-03 19:55:00'),(153,224,'Mix de 3 Brusquetas',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-09-03 19:55:00'),(154,224,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,2,NULL,'entregado','2026-09-03 19:55:00'),(155,225,'Frutos Rojos',210.00,'Ensaladas',3,NULL,2,NULL,'entregado','2026-08-18 21:25:00'),(156,225,'Crema del Día',180.00,'Sopas & Cremas',3,NULL,1,NULL,'entregado','2026-08-18 21:25:00'),(157,226,'Frutos Rojos',210.00,'Ensaladas',3,NULL,1,NULL,'entregado','2026-08-26 20:45:00'),(158,226,'Crema del Día',180.00,'Sopas & Cremas',3,NULL,1,NULL,'entregado','2026-08-26 20:45:00'),(159,227,'Frutos Rojos',210.00,'Ensaladas',3,NULL,2,NULL,'entregado','2026-09-06 19:35:00'),(160,227,'Crema del Día',180.00,'Sopas & Cremas',3,NULL,2,NULL,'entregado','2026-09-06 19:35:00'),(161,228,'Milano',260.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-08-16 20:25:00'),(162,228,'Margarita',190.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-08-16 20:25:00'),(163,229,'Milano',260.00,'Pizzas',4,NULL,2,NULL,'entregado','2026-08-29 21:35:00'),(164,229,'Margarita',190.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-08-29 21:35:00'),(165,230,'Milano',260.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-09-07 20:10:00'),(166,230,'Margarita',190.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-09-07 20:10:00'),(167,231,'Rib Eye (450 grs.)',785.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-08-13 02:20:00'),(168,231,'Margarita',190.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-08-13 02:20:00'),(169,231,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,2,NULL,'entregado','2026-08-13 02:20:00'),(170,232,'Rib Eye (450 grs.)',785.00,'Platos Fuertes',3,NULL,1,NULL,'entregado','2026-08-23 03:05:00'),(171,232,'Margarita',190.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-08-23 03:05:00'),(172,233,'Rib Eye (450 grs.)',785.00,'Platos Fuertes',3,NULL,1,NULL,'entregado','2026-09-02 02:45:00'),(173,233,'Margarita',190.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-09-02 02:45:00'),(174,233,'Aros de Calamar',210.00,'Entradas',3,NULL,1,NULL,'entregado','2026-09-02 02:45:00'),(175,234,'Salmón al Horno',295.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-08-16 03:25:00'),(176,234,'Agua Fresca',60.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-08-16 03:25:00'),(177,235,'Salmón al Horno',295.00,'Platos Fuertes',3,NULL,1,NULL,'entregado','2026-08-26 02:15:00'),(178,235,'Agua Fresca',60.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-08-26 02:15:00'),(179,236,'Salmón al Horno',295.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-09-05 03:20:00'),(180,236,'Agua Fresca',60.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-09-05 03:20:00'),(181,237,'Tabla Mixta',320.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-08-20 02:35:00'),(182,237,'Burrata',260.00,'Pizzas',4,NULL,2,NULL,'entregado','2026-08-20 02:35:00'),(183,238,'Tabla Mixta',320.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-08-31 03:15:00'),(184,238,'Burrata',260.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-08-31 03:15:00'),(185,239,'Tabla Mixta',320.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-09-07 02:55:00'),(186,239,'Burrata',260.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-09-07 02:55:00'),(187,240,'Filete de Res en su Jugo',320.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-08-18 03:10:00'),(188,240,'Aros de Calamar',210.00,'Entradas',3,NULL,1,NULL,'entregado','2026-08-18 03:10:00'),(189,241,'Filete de Res en su Jugo',320.00,'Platos Fuertes',3,NULL,1,NULL,'entregado','2026-08-28 02:25:00'),(190,241,'Aros de Calamar',210.00,'Entradas',3,NULL,1,NULL,'entregado','2026-08-28 02:25:00'),(191,242,'Filete de Res en su Jugo',320.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-09-08 03:35:00'),(192,242,'Aros de Calamar',210.00,'Entradas',3,NULL,2,NULL,'entregado','2026-09-08 03:35:00'),(193,424,'Enchiladas Suizas',220.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-08-29 14:41:00'),(194,424,'Café Americano',65.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-08-29 14:41:00'),(195,425,'Enchiladas Suizas',220.00,'Desayunos',3,NULL,4,NULL,'entregado','2026-08-29 14:48:00'),(196,425,'Café Americano',65.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-08-29 14:48:00'),(197,426,'Chilaquiles',180.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-08-29 15:29:00'),(198,426,'Café de Olla',65.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-08-29 15:29:00'),(199,427,'Enmoladas',240.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-08-29 15:46:00'),(200,427,'Jugo Verde',95.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-08-29 15:46:00'),(201,428,'Chilaquiles',180.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-08-29 15:18:00'),(202,428,'Café de Olla',65.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-08-29 15:18:00'),(203,429,'Enchiladas Suizas',220.00,'Desayunos',3,NULL,3,NULL,'entregado','2026-08-29 15:35:00'),(204,429,'Café Americano',65.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-08-29 15:35:00'),(205,430,'Enmoladas',240.00,'Desayunos',3,NULL,4,NULL,'entregado','2026-08-29 16:36:00'),(206,430,'Jugo Verde',95.00,'Jugos & Smoothies',2,NULL,2,NULL,'entregado','2026-08-29 16:36:00'),(207,431,'Enchiladas Suizas',220.00,'Desayunos',3,NULL,4,NULL,'entregado','2026-08-29 16:53:00'),(208,431,'Café Americano',65.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-08-29 16:53:00'),(209,431,'Cappuccino',75.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-08-29 16:53:00'),(210,432,'Toast de Salmón Ahumado',230.00,'Desayunos',3,NULL,4,NULL,'entregado','2026-08-29 16:25:00'),(211,432,'Cappuccino',75.00,'Café & Bebidas',1,NULL,4,NULL,'entregado','2026-08-29 16:25:00'),(212,433,'Toast de Salmón Ahumado',230.00,'Desayunos',3,NULL,3,NULL,'entregado','2026-08-29 16:42:00'),(213,433,'Cappuccino',75.00,'Café & Bebidas',1,NULL,3,NULL,'entregado','2026-08-29 16:42:00'),(214,434,'Chilaquiles',180.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-08-29 16:14:00'),(215,434,'Café de Olla',65.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-08-29 16:14:00'),(216,435,'Chilaquiles',180.00,'Desayunos',3,NULL,3,NULL,'entregado','2026-08-29 17:43:00'),(217,435,'Café de Olla',65.00,'Café & Bebidas',1,NULL,3,NULL,'entregado','2026-08-29 17:43:00'),(218,436,'Chilaquiles',180.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-08-29 17:15:00'),(219,436,'Jugo de Naranja',85.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-08-29 17:15:00'),(220,436,'Cappuccino',75.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-08-29 17:15:00'),(221,437,'Enmoladas',240.00,'Desayunos',3,NULL,3,NULL,'entregado','2026-08-29 17:32:00'),(222,437,'Jugo Verde',95.00,'Jugos & Smoothies',2,NULL,2,NULL,'entregado','2026-08-29 17:32:00'),(223,438,'Hamburguesa de la Casa',260.00,'Platos Fuertes',3,NULL,3,NULL,'entregado','2026-08-29 18:50:00'),(224,438,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-08-29 18:50:00'),(225,439,'Spaguetti a la Boloñesa',280.00,'Pastas',3,NULL,3,NULL,'entregado','2026-08-29 18:22:00'),(226,439,'Mix de 3 Brusquetas',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-08-29 18:22:00'),(227,440,'Spaguetti a la Boloñesa',280.00,'Pastas',3,NULL,2,NULL,'entregado','2026-08-29 19:12:00'),(228,440,'Mix de 3 Brusquetas',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-08-29 19:12:00'),(229,441,'Milano',260.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-08-29 19:29:00'),(230,441,'Margarita',190.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-08-29 19:29:00'),(231,441,'Café Americano',65.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-08-29 19:29:00'),(232,442,'Filete de Res en su Jugo',320.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-08-29 19:46:00'),(233,442,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,2,NULL,'entregado','2026-08-29 19:46:00'),(234,443,'Hamburguesa de la Casa',260.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-08-29 19:18:00'),(235,443,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-08-29 19:18:00'),(236,443,'Cappuccino',75.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-08-29 19:18:00'),(237,444,'Milano',260.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-08-29 20:19:00'),(238,444,'Margarita',190.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-08-29 20:19:00'),(239,445,'Filete de Res en su Jugo',320.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-08-29 20:36:00'),(240,445,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,2,NULL,'entregado','2026-08-29 20:36:00'),(241,446,'Hamburguesa de la Casa',260.00,'Platos Fuertes',3,NULL,3,NULL,'entregado','2026-08-29 20:53:00'),(242,446,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-08-29 20:53:00'),(243,447,'Hamburguesa de la Casa',260.00,'Platos Fuertes',3,NULL,3,NULL,'entregado','2026-08-29 20:25:00'),(244,447,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-08-29 20:25:00'),(245,447,'Jugo de Naranja',85.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-08-29 20:25:00'),(246,448,'Aros de Calamar',210.00,'Entradas',3,NULL,1,NULL,'entregado','2026-08-29 21:26:00'),(247,448,'Burrata',260.00,'Pizzas',4,NULL,2,NULL,'entregado','2026-08-29 21:26:00'),(248,449,'Spaguetti a la Boloñesa',280.00,'Pastas',3,NULL,3,NULL,'entregado','2026-08-29 21:43:00'),(249,449,'Mix de 3 Brusquetas',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-08-29 21:43:00'),(250,449,'Café Americano',65.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-08-29 21:43:00'),(251,450,'Cappuccino',75.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-08-29 22:33:00'),(252,450,'Molletes',100.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-08-29 22:33:00'),(253,451,'Café Americano',65.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-08-29 23:40:00'),(254,451,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-08-29 23:40:00'),(255,451,'Café Americano',65.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-08-29 23:40:00'),(256,451,'Café Americano',65.00,'Café & Bebidas',1,NULL,1,NULL,'cancelado','2026-08-29 23:40:00'),(257,452,'Café Americano',65.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-08-30 00:47:00'),(258,452,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-08-30 00:47:00'),(259,453,'Latte',80.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-08-30 00:19:00'),(260,453,'Frutos Rojos',210.00,'Ensaladas',3,NULL,1,NULL,'entregado','2026-08-30 00:19:00'),(261,454,'Spaguetti a la Boloñesa',280.00,'Pastas',3,NULL,2,NULL,'entregado','2026-08-30 01:54:00'),(262,454,'Mix de 3 Brusquetas',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-08-30 01:54:00'),(263,455,'Spaguetti a la Boloñesa',280.00,'Pastas',3,NULL,4,NULL,'entregado','2026-08-30 01:26:00'),(264,455,'Mix de 3 Brusquetas',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-08-30 01:26:00'),(265,456,'Salmón al Horno',295.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-08-30 01:43:00'),(266,456,'Aros de Calamar',210.00,'Entradas',3,NULL,1,NULL,'entregado','2026-08-30 01:43:00'),(267,457,'Spaguetti a la Boloñesa',280.00,'Pastas',3,NULL,3,NULL,'entregado','2026-08-30 02:16:00'),(268,457,'Mix de 3 Brusquetas',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-08-30 02:16:00'),(269,458,'Salmón al Horno',295.00,'Platos Fuertes',3,NULL,3,NULL,'entregado','2026-08-30 02:33:00'),(270,458,'Aros de Calamar',210.00,'Entradas',3,NULL,1,NULL,'entregado','2026-08-30 02:33:00'),(271,459,'Hamburguesa de la Casa',260.00,'Platos Fuertes',3,NULL,4,NULL,'entregado','2026-08-30 02:50:00'),(272,459,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-08-30 02:50:00'),(273,459,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,4,NULL,'entregado','2026-08-30 02:50:00'),(274,459,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-08-30 02:50:00'),(275,460,'Hamburguesa de la Casa',260.00,'Platos Fuertes',3,NULL,3,NULL,'entregado','2026-08-30 02:22:00'),(276,460,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-08-30 02:22:00'),(277,460,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,3,NULL,'entregado','2026-08-30 02:22:00'),(278,461,'Salmón al Horno',295.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-08-30 03:23:00'),(279,461,'Aros de Calamar',210.00,'Entradas',3,NULL,1,NULL,'entregado','2026-08-30 03:23:00'),(280,462,'Hamburguesa de la Casa',260.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-08-30 03:40:00'),(281,462,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-08-30 03:40:00'),(282,462,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,2,NULL,'entregado','2026-08-30 03:40:00'),(283,463,'Salmón al Horno',295.00,'Platos Fuertes',3,NULL,3,NULL,'entregado','2026-08-30 03:12:00'),(284,463,'Aros de Calamar',210.00,'Entradas',3,NULL,1,NULL,'entregado','2026-08-30 03:12:00'),(285,464,'Filete de Res en su Jugo',320.00,'Platos Fuertes',3,NULL,4,NULL,'entregado','2026-08-30 03:29:00'),(286,464,'Crema del Día',180.00,'Sopas & Cremas',3,NULL,2,NULL,'entregado','2026-08-30 03:29:00'),(287,465,'Burrata',260.00,'Pizzas',4,NULL,2,NULL,'entregado','2026-08-30 03:46:00'),(288,465,'Milano',260.00,'Pizzas',4,NULL,2,NULL,'entregado','2026-08-30 03:46:00'),(289,466,'Toast de Salmón Ahumado',230.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-08-30 14:41:00'),(290,466,'Cappuccino',75.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-08-30 14:41:00'),(291,466,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-08-30 14:41:00'),(292,467,'Chilaquiles',180.00,'Desayunos',3,NULL,4,NULL,'entregado','2026-08-30 14:48:00'),(293,467,'Café de Olla',65.00,'Café & Bebidas',1,NULL,4,NULL,'entregado','2026-08-30 14:48:00'),(294,468,'Enchiladas Suizas',220.00,'Desayunos',3,NULL,3,NULL,'entregado','2026-08-30 15:29:00'),(295,468,'Café Americano',65.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-08-30 15:29:00'),(296,469,'Enmoladas',240.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-08-30 15:46:00'),(297,469,'Jugo Verde',95.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-08-30 15:46:00'),(298,469,'Cappuccino',75.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-08-30 15:46:00'),(299,470,'Chilaquiles',180.00,'Desayunos',3,NULL,4,NULL,'entregado','2026-08-30 15:18:00'),(300,470,'Jugo de Naranja',85.00,'Jugos & Smoothies',2,NULL,2,NULL,'entregado','2026-08-30 15:18:00'),(301,470,'Jugo de Naranja',85.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-08-30 15:18:00'),(302,471,'Enmoladas',240.00,'Desayunos',3,NULL,4,NULL,'entregado','2026-08-30 15:35:00'),(303,471,'Jugo Verde',95.00,'Jugos & Smoothies',2,NULL,2,NULL,'entregado','2026-08-30 15:35:00'),(304,472,'Chilaquiles',180.00,'Desayunos',3,NULL,4,NULL,'entregado','2026-08-30 16:36:00'),(305,472,'Jugo de Naranja',85.00,'Jugos & Smoothies',2,NULL,2,NULL,'entregado','2026-08-30 16:36:00'),(306,473,'Enmoladas',240.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-08-30 16:53:00'),(307,473,'Jugo Verde',95.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-08-30 16:53:00'),(308,474,'Chilaquiles',180.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-08-30 16:25:00'),(309,474,'Café de Olla',65.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-08-30 16:25:00'),(310,475,'Enchiladas Suizas',220.00,'Desayunos',3,NULL,4,NULL,'entregado','2026-08-30 16:42:00'),(311,475,'Café Americano',65.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-08-30 16:42:00'),(312,475,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-08-30 16:42:00'),(313,476,'Chilaquiles',180.00,'Desayunos',3,NULL,4,NULL,'entregado','2026-08-30 16:14:00'),(314,476,'Café de Olla',65.00,'Café & Bebidas',1,NULL,4,NULL,'entregado','2026-08-30 16:14:00'),(315,477,'Chilaquiles',180.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-08-30 17:43:00'),(316,477,'Jugo de Naranja',85.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-08-30 17:43:00'),(317,477,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-08-30 17:43:00'),(318,478,'Toast de Salmón Ahumado',230.00,'Desayunos',3,NULL,4,NULL,'entregado','2026-08-30 17:15:00'),(319,478,'Cappuccino',75.00,'Café & Bebidas',1,NULL,4,NULL,'entregado','2026-08-30 17:15:00'),(320,478,'Café Americano',65.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-08-30 17:15:00'),(321,479,'Enmoladas',240.00,'Desayunos',3,NULL,4,NULL,'entregado','2026-08-30 17:32:00'),(322,479,'Jugo Verde',95.00,'Jugos & Smoothies',2,NULL,2,NULL,'entregado','2026-08-30 17:32:00'),(323,479,'Jugo de Naranja',85.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-08-30 17:32:00'),(324,480,'Milano',260.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-08-30 18:50:00'),(325,480,'Margarita',190.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-08-30 18:50:00'),(326,481,'Spaguetti a la Boloñesa',280.00,'Pastas',3,NULL,3,NULL,'entregado','2026-08-30 18:22:00'),(327,481,'Mix de 3 Brusquetas',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-08-30 18:22:00'),(328,482,'Spaguetti a la Boloñesa',280.00,'Pastas',3,NULL,3,NULL,'entregado','2026-08-30 19:12:00'),(329,482,'Mix de 3 Brusquetas',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-08-30 19:12:00'),(330,482,'Jugo de Naranja',85.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-08-30 19:12:00'),(331,483,'Aros de Calamar',210.00,'Entradas',3,NULL,1,NULL,'entregado','2026-08-30 19:29:00'),(332,483,'Burrata',260.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-08-30 19:29:00'),(333,483,'Cappuccino',75.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-08-30 19:29:00'),(334,484,'Hamburguesa de la Casa',260.00,'Platos Fuertes',3,NULL,3,NULL,'entregado','2026-08-30 19:46:00'),(335,484,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-08-30 19:46:00'),(336,485,'Spaguetti a la Boloñesa',280.00,'Pastas',3,NULL,3,NULL,'entregado','2026-08-30 20:19:00'),(337,485,'Mix de 3 Brusquetas',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-08-30 20:19:00'),(338,486,'Filete de Res en su Jugo',320.00,'Platos Fuertes',3,NULL,3,NULL,'entregado','2026-08-30 20:36:00'),(339,486,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,3,NULL,'entregado','2026-08-30 20:36:00'),(340,487,'Aros de Calamar',210.00,'Entradas',3,NULL,1,NULL,'entregado','2026-08-30 20:53:00'),(341,487,'Burrata',260.00,'Pizzas',4,NULL,2,NULL,'entregado','2026-08-30 20:53:00'),(342,488,'Spaguetti a la Boloñesa',280.00,'Pastas',3,NULL,3,NULL,'entregado','2026-08-30 21:26:00'),(343,488,'Mix de 3 Brusquetas',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-08-30 21:26:00'),(344,489,'Hamburguesa de la Casa',260.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-08-30 21:43:00'),(345,489,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-08-30 21:43:00'),(346,490,'Café Americano',65.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-08-30 22:33:00'),(347,490,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-08-30 22:33:00'),(348,491,'Café Americano',65.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-08-30 23:40:00'),(349,491,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-08-30 23:40:00'),(350,492,'Cappuccino',75.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-08-31 00:47:00'),(351,492,'Molletes',100.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-08-31 00:47:00'),(352,492,'Café Americano',65.00,'Café & Bebidas',1,NULL,1,NULL,'cancelado','2026-08-31 00:47:00'),(353,493,'Hamburguesa de la Casa',260.00,'Platos Fuertes',3,NULL,4,NULL,'entregado','2026-08-31 01:54:00'),(354,493,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-08-31 01:54:00'),(355,493,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,4,NULL,'entregado','2026-08-31 01:54:00'),(356,494,'Burrata',260.00,'Pizzas',4,NULL,2,NULL,'entregado','2026-08-31 01:26:00'),(357,494,'Milano',260.00,'Pizzas',4,NULL,2,NULL,'entregado','2026-08-31 01:26:00'),(358,495,'Hamburguesa de la Casa',260.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-08-31 02:16:00'),(359,495,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-08-31 02:16:00'),(360,495,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,2,NULL,'entregado','2026-08-31 02:16:00'),(361,495,'Jugo de Naranja',85.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-08-31 02:16:00'),(362,496,'Spaguetti a la Boloñesa',280.00,'Pastas',3,NULL,2,NULL,'entregado','2026-08-31 02:33:00'),(363,496,'Mix de 3 Brusquetas',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-08-31 02:33:00'),(364,497,'Rib Eye (450 grs.)',785.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-08-31 03:23:00'),(365,497,'Tabla Mixta',320.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-08-31 03:23:00'),(366,497,'Cappuccino',75.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-08-31 03:23:00'),(367,498,'Hamburguesa de la Casa',260.00,'Platos Fuertes',3,NULL,4,NULL,'entregado','2026-08-31 03:40:00'),(368,498,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-08-31 03:40:00'),(369,498,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,4,NULL,'entregado','2026-08-31 03:40:00'),(370,300,'Chilaquiles',180.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-08-31 14:41:00'),(371,300,'Café de Olla',65.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-08-31 14:41:00'),(372,300,'Cappuccino',75.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-08-31 14:41:00'),(373,301,'Chilaquiles',180.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-08-31 15:29:00'),(374,301,'Café de Olla',65.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-08-31 15:29:00'),(375,302,'Enmoladas',240.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-08-31 15:46:00'),(376,302,'Jugo Verde',95.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-08-31 15:46:00'),(377,303,'Chilaquiles',180.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-08-31 16:36:00'),(378,303,'Café de Olla',65.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-08-31 16:36:00'),(379,304,'Molletes',100.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-08-31 16:53:00'),(380,304,'Latte',80.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-08-31 16:53:00'),(381,305,'Enmoladas',240.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-08-31 17:43:00'),(382,305,'Jugo Verde',95.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-08-31 17:43:00'),(383,306,'Hamburguesa de la Casa',260.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-08-31 18:50:00'),(384,306,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-08-31 18:50:00'),(385,307,'Milano',260.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-08-31 19:12:00'),(386,307,'Margarita',190.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-08-31 19:12:00'),(387,308,'Milano',260.00,'Pizzas',4,NULL,2,NULL,'entregado','2026-08-31 20:19:00'),(388,308,'Margarita',190.00,'Pizzas',4,NULL,2,NULL,'entregado','2026-08-31 20:19:00'),(389,309,'Milano',260.00,'Pizzas',4,NULL,2,NULL,'entregado','2026-08-31 20:36:00'),(390,309,'Margarita',190.00,'Pizzas',4,NULL,2,NULL,'entregado','2026-08-31 20:36:00'),(391,310,'Frutos Rojos',210.00,'Ensaladas',3,NULL,2,NULL,'entregado','2026-08-31 21:26:00'),(392,310,'Crema del Día',180.00,'Sopas & Cremas',3,NULL,1,NULL,'entregado','2026-08-31 21:26:00'),(393,311,'Café Americano',65.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-09-01 00:47:00'),(394,311,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-09-01 00:47:00'),(395,312,'Burrata',260.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-09-01 01:54:00'),(396,312,'Milano',260.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-09-01 01:54:00'),(397,313,'Salmón al Horno',295.00,'Platos Fuertes',3,NULL,3,NULL,'entregado','2026-09-01 02:16:00'),(398,313,'Aros de Calamar',210.00,'Entradas',3,NULL,1,NULL,'entregado','2026-09-01 02:16:00'),(399,313,'Café Americano',65.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-09-01 02:16:00'),(400,314,'Filete de Res en su Jugo',320.00,'Platos Fuertes',3,NULL,3,NULL,'entregado','2026-09-01 03:23:00'),(401,314,'Crema del Día',180.00,'Sopas & Cremas',3,NULL,2,NULL,'entregado','2026-09-01 03:23:00'),(402,314,'Jugo de Naranja',85.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-09-01 03:23:00'),(403,315,'Enchiladas Suizas',220.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-09-01 14:41:00'),(404,315,'Café Americano',65.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-09-01 14:41:00'),(405,316,'Chilaquiles',180.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-09-01 15:29:00'),(406,316,'Café de Olla',65.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-09-01 15:29:00'),(407,316,'Jugo de Naranja',85.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-09-01 15:29:00'),(408,317,'Chilaquiles',180.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-09-01 15:46:00'),(409,317,'Café de Olla',65.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-09-01 15:46:00'),(410,318,'Toast de Salmón Ahumado',230.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-09-01 16:36:00'),(411,318,'Cappuccino',75.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-09-01 16:36:00'),(412,319,'Chilaquiles',180.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-09-01 16:53:00'),(413,319,'Café de Olla',65.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-09-01 16:53:00'),(414,319,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-09-01 16:53:00'),(415,320,'Enchiladas Suizas',220.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-09-01 16:25:00'),(416,320,'Café Americano',65.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-09-01 16:25:00'),(417,321,'Enmoladas',240.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-09-01 17:43:00'),(418,321,'Jugo Verde',95.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-09-01 17:43:00'),(419,322,'Enchiladas Suizas',220.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-09-01 17:15:00'),(420,322,'Café Americano',65.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-09-01 17:15:00'),(421,323,'Frutos Rojos',210.00,'Ensaladas',3,NULL,2,NULL,'entregado','2026-09-01 18:50:00'),(422,323,'Crema del Día',180.00,'Sopas & Cremas',3,NULL,1,NULL,'entregado','2026-09-01 18:50:00'),(423,324,'Spaguetti a la Boloñesa',280.00,'Pastas',3,NULL,3,NULL,'entregado','2026-09-01 19:12:00'),(424,324,'Mix de 3 Brusquetas',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-09-01 19:12:00'),(425,324,'Jugo de Naranja',85.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-09-01 19:12:00'),(426,325,'Hamburguesa de la Casa',260.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-09-01 19:29:00'),(427,325,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-09-01 19:29:00'),(428,325,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-09-01 19:29:00'),(429,326,'Hamburguesa de la Casa',260.00,'Platos Fuertes',3,NULL,3,NULL,'entregado','2026-09-01 20:19:00'),(430,326,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-09-01 20:19:00'),(431,327,'Milano',260.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-09-01 20:36:00'),(432,327,'Margarita',190.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-09-01 20:36:00'),(433,328,'Milano',260.00,'Pizzas',4,NULL,2,NULL,'entregado','2026-09-01 21:26:00'),(434,328,'Margarita',190.00,'Pizzas',4,NULL,2,NULL,'entregado','2026-09-01 21:26:00'),(435,328,'Café Americano',65.00,'Café & Bebidas',1,NULL,1,NULL,'cancelado','2026-09-01 21:26:00'),(436,329,'Latte',80.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-09-01 23:40:00'),(437,329,'Frutos Rojos',210.00,'Ensaladas',3,NULL,1,NULL,'entregado','2026-09-01 23:40:00'),(438,330,'Cappuccino',75.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-09-02 00:47:00'),(439,330,'Molletes',100.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-09-02 00:47:00'),(440,331,'Filete de Res en su Jugo',320.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-09-02 01:54:00'),(441,331,'Crema del Día',180.00,'Sopas & Cremas',3,NULL,1,NULL,'entregado','2026-09-02 01:54:00'),(442,332,'Spaguetti a la Boloñesa',280.00,'Pastas',3,NULL,3,NULL,'entregado','2026-09-02 02:16:00'),(443,332,'Mix de 3 Brusquetas',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-09-02 02:16:00'),(444,333,'Salmón al Horno',295.00,'Platos Fuertes',3,NULL,3,NULL,'entregado','2026-09-02 02:33:00'),(445,333,'Aros de Calamar',210.00,'Entradas',3,NULL,1,NULL,'entregado','2026-09-02 02:33:00'),(446,334,'Rib Eye (450 grs.)',785.00,'Platos Fuertes',3,NULL,1,NULL,'entregado','2026-09-02 03:23:00'),(447,334,'Tabla Mixta',320.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-09-02 03:23:00'),(448,334,'Jugo de Naranja',85.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-09-02 03:23:00'),(449,335,'Salmón al Horno',295.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-09-02 03:40:00'),(450,335,'Aros de Calamar',210.00,'Entradas',3,NULL,1,NULL,'entregado','2026-09-02 03:40:00'),(451,335,'Jugo de Naranja',85.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-09-02 03:40:00'),(452,336,'Enchiladas Suizas',220.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-09-02 14:41:00'),(453,336,'Café Americano',65.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-09-02 14:41:00'),(454,337,'Chilaquiles',180.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-09-02 15:29:00'),(455,337,'Café de Olla',65.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-09-02 15:29:00'),(456,337,'Café Americano',65.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-09-02 15:29:00'),(457,338,'Enchiladas Suizas',220.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-09-02 15:46:00'),(458,338,'Café Americano',65.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-09-02 15:46:00'),(459,339,'Enchiladas Suizas',220.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-09-02 15:18:00'),(460,339,'Café Americano',65.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-09-02 15:18:00'),(461,340,'Molletes',100.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-09-02 16:36:00'),(462,340,'Latte',80.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-09-02 16:36:00'),(463,341,'Chilaquiles',180.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-09-02 16:53:00'),(464,341,'Jugo de Naranja',85.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-09-02 16:53:00'),(465,342,'Enmoladas',240.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-09-02 16:25:00'),(466,342,'Jugo Verde',95.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-09-02 16:25:00'),(467,343,'Chilaquiles',180.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-09-02 17:43:00'),(468,343,'Jugo de Naranja',85.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-09-02 17:43:00'),(469,344,'Chilaquiles',180.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-09-02 17:15:00'),(470,344,'Café de Olla',65.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-09-02 17:15:00'),(471,345,'Milano',260.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-09-02 18:50:00'),(472,345,'Margarita',190.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-09-02 18:50:00'),(473,346,'Hamburguesa de la Casa',260.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-09-02 19:12:00'),(474,346,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-09-02 19:12:00'),(475,347,'Filete de Res en su Jugo',320.00,'Platos Fuertes',3,NULL,3,NULL,'entregado','2026-09-02 19:29:00'),(476,347,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,3,NULL,'entregado','2026-09-02 19:29:00'),(477,347,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-09-02 19:29:00'),(478,348,'Filete de Res en su Jugo',320.00,'Platos Fuertes',3,NULL,3,NULL,'entregado','2026-09-02 20:19:00'),(479,348,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,3,NULL,'entregado','2026-09-02 20:19:00'),(480,349,'Frutos Rojos',210.00,'Ensaladas',3,NULL,3,NULL,'entregado','2026-09-02 20:36:00'),(481,349,'Crema del Día',180.00,'Sopas & Cremas',3,NULL,2,NULL,'entregado','2026-09-02 20:36:00'),(482,350,'Frutos Rojos',210.00,'Ensaladas',3,NULL,2,NULL,'entregado','2026-09-02 20:53:00'),(483,350,'Crema del Día',180.00,'Sopas & Cremas',3,NULL,1,NULL,'entregado','2026-09-02 20:53:00'),(484,351,'Spaguetti a la Boloñesa',280.00,'Pastas',3,NULL,2,NULL,'entregado','2026-09-02 21:26:00'),(485,351,'Mix de 3 Brusquetas',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-09-02 21:26:00'),(486,352,'Cappuccino',75.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-09-02 23:40:00'),(487,352,'Molletes',100.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-09-02 23:40:00'),(488,353,'Cappuccino',75.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-09-03 00:47:00'),(489,353,'Molletes',100.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-09-03 00:47:00'),(490,354,'Filete de Res en su Jugo',320.00,'Platos Fuertes',3,NULL,3,NULL,'entregado','2026-09-03 01:54:00'),(491,354,'Crema del Día',180.00,'Sopas & Cremas',3,NULL,2,NULL,'entregado','2026-09-03 01:54:00'),(492,355,'Hamburguesa de la Casa',260.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-09-03 01:26:00'),(493,355,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-09-03 01:26:00'),(494,355,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,2,NULL,'entregado','2026-09-03 01:26:00'),(495,356,'Hamburguesa de la Casa',260.00,'Platos Fuertes',3,NULL,3,NULL,'entregado','2026-09-03 02:16:00'),(496,356,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-09-03 02:16:00'),(497,356,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,3,NULL,'entregado','2026-09-03 02:16:00'),(498,356,'Cappuccino',75.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-09-03 02:16:00'),(499,357,'Filete de Res en su Jugo',320.00,'Platos Fuertes',3,NULL,3,NULL,'entregado','2026-09-03 02:33:00'),(500,357,'Crema del Día',180.00,'Sopas & Cremas',3,NULL,2,NULL,'entregado','2026-09-03 02:33:00'),(501,358,'Hamburguesa de la Casa',260.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-09-03 03:23:00'),(502,358,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-09-03 03:23:00'),(503,358,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,2,NULL,'entregado','2026-09-03 03:23:00'),(504,359,'Spaguetti a la Boloñesa',280.00,'Pastas',3,NULL,2,NULL,'entregado','2026-09-03 03:40:00'),(505,359,'Mix de 3 Brusquetas',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-09-03 03:40:00'),(506,359,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-09-03 03:40:00'),(507,360,'Enchiladas Suizas',220.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-09-03 14:41:00'),(508,360,'Café Americano',65.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-09-03 14:41:00'),(509,361,'Enchiladas Suizas',220.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-09-03 15:29:00'),(510,361,'Café Americano',65.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-09-03 15:29:00'),(511,361,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-09-03 15:29:00'),(512,362,'Chilaquiles',180.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-09-03 15:46:00'),(513,362,'Jugo de Naranja',85.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-09-03 15:46:00'),(514,363,'Toast de Salmón Ahumado',230.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-09-03 15:18:00'),(515,363,'Cappuccino',75.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-09-03 15:18:00'),(516,364,'Enmoladas',240.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-09-03 16:36:00'),(517,364,'Jugo Verde',95.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-09-03 16:36:00'),(518,365,'Toast de Salmón Ahumado',230.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-09-03 16:53:00'),(519,365,'Cappuccino',75.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-09-03 16:53:00'),(520,366,'Enmoladas',240.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-09-03 16:25:00'),(521,366,'Jugo Verde',95.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-09-03 16:25:00'),(522,367,'Chilaquiles',180.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-09-03 17:43:00'),(523,367,'Jugo de Naranja',85.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-09-03 17:43:00'),(524,368,'Enmoladas',240.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-09-03 17:15:00'),(525,368,'Jugo Verde',95.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-09-03 17:15:00'),(526,369,'Milano',260.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-09-03 18:50:00'),(527,369,'Margarita',190.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-09-03 18:50:00'),(528,369,'Café Americano',65.00,'Café & Bebidas',1,NULL,1,NULL,'cancelado','2026-09-03 18:50:00'),(529,370,'Filete de Res en su Jugo',320.00,'Platos Fuertes',3,NULL,3,NULL,'entregado','2026-09-03 19:12:00'),(530,370,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,3,NULL,'entregado','2026-09-03 19:12:00'),(531,371,'Spaguetti a la Boloñesa',280.00,'Pastas',3,NULL,3,NULL,'entregado','2026-09-03 19:29:00'),(532,371,'Mix de 3 Brusquetas',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-09-03 19:29:00'),(533,372,'Spaguetti a la Boloñesa',280.00,'Pastas',3,NULL,2,NULL,'entregado','2026-09-03 19:46:00'),(534,372,'Mix de 3 Brusquetas',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-09-03 19:46:00'),(535,373,'Frutos Rojos',210.00,'Ensaladas',3,NULL,2,NULL,'entregado','2026-09-03 20:19:00'),(536,373,'Crema del Día',180.00,'Sopas & Cremas',3,NULL,1,NULL,'entregado','2026-09-03 20:19:00'),(537,373,'Jugo de Naranja',85.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-09-03 20:19:00'),(538,374,'Frutos Rojos',210.00,'Ensaladas',3,NULL,3,NULL,'entregado','2026-09-03 20:36:00'),(539,374,'Crema del Día',180.00,'Sopas & Cremas',3,NULL,2,NULL,'entregado','2026-09-03 20:36:00'),(540,375,'Hamburguesa de la Casa',260.00,'Platos Fuertes',3,NULL,3,NULL,'entregado','2026-09-03 20:53:00'),(541,375,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-09-03 20:53:00'),(542,376,'Spaguetti a la Boloñesa',280.00,'Pastas',3,NULL,3,NULL,'entregado','2026-09-03 21:26:00'),(543,376,'Mix de 3 Brusquetas',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-09-03 21:26:00'),(544,376,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-09-03 21:26:00'),(545,377,'Hamburguesa de la Casa',260.00,'Platos Fuertes',3,NULL,3,NULL,'entregado','2026-09-03 21:43:00'),(546,377,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-09-03 21:43:00'),(547,378,'Café Americano',65.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-09-03 22:33:00'),(548,378,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-09-03 22:33:00'),(549,378,'Cappuccino',75.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-09-03 22:33:00'),(550,379,'Café Americano',65.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-09-03 23:40:00'),(551,379,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-09-03 23:40:00'),(552,380,'Café Americano',65.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-09-04 00:47:00'),(553,380,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-09-04 00:47:00'),(554,381,'Burrata',260.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-09-04 01:54:00'),(555,381,'Milano',260.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-09-04 01:54:00'),(556,381,'Café Americano',65.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-09-04 01:54:00'),(557,382,'Spaguetti a la Boloñesa',280.00,'Pastas',3,NULL,2,NULL,'entregado','2026-09-04 01:26:00'),(558,382,'Mix de 3 Brusquetas',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-09-04 01:26:00'),(559,383,'Hamburguesa de la Casa',260.00,'Platos Fuertes',3,NULL,3,NULL,'entregado','2026-09-04 02:16:00'),(560,383,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-09-04 02:16:00'),(561,383,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,3,NULL,'entregado','2026-09-04 02:16:00'),(562,384,'Salmón al Horno',295.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-09-04 02:33:00'),(563,384,'Aros de Calamar',210.00,'Entradas',3,NULL,1,NULL,'entregado','2026-09-04 02:33:00'),(564,385,'Rib Eye (450 grs.)',785.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-09-04 02:50:00'),(565,385,'Tabla Mixta',320.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-09-04 02:50:00'),(566,385,'Jugo de Naranja',85.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-09-04 02:50:00'),(567,386,'Burrata',260.00,'Pizzas',4,NULL,2,NULL,'entregado','2026-09-04 03:23:00'),(568,386,'Milano',260.00,'Pizzas',4,NULL,2,NULL,'entregado','2026-09-04 03:23:00'),(569,387,'Hamburguesa de la Casa',260.00,'Platos Fuertes',3,NULL,3,NULL,'entregado','2026-09-04 03:40:00'),(570,387,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-09-04 03:40:00'),(571,387,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,3,NULL,'entregado','2026-09-04 03:40:00'),(572,388,'Rib Eye (450 grs.)',785.00,'Platos Fuertes',3,NULL,1,NULL,'entregado','2026-09-04 03:12:00'),(573,388,'Tabla Mixta',320.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-09-04 03:12:00'),(574,389,'Chilaquiles',180.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-09-04 14:41:00'),(575,389,'Jugo de Naranja',85.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-09-04 14:41:00'),(576,389,'Cappuccino',75.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-09-04 14:41:00'),(577,390,'Enmoladas',240.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-09-04 14:48:00'),(578,390,'Jugo Verde',95.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-09-04 14:48:00'),(579,391,'Enmoladas',240.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-09-04 15:29:00'),(580,391,'Jugo Verde',95.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-09-04 15:29:00'),(581,391,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-09-04 15:29:00'),(582,392,'Enchiladas Suizas',220.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-09-04 15:46:00'),(583,392,'Café Americano',65.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-09-04 15:46:00'),(584,393,'Chilaquiles',180.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-09-04 15:18:00'),(585,393,'Jugo de Naranja',85.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-09-04 15:18:00'),(586,393,'Cappuccino',75.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-09-04 15:18:00'),(587,394,'Chilaquiles',180.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-09-04 16:36:00'),(588,394,'Jugo de Naranja',85.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-09-04 16:36:00'),(589,395,'Chilaquiles',180.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-09-04 16:53:00'),(590,395,'Café de Olla',65.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-09-04 16:53:00'),(591,396,'Enmoladas',240.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-09-04 16:25:00'),(592,396,'Jugo Verde',95.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-09-04 16:25:00'),(593,397,'Molletes',100.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-09-04 16:42:00'),(594,397,'Latte',80.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-09-04 16:42:00'),(595,398,'Toast de Salmón Ahumado',230.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-09-04 17:43:00'),(596,398,'Cappuccino',75.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-09-04 17:43:00'),(597,398,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-09-04 17:43:00'),(598,399,'Chilaquiles',180.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-09-04 17:15:00'),(599,399,'Jugo de Naranja',85.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-09-04 17:15:00'),(600,400,'Frutos Rojos',210.00,'Ensaladas',3,NULL,2,NULL,'entregado','2026-09-04 18:50:00'),(601,400,'Crema del Día',180.00,'Sopas & Cremas',3,NULL,1,NULL,'entregado','2026-09-04 18:50:00'),(602,401,'Aros de Calamar',210.00,'Entradas',3,NULL,1,NULL,'entregado','2026-09-04 19:12:00'),(603,401,'Burrata',260.00,'Pizzas',4,NULL,2,NULL,'entregado','2026-09-04 19:12:00'),(604,402,'Hamburguesa de la Casa',260.00,'Platos Fuertes',3,NULL,3,NULL,'entregado','2026-09-04 19:29:00'),(605,402,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-09-04 19:29:00'),(606,403,'Filete de Res en su Jugo',320.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-09-04 19:46:00'),(607,403,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,2,NULL,'entregado','2026-09-04 19:46:00'),(608,404,'Frutos Rojos',210.00,'Ensaladas',3,NULL,2,NULL,'entregado','2026-09-04 20:19:00'),(609,404,'Crema del Día',180.00,'Sopas & Cremas',3,NULL,1,NULL,'entregado','2026-09-04 20:19:00'),(610,405,'Spaguetti a la Boloñesa',280.00,'Pastas',3,NULL,2,NULL,'entregado','2026-09-04 20:36:00'),(611,405,'Mix de 3 Brusquetas',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-09-04 20:36:00'),(612,406,'Hamburguesa de la Casa',260.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-09-04 20:53:00'),(613,406,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-09-04 20:53:00'),(614,407,'Aros de Calamar',210.00,'Entradas',3,NULL,1,NULL,'entregado','2026-09-04 20:25:00'),(615,407,'Burrata',260.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-09-04 20:25:00'),(616,407,'Café Americano',65.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-09-04 20:25:00'),(617,408,'Milano',260.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-09-04 21:26:00'),(618,408,'Margarita',190.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-09-04 21:26:00'),(619,409,'Frutos Rojos',210.00,'Ensaladas',3,NULL,3,NULL,'entregado','2026-09-04 21:43:00'),(620,409,'Crema del Día',180.00,'Sopas & Cremas',3,NULL,2,NULL,'entregado','2026-09-04 21:43:00'),(621,409,'Café Americano',65.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-09-04 21:43:00'),(622,410,'Café Americano',65.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-09-04 22:33:00'),(623,410,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-09-04 22:33:00'),(624,410,'Café Americano',65.00,'Café & Bebidas',1,NULL,1,NULL,'cancelado','2026-09-04 22:33:00'),(625,411,'Cappuccino',75.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-09-04 23:40:00'),(626,411,'Molletes',100.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-09-04 23:40:00'),(627,411,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-09-04 23:40:00'),(628,412,'Café Americano',65.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-09-05 00:47:00'),(629,412,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-09-05 00:47:00'),(630,412,'Café Americano',65.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-09-05 00:47:00'),(631,413,'Café Americano',65.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-09-05 00:19:00'),(632,413,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-09-05 00:19:00'),(633,414,'Burrata',260.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-09-05 01:54:00'),(634,414,'Milano',260.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-09-05 01:54:00'),(635,415,'Salmón al Horno',295.00,'Platos Fuertes',3,NULL,3,NULL,'entregado','2026-09-05 01:26:00'),(636,415,'Aros de Calamar',210.00,'Entradas',3,NULL,1,NULL,'entregado','2026-09-05 01:26:00'),(637,416,'Rib Eye (450 grs.)',785.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-09-05 02:16:00'),(638,416,'Tabla Mixta',320.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-09-05 02:16:00'),(639,417,'Spaguetti a la Boloñesa',280.00,'Pastas',3,NULL,4,NULL,'entregado','2026-09-05 02:33:00'),(640,417,'Mix de 3 Brusquetas',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-09-05 02:33:00'),(641,418,'Rib Eye (450 grs.)',785.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-09-05 02:50:00'),(642,418,'Tabla Mixta',320.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-09-05 02:50:00'),(643,419,'Spaguetti a la Boloñesa',280.00,'Pastas',3,NULL,4,NULL,'entregado','2026-09-05 02:22:00'),(644,419,'Mix de 3 Brusquetas',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-09-05 02:22:00'),(645,419,'Café Americano',65.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-09-05 02:22:00'),(646,420,'Hamburguesa de la Casa',260.00,'Platos Fuertes',3,NULL,4,NULL,'entregado','2026-09-05 03:23:00'),(647,420,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-09-05 03:23:00'),(648,420,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,4,NULL,'entregado','2026-09-05 03:23:00'),(649,420,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,1,NULL,'entregado','2026-09-05 03:23:00'),(650,421,'Hamburguesa de la Casa',260.00,'Platos Fuertes',3,NULL,3,NULL,'entregado','2026-09-05 03:40:00'),(651,421,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-09-05 03:40:00'),(652,421,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,3,NULL,'entregado','2026-09-05 03:40:00'),(653,422,'Hamburguesa de la Casa',260.00,'Platos Fuertes',3,NULL,4,NULL,'entregado','2026-09-05 03:12:00'),(654,422,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-09-05 03:12:00'),(655,422,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,4,NULL,'entregado','2026-09-05 03:12:00'),(656,423,'Spaguetti a la Boloñesa',280.00,'Pastas',3,NULL,3,NULL,'entregado','2026-09-05 03:29:00'),(657,423,'Mix de 3 Brusquetas',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-09-05 03:29:00'),(661,121,'Aceitunas Temperadas con Aceite de Chile',160.00,'Para Picar',3,NULL,1,NULL,'cancelado','2026-09-08 03:56:53'),(662,121,'Rigatoni al Limón con Camarones y Parmesano',280.00,'Pastas',3,NULL,1,NULL,'entregado','2026-09-08 03:57:17'),(663,121,'Tostadas de Atún',195.00,'Entradas',3,NULL,1,NULL,'entregado','2026-09-08 03:58:19'),(664,121,'Tabla Mixta',320.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-09-08 03:58:23'),(665,121,'Tostadas de Atún',195.00,'Entradas',3,NULL,1,NULL,'entregado','2026-09-14 19:49:46'),(666,121,'Spaguetti a la Boloñesa',280.00,'Pastas',3,NULL,1,NULL,'entregado','2026-09-14 19:49:46'),(668,506,'Omelette Fitness',190.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-09-23 04:45:22'),(669,506,'Tabla Mixta',320.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-09-23 04:49:05'),(670,509,'Sopa Especial de Fin de Semana',180.00,'Sopas & Cremas',3,NULL,1,NULL,'entregado','2026-10-02 22:08:40'),(671,509,'Aceitunas Temperadas con Aceite de Chile',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-10-02 22:08:40'),(672,509,'Margarita',190.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-10-02 22:08:40'),(673,509,'Piñada',140.00,'Bebidas sin Alcohol',1,NULL,1,NULL,'entregado','2026-10-02 22:08:41'),(674,509,'Camarones al Ajillo',210.00,'Entradas',3,NULL,1,NULL,'entregado','2026-10-02 22:08:41'),(675,509,'Camarones al Ajillo',210.00,'Entradas',3,NULL,1,NULL,'entregado','2026-10-02 23:11:26'),(676,509,'Cecina y Huevo con Chorizo',220.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-10-02 23:12:20'),(694,509,'Torreta de Salmón',220.00,'Entradas',3,NULL,1,NULL,'entregado','2026-10-03 02:17:20');
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
) ENGINE=InnoDB AUTO_INCREMENT=311 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ticket_mesas`
--

LOCK TABLES `ticket_mesas` WRITE;
/*!40000 ALTER TABLE `ticket_mesas` DISABLE KEYS */;
INSERT INTO `ticket_mesas` VALUES (1,1,1,1,'2026-09-07 20:28:19'),(2,2,3,1,'2026-09-07 20:28:19'),(3,3,6,1,'2026-09-07 20:28:19'),(4,5,2,1,'2026-09-07 20:28:19'),(5,7,5,1,'2026-09-07 20:28:19'),(6,8,7,1,'2026-09-07 20:28:19'),(7,9,1,1,'2026-09-07 20:28:19'),(8,10,2,1,'2026-09-07 20:28:19'),(9,11,3,1,'2026-09-07 20:28:19'),(10,12,4,1,'2026-09-07 20:28:19'),(11,13,5,1,'2026-09-07 20:28:19'),(12,14,6,1,'2026-09-07 20:28:19'),(13,15,7,1,'2026-09-07 20:28:19'),(14,4,8,1,'2026-09-07 20:28:19'),(15,16,9,1,'2026-09-07 20:28:19'),(16,17,10,1,'2026-09-07 20:28:19'),(17,6,11,1,'2026-09-07 20:28:19'),(18,18,12,1,'2026-09-07 20:28:19'),(19,19,13,1,'2026-09-07 20:28:19'),(20,20,14,1,'2026-09-07 20:28:19'),(21,21,15,1,'2026-09-07 20:28:19'),(22,22,16,1,'2026-09-07 20:28:19'),(23,101,5,1,'2026-09-07 20:28:19'),(24,102,5,1,'2026-09-07 20:28:19'),(25,103,1,1,'2026-09-07 20:28:19'),(26,104,3,1,'2026-09-07 20:28:19'),(27,105,3,1,'2026-09-07 20:28:19'),(28,106,4,1,'2026-09-07 20:28:19'),(29,107,6,1,'2026-09-07 20:28:19'),(30,108,6,1,'2026-09-07 20:28:19'),(31,109,7,1,'2026-09-07 20:28:19'),(32,110,2,1,'2026-09-07 20:28:19'),(33,111,2,1,'2026-09-07 20:28:19'),(34,112,4,1,'2026-09-07 20:28:19'),(35,113,8,1,'2026-09-07 20:28:19'),(36,114,8,1,'2026-09-07 20:28:19'),(37,115,9,1,'2026-09-07 20:28:19'),(38,116,11,1,'2026-09-07 20:28:19'),(39,117,10,1,'2026-09-07 20:28:19'),(40,118,11,1,'2026-09-07 20:28:19'),(43,120,6,1,'2026-09-07 20:28:20'),(44,121,10,1,'2026-09-07 20:28:20'),(47,200,1,1,'2026-09-07 20:28:20'),(48,201,3,1,'2026-09-07 20:28:20'),(49,202,2,1,'2026-09-07 20:28:20'),(50,203,4,1,'2026-09-07 20:28:20'),(51,204,5,1,'2026-09-07 20:28:20'),(52,205,6,1,'2026-09-07 20:28:20'),(53,206,7,1,'2026-09-07 20:28:20'),(54,207,8,1,'2026-09-07 20:28:20'),(55,208,9,1,'2026-09-07 20:28:20'),(56,209,10,1,'2026-09-07 20:28:20'),(57,210,1,1,'2026-09-07 20:28:20'),(58,211,2,1,'2026-09-07 20:28:20'),(59,212,3,1,'2026-09-07 20:28:20'),(60,213,4,1,'2026-09-07 20:28:20'),(61,214,5,1,'2026-09-07 20:28:20'),(62,215,6,1,'2026-09-07 20:28:20'),(63,216,7,1,'2026-09-07 20:28:20'),(64,217,3,1,'2026-09-07 20:28:20'),(65,218,6,1,'2026-09-07 20:28:20'),(66,219,2,1,'2026-09-07 20:28:20'),(67,220,5,1,'2026-09-07 20:28:20'),(68,221,1,1,'2026-09-07 20:28:20'),(69,222,4,1,'2026-09-07 20:28:20'),(70,223,2,1,'2026-09-07 20:28:20'),(71,224,6,1,'2026-09-07 20:28:20'),(72,225,1,1,'2026-09-07 20:28:20'),(73,226,5,1,'2026-09-07 20:28:20'),(74,227,3,1,'2026-09-07 20:28:20'),(75,228,2,1,'2026-09-07 20:28:20'),(76,229,4,1,'2026-09-07 20:28:20'),(77,230,6,1,'2026-09-07 20:28:20'),(78,231,3,1,'2026-09-07 20:28:20'),(79,232,6,1,'2026-09-07 20:28:20'),(80,233,2,1,'2026-09-07 20:28:20'),(81,234,5,1,'2026-09-07 20:28:20'),(82,235,1,1,'2026-09-07 20:28:20'),(83,236,4,1,'2026-09-07 20:28:20'),(84,237,6,1,'2026-09-07 20:28:20'),(85,238,2,1,'2026-09-07 20:28:20'),(86,239,3,1,'2026-09-07 20:28:20'),(87,240,4,1,'2026-09-07 20:28:20'),(88,241,5,1,'2026-09-07 20:28:20'),(89,242,1,1,'2026-09-07 20:28:20'),(90,424,4,1,'2026-09-07 20:28:20'),(91,425,5,1,'2026-09-07 20:28:20'),(92,426,7,1,'2026-09-07 20:28:20'),(93,427,8,1,'2026-09-07 20:28:20'),(94,428,9,1,'2026-09-07 20:28:20'),(95,429,10,1,'2026-09-07 20:28:20'),(96,430,1,1,'2026-09-07 20:28:20'),(97,431,2,1,'2026-09-07 20:28:20'),(98,432,3,1,'2026-09-07 20:28:20'),(99,433,4,1,'2026-09-07 20:28:20'),(100,434,5,1,'2026-09-07 20:28:20'),(101,435,7,1,'2026-09-07 20:28:20'),(102,436,8,1,'2026-09-07 20:28:20'),(103,437,9,1,'2026-09-07 20:28:20'),(104,438,11,1,'2026-09-07 20:28:20'),(105,439,1,1,'2026-09-07 20:28:20'),(106,440,3,1,'2026-09-07 20:28:20'),(107,441,4,1,'2026-09-07 20:28:20'),(108,442,5,1,'2026-09-07 20:28:20'),(109,443,6,1,'2026-09-07 20:28:20'),(110,444,8,1,'2026-09-07 20:28:20'),(111,445,9,1,'2026-09-07 20:28:20'),(112,446,10,1,'2026-09-07 20:28:20'),(113,447,11,1,'2026-09-07 20:28:20'),(114,448,2,1,'2026-09-07 20:28:20'),(115,449,3,1,'2026-09-07 20:28:20'),(116,450,5,1,'2026-09-07 20:28:20'),(117,451,7,1,'2026-09-07 20:28:20'),(118,452,9,1,'2026-09-07 20:28:20'),(119,453,10,1,'2026-09-07 20:28:20'),(120,454,1,1,'2026-09-07 20:28:20'),(121,455,2,1,'2026-09-07 20:28:20'),(122,456,3,1,'2026-09-07 20:28:20'),(123,457,5,1,'2026-09-07 20:28:20'),(124,458,6,1,'2026-09-07 20:28:20'),(125,459,7,1,'2026-09-07 20:28:20'),(126,460,8,1,'2026-09-07 20:28:20'),(127,461,10,1,'2026-09-07 20:28:20'),(128,462,11,1,'2026-09-07 20:28:20'),(129,463,1,1,'2026-09-07 20:28:20'),(130,464,2,1,'2026-09-07 20:28:20'),(131,465,3,1,'2026-09-07 20:28:20'),(132,466,2,1,'2026-09-07 20:28:20'),(133,467,3,1,'2026-09-07 20:28:20'),(134,468,5,1,'2026-09-07 20:28:20'),(135,469,6,1,'2026-09-07 20:28:20'),(136,470,7,1,'2026-09-07 20:28:20'),(137,471,8,1,'2026-09-07 20:28:20'),(138,472,10,1,'2026-09-07 20:28:20'),(139,473,11,1,'2026-09-07 20:28:20'),(140,474,1,1,'2026-09-07 20:28:20'),(141,475,2,1,'2026-09-07 20:28:20'),(142,476,3,1,'2026-09-07 20:28:20'),(143,477,5,1,'2026-09-07 20:28:20'),(144,478,6,1,'2026-09-07 20:28:20'),(145,479,7,1,'2026-09-07 20:28:20'),(146,480,9,1,'2026-09-07 20:28:20'),(147,481,10,1,'2026-09-07 20:28:20'),(148,482,1,1,'2026-09-07 20:28:20'),(149,483,2,1,'2026-09-07 20:28:20'),(150,484,3,1,'2026-09-07 20:28:20'),(151,485,5,1,'2026-09-07 20:28:20'),(152,486,6,1,'2026-09-07 20:28:20'),(153,487,7,1,'2026-09-07 20:28:20'),(154,488,9,1,'2026-09-07 20:28:20'),(155,489,10,1,'2026-09-07 20:28:20'),(156,490,1,1,'2026-09-07 20:28:20'),(157,491,3,1,'2026-09-07 20:28:20'),(158,492,5,1,'2026-09-07 20:28:20'),(159,493,7,1,'2026-09-07 20:28:20'),(160,494,8,1,'2026-09-07 20:28:20'),(161,495,10,1,'2026-09-07 20:28:20'),(162,496,11,1,'2026-09-07 20:28:20'),(163,497,2,1,'2026-09-07 20:28:20'),(164,498,3,1,'2026-09-07 20:28:20'),(165,300,1,1,'2026-09-07 20:28:21'),(166,301,3,1,'2026-09-07 20:28:21'),(167,302,4,1,'2026-09-07 20:28:21'),(168,303,6,1,'2026-09-07 20:28:21'),(169,304,7,1,'2026-09-07 20:28:21'),(170,305,9,1,'2026-09-07 20:28:21'),(171,306,11,1,'2026-09-07 20:28:21'),(172,307,2,1,'2026-09-07 20:28:21'),(173,308,4,1,'2026-09-07 20:28:21'),(174,309,5,1,'2026-09-07 20:28:21'),(175,310,7,1,'2026-09-07 20:28:21'),(176,311,11,1,'2026-09-07 20:28:21'),(177,312,2,1,'2026-09-07 20:28:21'),(178,313,4,1,'2026-09-07 20:28:21'),(179,314,6,1,'2026-09-07 20:28:21'),(180,315,5,1,'2026-09-07 20:28:21'),(181,316,7,1,'2026-09-07 20:28:21'),(182,317,8,1,'2026-09-07 20:28:21'),(183,318,10,1,'2026-09-07 20:28:21'),(184,319,11,1,'2026-09-07 20:28:21'),(185,320,1,1,'2026-09-07 20:28:21'),(186,321,3,1,'2026-09-07 20:28:21'),(187,322,4,1,'2026-09-07 20:28:21'),(188,323,6,1,'2026-09-07 20:28:21'),(189,324,8,1,'2026-09-07 20:28:21'),(190,325,9,1,'2026-09-07 20:28:21'),(191,326,11,1,'2026-09-07 20:28:21'),(192,327,1,1,'2026-09-07 20:28:21'),(193,328,3,1,'2026-09-07 20:28:21'),(194,329,6,1,'2026-09-07 20:28:21'),(195,330,8,1,'2026-09-07 20:28:21'),(196,331,10,1,'2026-09-07 20:28:21'),(197,332,1,1,'2026-09-07 20:28:21'),(198,333,2,1,'2026-09-07 20:28:21'),(199,334,4,1,'2026-09-07 20:28:21'),(200,335,5,1,'2026-09-07 20:28:21'),(201,336,4,1,'2026-09-07 20:28:21'),(202,337,6,1,'2026-09-07 20:28:21'),(203,338,7,1,'2026-09-07 20:28:21'),(204,339,8,1,'2026-09-07 20:28:21'),(205,340,10,1,'2026-09-07 20:28:21'),(206,341,11,1,'2026-09-07 20:28:21'),(207,342,1,1,'2026-09-07 20:28:21'),(208,343,3,1,'2026-09-07 20:28:21'),(209,344,4,1,'2026-09-07 20:28:21'),(210,345,6,1,'2026-09-07 20:28:21'),(211,346,8,1,'2026-09-07 20:28:21'),(212,347,9,1,'2026-09-07 20:28:21'),(213,348,11,1,'2026-09-07 20:28:21'),(214,349,1,1,'2026-09-07 20:28:21'),(215,350,2,1,'2026-09-07 20:28:21'),(216,351,4,1,'2026-09-07 20:28:21'),(217,352,7,1,'2026-09-07 20:28:21'),(218,353,9,1,'2026-09-07 20:28:21'),(219,354,11,1,'2026-09-07 20:28:21'),(220,355,1,1,'2026-09-07 20:28:21'),(221,356,3,1,'2026-09-07 20:28:21'),(222,357,4,1,'2026-09-07 20:28:21'),(223,358,6,1,'2026-09-07 20:28:21'),(224,359,7,1,'2026-09-07 20:28:21'),(225,360,6,1,'2026-09-07 20:28:21'),(226,361,8,1,'2026-09-07 20:28:21'),(227,362,9,1,'2026-09-07 20:28:21'),(228,363,10,1,'2026-09-07 20:28:21'),(229,364,1,1,'2026-09-07 20:28:21'),(230,365,2,1,'2026-09-07 20:28:21'),(231,366,3,1,'2026-09-07 20:28:21'),(232,367,5,1,'2026-09-07 20:28:21'),(233,368,6,1,'2026-09-07 20:28:21'),(234,369,8,1,'2026-09-07 20:28:21'),(235,370,10,1,'2026-09-07 20:28:21'),(236,371,11,1,'2026-09-07 20:28:21'),(237,372,1,1,'2026-09-07 20:28:21'),(238,373,3,1,'2026-09-07 20:28:21'),(239,374,4,1,'2026-09-07 20:28:21'),(240,375,5,1,'2026-09-07 20:28:21'),(241,376,7,1,'2026-09-07 20:28:21'),(242,377,8,1,'2026-09-07 20:28:21'),(243,378,10,1,'2026-09-07 20:28:21'),(244,379,1,1,'2026-09-07 20:28:21'),(245,380,3,1,'2026-09-07 20:28:21'),(246,381,5,1,'2026-09-07 20:28:21'),(247,382,6,1,'2026-09-07 20:28:21'),(248,383,8,1,'2026-09-07 20:28:21'),(249,384,9,1,'2026-09-07 20:28:21'),(250,385,10,1,'2026-09-07 20:28:21'),(251,386,1,1,'2026-09-07 20:28:21'),(252,387,2,1,'2026-09-07 20:28:21'),(253,388,3,1,'2026-09-07 20:28:21'),(254,389,2,1,'2026-09-07 20:28:21'),(255,390,3,1,'2026-09-07 20:28:21'),(256,391,5,1,'2026-09-07 20:28:21'),(257,392,6,1,'2026-09-07 20:28:21'),(258,393,7,1,'2026-09-07 20:28:21'),(259,394,9,1,'2026-09-07 20:28:21'),(260,395,10,1,'2026-09-07 20:28:21'),(261,396,11,1,'2026-09-07 20:28:21'),(262,397,1,1,'2026-09-07 20:28:21'),(263,398,3,1,'2026-09-07 20:28:21'),(264,399,4,1,'2026-09-07 20:28:21'),(265,400,6,1,'2026-09-07 20:28:21'),(266,401,8,1,'2026-09-07 20:28:21'),(267,402,9,1,'2026-09-07 20:28:21'),(268,403,10,1,'2026-09-07 20:28:21'),(269,404,1,1,'2026-09-07 20:28:21'),(270,405,2,1,'2026-09-07 20:28:21'),(271,406,3,1,'2026-09-07 20:28:21'),(272,407,4,1,'2026-09-07 20:28:21'),(273,408,6,1,'2026-09-07 20:28:21'),(274,409,7,1,'2026-09-07 20:28:21'),(275,410,9,1,'2026-09-07 20:28:21'),(276,411,11,1,'2026-09-07 20:28:21'),(277,412,2,1,'2026-09-07 20:28:21'),(278,413,3,1,'2026-09-07 20:28:21'),(279,414,5,1,'2026-09-07 20:28:21'),(280,415,6,1,'2026-09-07 20:28:21'),(281,416,8,1,'2026-09-07 20:28:21'),(282,417,9,1,'2026-09-07 20:28:21'),(283,418,10,1,'2026-09-07 20:28:21'),(284,419,11,1,'2026-09-07 20:28:21'),(285,420,2,1,'2026-09-07 20:28:21'),(286,421,3,1,'2026-09-07 20:28:21'),(287,422,4,1,'2026-09-07 20:28:21'),(288,423,5,1,'2026-09-07 20:28:21'),(296,506,14,1,'2026-09-23 04:11:13'),(299,509,10,1,'2026-09-30 17:01:01'),(300,510,9,1,'2026-10-03 01:06:58'),(310,519,14,1,'2026-10-05 03:07:32');
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
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ticket_pagos`
--

LOCK TABLES `ticket_pagos` WRITE;
/*!40000 ALTER TABLE `ticket_pagos` DISABLE KEYS */;
INSERT INTO `ticket_pagos` VALUES (1,425,1,'tarjeta',565.50,'2026-09-07 20:28:20'),(2,425,2,'efectivo',565.50,'2026-09-07 20:28:20'),(3,442,1,'tarjeta',454.50,'2026-09-07 20:28:20'),(4,442,2,'efectivo',454.50,'2026-09-07 20:28:20'),(5,459,1,'tarjeta',997.50,'2026-09-07 20:28:20'),(6,459,2,'efectivo',997.50,'2026-09-07 20:28:20'),(7,476,1,'tarjeta',549.00,'2026-09-07 20:28:21'),(8,476,2,'efectivo',549.00,'2026-09-07 20:28:21'),(9,493,1,'tarjeta',913.00,'2026-09-07 20:28:21'),(10,493,2,'efectivo',913.00,'2026-09-07 20:28:21'),(11,306,1,'tarjeta',340.00,'2026-09-07 20:28:21'),(12,306,2,'efectivo',340.00,'2026-09-07 20:28:21'),(13,323,1,'tarjeta',336.00,'2026-09-07 20:28:21'),(14,323,2,'efectivo',336.00,'2026-09-07 20:28:21'),(15,340,1,'tarjeta',103.50,'2026-09-07 20:28:21'),(16,340,2,'efectivo',103.50,'2026-09-07 20:28:21'),(17,357,1,'tarjeta',759.00,'2026-09-07 20:28:21'),(18,357,2,'efectivo',759.00,'2026-09-07 20:28:21'),(19,374,1,'tarjeta',554.50,'2026-09-07 20:28:21'),(20,374,2,'efectivo',554.50,'2026-09-07 20:28:21'),(21,391,1,'tarjeta',357.50,'2026-09-07 20:28:21'),(22,391,2,'efectivo',357.50,'2026-09-07 20:28:21'),(23,408,1,'tarjeta',225.00,'2026-09-07 20:28:21'),(24,408,2,'efectivo',225.00,'2026-09-07 20:28:21');
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
) ENGINE=InnoDB AUTO_INCREMENT=520 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tickets`
--

LOCK TABLES `tickets` WRITE;
/*!40000 ALTER TABLE `tickets` DISABLE KEYS */;
INSERT INTO `tickets` VALUES (1,2,'Camila Estrada','2026-09-05 14:05:00','2026-09-05 15:35:00','2026-09-05 15:35:00','cerrado','tarjeta',64.60,NULL,2),(2,4,'Javier Montiel','2026-09-04 14:40:00','2026-09-04 16:10:00','2026-09-04 16:10:00','cerrado','efectivo',91.20,NULL,3),(3,6,'Familia Guerrero','2026-09-03 20:10:00','2026-09-03 21:40:00','2026-09-03 21:40:00','cerrado','tarjeta',308.55,NULL,2),(4,2,'Sofía Pedraza','2026-09-02 20:16:19','2026-09-02 21:46:19','2026-09-02 21:46:19','cerrado',NULL,0.00,NULL,NULL),(5,4,'Nicolás Andrade','2026-09-01 21:05:00','2026-09-01 22:35:00','2026-09-01 22:35:00','cerrado','tarjeta',102.00,NULL,3),(6,4,'Fernanda & Roque','2026-08-31 20:01:19','2026-08-31 21:31:19','2026-08-31 21:31:19','cerrado',NULL,0.00,NULL,NULL),(7,3,'Mesa 5','2026-06-18 16:00:00',NULL,NULL,'cancelado','efectivo',0.00,NULL,NULL),(8,4,'Grupo Torres','2026-08-29 15:15:00','2026-08-29 16:45:00','2026-08-29 16:45:00','cerrado','efectivo',84.80,NULL,5),(9,2,'Ana Villalobos','2026-08-28 20:25:19','2026-08-28 21:55:19','2026-08-28 21:55:19','cerrado',NULL,0.00,NULL,NULL),(10,4,'Renata Ibáñez','2026-08-27 19:47:19','2026-08-27 21:17:19','2026-08-27 21:17:19','cerrado',NULL,0.00,NULL,NULL),(11,3,'Javier Montiel','2026-08-26 20:10:19','2026-08-26 21:40:19','2026-08-26 21:40:19','cerrado',NULL,0.00,NULL,NULL),(12,2,'Diego Lozano','2026-08-25 20:21:19','2026-08-25 21:51:19','2026-08-25 21:51:19','cerrado',NULL,0.00,NULL,NULL),(13,4,'Familia Cuevas','2026-08-24 19:55:19','2026-08-24 21:25:19','2026-08-24 21:25:19','cerrado',NULL,0.00,NULL,NULL),(14,4,'Familia Guerrero','2026-08-23 20:06:19','2026-08-23 21:36:19','2026-08-23 21:36:19','cerrado',NULL,0.00,NULL,NULL),(15,3,'Grupo Salinas','2026-08-22 19:43:19','2026-08-22 21:13:19','2026-08-22 21:13:19','cerrado',NULL,0.00,NULL,NULL),(16,2,'Mauricio Trejo','2026-08-21 19:59:19','2026-08-21 21:29:19','2026-08-21 21:29:19','cerrado',NULL,0.00,NULL,NULL),(17,4,'Lucía Bermúdez','2026-08-20 20:23:19','2026-08-20 21:53:19','2026-08-20 21:53:19','cerrado',NULL,0.00,NULL,NULL),(18,5,'Grupo Peralta','2026-08-19 20:13:19','2026-08-19 21:43:19','2026-08-19 21:43:19','cerrado',NULL,0.00,NULL,NULL),(19,1,'Caja','2026-08-18 20:27:19','2026-08-18 21:57:19','2026-08-18 21:57:19','cerrado',NULL,0.00,NULL,NULL),(20,1,'Llevar','2026-08-17 20:19:19','2026-08-17 21:49:19','2026-08-17 21:49:19','cerrado',NULL,0.00,NULL,NULL),(21,4,'Tomás Iriarte','2026-08-16 19:52:19','2026-08-16 21:22:19','2026-08-16 21:22:19','cerrado',NULL,0.00,NULL,NULL),(22,3,'Paulina Cortés','2026-08-15 20:04:19','2026-08-15 21:34:19','2026-08-15 21:34:19','cerrado',NULL,0.00,NULL,NULL),(101,2,'Camila Estrada','2026-07-25 09:05:00','2026-07-25 10:35:00','2026-07-25 10:35:00','cerrado','tarjeta',103.70,NULL,2),(102,2,'Camila Estrada','2026-07-24 09:35:00','2026-07-24 11:05:00','2026-07-24 11:05:00','cerrado','tarjeta',78.20,NULL,2),(103,2,'Camila Estrada','2026-07-23 09:05:00','2026-07-23 10:35:00','2026-07-23 10:35:00','cerrado','tarjeta',51.85,NULL,2),(104,4,'Javier Montiel','2026-07-22 14:05:00','2026-07-22 15:35:00','2026-07-22 15:35:00','cerrado','efectivo',173.40,NULL,2),(105,3,'Javier Montiel','2026-07-21 14:05:00','2026-07-21 15:35:00','2026-07-21 15:35:00','cerrado','tarjeta',74.80,NULL,2),(106,4,'Javier Montiel','2026-07-20 13:35:00','2026-07-20 15:05:00','2026-07-20 15:05:00','cerrado','efectivo',125.80,NULL,2),(107,6,'Familia Guerrero','2026-07-19 13:05:00','2026-07-19 14:35:00','2026-07-19 14:35:00','cerrado','tarjeta',106.80,NULL,3),(108,5,'Familia Guerrero','2026-07-18 13:35:00','2026-07-18 15:05:00','2026-07-18 15:05:00','cerrado','tarjeta',64.80,NULL,3),(109,6,'Familia Guerrero','2026-07-17 13:05:00','2026-07-17 14:35:00','2026-07-17 14:35:00','cerrado','tarjeta',99.60,NULL,3),(110,4,'Nicolas Andrade','2026-07-16 19:05:00','2026-07-16 20:35:00','2026-07-16 20:35:00','cerrado','tarjeta',207.60,NULL,3),(111,2,'Nicolas Andrade','2026-07-15 19:35:00','2026-07-15 21:05:00','2026-07-15 21:05:00','cerrado','tarjeta',113.40,NULL,3),(112,4,'Nicolas Andrade','2026-07-14 19:05:00','2026-07-14 20:35:00','2026-07-14 20:35:00','cerrado','tarjeta',226.80,NULL,3),(113,2,'Sofia Pedraza','2026-07-13 15:05:00','2026-07-13 16:35:00','2026-07-13 16:35:00','cerrado','efectivo',31.20,NULL,5),(114,2,'Sofia Pedraza','2026-07-12 15:35:00','2026-07-12 17:05:00','2026-07-12 17:05:00','cerrado','tarjeta',31.20,NULL,5),(115,3,'Sofia Pedraza','2026-07-11 15:05:00','2026-07-11 16:35:00','2026-07-11 16:35:00','cerrado','tarjeta',48.80,NULL,5),(116,2,'Fernanda & Roque','2026-09-06 20:05:00','2026-09-06 21:35:00','2026-09-06 21:35:00','cerrado','tarjeta',46.40,NULL,5),(117,4,'Fernanda & Roque','2026-09-05 20:35:00','2026-09-05 22:05:00','2026-09-05 22:05:00','cerrado','tarjeta',72.00,NULL,5),(118,2,'Fernanda & Roque','2026-09-04 20:05:00','2026-09-04 21:35:00','2026-09-04 21:35:00','cerrado','tarjeta',42.40,NULL,5),(120,2,'POS Completada','2026-09-07 18:00:00','2026-09-07 19:30:00',NULL,'cerrado','efectivo',0.00,28,NULL),(121,2,'Walk-in Una Mesa','2026-09-10 20:10:00','2026-09-30 09:18:39','2026-09-30 09:18:39','cerrado','tarjeta',190.50,NULL,NULL),(200,2,'Ana Ruiz','2026-08-11 09:15:00','2026-08-11 11:03:00','2026-08-11 11:03:00','cerrado','tarjeta',59.00,NULL,3),(201,3,'León Vega','2026-08-13 09:40:00','2026-08-13 10:34:00','2026-08-13 10:34:00','cerrado','efectivo',52.00,NULL,6),(202,2,'Bruno Salas','2026-08-16 10:05:00','2026-08-16 11:06:00','2026-08-16 11:06:00','cerrado','tarjeta',32.00,NULL,3),(203,4,'Familia Nava','2026-08-20 08:50:00','2026-08-20 09:58:00','2026-08-20 09:58:00','cerrado','tarjeta',92.00,NULL,6),(204,2,'Ivy Cano','2026-08-25 09:30:00','2026-08-25 10:45:00','2026-08-25 10:45:00','cerrado','efectivo',0.00,NULL,3),(205,2,'Sol Marín','2026-09-01 10:10:00','2026-09-01 11:32:00','2026-09-01 11:32:00','cerrado','tarjeta',51.00,NULL,6),(206,2,'Rita Peña','2026-08-12 09:00:00','2026-08-12 10:29:00','2026-08-12 10:29:00','cerrado','tarjeta',87.00,NULL,3),(207,3,'Tono Gil','2026-08-18 09:20:00','2026-08-18 10:56:00','2026-08-18 10:56:00','cerrado','efectivo',40.00,NULL,6),(208,2,'Lía Ordaz','2026-08-26 10:00:00','2026-08-26 11:43:00','2026-08-26 11:43:00','cerrado','tarjeta',69.00,NULL,3),(209,2,'Gael Ruan','2026-09-02 09:45:00','2026-09-02 11:35:00','2026-09-02 11:35:00','cerrado','tarjeta',47.00,NULL,6),(210,2,'Vera Luna','2026-08-14 10:30:00','2026-08-14 11:26:00','2026-08-14 11:26:00','cerrado','tarjeta',92.00,NULL,3),(211,2,'Pau Rivas','2026-08-19 09:10:00','2026-08-19 10:13:00','2026-08-19 10:13:00','cerrado','tarjeta',37.00,NULL,6),(212,3,'Cris Mora','2026-08-27 10:20:00','2026-08-27 11:30:00','2026-08-27 11:30:00','cerrado','efectivo',48.00,NULL,3),(213,2,'Noa Cid','2026-09-04 09:35:00','2026-09-04 10:52:00','2026-09-04 10:52:00','cerrado','tarjeta',70.00,NULL,6),(214,2,'Dani Frey','2026-08-15 09:25:00','2026-08-15 10:49:00','2026-08-15 10:49:00','cerrado','tarjeta',68.00,NULL,3),(215,3,'Uri Mena','2026-08-22 10:15:00','2026-08-22 11:46:00','2026-08-22 11:46:00','cerrado','efectivo',0.00,NULL,6),(216,2,'Bea Toro','2026-09-03 09:50:00','2026-09-03 11:28:00','2026-09-03 11:28:00','cerrado','tarjeta',37.00,NULL,3),(217,4,'Grupo Lara','2026-08-11 14:10:00','2026-08-11 15:55:00','2026-08-11 15:55:00','cerrado','tarjeta',109.00,NULL,4),(218,3,'Toña Vela','2026-08-14 13:40:00','2026-08-14 14:31:00','2026-08-14 14:31:00','cerrado','efectivo',42.00,NULL,3),(219,4,'Nico Paz','2026-08-21 15:00:00','2026-08-21 15:58:00','2026-08-21 15:58:00','cerrado','tarjeta',100.00,NULL,4),(220,2,'Ele Sáenz','2026-08-28 14:30:00','2026-08-28 15:35:00','2026-08-28 15:35:00','cerrado','tarjeta',59.00,NULL,3),(221,3,'Rux Dávila','2026-09-05 13:20:00','2026-09-05 14:32:00','2026-09-05 14:32:00','cerrado','tarjeta',109.00,NULL,4),(222,3,'Beto Nájera','2026-08-13 14:00:00','2026-08-13 15:19:00','2026-08-13 15:19:00','cerrado','tarjeta',86.00,NULL,3),(223,2,'Kena Ríos','2026-08-24 15:10:00','2026-08-24 16:36:00','2026-08-24 16:36:00','cerrado','efectivo',44.00,NULL,4),(224,4,'Grupo Sáez','2026-09-03 13:50:00','2026-09-03 15:23:00','2026-09-03 15:23:00','cerrado','tarjeta',134.00,NULL,3),(225,2,'Mar Cueto','2026-08-18 15:20:00','2026-08-18 17:00:00','2026-08-18 17:00:00','cerrado','tarjeta',72.00,NULL,4),(226,2,'Alma Vidal','2026-08-26 14:40:00','2026-08-26 16:27:00','2026-08-26 16:27:00','cerrado','efectivo',0.00,NULL,3),(227,3,'Ori Lozano','2026-09-06 13:30:00','2026-09-06 14:23:00','2026-09-06 14:23:00','cerrado','tarjeta',101.00,NULL,4),(228,3,'Fito Reyna','2026-08-16 14:20:00','2026-08-16 15:20:00','2026-08-16 15:20:00','cerrado','tarjeta',54.00,NULL,3),(229,4,'Grupo Ibarra','2026-08-29 15:30:00','2026-08-29 16:37:00','2026-08-29 16:37:00','cerrado','tarjeta',92.00,NULL,4),(230,2,'Sam Peña','2026-09-07 14:05:00','2026-09-07 15:19:00','2026-09-07 15:19:00','cerrado','efectivo',45.00,NULL,3),(231,4,'Grupo Cano','2026-08-12 20:15:00','2026-08-12 21:36:00','2026-08-12 21:36:00','cerrado','tarjeta',287.00,NULL,4),(232,2,'Vic Aranda','2026-08-22 21:00:00','2026-08-22 22:28:00','2026-08-22 22:28:00','cerrado','tarjeta',127.00,NULL,6),(233,3,'Lalo Bravo','2026-09-01 20:40:00','2026-09-01 22:15:00','2026-09-01 22:15:00','cerrado','tarjeta',166.00,NULL,4),(234,2,'Nube Ríos','2026-08-15 21:20:00','2026-08-15 23:02:00','2026-08-15 23:02:00','cerrado','tarjeta',85.00,NULL,6),(235,2,'Tavo Cruz','2026-08-25 20:10:00','2026-08-25 21:59:00','2026-08-25 21:59:00','cerrado','efectivo',36.00,NULL,4),(236,3,'Yara Sol','2026-09-04 21:15:00','2026-09-04 22:10:00','2026-09-04 22:10:00','cerrado','tarjeta',85.00,NULL,6),(237,4,'Grupo Villa','2026-08-19 20:30:00','2026-08-19 21:32:00','2026-08-19 21:32:00','cerrado','tarjeta',118.00,NULL,4),(238,3,'Mina Fox','2026-08-30 21:10:00','2026-08-30 22:19:00','2026-08-30 22:19:00','cerrado','tarjeta',70.00,NULL,6),(239,4,'Grupo Elías','2026-09-06 20:50:00','2026-09-06 22:06:00','2026-09-06 22:06:00','cerrado','tarjeta',117.00,NULL,4),(240,3,'Ceci Robles','2026-08-17 21:05:00','2026-08-17 22:28:00','2026-08-17 22:28:00','cerrado','tarjeta',111.00,NULL,6),(241,2,'Gus Prado','2026-08-27 20:20:00','2026-08-27 21:50:00','2026-08-27 21:50:00','cerrado','efectivo',53.00,NULL,4),(242,3,'Rey Molina','2026-09-07 21:30:00','2026-09-07 23:07:00','2026-09-07 23:07:00','cerrado','tarjeta',148.00,NULL,6),(300,2,'Ana Rueda','2026-08-31 08:35:00','2026-08-31 09:51:00','2026-08-31 09:51:00','cerrado','efectivo',0.00,NULL,2),(301,2,'Bruno Salas','2026-08-31 09:23:00','2026-08-31 10:46:00','2026-08-31 10:46:00','cerrado','tarjeta',49.00,NULL,3),(302,2,'Carla Ibáñez','2026-08-31 09:40:00','2026-08-31 11:10:00','2026-08-31 11:10:00','cerrado','tarjeta',69.00,NULL,5),(303,2,'Diego Nava','2026-08-31 10:30:00','2026-08-31 12:07:00','2026-08-31 12:07:00','cerrado','efectivo',74.00,NULL,2),(304,2,'Elena Ferrer','2026-08-31 10:47:00','2026-08-31 12:31:00','2026-08-31 12:31:00','cerrado','tarjeta',27.00,NULL,3),(305,2,'Fabián Ortuño','2026-08-31 11:37:00','2026-08-31 12:27:00','2026-08-31 12:27:00','cerrado','tarjeta',69.00,NULL,5),(306,2,'Gina Palomares','2026-08-31 12:44:00','2026-08-31 13:41:00','2026-08-31 13:41:00','cerrado','dividido',0.00,NULL,2),(307,2,'Hugo Villaseñor','2026-08-31 13:06:00','2026-08-31 14:10:00','2026-08-31 14:10:00','cerrado','tarjeta',45.00,NULL,3),(308,3,'Inés Carbajal','2026-08-31 14:13:00','2026-08-31 15:24:00','2026-08-31 15:24:00','cerrado','tarjeta',108.00,NULL,5),(309,3,'Jonás Ledesma','2026-08-31 14:30:00','2026-08-31 15:48:00','2026-08-31 15:48:00','cerrado','efectivo',90.00,NULL,2),(310,2,'Katia Berrones','2026-08-31 15:20:00','2026-08-31 16:45:00','2026-08-31 16:45:00','cerrado','tarjeta',90.00,NULL,3),(311,2,'Luis Toledo','2026-08-31 18:41:00','2026-08-31 20:13:00','2026-08-31 20:13:00','cerrado','tarjeta',35.00,NULL,5),(312,2,'Miriam Cuéllar','2026-08-31 19:48:00','2026-08-31 21:27:00','2026-08-31 21:27:00','cerrado','efectivo',0.00,NULL,2),(313,3,'Nicolás Arámbula','2026-08-31 20:10:00','2026-08-31 21:56:00','2026-08-31 21:56:00','cerrado','tarjeta',116.00,NULL,3),(314,3,'Odette Fierro','2026-08-31 21:17:00','2026-08-31 22:09:00','2026-08-31 22:09:00','cerrado','tarjeta',169.00,NULL,5),(315,2,'Pablo Zepeda','2026-09-01 08:35:00','2026-09-01 09:34:00','2026-09-01 09:34:00','cerrado','efectivo',76.00,NULL,2),(316,2,'Regina Alfaro','2026-09-01 09:23:00','2026-09-01 10:29:00','2026-09-01 10:29:00','cerrado','tarjeta',86.00,NULL,3),(317,2,'Samuel Íñiguez','2026-09-01 09:40:00','2026-09-01 10:53:00','2026-09-01 10:53:00','cerrado','tarjeta',59.00,NULL,5),(318,2,'Tere Camarillo','2026-09-01 10:30:00','2026-09-01 11:50:00','2026-09-01 11:50:00','cerrado','efectivo',73.00,NULL,2),(319,2,'Uriel Bermúdez','2026-09-01 10:47:00','2026-09-01 12:14:00','2026-09-01 12:14:00','cerrado','tarjeta',57.00,NULL,3),(320,2,'Vania Loera','2026-09-01 10:19:00','2026-09-01 11:53:00','2026-09-01 11:53:00','cerrado','tarjeta',61.00,NULL,5),(321,2,'Wilfrido Anaya','2026-09-01 11:37:00','2026-09-01 13:18:00','2026-09-01 13:18:00','cerrado','efectivo',58.00,NULL,2),(322,2,'Ximena Duarte','2026-09-01 11:09:00','2026-09-01 12:57:00','2026-09-01 12:57:00','cerrado','tarjeta',76.00,NULL,3),(323,2,'Yolanda Prieto','2026-09-01 12:44:00','2026-09-01 13:38:00','2026-09-01 13:38:00','cerrado','dividido',72.00,NULL,5),(324,3,'Zacarías Beltrán','2026-09-01 13:06:00','2026-09-01 14:07:00','2026-09-01 14:07:00','cerrado','efectivo',0.00,NULL,2),(325,2,'Adriana Lozano','2026-09-01 13:23:00','2026-09-01 14:31:00','2026-09-01 14:31:00','cerrado','tarjeta',76.00,NULL,3),(326,3,'Beto Nájera','2026-09-01 14:13:00','2026-09-01 15:28:00','2026-09-01 15:28:00','cerrado','tarjeta',132.00,NULL,5),(327,2,'Cecilia Ynzunza','2026-09-01 14:30:00','2026-09-01 15:52:00','2026-09-01 15:52:00','cerrado','efectivo',68.00,NULL,2),(328,3,'Damián Portillo','2026-09-01 15:20:00','2026-09-01 16:49:00','2026-09-01 16:49:00','cerrado','tarjeta',135.00,NULL,3),(329,2,'Estela Guardado','2026-09-01 17:34:00','2026-09-01 19:10:00','2026-09-01 19:10:00','cerrado','tarjeta',44.00,NULL,5),(330,2,'Fausto Rivas','2026-09-01 18:41:00','2026-09-01 20:24:00','2026-09-01 20:24:00','cerrado','efectivo',30.00,NULL,2),(331,2,'Gael Ruan','2026-09-01 19:48:00','2026-09-01 21:38:00','2026-09-01 21:38:00','cerrado','tarjeta',82.00,NULL,3),(332,3,'Héctor Salcedo','2026-09-01 20:10:00','2026-09-01 21:06:00','2026-09-01 21:06:00','cerrado','tarjeta',139.00,NULL,5),(333,3,'Irene Bustos','2026-09-01 20:27:00','2026-09-01 21:30:00','2026-09-01 21:30:00','cerrado','efectivo',110.00,NULL,2),(334,2,'Joaquín Nieto','2026-09-01 21:17:00','2026-09-01 22:27:00','2026-09-01 22:27:00','cerrado','tarjeta',179.00,NULL,3),(335,2,'Karla Villalobos','2026-09-01 21:34:00','2026-09-01 22:51:00','2026-09-01 22:51:00','cerrado','tarjeta',106.00,NULL,5),(336,2,'Lía Ordaz','2026-09-02 08:35:00','2026-09-02 09:59:00','2026-09-02 09:59:00','cerrado','efectivo',0.00,NULL,2),(337,2,'Mar Cueto','2026-09-02 09:23:00','2026-09-02 10:54:00','2026-09-02 10:54:00','cerrado','tarjeta',56.00,NULL,3),(338,2,'Noa Cid','2026-09-02 09:40:00','2026-09-02 11:18:00','2026-09-02 11:18:00','cerrado','tarjeta',61.00,NULL,5),(339,2,'Ori Lozano','2026-09-02 09:12:00','2026-09-02 10:57:00','2026-09-02 10:57:00','cerrado','efectivo',76.00,NULL,2),(340,2,'Paula Rivas','2026-09-02 10:30:00','2026-09-02 11:21:00','2026-09-02 11:21:00','cerrado','dividido',27.00,NULL,3),(341,2,'Quirino Ávila','2026-09-02 10:47:00','2026-09-02 11:45:00','2026-09-02 11:45:00','cerrado','tarjeta',53.00,NULL,5),(342,2,'Rita Peña','2026-09-02 10:19:00','2026-09-02 11:24:00','2026-09-02 11:24:00','cerrado','efectivo',69.00,NULL,2),(343,2,'Sol Marín','2026-09-02 11:37:00','2026-09-02 12:49:00','2026-09-02 12:49:00','cerrado','tarjeta',45.00,NULL,3),(344,2,'Tono Gil','2026-09-02 11:09:00','2026-09-02 12:28:00','2026-09-02 12:28:00','cerrado','tarjeta',59.00,NULL,5),(345,2,'Ulises Nava','2026-09-02 12:44:00','2026-09-02 14:10:00','2026-09-02 14:10:00','cerrado','efectivo',45.00,NULL,2),(346,2,'Vera Luna','2026-09-02 13:06:00','2026-09-02 14:39:00','2026-09-02 14:39:00','cerrado','tarjeta',102.00,NULL,3),(347,3,'Wendy Fuentes','2026-09-02 13:23:00','2026-09-02 15:03:00','2026-09-02 15:03:00','cerrado','tarjeta',151.00,NULL,5),(348,3,'Xavier Peña','2026-09-02 14:13:00','2026-09-02 16:00:00','2026-09-02 16:00:00','cerrado','efectivo',0.00,NULL,2),(349,3,'Yara Sol','2026-09-02 14:30:00','2026-09-02 15:23:00','2026-09-02 15:23:00','cerrado','tarjeta',99.00,NULL,3),(350,2,'Grupo Lara','2026-09-02 14:47:00','2026-09-02 15:47:00','2026-09-02 15:47:00','cerrado','tarjeta',72.00,NULL,5),(351,2,'Grupo Cano','2026-09-02 15:20:00','2026-09-02 16:27:00','2026-09-02 16:27:00','cerrado','efectivo',108.00,NULL,2),(352,2,'Grupo Ibarra','2026-09-02 17:34:00','2026-09-02 18:48:00','2026-09-02 18:48:00','cerrado','tarjeta',38.00,NULL,3),(353,2,'Grupo Villa','2026-09-02 18:41:00','2026-09-02 20:02:00','2026-09-02 20:02:00','cerrado','tarjeta',30.00,NULL,5),(354,3,'Grupo Sáez','2026-09-02 19:48:00','2026-09-02 21:16:00','2026-09-02 21:16:00','cerrado','efectivo',158.00,NULL,2),(355,2,'Familia Nava','2026-09-02 19:20:00','2026-09-02 20:55:00','2026-09-02 20:55:00','cerrado','tarjeta',83.00,NULL,3),(356,3,'Familia Robles','2026-09-02 20:10:00','2026-09-02 21:52:00','2026-09-02 21:52:00','cerrado','tarjeta',168.00,NULL,5),(357,3,'Familia Prado','2026-09-02 20:27:00','2026-09-02 22:16:00','2026-09-02 22:16:00','cerrado','dividido',198.00,NULL,2),(358,2,'Familia Mena','2026-09-02 21:17:00','2026-09-02 22:12:00','2026-09-02 22:12:00','cerrado','tarjeta',125.00,NULL,3),(359,2,'Familia Cruz','2026-09-02 21:34:00','2026-09-02 22:36:00','2026-09-02 22:36:00','cerrado','tarjeta',95.00,NULL,5),(360,2,'Ana Rueda','2026-09-03 08:35:00','2026-09-03 09:44:00','2026-09-03 09:44:00','cerrado','efectivo',0.00,NULL,2),(361,2,'Bruno Salas','2026-09-03 09:23:00','2026-09-03 10:39:00','2026-09-03 10:39:00','cerrado','tarjeta',58.00,NULL,3),(362,2,'Carla Ibáñez','2026-09-03 09:40:00','2026-09-03 11:03:00','2026-09-03 11:03:00','cerrado','tarjeta',53.00,NULL,5),(363,2,'Diego Nava','2026-09-03 09:12:00','2026-09-03 10:42:00','2026-09-03 10:42:00','cerrado','efectivo',92.00,NULL,2),(364,2,'Elena Ferrer','2026-09-03 10:30:00','2026-09-03 12:07:00','2026-09-03 12:07:00','cerrado','tarjeta',86.00,NULL,3),(365,2,'Fabián Ortuño','2026-09-03 10:47:00','2026-09-03 12:31:00','2026-09-03 12:31:00','cerrado','tarjeta',73.00,NULL,5),(366,2,'Gina Palomares','2026-09-03 10:19:00','2026-09-03 11:09:00','2026-09-03 11:09:00','cerrado','efectivo',69.00,NULL,2),(367,2,'Hugo Villaseñor','2026-09-03 11:37:00','2026-09-03 12:34:00','2026-09-03 12:34:00','cerrado','tarjeta',45.00,NULL,3),(368,2,'Inés Carbajal','2026-09-03 11:09:00','2026-09-03 12:13:00','2026-09-03 12:13:00','cerrado','tarjeta',69.00,NULL,5),(369,2,'Jonás Ledesma','2026-09-03 12:44:00','2026-09-03 13:55:00','2026-09-03 13:55:00','cerrado','efectivo',45.00,NULL,2),(370,3,'Katia Berrones','2026-09-03 13:06:00','2026-09-03 14:24:00','2026-09-03 14:24:00','cerrado','tarjeta',178.00,NULL,3),(371,3,'Luis Toledo','2026-09-03 13:23:00','2026-09-03 14:48:00','2026-09-03 14:48:00','cerrado','tarjeta',139.00,NULL,5),(372,2,'Miriam Cuéllar','2026-09-03 13:40:00','2026-09-03 15:12:00','2026-09-03 15:12:00','cerrado','efectivo',0.00,NULL,2),(373,2,'Nicolás Arámbula','2026-09-03 14:13:00','2026-09-03 15:52:00','2026-09-03 15:52:00','cerrado','tarjeta',69.00,NULL,3),(374,3,'Odette Fierro','2026-09-03 14:30:00','2026-09-03 16:16:00','2026-09-03 16:16:00','cerrado','dividido',119.00,NULL,5),(375,3,'Pablo Zepeda','2026-09-03 14:47:00','2026-09-03 15:39:00','2026-09-03 15:39:00','cerrado','efectivo',165.00,NULL,2),(376,3,'Regina Alfaro','2026-09-03 15:20:00','2026-09-03 16:19:00','2026-09-03 16:19:00','cerrado','tarjeta',185.00,NULL,3),(377,3,'Samuel Íñiguez','2026-09-03 15:37:00','2026-09-03 16:43:00','2026-09-03 16:43:00','cerrado','tarjeta',132.00,NULL,5),(378,2,'Tere Camarillo','2026-09-03 16:27:00','2026-09-03 17:40:00','2026-09-03 17:40:00','cerrado','efectivo',44.00,NULL,2),(379,2,'Uriel Bermúdez','2026-09-03 17:34:00','2026-09-03 18:54:00','2026-09-03 18:54:00','cerrado','tarjeta',29.00,NULL,3),(380,2,'Vania Loera','2026-09-03 18:41:00','2026-09-03 20:08:00','2026-09-03 20:08:00','cerrado','tarjeta',35.00,NULL,5),(381,2,'Wilfrido Anaya','2026-09-03 19:48:00','2026-09-03 21:22:00','2026-09-03 21:22:00','cerrado','efectivo',59.00,NULL,2),(382,2,'Ximena Duarte','2026-09-03 19:20:00','2026-09-03 21:01:00','2026-09-03 21:01:00','cerrado','tarjeta',108.00,NULL,3),(383,3,'Yolanda Prieto','2026-09-03 20:10:00','2026-09-03 21:58:00','2026-09-03 21:58:00','cerrado','tarjeta',159.00,NULL,5),(384,2,'Zacarías Beltrán','2026-09-03 20:27:00','2026-09-03 21:21:00','2026-09-03 21:21:00','cerrado','efectivo',0.00,NULL,2),(385,3,'Adriana Lozano','2026-09-03 20:44:00','2026-09-03 21:45:00','2026-09-03 21:45:00','cerrado','tarjeta',198.00,NULL,3),(386,3,'Beto Nájera','2026-09-03 21:17:00','2026-09-03 22:25:00','2026-09-03 22:25:00','cerrado','tarjeta',125.00,NULL,5),(387,3,'Cecilia Ynzunza','2026-09-03 21:34:00','2026-09-03 22:49:00','2026-09-03 22:49:00','cerrado','efectivo',199.00,NULL,2),(388,2,'Damián Portillo','2026-09-03 21:06:00','2026-09-03 22:28:00','2026-09-03 22:28:00','cerrado','tarjeta',166.00,NULL,3),(389,2,'Estela Guardado','2026-09-04 08:35:00','2026-09-04 10:04:00','2026-09-04 10:04:00','cerrado','tarjeta',62.00,NULL,5),(390,2,'Fausto Rivas','2026-09-04 08:42:00','2026-09-04 10:18:00','2026-09-04 10:18:00','cerrado','efectivo',69.00,NULL,2),(391,2,'Gael Ruan','2026-09-04 09:23:00','2026-09-04 11:06:00','2026-09-04 11:06:00','cerrado','dividido',65.00,NULL,3),(392,2,'Héctor Salcedo','2026-09-04 09:40:00','2026-09-04 11:30:00','2026-09-04 11:30:00','cerrado','tarjeta',61.00,NULL,5),(393,2,'Irene Bustos','2026-09-04 09:12:00','2026-09-04 10:08:00','2026-09-04 10:08:00','cerrado','efectivo',52.00,NULL,2),(394,2,'Joaquín Nieto','2026-09-04 10:30:00','2026-09-04 11:33:00','2026-09-04 11:33:00','cerrado','tarjeta',67.00,NULL,3),(395,2,'Karla Villalobos','2026-09-04 10:47:00','2026-09-04 11:57:00','2026-09-04 11:57:00','cerrado','tarjeta',59.00,NULL,5),(396,2,'Lía Ordaz','2026-09-04 10:19:00','2026-09-04 11:36:00','2026-09-04 11:36:00','cerrado','efectivo',0.00,NULL,2),(397,2,'Mar Cueto','2026-09-04 10:36:00','2026-09-04 12:00:00','2026-09-04 12:00:00','cerrado','tarjeta',18.00,NULL,3),(398,2,'Noa Cid','2026-09-04 11:37:00','2026-09-04 13:08:00','2026-09-04 13:08:00','cerrado','tarjeta',82.00,NULL,5),(399,2,'Ori Lozano','2026-09-04 11:09:00','2026-09-04 12:47:00','2026-09-04 12:47:00','cerrado','efectivo',67.00,NULL,2),(400,2,'Paula Rivas','2026-09-04 12:44:00','2026-09-04 14:29:00','2026-09-04 14:29:00','cerrado','tarjeta',90.00,NULL,3),(401,3,'Quirino Ávila','2026-09-04 13:06:00','2026-09-04 13:57:00','2026-09-04 13:57:00','cerrado','tarjeta',88.00,NULL,5),(402,3,'Rita Peña','2026-09-04 13:23:00','2026-09-04 14:21:00','2026-09-04 14:21:00','cerrado','efectivo',132.00,NULL,2),(403,2,'Sol Marín','2026-09-04 13:40:00','2026-09-04 14:45:00','2026-09-04 14:45:00','cerrado','tarjeta',79.00,NULL,3),(404,2,'Tono Gil','2026-09-04 14:13:00','2026-09-04 15:25:00','2026-09-04 15:25:00','cerrado','tarjeta',72.00,NULL,5),(405,2,'Ulises Nava','2026-09-04 14:30:00','2026-09-04 15:49:00','2026-09-04 15:49:00','cerrado','efectivo',72.00,NULL,2),(406,2,'Vera Luna','2026-09-04 14:47:00','2026-09-04 16:13:00','2026-09-04 16:13:00','cerrado','tarjeta',102.00,NULL,3),(407,2,'Wendy Fuentes','2026-09-04 14:19:00','2026-09-04 15:52:00','2026-09-04 15:52:00','cerrado','tarjeta',64.00,NULL,5),(408,2,'Xavier Peña','2026-09-04 15:20:00','2026-09-04 17:00:00','2026-09-04 17:00:00','cerrado','dividido',0.00,NULL,2),(409,3,'Yara Sol','2026-09-04 15:37:00','2026-09-04 17:24:00','2026-09-04 17:24:00','cerrado','tarjeta',106.00,NULL,3),(410,2,'Grupo Lara','2026-09-04 16:27:00','2026-09-04 17:20:00','2026-09-04 17:20:00','cerrado','tarjeta',35.00,NULL,5),(411,2,'Grupo Cano','2026-09-04 17:34:00','2026-09-04 18:34:00','2026-09-04 18:34:00','cerrado','efectivo',49.00,NULL,2),(412,2,'Grupo Ibarra','2026-09-04 18:41:00','2026-09-04 19:48:00','2026-09-04 19:48:00','cerrado','tarjeta',53.00,NULL,3),(413,2,'Grupo Villa','2026-09-04 18:13:00','2026-09-04 19:27:00','2026-09-04 19:27:00','cerrado','tarjeta',35.00,NULL,5),(414,2,'Grupo Sáez','2026-09-04 19:48:00','2026-09-04 21:09:00','2026-09-04 21:09:00','cerrado','efectivo',62.00,NULL,2),(415,3,'Familia Nava','2026-09-04 19:20:00','2026-09-04 20:48:00','2026-09-04 20:48:00','cerrado','tarjeta',110.00,NULL,3),(416,4,'Familia Robles','2026-09-04 20:10:00','2026-09-04 21:45:00','2026-09-04 21:45:00','cerrado','tarjeta',227.00,NULL,5),(417,4,'Familia Prado','2026-09-04 20:27:00','2026-09-04 22:09:00','2026-09-04 22:09:00','cerrado','efectivo',144.00,NULL,2),(418,4,'Familia Mena','2026-09-04 20:44:00','2026-09-04 22:33:00','2026-09-04 22:33:00','cerrado','tarjeta',284.00,NULL,3),(419,4,'Familia Cruz','2026-09-04 20:16:00','2026-09-04 21:11:00','2026-09-04 21:11:00','cerrado','tarjeta',181.00,NULL,5),(420,4,'Ana Rueda','2026-09-04 21:17:00','2026-09-04 22:19:00','2026-09-04 22:19:00','cerrado','efectivo',0.00,NULL,2),(421,3,'Bruno Salas','2026-09-04 21:34:00','2026-09-04 22:43:00','2026-09-04 22:43:00','cerrado','tarjeta',133.00,NULL,3),(422,4,'Carla Ibáñez','2026-09-04 21:06:00','2026-09-04 22:22:00','2026-09-04 22:22:00','cerrado','tarjeta',199.00,NULL,5),(423,3,'Diego Nava','2026-09-04 21:23:00','2026-09-04 22:46:00','2026-09-04 22:46:00','cerrado','efectivo',174.00,NULL,2),(424,2,'Elena Ferrer','2026-08-29 08:35:00','2026-08-29 10:05:00','2026-08-29 10:05:00','cerrado','tarjeta',76.00,NULL,3),(425,4,'Fabián Ortuño','2026-08-29 08:42:00','2026-08-29 10:19:00','2026-08-29 10:19:00','cerrado','dividido',121.00,NULL,5),(426,2,'Gina Palomares','2026-08-29 09:23:00','2026-08-29 11:07:00','2026-08-29 11:07:00','cerrado','efectivo',59.00,NULL,2),(427,2,'Hugo Villaseñor','2026-08-29 09:40:00','2026-08-29 10:30:00','2026-08-29 10:30:00','cerrado','tarjeta',58.00,NULL,3),(428,2,'Inés Carbajal','2026-08-29 09:12:00','2026-08-29 10:09:00','2026-08-29 10:09:00','cerrado','tarjeta',59.00,NULL,5),(429,3,'Jonás Ledesma','2026-08-29 09:29:00','2026-08-29 10:33:00','2026-08-29 10:33:00','cerrado','efectivo',79.00,NULL,2),(430,4,'Katia Berrones','2026-08-29 10:30:00','2026-08-29 11:41:00','2026-08-29 11:41:00','cerrado','tarjeta',173.00,NULL,3),(431,4,'Luis Toledo','2026-08-29 10:47:00','2026-08-29 12:05:00','2026-08-29 12:05:00','cerrado','tarjeta',130.00,NULL,5),(432,4,'Miriam Cuéllar','2026-08-29 10:19:00','2026-08-29 11:44:00','2026-08-29 11:44:00','cerrado','efectivo',0.00,NULL,2),(433,3,'Nicolás Arámbula','2026-08-29 10:36:00','2026-08-29 12:08:00','2026-08-29 12:08:00','cerrado','tarjeta',92.00,NULL,3),(434,2,'Odette Fierro','2026-08-29 10:08:00','2026-08-29 11:47:00','2026-08-29 11:47:00','cerrado','tarjeta',59.00,NULL,5),(435,3,'Pablo Zepeda','2026-08-29 11:37:00','2026-08-29 13:23:00','2026-08-29 13:23:00','cerrado','efectivo',110.00,NULL,2),(436,2,'Regina Alfaro','2026-08-29 11:09:00','2026-08-29 12:01:00','2026-08-29 12:01:00','cerrado','tarjeta',78.00,NULL,3),(437,3,'Samuel Íñiguez','2026-08-29 11:26:00','2026-08-29 12:25:00','2026-08-29 12:25:00','cerrado','tarjeta',109.00,NULL,5),(438,3,'Tere Camarillo','2026-08-29 12:44:00','2026-08-29 13:50:00','2026-08-29 13:50:00','cerrado','efectivo',132.00,NULL,2),(439,3,'Uriel Bermúdez','2026-08-29 12:16:00','2026-08-29 13:29:00','2026-08-29 13:29:00','cerrado','tarjeta',116.00,NULL,3),(440,2,'Vania Loera','2026-08-29 13:06:00','2026-08-29 14:26:00','2026-08-29 14:26:00','cerrado','tarjeta',86.00,NULL,5),(441,2,'Wilfrido Anaya','2026-08-29 13:23:00','2026-08-29 14:50:00','2026-08-29 14:50:00','cerrado','efectivo',52.00,NULL,2),(442,2,'Ximena Duarte','2026-08-29 13:40:00','2026-08-29 15:14:00','2026-08-29 15:14:00','cerrado','dividido',119.00,NULL,3),(443,2,'Yolanda Prieto','2026-08-29 13:12:00','2026-08-29 14:53:00','2026-08-29 14:53:00','cerrado','tarjeta',91.00,NULL,5),(444,2,'Zacarías Beltrán','2026-08-29 14:13:00','2026-08-29 16:01:00','2026-08-29 16:01:00','cerrado','efectivo',0.00,NULL,2),(445,2,'Adriana Lozano','2026-08-29 14:30:00','2026-08-29 15:24:00','2026-08-29 15:24:00','cerrado','tarjeta',79.00,NULL,3),(446,3,'Beto Nájera','2026-08-29 14:47:00','2026-08-29 15:48:00','2026-08-29 15:48:00','cerrado','tarjeta',132.00,NULL,5),(447,3,'Cecilia Ynzunza','2026-08-29 14:19:00','2026-08-29 15:27:00','2026-08-29 15:27:00','cerrado','efectivo',178.00,NULL,2),(448,3,'Damián Portillo','2026-08-29 15:20:00','2026-08-29 16:35:00','2026-08-29 16:35:00','cerrado','tarjeta',110.00,NULL,3),(449,3,'Estela Guardado','2026-08-29 15:37:00','2026-08-29 16:59:00','2026-08-29 16:59:00','cerrado','tarjeta',147.00,NULL,5),(450,2,'Fausto Rivas','2026-08-29 16:27:00','2026-08-29 17:56:00','2026-08-29 17:56:00','cerrado','efectivo',30.00,NULL,2),(451,2,'Gael Ruan','2026-08-29 17:34:00','2026-08-29 19:10:00','2026-08-29 19:10:00','cerrado','tarjeta',36.00,NULL,3),(452,2,'Héctor Salcedo','2026-08-29 18:41:00','2026-08-29 20:24:00','2026-08-29 20:24:00','cerrado','tarjeta',35.00,NULL,5),(453,2,'Irene Bustos','2026-08-29 18:13:00','2026-08-29 20:03:00','2026-08-29 20:03:00','cerrado','efectivo',37.00,NULL,2),(454,2,'Joaquín Nieto','2026-08-29 19:48:00','2026-08-29 20:44:00','2026-08-29 20:44:00','cerrado','tarjeta',108.00,NULL,3),(455,4,'Karla Villalobos','2026-08-29 19:20:00','2026-08-29 20:23:00','2026-08-29 20:23:00','cerrado','tarjeta',173.00,NULL,5),(456,2,'Lía Ordaz','2026-08-29 19:37:00','2026-08-29 20:47:00','2026-08-29 20:47:00','cerrado','efectivo',0.00,NULL,2),(457,3,'Mar Cueto','2026-08-29 20:10:00','2026-08-29 21:27:00','2026-08-29 21:27:00','cerrado','tarjeta',116.00,NULL,3),(458,3,'Noa Cid','2026-08-29 20:27:00','2026-08-29 21:51:00','2026-08-29 21:51:00','cerrado','tarjeta',131.00,NULL,5),(459,4,'Ori Lozano','2026-08-29 20:44:00','2026-08-29 22:15:00','2026-08-29 22:15:00','cerrado','dividido',260.00,NULL,2),(460,3,'Paula Rivas','2026-08-29 20:16:00','2026-08-29 21:54:00','2026-08-29 21:54:00','cerrado','tarjeta',199.00,NULL,3),(461,2,'Quirino Ávila','2026-08-29 21:17:00','2026-08-29 23:02:00','2026-08-29 23:02:00','cerrado','tarjeta',96.00,NULL,5),(462,2,'Rita Peña','2026-08-29 21:34:00','2026-08-29 22:25:00','2026-08-29 22:25:00','cerrado','efectivo',100.00,NULL,2),(463,3,'Sol Marín','2026-08-29 21:06:00','2026-08-29 22:04:00','2026-08-29 22:04:00','cerrado','tarjeta',110.00,NULL,3),(464,4,'Tono Gil','2026-08-29 21:23:00','2026-08-29 22:28:00','2026-08-29 22:28:00','cerrado','tarjeta',197.00,NULL,5),(465,4,'Ulises Nava','2026-08-29 21:40:00','2026-08-29 22:52:00','2026-08-29 22:52:00','cerrado','efectivo',104.00,NULL,2),(466,2,'Vera Luna','2026-08-30 08:35:00','2026-08-30 09:54:00','2026-08-30 09:54:00','cerrado','tarjeta',103.00,NULL,3),(467,4,'Wendy Fuentes','2026-08-30 08:42:00','2026-08-30 10:08:00','2026-08-30 10:08:00','cerrado','tarjeta',118.00,NULL,5),(468,3,'Xavier Peña','2026-08-30 09:23:00','2026-08-30 10:56:00','2026-08-30 10:56:00','cerrado','efectivo',0.00,NULL,2),(469,2,'Yara Sol','2026-08-30 09:40:00','2026-08-30 11:20:00','2026-08-30 11:20:00','cerrado','tarjeta',65.00,NULL,3),(470,4,'Grupo Lara','2026-08-30 09:12:00','2026-08-30 10:59:00','2026-08-30 10:59:00','cerrado','tarjeta',117.00,NULL,5),(471,4,'Grupo Cano','2026-08-30 09:29:00','2026-08-30 10:22:00','2026-08-30 10:22:00','cerrado','efectivo',173.00,NULL,2),(472,4,'Grupo Ibarra','2026-08-30 10:30:00','2026-08-30 11:30:00','2026-08-30 11:30:00','cerrado','tarjeta',134.00,NULL,3),(473,2,'Grupo Villa','2026-08-30 10:47:00','2026-08-30 11:54:00','2026-08-30 11:54:00','cerrado','tarjeta',69.00,NULL,5),(474,2,'Grupo Sáez','2026-08-30 10:19:00','2026-08-30 11:33:00','2026-08-30 11:33:00','cerrado','efectivo',59.00,NULL,2),(475,4,'Familia Nava','2026-08-30 10:36:00','2026-08-30 11:57:00','2026-08-30 11:57:00','cerrado','tarjeta',109.00,NULL,3),(476,4,'Familia Robles','2026-08-30 10:08:00','2026-08-30 11:36:00','2026-08-30 11:36:00','cerrado','dividido',118.00,NULL,5),(477,2,'Familia Prado','2026-08-30 11:37:00','2026-08-30 13:12:00','2026-08-30 13:12:00','cerrado','efectivo',52.00,NULL,2),(478,4,'Familia Mena','2026-08-30 11:09:00','2026-08-30 12:51:00','2026-08-30 12:51:00','cerrado','tarjeta',193.00,NULL,3),(479,4,'Familia Cruz','2026-08-30 11:26:00','2026-08-30 13:15:00','2026-08-30 13:15:00','cerrado','tarjeta',148.00,NULL,5),(480,2,'Ana Rueda','2026-08-30 12:44:00','2026-08-30 13:39:00','2026-08-30 13:39:00','cerrado','efectivo',0.00,NULL,2),(481,3,'Bruno Salas','2026-08-30 12:16:00','2026-08-30 13:18:00','2026-08-30 13:18:00','cerrado','tarjeta',116.00,NULL,3),(482,3,'Carla Ibáñez','2026-08-30 13:06:00','2026-08-30 14:15:00','2026-08-30 14:15:00','cerrado','tarjeta',149.00,NULL,5),(483,2,'Diego Nava','2026-08-30 13:23:00','2026-08-30 14:39:00','2026-08-30 14:39:00','cerrado','efectivo',82.00,NULL,2),(484,3,'Elena Ferrer','2026-08-30 13:40:00','2026-08-30 15:03:00','2026-08-30 15:03:00','cerrado','tarjeta',165.00,NULL,3),(485,3,'Fabián Ortuño','2026-08-30 14:13:00','2026-08-30 15:43:00','2026-08-30 15:43:00','cerrado','tarjeta',139.00,NULL,5),(486,3,'Gina Palomares','2026-08-30 14:30:00','2026-08-30 16:07:00','2026-08-30 16:07:00','cerrado','efectivo',142.00,NULL,2),(487,3,'Hugo Villaseñor','2026-08-30 14:47:00','2026-08-30 16:31:00','2026-08-30 16:31:00','cerrado','tarjeta',73.00,NULL,3),(488,3,'Inés Carbajal','2026-08-30 15:20:00','2026-08-30 16:10:00','2026-08-30 16:10:00','cerrado','tarjeta',139.00,NULL,5),(489,2,'Jonás Ledesma','2026-08-30 15:37:00','2026-08-30 16:34:00','2026-08-30 16:34:00','cerrado','efectivo',68.00,NULL,2),(490,2,'Katia Berrones','2026-08-30 16:27:00','2026-08-30 17:31:00','2026-08-30 17:31:00','cerrado','tarjeta',44.00,NULL,3),(491,2,'Luis Toledo','2026-08-30 17:34:00','2026-08-30 18:45:00','2026-08-30 18:45:00','cerrado','tarjeta',35.00,NULL,5),(492,2,'Miriam Cuéllar','2026-08-30 18:41:00','2026-08-30 19:59:00','2026-08-30 19:59:00','cerrado','efectivo',0.00,NULL,2),(493,4,'Nicolás Arámbula','2026-08-30 19:48:00','2026-08-30 21:13:00','2026-08-30 21:13:00','cerrado','dividido',166.00,NULL,3),(494,4,'Odette Fierro','2026-08-30 19:20:00','2026-08-30 20:52:00','2026-08-30 20:52:00','cerrado','tarjeta',125.00,NULL,5),(495,2,'Pablo Zepeda','2026-08-30 20:10:00','2026-08-30 21:49:00','2026-08-30 21:49:00','cerrado','efectivo',137.00,NULL,2),(496,2,'Regina Alfaro','2026-08-30 20:27:00','2026-08-30 22:13:00','2026-08-30 22:13:00','cerrado','tarjeta',108.00,NULL,3),(497,3,'Samuel Íñiguez','2026-08-30 21:17:00','2026-08-30 22:09:00','2026-08-30 22:09:00','cerrado','tarjeta',236.00,NULL,5),(498,4,'Tere Camarillo','2026-08-30 21:34:00','2026-08-30 22:33:00','2026-08-30 22:33:00','cerrado','efectivo',199.00,NULL,2),(506,1,'Emilio','2026-09-22 22:11:12','2026-10-04 21:07:12','2026-10-04 21:07:12','cerrado','efectivo',0.00,NULL,2),(509,2,'Emilio Arriaga','2026-09-30 11:01:01',NULL,NULL,'abierto',NULL,0.00,NULL,5),(510,3,'Alex Oliva','2026-10-02 19:06:58',NULL,NULL,'abierto',NULL,0.00,NULL,2),(519,1,'Manuel','2026-10-04 21:07:32',NULL,NULL,'abierto',NULL,0.00,NULL,2);
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
  `nip_lookup` char(64) DEFAULT NULL,
  `rol` enum('admin','waiter','cook') NOT NULL DEFAULT 'waiter',
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `uq_usuarios_nip_lookup` (`nip_lookup`),
  CONSTRAINT `chk_usuarios_admin_sin_nip` CHECK (((`rol` <> _utf8mb4'admin') or ((`nip_hash` is null) and (`nip_lookup` is null))))
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `usuarios`
--

LOCK TABLES `usuarios` WRITE;
/*!40000 ALTER TABLE `usuarios` DISABLE KEYS */;
INSERT INTO `usuarios` VALUES (1,'admin_demo','Administrador Demo','$2y$12$VK6JthhJgua5GFkggWTqYOmhnUfPZ40giH1au5.Vk24dLp9Gf4oJS',NULL,NULL,'admin',1,'2026-09-07 20:18:29',NULL),(2,'mesero1','Carlos Hernández','!sin-credencial',NULL,NULL,'waiter',1,'2026-09-07 20:28:19',NULL),(3,'mesero2','Valeria Ríos','!sin-credencial',NULL,NULL,'waiter',1,'2026-09-07 20:28:19',NULL),(4,'cocinero1','Mariana López','!sin-credencial',NULL,NULL,'cook',1,'2026-09-07 20:28:19',NULL),(5,'mesero3','Emilio Cárdenas','!sin-credencial',NULL,NULL,'waiter',1,'2026-09-07 20:28:19',NULL),(6,'mesero_inactivo','Daniel Torres','!sin-credencial',NULL,NULL,'waiter',0,'2026-09-07 20:28:19',NULL);
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `verificaciones_contacto`
--

LOCK TABLES `verificaciones_contacto` WRITE;
/*!40000 ALTER TABLE `verificaciones_contacto` DISABLE KEYS */;
/*!40000 ALTER TABLE `verificaciones_contacto` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'casa_pestalozzi'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-13  5:28:42
