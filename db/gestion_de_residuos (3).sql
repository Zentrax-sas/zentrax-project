-- MySQL dump 10.13  Distrib 8.0.46, for Win64 (x86_64)
--
-- Host: localhost    Database: gestion_residuos
-- ------------------------------------------------------
-- Server version	8.0.46

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `acopio`
--

DROP TABLE IF EXISTS `acopio`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `acopio` (
  `id_centro` int NOT NULL,
  `horario_atencion` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id_centro`),
  CONSTRAINT `fk_acopio_centro` FOREIGN KEY (`id_centro`) REFERENCES `centro` (`id_centro`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `camion`
--

DROP TABLE IF EXISTS `camion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `camion` (
  `id_camion` int NOT NULL AUTO_INCREMENT,
  `matricula` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `marca` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `modelo` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `capacidad_carga` decimal(8,2) NOT NULL,
  `estado` enum('Disponible','En Servicio','En Mantenimiento') COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id_camion`),
  UNIQUE KEY `matricula` (`matricula`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `centro`
--

DROP TABLE IF EXISTS `centro`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `centro` (
  `id_centro` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `direccion` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `telefono` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id_centro`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `contenedor`
--

DROP TABLE IF EXISTS `contenedor`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contenedor` (
  `id_contenedor` int NOT NULL AUTO_INCREMENT,
  `codigo` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `capacidad` decimal(8,2) NOT NULL,
  `direccion` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `longitud` decimal(10,7) NOT NULL,
  `latitud` decimal(10,7) NOT NULL,
  `estado` enum('Disponible','Lleno','Dañado','Fuera de Servicio') COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_tipo_residuo` int NOT NULL,
  `id_ruta` int NOT NULL,
  PRIMARY KEY (`id_contenedor`),
  UNIQUE KEY `codigo` (`codigo`),
  KEY `fk_contenedor_tipo` (`id_tipo_residuo`),
  KEY `fk_contenedor_ruta` (`id_ruta`),
  CONSTRAINT `fk_contenedor_ruta` FOREIGN KEY (`id_ruta`) REFERENCES `ruta` (`id_ruta`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_contenedor_tipo` FOREIGN KEY (`id_tipo_residuo`) REFERENCES `tipo_residuo` (`id_tipo_residuo`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `cuadrilla`
--

DROP TABLE IF EXISTS `cuadrilla`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cuadrilla` (
  `id_cuadrilla` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `turno` enum('Matutino','Vespertino','Nocturno') COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_centro` int NOT NULL,
  PRIMARY KEY (`id_cuadrilla`),
  KEY `fk_cuadrilla_centro` (`id_centro`),
  CONSTRAINT `fk_cuadrilla_centro` FOREIGN KEY (`id_centro`) REFERENCES `centro` (`id_centro`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `foto`
--

DROP TABLE IF EXISTS `foto`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `foto` (
  `id_foto` int NOT NULL AUTO_INCREMENT,
  `fecha` datetime NOT NULL,
  `url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_incidencia` int NOT NULL,
  PRIMARY KEY (`id_foto`),
  KEY `fk_foto_incidencia` (`id_incidencia`),
  CONSTRAINT `fk_foto_incidencia` FOREIGN KEY (`id_incidencia`) REFERENCES `incidencia` (`id_incidencia`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `incidencia`
--

DROP TABLE IF EXISTS `incidencia`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `incidencia` (
  `id_incidencia` int NOT NULL AUTO_INCREMENT,
  `descripcion` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `fecha_reporte` datetime NOT NULL,
  `estado` enum('Pendiente','En Proceso','Resuelta') COLLATE utf8mb4_unicode_ci NOT NULL,
  `prioridad` enum('Baja','Media','Alta') COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_contenedor` int NOT NULL,
  `id_cuadrilla` int NOT NULL,
  `id_usuario` int NOT NULL,
  PRIMARY KEY (`id_incidencia`),
  KEY `fk_incidencia_contenedor` (`id_contenedor`),
  KEY `fk_incidencia_cuadrilla` (`id_cuadrilla`),
  KEY `fk_incidencia_usuario` (`id_usuario`),
  CONSTRAINT `fk_incidencia_contenedor` FOREIGN KEY (`id_contenedor`) REFERENCES `contenedor` (`id_contenedor`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_incidencia_cuadrilla` FOREIGN KEY (`id_cuadrilla`) REFERENCES `cuadrilla` (`id_cuadrilla`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_incidencia_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuario` (`id_usuario`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `maquinaria`
--

DROP TABLE IF EXISTS `maquinaria`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `maquinaria` (
  `id_maquinaria` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tipo` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `estado` enum('Disponible','En Uso','En Mantenimiento') COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_centro` int NOT NULL,
  PRIMARY KEY (`id_maquinaria`),
  KEY `fk_maquinaria_centro` (`id_centro`),
  CONSTRAINT `fk_maquinaria_centro` FOREIGN KEY (`id_centro`) REFERENCES `centro` (`id_centro`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `reclamo`
--

DROP TABLE IF EXISTS `reclamo`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reclamo` (
  `id_reclamo` int NOT NULL AUTO_INCREMENT,
  `fecha` datetime NOT NULL,
  `descripcion` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `estado` enum('Pendiente','En Proceso','Resuelto') COLLATE utf8mb4_unicode_ci NOT NULL,
  `ci` char(8) COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_incidencia` int NOT NULL,
  PRIMARY KEY (`id_reclamo`),
  KEY `fk_reclamo_vecino` (`ci`),
  KEY `fk_reclamo_incidencia` (`id_incidencia`),
  CONSTRAINT `fk_reclamo_incidencia` FOREIGN KEY (`id_incidencia`) REFERENCES `incidencia` (`id_incidencia`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_reclamo_vecino` FOREIGN KEY (`ci`) REFERENCES `vecino` (`ci`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `recorre`
--

DROP TABLE IF EXISTS `recorre`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `recorre` (
  `id_camion` int NOT NULL,
  `id_ruta` int NOT NULL,
  PRIMARY KEY (`id_camion`,`id_ruta`),
  KEY `fk_recorre_ruta` (`id_ruta`),
  CONSTRAINT `fk_recorre_camion` FOREIGN KEY (`id_camion`) REFERENCES `camion` (`id_camion`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_recorre_ruta` FOREIGN KEY (`id_ruta`) REFERENCES `ruta` (`id_ruta`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ruta`
--

DROP TABLE IF EXISTS `ruta`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ruta` (
  `id_ruta` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `zona` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id_ruta`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `tipo_residuo`
--

DROP TABLE IF EXISTS `tipo_residuo`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tipo_residuo` (
  `id_tipo_residuo` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descripcion` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id_tipo_residuo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `usa`
--

DROP TABLE IF EXISTS `usa`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `usa` (
  `id_cuadrilla` int NOT NULL,
  `id_camion` int NOT NULL,
  PRIMARY KEY (`id_cuadrilla`,`id_camion`),
  KEY `fk_usa_camion` (`id_camion`),
  CONSTRAINT `fk_usa_camion` FOREIGN KEY (`id_camion`) REFERENCES `camion` (`id_camion`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_usa_cuadrilla` FOREIGN KEY (`id_cuadrilla`) REFERENCES `cuadrilla` (`id_cuadrilla`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `usuario`
--

DROP TABLE IF EXISTS `usuario`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `usuario` (
  `id_usuario` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `apellido` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `contrasena` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `telefono` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `fecha_registro` date NOT NULL,
  `rol` enum('Administrador','Operario') COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_centro` int NOT NULL,
  PRIMARY KEY (`id_usuario`),
  UNIQUE KEY `email` (`email`),
  KEY `fk_usuario_centro` (`id_centro`),
  CONSTRAINT `fk_usuario_centro` FOREIGN KEY (`id_centro`) REFERENCES `centro` (`id_centro`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `vecino`
--

DROP TABLE IF EXISTS `vecino`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `vecino` (
  `ci` char(8) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nombre` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `apellido` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `telefono` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`ci`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `vertedero`
--

DROP TABLE IF EXISTS `vertedero`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `vertedero` (
  `id_centro` int NOT NULL,
  `capacidad_maxima` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id_centro`),
  CONSTRAINT `fk_vertedero_centro` FOREIGN KEY (`id_centro`) REFERENCES `centro` (`id_centro`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-07-02 11:41:49
