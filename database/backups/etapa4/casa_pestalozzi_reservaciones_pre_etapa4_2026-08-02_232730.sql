-- MySQL dump 10.13  Distrib 9.7.1, for Win64 (x86_64)
--
-- Host: 127.0.0.1    Database: casa-pestalozzi
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
INSERT INTO `configuracion_anuncio` VALUES (1,'Este sábado tendremos música en vivo a partir de las 19:00 h.','evento',1,NULL,NULL,NULL,NULL,1,'2026-07-31 00:37:48');
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
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `excepciones_operacion`
--

LOCK TABLES `excepciones_operacion` WRITE;
/*!40000 ALTER TABLE `excepciones_operacion` DISABLE KEYS */;
INSERT INTO `excepciones_operacion` VALUES (1,'2026-11-29','cerrado','Cierre de prueba',NULL,NULL,1,NULL,'2026-07-30 23:10:44',NULL),(2,'2026-12-02','horario_especial','Horario especial de prueba','14:00:00','21:00:00',1,NULL,'2026-07-30 23:10:44',NULL);
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
INSERT INTO `feedback` VALUES (1,NULL,1,5,5,4,5,'Todo excelente, el salmón estaba delicioso y el servicio muy atento.','2026-06-18 20:50:00'),(2,NULL,2,4,5,3,4,'Muy rica la comida, aunque tardó un poco en llegar.','2026-06-18 21:30:00'),(3,NULL,3,5,4,4,5,'Celebramos un cumpleaños y quedamos encantados. Volveremos.','2026-06-19 04:00:00'),(4,NULL,5,5,5,5,5,'Experiencia impecable de principio a fin. El filete, espectacular.','2026-06-19 04:15:00'),(5,NULL,8,3,4,2,3,'La hamburguesa buena, pero esperamos demasiado por la cuenta.','2026-06-18 22:10:00'),(6,NULL,2,4,4,4,4,'Buen ambiente y sazón. Repetiría el jugo verde.','2026-06-18 21:35:00'),(7,NULL,1,5,5,5,5,'El mejor desayuno de la Del Valle, sin duda.','2026-06-18 20:55:00'),(8,NULL,3,2,3,2,2,'La pizza llegó fría y tardaron en atendernos.','2026-06-19 04:05:00'),(9,NULL,3,2,4,3,3,'La Pizza Milano llegó tibia; de sabor bien, pero fría le resta mucho.','2026-06-20 03:40:00'),(10,NULL,5,3,4,3,3,'El filete pedido término medio llegó casi bien cocido. La próxima lo cuidaré.','2026-06-20 03:55:00'),(11,NULL,NULL,2,3,3,2,'Los chilaquiles llegaron aguados y el huevo frío. Esperábamos más.','2026-06-20 16:15:00'),(12,NULL,3,5,5,4,5,'El Rib Eye estaba en su punto perfecto, jugoso y caliente. Excelente cocina.','2026-06-21 03:10:00'),(13,NULL,8,4,4,2,3,'Comida muy buena, pero esperamos casi 20 minutos para que trajeran la cuenta.','2026-06-19 22:20:00'),(14,NULL,2,4,5,2,3,'Todo rico, aunque cobrar con tarjeta tomó mucho tiempo. La terminal fallaba.','2026-06-20 21:05:00'),(15,NULL,NULL,5,5,2,4,'Amamos el lugar, pero el cierre de cuenta en hora pico es eterno.','2026-06-20 21:40:00'),(16,NULL,NULL,4,3,2,3,'Sábado lleno: la comida tardó más de 40 minutos en salir.','2026-06-21 03:30:00'),(17,NULL,NULL,4,4,2,3,'Rico todo, pero tardaron mucho en tomarnos la orden al inicio.','2026-06-21 20:10:00'),(18,NULL,2,3,2,3,3,'Tuvimos que pedir el agua y los cubiertos dos veces. Faltó seguimiento a la mesa.','2026-06-21 20:25:00'),(19,NULL,5,5,5,5,5,'Ricardo, nuestro mesero, fue atentísimo y nos recomendó de maravilla. ¡Un lujo!','2026-06-22 03:15:00'),(20,NULL,NULL,4,2,4,3,'La comida bien, pero el mesero se veía saturado y algo cortante.','2026-06-22 21:30:00'),(21,NULL,1,5,5,5,5,'Nos reconocieron como clientes frecuentes y hasta una cortesía nos dieron. ¡Gracias!','2026-06-22 15:40:00'),(22,NULL,NULL,5,5,4,4,'Comida deliciosa, pero la música estaba tan alta que costaba conversar.','2026-06-23 03:50:00'),(23,NULL,NULL,4,4,4,3,'Buen lugar, aunque el aire acondicionado estaba muy frío en la zona del ventanal.','2026-06-23 20:20:00'),(24,NULL,3,4,5,4,5,'La terraza es hermosa y muy tranquila para comer en familia.','2026-06-23 21:10:00'),(25,NULL,NULL,4,4,4,3,'La comida bien, pero los baños necesitaban atención a media tarde.','2026-06-23 23:05:00'),(26,NULL,NULL,4,4,4,3,'Sabor bueno, pero las porciones se me hicieron chicas para el precio.','2026-06-24 20:45:00'),(27,NULL,8,5,5,4,5,'La Hamburguesa de la Casa vale cada peso. Enorme y sabrosa.','2026-06-24 21:20:00'),(28,NULL,1,3,4,4,3,'El cappuccino llegó tibio y sin mucha espuma. El desayuno sí muy rico.','2026-06-24 15:30:00'),(29,NULL,2,5,4,4,5,'El jugo verde y el café de olla, espectaculares. Mi desayuno favorito.','2026-06-25 15:15:00'),(30,NULL,NULL,4,5,4,4,'Todo muy rico, pero me gustaría ver más opciones vegetarianas y sin gluten.','2026-06-25 20:35:00'),(31,NULL,NULL,5,5,4,4,'La comida excelente; ojalá tuvieran más variedad de postres.','2026-06-26 03:25:00'),(32,NULL,NULL,3,3,3,2,'Nos trajeron un platillo equivocado y tuvimos que esperar a que lo corrigieran.','2026-06-26 21:00:00'),(33,NULL,NULL,5,4,3,4,'Reservamos pero la mesa no estaba lista a la hora acordada. Lo demás, muy bien.','2026-06-27 03:10:00'),(34,NULL,3,5,5,5,5,'Fuimos con niños y los atendieron increíble. Menú infantil por favor.','2026-06-27 20:15:00'),(35,NULL,5,5,5,4,5,'Festejamos un aniversario y todo fue perfecto. El postre de cortesía, un detallazo.','2026-06-28 03:40:00'),(36,NULL,NULL,5,5,5,5,'De lo mejor de la Del Valle. Volveremos muy pronto, todo impecable.','2026-06-28 21:30:00');
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
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `feedback_tokens`
--

LOCK TABLES `feedback_tokens` WRITE;
/*!40000 ALTER TABLE `feedback_tokens` DISABLE KEYS */;
INSERT INTO `feedback_tokens` VALUES (1,125,'1c04d1ebc54e66d641079b255e930906',0,'2026-07-30 23:43:11'),(2,122,'d51664bfb7f78ec5f97eac9df96f5c82',0,'2026-08-02 21:19:30'),(3,129,'3de6c8d2f24ee4b3802f5987fa517728',0,'2026-08-02 22:19:56'),(4,121,'2369ed1627d482158477344f773417a2',0,'2026-08-02 22:20:02'),(5,126,'03b5bcd974c5cc742f7c33ef8d87692c',0,'2026-08-02 22:20:08'),(6,127,'5840ae1b20a3c7784130e4bfca48b95d',0,'2026-08-02 22:20:15'),(7,124,'a18387425a3e2a2b571de503c14f2bf4',0,'2026-08-02 22:20:58'),(8,131,'2062e4ccbc96f8a4ccca167468474eb3',0,'2026-08-02 22:24:26'),(9,128,'f3fa7b1f384bf63e00551996cf6e6cdb',0,'2026-08-02 22:25:19'),(10,132,'df40f99427eef87707535136f25a1d06',0,'2026-08-02 22:25:34'),(11,119,'88460eb7ce3eda46b6a258c7d6a1d464',0,'2026-08-02 23:12:49'),(12,130,'0ea86402986c8c9ae23974c27081be42',0,'2026-08-02 23:12:57'),(13,134,'0ab659cc70d67bdeea6e7bb1bcdd337b',0,'2026-08-02 23:13:04'),(14,135,'daf52751e17377921a08b57a284639d5',0,'2026-08-03 00:05:12'),(15,133,'778f93cd56f5aafa62b9af264e3df582',0,'2026-08-03 00:10:38'),(16,136,'e44d0811d8faa9891c2a4b90a1e35a02',0,'2026-08-03 01:31:56');
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
INSERT INTO `gastos_fijos` VALUES (1,'Renta del local','renta',45000.00,1,'2026-07-30 23:10:45','2026-07-30 23:10:45'),(2,'Luz (CFE)','servicios',8000.00,1,'2026-07-30 23:10:45','2026-07-30 23:10:45'),(3,'Agua','servicios',2500.00,1,'2026-07-30 23:10:45','2026-07-30 23:10:45'),(4,'Gas','servicios',4000.00,1,'2026-07-30 23:10:45','2026-07-30 23:10:45'),(5,'Internet y teléfono','servicios',1200.00,1,'2026-07-30 23:10:45','2026-07-30 23:10:45'),(6,'Nómina','nomina',60000.00,1,'2026-07-30 23:10:45','2026-07-30 23:10:45');
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
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `horarios_operacion`
--

