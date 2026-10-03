<?php
function createAsignacionVehiculoFixture(PDO $db): void {
    $db->exec("CREATE TABLE asignacion_vehiculo_operativa (
        id_asignacion_vehiculo INTEGER PRIMARY KEY AUTOINCREMENT,id_cuadrilla INTEGER NOT NULL,id_vehiculo INTEGER NOT NULL,
        fecha_inicio TEXT NOT NULL,fecha_fin TEXT,id_usuario_asigna INTEGER NOT NULL,id_usuario_finaliza INTEGER,motivo_cierre TEXT,
        CHECK(fecha_fin IS NULL OR fecha_fin>=fecha_inicio),
        CHECK((fecha_fin IS NULL AND id_usuario_finaliza IS NULL AND motivo_cierre IS NULL) OR
        (fecha_fin IS NOT NULL AND id_usuario_finaliza IS NOT NULL AND motivo_cierre IS NOT NULL AND length(trim(motivo_cierre)) BETWEEN 1 AND 150)));
        CREATE UNIQUE INDEX uq_avo_c ON asignacion_vehiculo_operativa(id_cuadrilla) WHERE fecha_fin IS NULL;
        CREATE UNIQUE INDEX uq_avo_v ON asignacion_vehiculo_operativa(id_vehiculo) WHERE fecha_fin IS NULL;");
}
