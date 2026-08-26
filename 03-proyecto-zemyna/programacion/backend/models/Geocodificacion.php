<?php

class Geocodificacion {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function findCache($idContenedor) {
        $query = "SELECT direccion, barrio, localidad, fecha_consulta
                  FROM geocodificacion_cache
                  WHERE id_contenedor = :id_contenedor
                  LIMIT 1";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':id_contenedor', (int) $idContenedor, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $exception) {
            return null;
        }
    }

    public function findContenedor($idContenedor) {
        $query = "SELECT id_contenedor, codigo, direccion, latitud, longitud, estado
                  FROM contenedor
                  WHERE id_contenedor = :id_contenedor
                  AND activo = 1
                  LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id_contenedor', (int) $idContenedor, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function saveCache($idContenedor, $direccion, $barrio, $localidad) {
        $query = "INSERT INTO geocodificacion_cache
                  (id_contenedor, direccion, barrio, localidad, fecha_consulta)
                  VALUES (:id_contenedor, :direccion, :barrio, :localidad, NOW())
                  ON DUPLICATE KEY UPDATE
                      direccion = VALUES(direccion),
                      barrio = VALUES(barrio),
                      localidad = VALUES(localidad),
                      fecha_consulta = NOW()";

        try {
            $stmt = $this->conn->prepare($query);
            return $stmt->execute([
                ':id_contenedor' => (int) $idContenedor,
                ':direccion' => $direccion,
                ':barrio' => $barrio,
                ':localidad' => $localidad
            ]);
        } catch (PDOException $exception) {
            return false;
        }
    }
}