LOCK TABLES `horarios_operacion` WRITE;
/*!40000 ALTER TABLE `horarios_operacion` DISABLE KEYS */;
INSERT INTO `horarios_operacion` VALUES (1,0,1,'08:30:00','23:30:00',1,'2026-08-03 00:05:48'),(2,1,1,'13:00:00','22:00:00',1,'2026-08-03 00:05:48'),(3,2,1,'08:30:00','22:00:00',1,'2026-08-03 00:05:48'),(4,3,1,'08:30:00','22:00:00',1,'2026-08-03 00:05:48'),(5,4,1,'08:30:00','22:00:00',1,'2026-08-03 00:05:48'),(6,5,1,'08:30:00','22:00:00',1,'2026-08-03 00:05:48'),(7,6,1,'08:30:00','22:00:00',1,'2026-08-03 00:05:48');
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
INSERT INTO `ingredientes` VALUES (1,'Café molido','g',5000.000,500.000,0.3000,1,'2026-07-30 23:10:45','2026-07-30 23:10:45'),(2,'Agua','ml',100000.000,5000.000,0.0001,1,'2026-07-30 23:10:45','2026-07-30 23:10:45'),(3,'Leche','ml',2100.000,3000.000,0.0250,1,'2026-07-30 23:10:45','2026-07-30 23:10:45'),(4,'Chocolate en polvo','g',250.000,400.000,0.2000,1,'2026-07-30 23:10:45','2026-07-30 23:10:45'),(5,'Azúcar','g',8000.000,800.000,0.0300,1,'2026-07-30 23:10:45','2026-07-30 23:10:45'),(6,'Canela','g',30.000,50.000,0.5000,1,'2026-07-30 23:10:45','2026-07-30 23:10:45'),(7,'Piloncillo','g',3000.000,300.000,0.0600,1,'2026-07-30 23:10:45','2026-07-30 23:10:45'),(8,'Fruta de temporada','g',420.000,600.000,0.0400,1,'2026-07-30 23:10:45','2026-07-30 23:10:45'),(9,'Hielo','g',20000.000,1000.000,0.0050,1,'2026-07-30 23:10:45','2026-07-30 23:10:45');
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
) ENGINE=InnoDB AUTO_INCREMENT=66 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `producto_componentes`
--

