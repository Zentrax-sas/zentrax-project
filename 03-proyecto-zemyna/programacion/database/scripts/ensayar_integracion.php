<?php
// Ensayo explícito: jamás selecciona la base real. Conserva ambas bases de ensayo.
if (PHP_SAPI !== 'cli' || !in_array($argc,[3,4],true)) exit("Uso: php ensayar_integracion.php <dump.sql> <zemyna_integracion_test_identificador>\n");
[$script, $dumpPath, $name] = $argv;
if (!preg_match('/^zemyna_integracion_test_[a-z0-9_]{1,32}$/D', $name)) throw new RuntimeException('Destino de ensayo inválido.');
require_once __DIR__.'/../../backend/config/parse_env.php';
require_once __DIR__.'/../../backend/tests/fixtures/mariadb_sql.php';
loadEnvFile(__DIR__.'/../../.env');
$db = new PDO('mysql:host=127.0.0.1;port=3310;charset=utf8mb4', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
function snapshot(PDO $db): array {
    $result = [];
    foreach ($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table)) throw new RuntimeException('Tabla inesperada.');
        $columns = array_column($db->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC), 'Field');
        $hashes=[]; $s=$db->query("SELECT * FROM `$table`");
        while ($row=$s->fetch(PDO::FETCH_ASSOC)) $hashes[]=hash('sha256',json_encode($row,JSON_THROW_ON_ERROR));
        sort($hashes); $result[$table]=['count'=>count($hashes),'columns'=>$columns,'hash'=>hash('sha256',implode('', $hashes)),'row_hashes'=>$hashes];
    }
    return $result;
}
if (($argv[3] ?? '') === '--verificar') {
    $report=json_decode(file_get_contents(__DIR__.'/../../backend/tmp/integration/migration-report.json'),true,512,JSON_THROW_ON_ERROR);
    if ($report['database']!==$name) throw new RuntimeException('Informe de otro ensayo.');
    $db->exec("USE `$name`"); $current=snapshot($db);
    foreach ($report['after'] as $table=>$baseline) {
        if ($current[$table]['hash']!==$baseline['hash'] || $current[$table]['count']!==$baseline['count']) throw new RuntimeException('Datos del ensayo alterados tras pruebas: '.$table);
    }
    echo "Integridad posterior a pruebas: todas las tablas conservadas.\n"; exit(0);
}
$sql=file_get_contents($dumpPath);
if ($sql===false || !str_contains($sql,'-- Dump completed on')) throw new RuntimeException('Dump incompleto.');
$sql=preg_replace('/^CREATE DATABASE[^\r\n]*;\r?\n/m','',$sql);
$sql=preg_replace('/^USE[^\r\n]*;\r?\n/m','',$sql);
if (preg_match('/\b(?:USE|CREATE\s+DATABASE|DROP\s+DATABASE)\b/i', preg_replace('/^--.*$/m','',$sql)) || str_contains(preg_replace('/^--.*$/m','',$sql),'`gestion_residuosfinal`')) throw new RuntimeException('Dump con selección externa no admitida.');
$db->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db->exec("USE `$name`");
foreach (preg_split('/;\r?\n/', $sql) as $statement) { if (trim($statement) !== '') $db->exec($statement); }
$before=snapshot($db); echo "Restaurado: $name; ".count($before)." tablas\n";
$root=__DIR__.'/../../base-datos/database/sql/';
$applied=[];
foreach ([15,16,17,18,19,20,21,22] as $version) {
    $matches=glob($root.'migration_v'.$version.'_*.sql');
    if (count($matches)!==1) throw new RuntimeException('Migración ambigua: '.$version);
    if ($db->query('SELECT DATABASE()')->fetchColumn()!==$name) throw new RuntimeException('Destino alterado.');
    $db->exec(mariaDbSqlForPdo(file_get_contents($matches[0])));
    $applied[]=basename($matches[0]); echo 'Aplicada: '.end($applied)."\n";
}
$after=snapshot($db); $preserved=[];
foreach ($before as $table=>$base) {
    $columns=implode(',',array_map(fn($c)=>'`'.$c.'`',$base['columns']));
    $hashes=[]; $s=$db->query("SELECT $columns FROM `$table`");
    while ($row=$s->fetch(PDO::FETCH_ASSOC)) $hashes[]=hash('sha256',json_encode($row,JSON_THROW_ON_ERROR));
    sort($hashes); $same=count($hashes)===$base['count'] && hash('sha256',implode('', $hashes))===$base['hash'];
    // V17-V21 conceden permisos nuevos; solo esas dos tablas admiten filas agregadas.
    if (!$same && (!in_array($table,['permiso','rol_permiso'],true) || array_diff($base['row_hashes'],$hashes))) throw new RuntimeException('Datos originales alterados: '.$table);
    $preserved[$table]=['before'=>$base['count'],'after'=>$after[$table]['count'],'same_original_columns'=>$same];
}
// Las huellas anteriores verifican tambien cada fila previa de permisos.
$clean=$name.'_clean';
$db->exec("CREATE DATABASE `$clean` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db->exec("USE `$clean`");
$db->exec(mariaDbSqlForPdo(file_get_contents($root.'schema.sql')));
echo "Instalación limpia correcta: $clean\n";
$report=['database'=>$name,'clean_database'=>$clean,'dump_sha256'=>hash_file('sha256',$dumpPath),'migrations'=>$applied,'data'=>$preserved,'before'=>$before,'after'=>$after];
$out=__DIR__.'/../../backend/tmp/integration'; if (!is_dir($out)) mkdir($out,0777,true);
file_put_contents($out.'/migration-report.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
echo "Integridad y conteos verificados; informe backend/tmp/integration/migration-report.json\n";
