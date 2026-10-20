<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../../backend/config/database.php';
$db=(new Database())->getConnection();if(!$db)throw new RuntimeException('Sin conexión.');
foreach(['incidencia','solicitud'] as $tabla){
    $columnas=$db->query("SHOW COLUMNS FROM `$tabla`")->fetchAll(PDO::FETCH_ASSOC);
    foreach($columnas as $columna){
        if($columna['Field']==='estado'&&str_starts_with($columna['Type'],'enum(')&&!str_contains($columna['Type'],"'Cancelada'")){
            $tipo=substr($columna['Type'],0,-1).",'Cancelada')";
            $db->exec("ALTER TABLE `$tabla` MODIFY estado $tipo NOT NULL");
        }
    }
    if(!in_array('fecha_cancelacion',array_column($columnas,'Field'),true))$db->exec("ALTER TABLE `$tabla` ADD COLUMN fecha_cancelacion DATETIME(6) NULL");
}
echo "Migración de cancelación aplicada; registros conservados.\n";