LOCK TABLES `producto_componentes` WRITE;
/*!40000 ALTER TABLE `producto_componentes` DISABLE KEYS */;
INSERT INTO `producto_componentes` VALUES (1,66,'subreceta',1,60.000),(2,66,'ingrediente',2,90.000),(4,67,'subreceta',1,60.000),(5,67,'ingrediente',3,120.000),(7,69,'ingrediente',1,15.000),(8,69,'ingrediente',2,200.000),(9,69,'ingrediente',7,25.000),(10,69,'ingrediente',6,2.000),(14,71,'ingrediente',4,30.000),(15,71,'ingrediente',3,200.000),(16,71,'ingrediente',5,10.000),(17,72,'ingrediente',8,120.000),(18,72,'ingrediente',2,250.000),(19,72,'ingrediente',5,20.000),(20,72,'ingrediente',9,100.000);
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
) ENGINE=InnoDB AUTO_INCREMENT=79 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
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
INSERT INTO `reservacion_mesas` VALUES (1,13,1,1,'2026-07-30 23:10:44'),(2,14,2,1,'2026-07-30 23:10:44'),(3,15,3,1,'2026-07-30 23:10:44'),(4,16,4,1,'2026-07-30 23:10:44'),(5,17,1,1,'2026-07-30 23:10:44'),(6,18,1,1,'2026-07-30 23:10:44'),(7,18,2,2,'2026-07-30 23:10:44'),(8,18,3,3,'2026-07-30 23:10:44'),(9,18,4,4,'2026-07-30 23:10:44'),(10,18,5,5,'2026-07-30 23:10:44'),(11,18,6,6,'2026-07-30 23:10:44'),(12,18,7,7,'2026-07-30 23:10:44'),(13,18,8,8,'2026-07-30 23:10:44'),(14,18,9,9,'2026-07-30 23:10:44'),(15,18,10,10,'2026-07-30 23:10:44'),(16,18,11,11,'2026-07-30 23:10:44'),(17,19,1,1,'2026-07-30 23:10:44'),(18,20,5,1,'2026-07-30 23:10:44'),(19,20,11,2,'2026-07-30 23:10:44'),(20,21,8,1,'2026-07-30 23:10:44'),(21,21,9,2,'2026-07-30 23:10:44'),(22,21,10,3,'2026-07-30 23:10:44'),(23,22,1,1,'2026-07-30 23:10:44'),(24,22,2,2,'2026-07-30 23:10:44'),(25,22,3,3,'2026-07-30 23:10:44'),(26,22,4,4,'2026-07-30 23:10:44'),(27,23,2,1,'2026-07-30 23:10:44'),(28,24,2,1,'2026-07-30 23:10:44'),(29,25,3,1,'2026-07-30 23:10:44'),(30,26,4,1,'2026-07-30 23:10:44'),(31,27,5,1,'2026-07-30 23:10:44'),(32,27,6,2,'2026-07-30 23:10:44'),(33,28,6,1,'2026-07-30 23:10:44'),(34,29,7,1,'2026-07-30 23:10:44'),(35,30,9,1,'2026-07-30 23:10:44'),(36,31,10,1,'2026-07-30 23:10:45'),(37,32,11,1,'2026-07-30 23:10:45'),(39,33,8,1,'2026-07-30 23:13:52'),(40,34,2,1,'2026-07-30 23:16:20'),(41,35,3,1,'2026-07-30 23:27:06'),(42,36,4,1,'2026-07-30 23:40:43'),(43,37,1,1,'2026-08-02 04:01:00'),(44,38,10,1,'2026-08-02 04:25:33'),(45,39,4,1,'2026-08-02 21:18:10'),(46,40,1,1,'2026-08-02 22:22:37'),(47,41,10,1,'2026-08-02 22:27:46'),(48,42,10,1,'2026-08-02 23:13:36'),(49,43,11,1,'2026-08-02 23:13:54'),(50,44,2,1,'2026-08-03 00:06:03'),(51,45,3,1,'2026-08-03 00:06:34');
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
  `contacto_tipo` enum('email','telefono') NOT NULL,
  `contacto` varchar(150) NOT NULL,
  `fecha` date NOT NULL,
  `hora` time NOT NULL,
  `comensales` int NOT NULL DEFAULT '2',
  `nota` text,
  `comentario_admin` text,
  `request_token` varchar(64) DEFAULT NULL,
  `request_fingerprint` char(64) DEFAULT NULL,
  `hold_expires_at` datetime DEFAULT NULL,
  `confirmed_at` datetime DEFAULT NULL,
  `arrived_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `status_changed_at` datetime DEFAULT NULL,
  `last_modified_by` int DEFAULT NULL,
  `last_modified_source` enum('cliente','personal','sistema') NOT NULL DEFAULT 'sistema',
  `last_change_reason` varchar(500) DEFAULT NULL,
  `estado` enum('pendiente_verificacion','confirmada','llego','en_curso','completada','cancelada','no_show','expirada') NOT NULL DEFAULT 'pendiente_verificacion',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reservaciones_request_token` (`request_token`),
  KEY `idx_reservaciones_fecha_estado_hora` (`fecha`,`estado`,`hora`),
  KEY `idx_reservaciones_fecha_hora` (`fecha`,`hora`),
  KEY `idx_reservaciones_estado` (`estado`),
  KEY `idx_reservaciones_contacto` (`contacto_tipo`,`contacto`,`estado`,`fecha`,`hora`),
  KEY `idx_reservaciones_retenciones_vencidas` (`estado`,`hold_expires_at`),
  KEY `fk_reservaciones_last_modified_by` (`last_modified_by`),
  CONSTRAINT `fk_reservaciones_last_modified_by` FOREIGN KEY (`last_modified_by`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_reservaciones_fingerprint` CHECK (((`request_fingerprint` is null) or (char_length(`request_fingerprint`) = 64))),
  CONSTRAINT `chk_reservaciones_retencion_vencimiento` CHECK (((`estado` <> _utf8mb4'pendiente_verificacion') or (`hold_expires_at` is not null)))
) ENGINE=InnoDB AUTO_INCREMENT=73 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `reservaciones`
--

LOCK TABLES `reservaciones` WRITE;
/*!40000 ALTER TABLE `reservaciones` DISABLE KEYS */;
INSERT INTO `reservaciones` VALUES (1,'Límite Una','email','limite.una@example.test','2026-11-30','13:00:00',2,'Una activa',NULL,'fx-limite-una-0001',NULL,NULL,'2026-11-30 12:00:00',NULL,NULL,'2026-11-30 12:00:00',NULL,'sistema','Fixture inicial','confirmada','2026-07-30 23:10:44',NULL),(2,'Límite Cuatro 1','email','limite.cuatro@example.test','2026-11-30','14:30:00',2,'',NULL,'fx-limite-cuatro-01',NULL,NULL,'2026-11-30 12:00:00',NULL,NULL,'2026-11-30 12:00:00',NULL,'sistema','Fixture inicial','confirmada','2026-07-30 23:10:44',NULL),(3,'Límite Cuatro 2','email','limite.cuatro@example.test','2026-12-01','15:00:00',2,'',NULL,'fx-limite-cuatro-02',NULL,NULL,'2026-11-30 12:00:00',NULL,NULL,'2026-11-30 12:00:00',NULL,'sistema','Fixture inicial','confirmada','2026-07-30 23:10:44',NULL),(4,'Límite Cuatro 3','email','limite.cuatro@example.test','2026-12-02','16:00:00',2,'',NULL,'fx-limite-cuatro-03',NULL,NULL,'2026-11-30 12:00:00',NULL,NULL,'2026-11-30 12:00:00',NULL,'sistema','Fixture inicial','confirmada','2026-07-30 23:10:44',NULL),(5,'Límite Cuatro 4','email','limite.cuatro@example.test','2026-12-03','17:00:00',2,'',NULL,'fx-limite-cuatro-04',NULL,NULL,'2026-11-30 12:00:00',NULL,NULL,'2026-11-30 12:00:00',NULL,'sistema','Fixture inicial','confirmada','2026-07-30 23:10:44',NULL),(6,'Límite Cinco 1','email','limite.cinco@example.test','2026-11-30','13:30:00',2,'',NULL,'fx-limite-cinco-01',NULL,NULL,'2026-11-30 12:00:00',NULL,NULL,'2026-11-30 12:00:00',NULL,'sistema','Fixture inicial','confirmada','2026-07-30 23:10:44',NULL),(7,'Límite Cinco 2','email','limite.cinco@example.test','2026-11-30','15:00:00',2,'',NULL,'fx-limite-cinco-02',NULL,NULL,'2026-11-30 12:00:00',NULL,NULL,'2026-11-30 12:00:00',NULL,'sistema','Fixture inicial','confirmada','2026-07-30 23:10:44',NULL),(8,'Límite Cinco 3','email','limite.cinco@example.test','2026-12-01','16:30:00',2,'',NULL,'fx-limite-cinco-03',NULL,NULL,'2026-11-30 12:00:00',NULL,NULL,'2026-11-30 12:00:00',NULL,'sistema','Fixture inicial','confirmada','2026-07-30 23:10:44',NULL),(9,'Límite Cinco 4','email','limite.cinco@example.test','2026-12-02','18:00:00',2,'',NULL,'fx-limite-cinco-04',NULL,NULL,'2026-11-30 12:00:00',NULL,NULL,'2026-11-30 12:00:00',NULL,'sistema','Fixture inicial','confirmada','2026-07-30 23:10:44',NULL),(10,'Límite Cinco 5','email','limite.cinco@example.test','2026-12-03','19:30:00',2,'',NULL,'fx-limite-cinco-05',NULL,NULL,'2026-11-30 12:00:00',NULL,NULL,'2026-11-30 12:00:00',NULL,'sistema','Fixture inicial','confirmada','2026-07-30 23:10:44',NULL),(11,'Identidad Teléfono','telefono','+525544442026','2026-12-03','18:30:00',3,'Contacto canónico',NULL,'fx-contacto-tel-001',NULL,NULL,'2026-11-30 12:00:00',NULL,NULL,'2026-11-30 12:00:00',NULL,'sistema','Fixture inicial','confirmada','2026-07-30 23:10:44',NULL),(12,'Histórica','email','historial@example.test','2026-11-27','18:00:00',2,'',NULL,'fx-historica-000001',NULL,NULL,'2026-11-27 17:50:00',NULL,NULL,'2026-11-27 19:30:00',NULL,'sistema','Ticket cerrado','completada','2026-07-30 23:10:44',NULL),(13,'Retención Vigente','email','hold.vigente@example.test','2026-11-30','17:30:00',2,'',NULL,'fx-hold-vigente-001',NULL,'2026-11-30 12:05:00',NULL,NULL,NULL,'2026-11-30 12:00:00',NULL,'cliente','Retención creada para verificación','pendiente_verificacion','2026-07-30 23:10:44',NULL),(14,'Retención Vencida','email','hold.vencida@example.test','2026-11-30','18:00:00',2,'',NULL,'fx-hold-vencida-001',NULL,'2026-11-30 11:59:59',NULL,NULL,NULL,'2026-11-30 12:00:00',NULL,'cliente','Retención creada para verificación','pendiente_verificacion','2026-07-30 23:10:44',NULL),(15,'Modificable','email','modificar@example.test','2026-11-30','18:30:00',2,'Mover a otra hora',NULL,'fx-modificable-0001',NULL,NULL,'2026-11-30 12:00:00',NULL,NULL,'2026-11-30 12:00:00',NULL,'sistema','Fixture inicial','confirmada','2026-07-30 23:10:44',NULL),(16,'Cancelable','email','cancelar@example.test','2026-11-30','19:00:00',2,'',NULL,'fx-cancelable-0001',NULL,NULL,'2026-11-30 12:00:00',NULL,NULL,'2026-11-30 12:00:00',NULL,'sistema','Fixture inicial','confirmada','2026-07-30 23:10:44',NULL),(17,'Sin Capacidad','email','sin.capacidad@example.test','2026-12-01','13:00:00',2,'Conservar al fallar modificación',NULL,'fx-sin-capacidad-01',NULL,NULL,'2026-11-30 12:00:00',NULL,NULL,'2026-11-30 12:00:00',NULL,'sistema','Fixture inicial','confirmada','2026-07-30 23:10:44',NULL),(18,'Bloqueo Total','email','bloqueo@example.test','2026-12-01','20:00:00',44,'Ocupa todas las mesas',NULL,'fx-bloqueo-total-01',NULL,NULL,'2026-11-30 12:00:00',NULL,NULL,'2026-11-30 12:00:00',NULL,'sistema','Fixture inicial','confirmada','2026-07-30 23:10:44',NULL),(19,'Una Mesa','email','una.mesa@example.test','2026-11-30','13:00:00',2,'',NULL,'fx-una-mesa-000001',NULL,NULL,'2026-11-30 12:00:00',NULL,NULL,'2026-11-30 12:00:00',NULL,'sistema','Fixture inicial','confirmada','2026-07-30 23:10:44',NULL),(20,'Dos Mesas','email','dos.mesas@example.test','2026-11-30','14:30:00',6,'',NULL,'fx-dos-mesas-00001',NULL,NULL,'2026-11-30 12:00:00',NULL,NULL,'2026-11-30 12:00:00',NULL,'sistema','Fixture inicial','confirmada','2026-07-30 23:10:44',NULL),(21,'Tres Mesas','email','tres.mesas@example.test','2026-11-30','16:00:00',10,'',NULL,'fx-tres-mesas-0001',NULL,NULL,'2026-11-30 12:00:00',NULL,NULL,'2026-11-30 12:00:00',NULL,'sistema','Fixture inicial','confirmada','2026-07-30 23:10:44',NULL),(22,'Cuatro Mesas Administrativa','email','cuatro.mesas@example.test','2026-12-03','20:00:00',13,'Supera el límite público',NULL,'fx-cuatro-mesas-001',NULL,NULL,'2026-11-30 12:00:00',NULL,NULL,'2026-11-30 12:00:00',NULL,'sistema','Fixture administrativo','confirmada','2026-07-30 23:10:44',NULL),(23,'Consecutiva A','email','consecutiva@example.test','2026-12-03','13:00:00',2,'',NULL,'fx-consecutiva-a-01',NULL,NULL,'2026-11-30 12:00:00',NULL,NULL,'2026-11-30 12:00:00',NULL,'sistema','Fixture inicial','confirmada','2026-07-30 23:10:44',NULL),(24,'Consecutiva B','email','consecutiva@example.test','2026-12-03','15:00:00',2,'',NULL,'fx-consecutiva-b-01',NULL,NULL,'2026-11-30 12:00:00',NULL,NULL,'2026-11-30 12:00:00',NULL,'sistema','Fixture inicial','confirmada','2026-07-30 23:10:44',NULL),(25,'POS Confirmada','email','pos.confirmada@example.test','2026-11-30','19:30:00',2,'',NULL,'fx-pos-confirmada-01',NULL,NULL,'2026-11-30 12:00:00',NULL,NULL,'2026-11-30 12:00:00',NULL,'sistema','Fixture inicial','confirmada','2026-07-30 23:10:44',NULL),(26,'POS Llegó','email','pos.llego@example.test','2026-11-30','20:00:00',2,'',NULL,'fx-pos-llego-000001',NULL,NULL,'2026-11-30 12:00:00','2026-11-30 19:50:00',NULL,'2026-11-30 19:50:00',NULL,'personal','Llegada registrada','llego','2026-07-30 23:10:44',NULL),(27,'POS En Curso','email','pos.encurso@example.test','2026-11-30','20:00:00',6,'',NULL,'fx-pos-encurso-001',NULL,NULL,'2026-11-30 12:00:00','2026-11-30 19:55:00','2026-08-02 17:12:49','2026-08-02 17:12:49',1,'personal','Ticket cerrado','completada','2026-07-30 23:10:44','2026-08-02 23:12:49'),(28,'POS Completada','email','pos.completa@example.test','2026-11-27','18:00:00',2,'',NULL,'fx-pos-completa-001',NULL,NULL,'2026-11-27 17:00:00','2026-11-27 17:55:00','2026-11-27 19:30:00','2026-11-27 19:30:00',NULL,'personal','Ticket cerrado','completada','2026-07-30 23:10:44',NULL),(29,'POS Tolerancia','email','pos.tolerancia@example.test','2026-11-30','20:30:00',2,'',NULL,'fx-pos-tolerancia-1',NULL,NULL,'2026-11-30 12:00:00',NULL,NULL,'2026-11-30 12:00:00',NULL,'sistema','Fixture inicial','confirmada','2026-07-30 23:10:44',NULL),(30,'POS No Show','email','pos.noshow@example.test','2026-11-30','19:00:00',2,'',NULL,'fx-pos-noshow-0001',NULL,NULL,'2026-11-30 12:00:00',NULL,NULL,'2026-11-30 12:00:00',NULL,'personal','Tolerancia vencida','no_show','2026-07-30 23:10:44',NULL),(31,'Reserva Futura','email','pos.futura@example.test','2026-12-01','13:00:00',2,'Advertencia de reserva próxima',NULL,'fx-pos-futura-00001',NULL,NULL,'2026-11-30 12:00:00',NULL,NULL,'2026-11-30 12:00:00',NULL,'sistema','Fixture inicial','confirmada','2026-07-30 23:10:45',NULL),(32,'Horario Afectado','email','horario@example.test','2026-11-30','21:00:00',2,'Conflicto al adelantar el cierre',NULL,'fx-horario-afectado',NULL,NULL,'2026-11-30 12:00:00',NULL,NULL,'2026-11-30 12:00:00',NULL,'sistema','Fixture inicial','confirmada','2026-07-30 23:10:45',NULL),(33,'Leonardo','email','','2026-07-30','19:00:00',2,NULL,NULL,'b372aac419a2583141e292e425c4e656799860c716b66822183cdb6c02e87ae7',NULL,NULL,'2026-07-30 17:12:47','2026-07-30 17:15:52',NULL,'2026-07-30 17:15:52',1,'personal','Servicio iniciado','en_curso','2026-07-30 23:12:47','2026-07-30 23:15:52'),(34,'Leonardo','email','','2026-07-30','17:30:00',2,NULL,NULL,'5319d8e2dff74bf485a0a69e4e6f2075',NULL,NULL,'2026-07-30 17:16:20','2026-07-30 17:16:34','2026-08-02 16:20:58','2026-08-02 16:20:58',1,'personal','Ticket cerrado','completada','2026-07-30 23:16:20','2026-08-02 22:20:58'),(35,'Leonardo Velasco Ojeda','email','leonardo.velasco.oj@gmail.com','2026-07-30','18:00:00',2,'',NULL,'699dcd7cc4611d0b35a9a7642cbce3ad6884b10ccc82ee178aaf18da01b9ab4e','c9a8654e3d30c12bc4a3046603f748458c2e627a18d3171207f26279e5b80235','2026-07-30 17:32:06','2026-07-30 17:27:12',NULL,NULL,'2026-07-30 17:27:12',NULL,'cliente','Contacto verificado mediante OTP','confirmada','2026-07-30 23:27:06','2026-07-30 23:27:12'),(36,'Leonardo','email','','2026-07-30','18:00:00',2,NULL,NULL,'f7dee72d951c4c748362b0fe4b03b2eb',NULL,NULL,'2026-07-30 17:40:43',NULL,NULL,'2026-07-30 17:40:43',NULL,'personal','Asignación automática de mesas','confirmada','2026-07-30 23:40:43','2026-07-30 23:40:43'),(37,'Leonardo Velasco Ojeda','email','leonardo.velasco.oj@gmail.com','2026-08-02','08:30:00',3,'',NULL,'6a47f3dd6e09f74878316d0e516acaeb2596444002e403dd843f09f05a64dc21','d2e5579a05bdbc3ba2ff4c40cd1eb64a236cdb438c38470f9b17f18bd14b5b97','2026-08-01 22:06:00','2026-08-01 22:01:03','2026-08-02 16:23:10','2026-08-02 16:24:26','2026-08-02 16:24:26',1,'personal','Ticket cerrado','completada','2026-08-02 04:01:00','2026-08-02 22:24:26'),(38,'Leonardo Velasco Ojeda','email','leonardo.velasco.oj@gmail.com','2026-08-02','09:00:00',4,'',NULL,'bafde98d923a189ee71f9f78693974f0c12f638cb7f41e3a87a3fe3e0f730ced','43af4d2d9de7ac3b18d21cad2311de66a0c7c29d9752620cb1cd09d64996ee99','2026-08-01 22:30:33',NULL,NULL,NULL,'2026-08-01 22:25:33',NULL,'cliente','Retención creada para verificación','pendiente_verificacion','2026-08-02 04:25:33',NULL),(39,'Leonardo Velasco Ojeda','email','leonardo.velasco.oj@gmail.com','2026-08-02','15:30:00',3,'',NULL,'829ba6f5a51fbd027859510f2803f140c0fd72da8001b67dba21be4feeb1b6a1','ee5b3114acb97e5327ab0386b2b0b62e2cb95ddd54a55df6de14e8a16e5ab35f','2026-08-02 15:23:10','2026-08-02 15:18:12','2026-08-02 17:12:31','2026-08-02 18:05:12','2026-08-02 18:05:12',1,'personal','Ticket cerrado','completada','2026-08-02 21:18:10','2026-08-03 00:05:12'),(40,'Leonardo Velasco Ojeda','email','leonardo.velasco.oj@gmail.com','2026-08-02','17:00:00',4,'',NULL,'983a4fda7c829ca68c55c12af432c050d70cf358112518e5f28780b744767d88','0c39c60676f4c512e31057692456b78af183ba2097225ae33288fdced2cd79ff','2026-08-02 16:27:37','2026-08-02 16:22:39',NULL,NULL,'2026-08-02 19:50:32',1,'personal','Tolerancia vencida','no_show','2026-08-02 22:22:37','2026-08-03 01:50:32'),(41,'Leonardo Velasco Ojeda','email','leonardo.velasco.oj@gmail.com','2026-08-02','16:30:00',2,'',NULL,'55b6f729cdd7589c59d7f2e030d8cf110de64ac65076c1ee939640dcc27c5e30','ce2ba04a4c70734e7c0a6a479719d061b14bc63bb5edb6c7a64d62fb1cb8c085',NULL,'2026-08-02 16:27:46','2026-08-02 17:12:11','2026-08-02 17:13:04','2026-08-02 17:13:04',1,'personal','Ticket cerrado','completada','2026-08-02 22:27:46','2026-08-02 23:13:04'),(42,'Leonardo Velasco Ojeda','email','leonardo.velasco.oj@gmail.com','2026-08-02','17:30:00',3,'',NULL,'dac55083d8cccf6641dac5df8a4a10090aedf7d4c89da4c22ffa44cce72a22c1','ab41ae81735f4075b71ab5d788a9b040c206deffbdfb7c59417b42b437467a27','2026-08-02 17:18:36','2026-08-02 17:13:39','2026-08-02 17:17:55',NULL,'2026-08-02 17:17:55',1,'personal','Llegada registrada','llego','2026-08-02 23:13:36','2026-08-02 23:17:55'),(43,'Leonardo Velasco Ojeda','email','leonardo.velasco.oj@gmail.com','2026-08-02','18:00:00',4,'',NULL,'b1fce311b2301677b3222f92144fa2b1d75eb95d680fd9f52e57d5bf3c5ca2b9','f0f9c8b11de1651bf3cbe1559756295ceb020fa3d239428f6d7b5c4c1611cf20',NULL,'2026-08-02 17:13:54',NULL,NULL,'2026-08-02 19:50:38',1,'personal','Tolerancia vencida','no_show','2026-08-02 23:13:54','2026-08-03 01:50:38'),(44,'Leonardo Velasco Ojeda','email','leonardo.velasco.oj@gmail.com','2026-08-02','18:30:00',3,'',NULL,'3a88894f2b41a87f47b221d8aac38769f57c11031b31fc858ad2357ac70e83b9','9cd22432a1de942f3c61d95eda5d914d9e69f3f53355e2a430ee5e9a63453b5f','2026-08-02 18:11:03','2026-08-02 18:06:05','2026-08-02 19:38:28',NULL,'2026-08-02 19:38:28',1,'personal','Servicio iniciado','en_curso','2026-08-03 00:06:03','2026-08-03 01:38:28'),(45,'Leonardo Velasco Ojeda','email','leonardo.velasco.oj@gmail.com','2026-08-02','19:00:00',2,'',NULL,'ab75dcdff6cc8f05411faa39453bedad42d5880a603bb422f41e91ffcaa9ace2','e5274ef32a5f7aec91b9073624788302edeaa9922d8c0352b18b8ec9a93febf2',NULL,'2026-08-02 18:06:34',NULL,NULL,'2026-08-02 19:50:34',1,'personal','Tolerancia vencida','no_show','2026-08-03 00:06:34','2026-08-03 01:50:34');
/*!40000 ALTER TABLE `reservaciones` ENABLE KEYS */;
UNLOCK TABLES;

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
INSERT INTO `subrecetas` VALUES (1,'Shot de espresso','ml',60.000,1,'2026-07-30 23:10:45','2026-07-30 23:10:45');
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
) ENGINE=InnoDB AUTO_INCREMENT=116 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ticket_items`
--

LOCK TABLES `ticket_items` WRITE;
/*!40000 ALTER TABLE `ticket_items` DISABLE KEYS */;
INSERT INTO `ticket_items` VALUES (1,1,'Toast de Salmón Ahumado',230.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-06-18 20:10:00'),(2,1,'Cappuccino',75.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-06-18 20:10:00'),(3,2,'Enchiladas Suizas',220.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-06-18 20:45:00'),(4,2,'Jugo Verde',95.00,'Jugos & Smoothies',2,NULL,2,NULL,'entregado','2026-06-18 20:45:00'),(5,2,'Café Americano',65.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-06-18 20:52:00'),(6,3,'Rib Eye (450 grs.)',785.00,'Platos Fuertes',3,NULL,1,NULL,'entregado','2026-06-19 02:15:00'),(7,3,'Milano',260.00,'Pizzas',4,NULL,2,NULL,'entregado','2026-06-19 02:15:00'),(8,3,'Camarones al Ajillo',210.00,'Entradas',3,NULL,1,NULL,'entregado','2026-06-19 02:15:00'),(9,3,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,4,NULL,'entregado','2026-06-19 02:16:00'),(10,5,'Filete de Res en su Jugo',320.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-06-19 03:10:00'),(11,5,'Queso Burrata con Jitomates Cherrys',210.00,'Entradas',4,NULL,1,NULL,'entregado','2026-06-19 03:10:00'),(12,8,'Hamburguesa de la Casa',260.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-06-18 21:20:00'),(13,8,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-06-18 21:20:00'),(14,8,'Refresco',55.00,'Café & Bebidas',1,NULL,4,NULL,'entregado','2026-06-18 21:21:00'),(15,9,'Café Americano',65.00,'Café & Bebidas',1,NULL,2,NULL,'enviado','2026-07-30 23:08:44'),(16,9,'Molletes',100.00,'Desayunos',3,NULL,1,NULL,'enviado','2026-07-30 23:08:44'),(17,10,'Rigatoni al Limón con Camarones y Parmesano',280.00,'Pastas',3,NULL,2,NULL,'entregado','2026-07-30 22:31:44'),(18,10,'Milano',260.00,'Pizzas',4,NULL,1,NULL,'listo','2026-07-30 22:32:44'),(19,10,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,4,NULL,'entregado','2026-07-30 22:31:44'),(20,11,'Chilaquiles',180.00,'Desayunos',3,NULL,2,NULL,'en_preparacion','2026-07-30 22:54:44'),(21,11,'Jugo Verde',95.00,'Jugos & Smoothies',2,NULL,3,NULL,'entregado','2026-07-30 22:54:44'),(22,11,'Cappuccino',75.00,'Café & Bebidas',1,NULL,1,NULL,'listo','2026-07-30 22:55:44'),(23,12,'Toast de Salmón Ahumado',230.00,'Desayunos',3,NULL,2,NULL,'enviado','2026-07-30 23:05:44'),(24,12,'Jugo de Naranja',85.00,'Jugos & Smoothies',2,NULL,2,NULL,'enviado','2026-07-30 23:05:44'),(25,13,'Filete de Res en su Jugo',320.00,'Platos Fuertes',3,NULL,2,NULL,'en_preparacion','2026-07-30 22:39:44'),(26,13,'Aros de Calamar',210.00,'Entradas',3,NULL,1,NULL,'entregado','2026-07-30 22:39:44'),(27,13,'Refresco',55.00,'Café & Bebidas',1,NULL,4,NULL,'entregado','2026-07-30 22:40:44'),(28,14,'Burrata',260.00,'Pizzas',4,NULL,2,NULL,'en_preparacion','2026-07-30 22:50:44'),(29,14,'Tabla Mixta',320.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-07-30 22:50:44'),(30,14,'Agua Fresca',60.00,'Café & Bebidas',1,NULL,4,NULL,'entregado','2026-07-30 22:51:44'),(31,15,'Rib Eye (450 grs.)',785.00,'Platos Fuertes',3,NULL,1,NULL,'entregado','2026-07-30 22:27:44'),(32,15,'Vacío en Escalopas',280.00,'Platos Fuertes',3,NULL,1,NULL,'entregado','2026-07-30 22:27:44'),(33,15,'Espárragos al Horno',180.00,'Entradas',4,NULL,1,NULL,'listo','2026-07-30 22:28:44'),(34,15,'Café de Olla',65.00,'Café & Bebidas',1,NULL,3,NULL,'entregado','2026-07-30 22:30:44'),(35,4,'Chilaquiles',180.00,'Desayunos',3,NULL,2,NULL,'en_preparacion','2026-07-30 23:00:44'),(36,4,'Café de Olla',65.00,'Café & Bebidas',1,NULL,2,NULL,'enviado','2026-07-30 23:00:44'),(37,16,'Salmón al Horno',295.00,'Platos Fuertes',3,NULL,1,NULL,'listo','2026-07-30 22:43:44'),(38,16,'Frutos Rojos',210.00,'Ensaladas',3,NULL,1,NULL,'entregado','2026-07-30 22:43:44'),(39,16,'Té / Infusión',65.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-07-30 22:44:44'),(40,17,'Mix de 3 Brusquetas',160.00,'Para Picar',3,NULL,2,NULL,'enviado','2026-07-30 23:06:44'),(41,17,'Smoothie de Fresa',100.00,'Jugos & Smoothies',2,NULL,2,NULL,'enviado','2026-07-30 23:06:44'),(42,17,'Latte',80.00,'Café & Bebidas',1,NULL,2,NULL,'enviado','2026-07-30 23:07:44'),(43,6,'Burrata',260.00,'Pizzas',4,NULL,2,NULL,'en_preparacion','2026-07-30 22:45:44'),(44,6,'Aros de Calamar',210.00,'Entradas',3,NULL,1,NULL,'listo','2026-07-30 22:45:44'),(45,6,'Agua de Coco',90.00,'Jugos & Smoothies',2,NULL,4,NULL,'entregado','2026-07-30 22:46:44'),(46,18,'Camarones a los 4 Quesos',260.00,'Pizzas',4,NULL,2,NULL,'en_preparacion','2026-07-30 22:57:44'),(47,18,'Aceitunas Temperadas con Aceite de Chile',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-07-30 22:57:44'),(48,18,'Cappuccino',75.00,'Café & Bebidas',1,NULL,5,NULL,'listo','2026-07-30 22:58:44'),(49,19,'Café Americano',65.00,'Café & Bebidas',1,NULL,1,NULL,'enviado','2026-07-30 23:09:44'),(50,19,'Croissant con Jamón de Pavo',165.00,'Desayunos',3,NULL,1,NULL,'enviado','2026-07-30 23:09:44'),(51,20,'Baguette de Cochinita',210.00,'Desayunos',3,NULL,2,NULL,'en_preparacion','2026-07-30 23:02:44'),(52,20,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,1,NULL,'enviado','2026-07-30 23:02:44'),(53,20,'Agua de Coco',90.00,'Jugos & Smoothies',2,NULL,1,NULL,'listo','2026-07-30 23:03:44'),(54,21,'Hamburguesa de la Casa',260.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-07-30 22:36:44'),(55,21,'Tacos de Cochinita',210.00,'Platos Fuertes',3,NULL,1,NULL,'entregado','2026-07-30 22:36:44'),(56,21,'Chocolate Caliente',80.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-07-30 22:37:44'),(57,21,'Margarita',190.00,'Pizzas',4,NULL,1,NULL,'listo','2026-07-30 22:37:44'),(58,22,'Lasagna de Filete de Res',280.00,'Pastas',3,NULL,2,NULL,'en_preparacion','2026-07-30 22:48:44'),(59,22,'Carpaccio de Salmón',180.00,'Entradas',3,NULL,1,NULL,'entregado','2026-07-30 22:48:44'),(60,22,'Copa Antioxidante',130.00,'Desayunos',2,NULL,1,NULL,'entregado','2026-07-30 22:49:44'),(61,101,'Enmoladas',240.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-05-08 15:10:00'),(62,101,'Enchiladas Suizas',220.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-05-08 15:10:00'),(63,101,'Cappuccino',75.00,'Café & Bebidas',1,NULL,2,NULL,'entregado','2026-05-08 15:11:00'),(64,102,'Enmoladas',240.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-05-22 15:40:00'),(65,102,'Enchiladas Suizas',220.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-05-22 15:40:00'),(66,103,'Enmoladas',240.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-06-05 15:10:00'),(67,103,'Café Americano',65.00,'Café & Bebidas',1,NULL,1,NULL,'entregado','2026-06-05 15:11:00'),(68,104,'Spaguetti a la Boloñesa',280.00,'Pastas',3,NULL,2,NULL,'entregado','2026-05-11 20:10:00'),(69,104,'Mix de 3 Brusquetas',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-05-11 20:10:00'),(70,104,'Limonada Natural',75.00,'Jugos & Smoothies',2,NULL,4,NULL,'entregado','2026-05-11 20:11:00'),(71,105,'Spaguetti a la Boloñesa',280.00,'Pastas',3,NULL,1,NULL,'entregado','2026-05-27 20:10:00'),(72,105,'Mix de 3 Brusquetas',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-05-27 20:10:00'),(73,106,'Spaguetti a la Boloñesa',280.00,'Pastas',3,NULL,2,NULL,'entregado','2026-06-10 19:40:00'),(74,106,'Crema del Día',180.00,'Sopas & Cremas',3,NULL,1,NULL,'entregado','2026-06-10 19:40:00'),(75,107,'Margarita',190.00,'Pizzas',4,NULL,3,NULL,'entregado','2026-05-09 19:10:00'),(76,107,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-05-09 19:10:00'),(77,108,'Margarita',190.00,'Pizzas',4,NULL,2,NULL,'entregado','2026-05-30 19:40:00'),(78,108,'Papas a la Francesa con Parmesano',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-05-30 19:40:00'),(79,109,'Margarita',190.00,'Pizzas',4,NULL,3,NULL,'entregado','2026-06-13 19:10:00'),(80,109,'Milano',260.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-06-13 19:10:00'),(81,110,'Rib Eye (450 grs.)',785.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-05-15 01:10:00'),(82,110,'Aceitunas Temperadas con Aceite de Chile',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-05-15 01:10:00'),(83,111,'Rib Eye (450 grs.)',785.00,'Platos Fuertes',3,NULL,1,NULL,'entregado','2026-05-29 01:40:00'),(84,111,'Aceitunas Temperadas con Aceite de Chile',160.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-05-29 01:40:00'),(85,112,'Rib Eye (450 grs.)',785.00,'Platos Fuertes',3,NULL,2,NULL,'entregado','2026-06-12 01:10:00'),(86,112,'Filete de Res en su Jugo',320.00,'Platos Fuertes',3,NULL,1,NULL,'entregado','2026-06-12 01:10:00'),(87,113,'Frutos Rojos',210.00,'Ensaladas',3,NULL,1,NULL,'entregado','2026-05-15 21:10:00'),(88,113,'Crema del Día',180.00,'Sopas & Cremas',3,NULL,1,NULL,'entregado','2026-05-15 21:10:00'),(89,114,'Frutos Rojos',210.00,'Ensaladas',3,NULL,1,NULL,'entregado','2026-05-29 21:40:00'),(90,114,'Crema del Día',180.00,'Sopas & Cremas',3,NULL,1,NULL,'entregado','2026-05-29 21:40:00'),(91,115,'Frutos Rojos',210.00,'Ensaladas',3,NULL,2,NULL,'entregado','2026-06-12 21:10:00'),(92,115,'Jugo Verde',95.00,'Jugos & Smoothies',2,NULL,2,NULL,'entregado','2026-06-12 21:11:00'),(93,116,'Tabla Mixta',320.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-05-17 02:10:00'),(94,116,'Burrata',260.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-05-17 02:10:00'),(95,117,'Tabla Mixta',320.00,'Para Picar',3,NULL,2,NULL,'entregado','2026-06-01 02:40:00'),(96,117,'Burrata',260.00,'Pizzas',4,NULL,1,NULL,'entregado','2026-06-01 02:40:00'),(97,118,'Tabla Mixta',320.00,'Para Picar',3,NULL,1,NULL,'entregado','2026-06-15 02:10:00'),(98,118,'Aros de Calamar',210.00,'Entradas',3,NULL,1,NULL,'entregado','2026-06-15 02:10:00'),(99,125,'Enchiladas Suizas',220.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-07-30 23:42:44'),(100,128,'Enmoladas',240.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-07-31 00:33:46'),(101,128,'Enchiladas Suizas',220.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-07-31 00:33:46'),(102,122,'Enchiladas Suizas',220.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-08-02 21:19:06'),(103,129,'Enchiladas Suizas',220.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-08-02 22:18:44'),(104,121,'Enmoladas',240.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-08-02 22:19:09'),(105,126,'Enmoladas',240.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-08-02 22:19:27'),(106,127,'Enmoladas',240.00,'Desayunos',3,NULL,2,NULL,'entregado','2026-08-02 22:19:40'),(107,124,'Cecina y Huevo con Chorizo',220.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-08-02 22:20:26'),(108,130,'Enmoladas',240.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-08-02 22:21:17'),(109,131,'Enmoladas',240.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-08-02 22:23:19'),(110,132,'Enmoladas',240.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-08-02 22:25:11'),(111,119,'Enchiladas Suizas',220.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-08-02 22:25:25'),(112,134,'Enmoladas',240.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-08-02 23:12:20'),(113,135,'Enmoladas',240.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-08-02 23:12:37'),(114,133,'Enmoladas',240.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-08-03 00:09:48'),(115,136,'Enmoladas',240.00,'Desayunos',3,NULL,1,NULL,'entregado','2026-08-03 01:31:37');
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
) ENGINE=InnoDB AUTO_INCREMENT=85 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ticket_mesas`
--

LOCK TABLES `ticket_mesas` WRITE;
/*!40000 ALTER TABLE `ticket_mesas` DISABLE KEYS */;
INSERT INTO `ticket_mesas` VALUES (1,1,1,1,'2026-07-30 23:10:44'),(2,2,3,1,'2026-07-30 23:10:44'),(3,3,6,1,'2026-07-30 23:10:44'),(4,5,2,1,'2026-07-30 23:10:44'),(5,7,5,1,'2026-07-30 23:10:44'),(6,8,7,1,'2026-07-30 23:10:44'),(7,9,1,1,'2026-07-30 23:10:44'),(8,10,2,1,'2026-07-30 23:10:44'),(9,11,3,1,'2026-07-30 23:10:44'),(10,12,4,1,'2026-07-30 23:10:44'),(11,13,5,1,'2026-07-30 23:10:44'),(12,14,6,1,'2026-07-30 23:10:44'),(13,15,7,1,'2026-07-30 23:10:44'),(14,4,8,1,'2026-07-30 23:10:44'),(15,16,9,1,'2026-07-30 23:10:44'),(16,17,10,1,'2026-07-30 23:10:44'),(17,6,11,1,'2026-07-30 23:10:44'),(18,18,12,1,'2026-07-30 23:10:44'),(19,19,13,1,'2026-07-30 23:10:44'),(20,20,14,1,'2026-07-30 23:10:44'),(21,21,15,1,'2026-07-30 23:10:44'),(22,22,16,1,'2026-07-30 23:10:44'),(23,101,5,1,'2026-07-30 23:10:44'),(24,102,5,1,'2026-07-30 23:10:44'),(25,103,1,1,'2026-07-30 23:10:44'),(26,104,3,1,'2026-07-30 23:10:44'),(27,105,3,1,'2026-07-30 23:10:44'),(28,106,4,1,'2026-07-30 23:10:44'),(29,107,6,1,'2026-07-30 23:10:44'),(30,108,6,1,'2026-07-30 23:10:44'),(31,109,7,1,'2026-07-30 23:10:44'),(32,110,2,1,'2026-07-30 23:10:44'),(33,111,2,1,'2026-07-30 23:10:44'),(34,112,4,1,'2026-07-30 23:10:44'),(35,113,8,1,'2026-07-30 23:10:44'),(36,114,8,1,'2026-07-30 23:10:44'),(37,115,9,1,'2026-07-30 23:10:44'),(38,116,11,1,'2026-07-30 23:10:44'),(39,117,10,1,'2026-07-30 23:10:44'),(40,118,11,1,'2026-07-30 23:10:44'),(41,119,5,1,'2026-07-30 23:10:44'),(42,119,6,2,'2026-07-30 23:10:44'),(43,120,6,1,'2026-07-30 23:10:44'),(44,121,10,1,'2026-07-30 23:10:45'),(45,122,1,1,'2026-07-30 23:10:45'),(46,122,11,2,'2026-07-30 23:10:45'),(47,123,8,1,'2026-07-30 23:15:52'),(48,124,2,1,'2026-07-30 23:16:34'),(49,125,9,1,'2026-07-30 23:42:19'),(50,126,9,1,'2026-07-30 23:44:05'),(51,127,7,1,'2026-07-30 23:46:51'),(52,128,3,1,'2026-07-31 00:32:58'),(53,129,11,1,'2026-08-02 21:19:48'),(54,130,7,1,'2026-08-02 22:21:05'),(55,130,9,2,'2026-08-02 22:21:05'),(56,131,1,1,'2026-08-02 22:23:14'),(57,132,1,1,'2026-08-02 22:24:57'),(58,133,1,1,'2026-08-02 22:27:27'),(59,134,10,1,'2026-08-02 23:12:11'),(60,135,4,1,'2026-08-02 23:12:34'),(61,136,6,1,'2026-08-03 01:31:32'),(62,136,9,2,'2026-08-03 01:31:32'),(63,137,2,1,'2026-08-03 01:38:28');
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
) ENGINE=InnoDB AUTO_INCREMENT=152 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tickets`
--

LOCK TABLES `tickets` WRITE;
/*!40000 ALTER TABLE `tickets` DISABLE KEYS */;
INSERT INTO `tickets` VALUES (1,2,'Camila Estrada','2026-07-28 14:05:00','2026-07-28 15:35:00','2026-07-28 15:35:00','cerrado','tarjeta',64.60,NULL,3),(2,4,'Javier Montiel','2026-07-27 14:40:00','2026-07-27 16:10:00','2026-07-27 16:10:00','cerrado','efectivo',91.20,NULL,4),(3,6,'Familia Guerrero','2026-07-26 20:10:00','2026-07-26 21:40:00','2026-07-26 21:40:00','cerrado','tarjeta',308.55,NULL,3),(4,2,'Sofía Pedraza','2026-07-25 16:58:44','2026-07-25 18:28:44','2026-07-25 18:28:44','cerrado',NULL,0.00,NULL,NULL),(5,4,'Nicolás Andrade','2026-07-24 21:05:00','2026-07-24 22:35:00','2026-07-24 22:35:00','cerrado','tarjeta',102.00,NULL,4),(6,4,'Fernanda & Roque','2026-07-23 16:43:44','2026-07-23 18:13:44','2026-07-23 18:13:44','cerrado',NULL,0.00,NULL,NULL),(7,3,'Mesa 5','2026-06-18 16:00:00',NULL,NULL,'cancelado','efectivo',0.00,NULL,NULL),(8,4,'Grupo Torres','2026-07-21 15:15:00','2026-07-21 16:45:00','2026-07-21 16:45:00','cerrado','efectivo',84.80,NULL,6),(9,2,'Ana Villalobos','2026-07-20 17:07:44','2026-07-20 18:37:44','2026-07-20 18:37:44','cerrado',NULL,0.00,NULL,NULL),(10,4,'Renata Ibáñez','2026-07-19 16:29:44','2026-07-19 17:59:44','2026-07-19 17:59:44','cerrado',NULL,0.00,NULL,NULL),(11,3,'Javier Montiel','2026-07-18 16:52:44','2026-07-18 18:22:44','2026-07-18 18:22:44','cerrado',NULL,0.00,NULL,NULL),(12,2,'Diego Lozano','2026-07-17 17:03:44','2026-07-17 18:33:44','2026-07-17 18:33:44','cerrado',NULL,0.00,NULL,NULL),(13,4,'Familia Cuevas','2026-07-16 16:37:44','2026-07-16 18:07:44','2026-07-16 18:07:44','cerrado',NULL,0.00,NULL,NULL),(14,4,'Familia Guerrero','2026-07-15 16:48:44','2026-07-15 18:18:44','2026-07-15 18:18:44','cerrado',NULL,0.00,NULL,NULL),(15,3,'Grupo Salinas','2026-07-14 16:25:44','2026-07-14 17:55:44','2026-07-14 17:55:44','cerrado',NULL,0.00,NULL,NULL),(16,2,'Mauricio Trejo','2026-07-13 16:41:44','2026-07-13 18:11:44','2026-07-13 18:11:44','cerrado',NULL,0.00,NULL,NULL),(17,4,'Lucía Bermúdez','2026-07-12 17:05:44','2026-07-12 18:35:44','2026-07-12 18:35:44','cerrado',NULL,0.00,NULL,NULL),(18,5,'Grupo Peralta','2026-07-11 16:55:44','2026-07-11 18:25:44','2026-07-11 18:25:44','cerrado',NULL,0.00,NULL,NULL),(19,1,'Caja','2026-07-10 17:09:44','2026-07-10 18:39:44','2026-07-10 18:39:44','cerrado',NULL,0.00,NULL,NULL),(20,1,'Llevar','2026-07-09 17:01:44','2026-07-09 18:31:44','2026-07-09 18:31:44','cerrado',NULL,0.00,NULL,NULL),(21,4,'Tomás Iriarte','2026-07-08 16:34:44','2026-07-08 18:04:44','2026-07-08 18:04:44','cerrado',NULL,0.00,NULL,NULL),(22,3,'Paulina Cortés','2026-07-07 16:46:44','2026-07-07 18:16:44','2026-07-07 18:16:44','cerrado',NULL,0.00,NULL,NULL),(101,2,'Camila Estrada','2026-06-16 09:05:00','2026-06-16 10:35:00','2026-06-16 10:35:00','cerrado','tarjeta',103.70,NULL,3),(102,2,'Camila Estrada','2026-06-15 09:35:00','2026-06-15 11:05:00','2026-06-15 11:05:00','cerrado','tarjeta',78.20,NULL,3),(103,2,'Camila Estrada','2026-06-14 09:05:00','2026-06-14 10:35:00','2026-06-14 10:35:00','cerrado','tarjeta',51.85,NULL,3),(104,4,'Javier Montiel','2026-06-13 14:05:00','2026-06-13 15:35:00','2026-06-13 15:35:00','cerrado','efectivo',173.40,NULL,3),(105,3,'Javier Montiel','2026-06-12 14:05:00','2026-06-12 15:35:00','2026-06-12 15:35:00','cerrado','tarjeta',74.80,NULL,3),(106,4,'Javier Montiel','2026-06-11 13:35:00','2026-06-11 15:05:00','2026-06-11 15:05:00','cerrado','efectivo',125.80,NULL,3),(107,6,'Familia Guerrero','2026-06-10 13:05:00','2026-06-10 14:35:00','2026-06-10 14:35:00','cerrado','tarjeta',106.80,NULL,4),(108,5,'Familia Guerrero','2026-06-09 13:35:00','2026-06-09 15:05:00','2026-06-09 15:05:00','cerrado','tarjeta',64.80,NULL,4),(109,6,'Familia Guerrero','2026-06-08 13:05:00','2026-06-08 14:35:00','2026-06-08 14:35:00','cerrado','tarjeta',99.60,NULL,4),(110,4,'Nicolas Andrade','2026-06-07 19:05:00','2026-06-07 20:35:00','2026-06-07 20:35:00','cerrado','tarjeta',207.60,NULL,4),(111,2,'Nicolas Andrade','2026-06-06 19:35:00','2026-06-06 21:05:00','2026-06-06 21:05:00','cerrado','tarjeta',113.40,NULL,4),(112,4,'Nicolas Andrade','2026-06-05 19:05:00','2026-06-05 20:35:00','2026-06-05 20:35:00','cerrado','tarjeta',226.80,NULL,4),(113,2,'Sofia Pedraza','2026-06-04 15:05:00','2026-06-04 16:35:00','2026-06-04 16:35:00','cerrado','efectivo',31.20,NULL,6),(114,2,'Sofia Pedraza','2026-06-03 15:35:00','2026-06-03 17:05:00','2026-06-03 17:05:00','cerrado','tarjeta',31.20,NULL,6),(115,3,'Sofia Pedraza','2026-06-02 15:05:00','2026-06-02 16:35:00','2026-06-02 16:35:00','cerrado','tarjeta',48.80,NULL,6),(116,2,'Fernanda & Roque','2026-07-29 20:05:00','2026-07-29 21:35:00','2026-07-29 21:35:00','cerrado','tarjeta',46.40,NULL,6),(117,4,'Fernanda & Roque','2026-07-28 20:35:00','2026-07-28 22:05:00','2026-07-28 22:05:00','cerrado','tarjeta',72.00,NULL,6),(118,2,'Fernanda & Roque','2026-07-27 20:05:00','2026-07-27 21:35:00','2026-07-27 21:35:00','cerrado','tarjeta',42.40,NULL,6),(119,6,'POS En Curso','2026-11-30 20:00:00','2026-08-02 17:12:49','2026-08-02 17:12:49','cerrado','efectivo',0.00,27,NULL),(120,2,'POS Completada','2026-11-27 18:00:00','2026-11-27 19:30:00',NULL,'cerrado','efectivo',0.00,28,NULL),(121,2,'Walk-in Una Mesa','2026-11-30 20:10:00','2026-08-02 16:20:02','2026-08-02 16:20:02','cerrado','efectivo',0.00,NULL,NULL),(122,6,'Walk-in Varias Mesas','2026-11-30 20:15:00','2026-08-02 15:19:30','2026-08-02 15:19:30','cerrado','efectivo',0.00,NULL,NULL),(123,2,'Leonardo','2026-07-30 17:15:52',NULL,NULL,'abierto',NULL,0.00,33,NULL),(124,2,'Leonardo','2026-07-30 17:16:34','2026-08-02 16:20:58','2026-08-02 16:20:58','cerrado','efectivo',0.00,34,NULL),(125,2,NULL,'2026-07-30 17:42:19','2026-07-30 17:43:11','2026-07-30 17:43:11','cerrado','efectivo',0.00,NULL,NULL),(126,8,NULL,'2026-07-30 17:44:05','2026-08-02 16:20:08','2026-08-02 16:20:08','cerrado','efectivo',0.00,NULL,NULL),(127,2,NULL,'2026-07-30 17:46:51','2026-08-02 16:20:15','2026-08-02 16:20:15','cerrado','efectivo',0.00,NULL,NULL),(128,2,NULL,'2026-07-30 18:32:58','2026-08-02 16:25:19','2026-08-02 16:25:19','cerrado','efectivo',0.00,NULL,NULL),(129,2,NULL,'2026-08-02 15:19:48','2026-08-02 16:19:56','2026-08-02 16:19:56','cerrado','efectivo',0.00,NULL,NULL),(130,2,NULL,'2026-08-02 16:21:05','2026-08-02 17:12:57','2026-08-02 17:12:57','cerrado','efectivo',0.00,NULL,NULL),(131,3,'Leonardo Velasco Ojeda','2026-08-02 16:23:14','2026-08-02 16:24:26','2026-08-02 16:24:26','cerrado','efectivo',0.00,37,NULL),(132,2,NULL,'2026-08-02 16:24:57','2026-08-02 16:25:34','2026-08-02 16:25:34','cerrado','efectivo',0.00,NULL,NULL),(133,2,NULL,'2026-08-02 16:27:27','2026-08-02 18:10:38','2026-08-02 18:10:38','cerrado','efectivo',0.00,NULL,NULL),(134,2,'Leonardo Velasco Ojeda','2026-08-02 17:12:11','2026-08-02 17:13:04','2026-08-02 17:13:04','cerrado','efectivo',0.00,41,NULL),(135,3,'Leonardo Velasco Ojeda','2026-08-02 17:12:34','2026-08-02 18:05:12','2026-08-02 18:05:12','cerrado','efectivo',0.00,39,NULL),(136,2,NULL,'2026-08-02 19:31:32','2026-08-02 19:31:56','2026-08-02 19:31:56','cerrado','efectivo',0.00,NULL,NULL),(137,3,'Leonardo Velasco Ojeda','2026-08-02 19:38:28',NULL,NULL,'abierto',NULL,0.00,44,NULL);
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
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `usuarios`
--

LOCK TABLES `usuarios` WRITE;
/*!40000 ALTER TABLE `usuarios` DISABLE KEYS */;
INSERT INTO `usuarios` VALUES (1,'admin_demo','Administrador Demo','$2y$12$qH/BVO2OPCYRbt7rUfYtIecXWTXOSk8hxWavaadrcfbwEnIHsXXd.',NULL,'admin',1,'2026-07-30 23:10:44',NULL),(2,'observador1','Observador General','$2y$10$wH8Lm8rMjYpPqOQF3AbY3eGy7PV8wzg6kgAYZ3i.E2oQ1FjiZ3Xj2','$2y$12$cn/3L8mkab6QsELxVwjUY.l9X32LeGBtHW0r0MKQEW/LH9doaPgoa','observer',1,'2026-07-30 23:10:44','2026-07-30 23:10:44'),(3,'mesero1','Carlos Hernández','$2y$10$wH8Lm8rMjYpPqOQF3AbY3eGy7PV8wzg6kgAYZ3i.E2oQ1FjiZ3Xj2','$2y$12$Jkhr3umCEYaNQY4OSGedgOu5eHImaGx1PtjXSMY9hXn3Zqu1OmReW','waiter',1,'2026-07-30 23:10:44','2026-07-30 23:10:44'),(4,'mesero2','Valeria Ríos','$2y$10$wH8Lm8rMjYpPqOQF3AbY3eGy7PV8wzg6kgAYZ3i.E2oQ1FjiZ3Xj2',NULL,'waiter',1,'2026-07-30 23:10:44',NULL),(5,'cajero1','Mariana López','$2y$10$wH8Lm8rMjYpPqOQF3AbY3eGy7PV8wzg6kgAYZ3i.E2oQ1FjiZ3Xj2','$2y$12$bb8wu.UY6FK8vBzU4E5X6uAZq3lZwzfSOn4kXcG9vRuV9eFMXF1MW','cashier',1,'2026-07-30 23:10:44','2026-07-30 23:10:44'),(6,'mesero3','Emilio Cárdenas','$2y$10$wH8Lm8rMjYpPqOQF3AbY3eGy7PV8wzg6kgAYZ3i.E2oQ1FjiZ3Xj2',NULL,'waiter',1,'2026-07-30 23:10:44',NULL),(7,'mesero_inactivo','Daniel Torres','$2y$10$wH8Lm8rMjYpPqOQF3AbY3eGy7PV8wzg6kgAYZ3i.E2oQ1FjiZ3Xj2',NULL,'waiter',0,'2026-07-30 23:10:44',NULL);
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
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `verificaciones_contacto`
--

LOCK TABLES `verificaciones_contacto` WRITE;
/*!40000 ALTER TABLE `verificaciones_contacto` DISABLE KEYS */;
INSERT INTO `verificaciones_contacto` VALUES (1,35,'email','leonardo.velasco.oj@gmail.com','$2y$10$C74zFzBH05Glw2NsdOh25uELlClfPEfMM8Jx3zo.jakoOiSljv0ZW','2026-07-30 17:32:06',0,'2026-07-30 17:27:12',NULL,'2026-07-30 23:27:06'),(2,NULL,'email','leonardo.velasco.oj@gmail.com','$2y$10$G3Hq/m.3mR/Mc8QJ7KSYZuaeoXud/3PFmEj.pk02f9YtnU3Ysue1a','2026-07-30 17:34:40',0,'2026-07-30 17:29:42',NULL,'2026-07-30 23:29:40'),(3,NULL,'telefono','+525578577637','$2y$10$/nZQJ84R8Wwvvh5IhMf9nOl/XJmAt2hAL.izN7ZmLZXlMOQLJWyVi','2026-07-30 17:34:56',0,'2026-07-30 17:29:59',NULL,'2026-07-30 23:29:56'),(4,NULL,'email','leonardo.velasco.oj@gmail.com','$2y$10$bTtPWDQxJo4rcc1I3XAKJOokFeg39WVfJrcWD8jMAKxIFUYJIp6QW','2026-07-30 17:39:48',0,'2026-07-30 17:34:50',NULL,'2026-07-30 23:34:48'),(5,37,'email','leonardo.velasco.oj@gmail.com','$2y$10$y.0r5v2biti7v.BjsYN5eOoWEuEJ.olHIKHRCiea6nmD3jpaJ4CDm','2026-08-01 22:06:00',0,'2026-08-01 22:01:03',NULL,'2026-08-02 04:01:00'),(6,38,'email','leonardo.velasco.oj@gmail.com','$2y$10$PtmBvdW2lC5R36vJIR5XkOkqsxo04pJzihb02ps0wSjaiv73aYGxS','2026-08-01 22:30:33',0,NULL,'2026-08-02 15:18:10','2026-08-02 04:25:33'),(7,39,'email','leonardo.velasco.oj@gmail.com','$2y$10$q732pzSnnfZ1Fpw16oBBc.9PlQoorfA46xdOFerw.gg7jVh.k/kgu','2026-08-02 15:23:10',0,'2026-08-02 15:18:12',NULL,'2026-08-02 21:18:10'),(8,40,'email','leonardo.velasco.oj@gmail.com','$2y$10$XEdL.Kaa3Oc1DuoQ.r4Wnu.wdfMefhf.LH4fEeHEr37uzvAQfpsV.','2026-08-02 16:27:37',0,'2026-08-02 16:22:39',NULL,'2026-08-02 22:22:37'),(9,42,'email','leonardo.velasco.oj@gmail.com','$2y$10$1Tm6WFn/BAJIDhpcQ1TjVOW.CS1HTxu6hhByqlaCHS1kmstJzLWL2','2026-08-02 17:18:37',0,'2026-08-02 17:13:39',NULL,'2026-08-02 23:13:37'),(10,44,'email','leonardo.velasco.oj@gmail.com','$2y$10$G.KBXzWlKKYOm0D86PCgmu/tupRJSYLJSsRRiciC09Qz3xYGOt2Dm','2026-08-02 18:11:03',0,'2026-08-02 18:06:05',NULL,'2026-08-03 00:06:03');
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

-- Dump completed on 2026-08-02 23:27:31
