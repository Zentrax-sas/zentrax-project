<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../config/database.php';

/** Optativa con ZEMYNA_UTF8_HTTP_BASE y credenciales de prueba existentes. Nunca altera operaciones reales. */
final class DashboardHttpIntegrationTest extends TestCase
{
    private PDO $db;
    private string $base;
    private array $cookies = [], $created = [];
    private int $requests = 0;
    private function insert(string $table, array $fields): int
    {
        $s=$this->db->prepare('INSERT INTO '.$table.' ('.implode(',',array_keys($fields)).') VALUES ('.implode(',',array_fill(0,count($fields),'?')).')');
        $s->execute(array_values($fields));$id=(int)$this->db->lastInsertId();$this->created[$table][]=$id;return $id;
    }
    private function http(string $session, string $path, int $expected=200, ?array $body=null, ?string $method=null): array
    {
        $c=curl_init($this->base.'/backend/api/'.$path);
        curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_CUSTOMREQUEST=>$method??($body===null?'GET':'POST'),
            CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_HEADERFUNCTION=>function($c,$line)use($session){if(preg_match('/^Set-Cookie:\s*([^;]+)/i',$line,$m))$this->cookies[$session]=$m[1];return strlen($line);}]);
        if(isset($this->cookies[$session]))curl_setopt($c,CURLOPT_COOKIE,$this->cookies[$session]);
        if($body!==null)curl_setopt($c,CURLOPT_POSTFIELDS,json_encode($body));
        $raw=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);$this->requests++;
        $this->assertSame($expected,$status,$path);$data=json_decode((string)$raw,true);$this->assertIsArray($data);return $data;
    }
    private function scalar(string $sql) { return $this->db->query($sql)->fetchColumn(); }
    private function fingerprints(): array
    {
        $result=[];
        foreach(['usuario','usuario_rol','rol','rol_permiso','incidencia','ruta','contenedor','cuadrilla','vehiculo','usa','participa','recorrido','atencion_contenedor'] as $table){
            $hash=hash_init('sha256');$rows=$this->db->query('SELECT * FROM '.$table.' ORDER BY '.($table==='rol_permiso'?'id_rol,id_permiso':'id_'.$table));
            while($r=$rows->fetch(PDO::FETCH_ASSOC))hash_update($hash,json_encode($r));$result[$table]=hash_final($hash);
        }
        return $result;
    }
    private function compare(array $data, ?string $from=null, ?string $to=null): void
    {
        // Consultas de contraste directas, independientes del modelo y de la paginación.
        $range=function(string $field)use($from,$to){return ($from?"$field >= ".$this->db->quote($from.' 00:00:00'):'1=1').($to?" AND $field < ".$this->db->quote((new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d 00:00:00')):'');};
        $actual=[
            'incidencias_activas'=>"SELECT COUNT(*) FROM incidencia WHERE estado<>'Resuelta'",
            'recorridos_en_proceso'=>"SELECT COUNT(*) FROM recorrido WHERE estado='En Proceso'",
            'cuadrillas_sin_recorrido'=>"SELECT COUNT(*) FROM cuadrilla WHERE id_cuadrilla NOT IN (SELECT DISTINCT u.id_cuadrilla FROM usa u,participa p,recorrido r WHERE u.id_usa=p.id_usa AND p.id_recorrido=r.id_recorrido AND r.estado IN ('Pendiente','En Proceso'))",
            'camiones_disponibles'=>"SELECT COUNT(*) FROM vehiculo WHERE activo=1 AND estado='Disponible' AND id_vehiculo NOT IN (SELECT u.id_vehiculo FROM usa u,participa p,recorrido r WHERE u.id_usa=p.id_usa AND p.id_recorrido=r.id_recorrido AND r.estado IN ('Pendiente','En Proceso'))",
            'contenedores_prioridad_alta'=>"SELECT COUNT(DISTINCT id_contenedor) FROM incidencia WHERE estado<>'Resuelta' AND prioridad='Alta'"
        ];
        foreach($actual as $key=>$sql)$this->assertSame((int)$this->scalar($sql),$data['actual'][$key],$key);
        $this->assertSame((int)$this->scalar('SELECT COUNT(*) FROM vehiculo WHERE activo=1')-$data['actual']['camiones_disponibles'],$data['actual']['camiones_no_disponibles']);
        $results=[
            'incidencias_reportadas'=>'SELECT COUNT(*) FROM incidencia WHERE '.$range('fecha_reporte'),
            'incidencias_resueltas'=>"SELECT COUNT(*) FROM incidencia WHERE estado='Resuelta' AND ".$range('fecha_resolucion'),
            'recorridos_finalizados'=>"SELECT COUNT(*) FROM recorrido WHERE estado='Finalizado' AND ".$range('fecha_fin'),
            'contenedores_atendidos'=>'SELECT COUNT(DISTINCT id_recorrido,id_contenedor) FROM atencion_contenedor WHERE '.$range('fecha_atencion'),
            'resoluciones_con_duracion'=>"SELECT COUNT(*) FROM incidencia WHERE estado='Resuelta' AND fecha_resolucion>=fecha_reporte AND ".$range('fecha_resolucion')
        ];
        foreach($results as $key=>$sql)$this->assertSame((int)$this->scalar($sql),$data['periodo_resultados'][$key],$key);
        $mean=$this->scalar("SELECT AVG(TIMESTAMPDIFF(SECOND,fecha_reporte,fecha_resolucion))/3600 FROM incidencia WHERE estado='Resuelta' AND fecha_resolucion>=fecha_reporte AND ".$range('fecha_resolucion'));
        $this->assertEquals($mean===null?null:round((float)$mean,2),$data['periodo_resultados']['tiempo_promedio_resolucion_horas']);
        foreach($data['recorridos']['por_estado'] as $state=>$total)$this->assertSame((int)$this->scalar('SELECT COUNT(*) FROM recorrido WHERE estado='.$this->db->quote($state).' AND '.$range('fecha_inicio')),$total);
        $chart=$this->db->query('SELECT estado,prioridad,COUNT(*) AS cantidad FROM incidencia WHERE '.$range('fecha_reporte').' GROUP BY estado,prioridad ORDER BY estado,prioridad')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertEquals($chart,$data['incidencias_por_estado_prioridad']);
        $top=$this->db->query('SELECT c.codigo,c.direccion,COUNT(*) AS cantidad FROM incidencia i JOIN contenedor c ON c.id_contenedor=i.id_contenedor WHERE '.$range('i.fecha_reporte').' GROUP BY c.id_contenedor,c.codigo,c.direccion ORDER BY cantidad DESC,c.codigo,c.id_contenedor LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertEquals($top,$data['contenedores_problematicos']);
        $this->assertSame((int)$this->scalar("SELECT COUNT(*) FROM incidencia WHERE estado='Resuelta' AND fecha_resolucion IS NULL"),$data['historicas_sin_fecha_resolucion']);
        $situations=[
            'alta'=>"SELECT COUNT(*) FROM incidencia WHERE estado<>'Resuelta' AND prioridad='Alta'",
            'reiteradas'=>"SELECT COUNT(*) FROM (SELECT id_contenedor FROM incidencia WHERE estado<>'Resuelta' AND id_contenedor IS NOT NULL GROUP BY id_contenedor HAVING COUNT(*)>=3) q",
            'sin_recorrido'=>$actual['cuadrillas_sin_recorrido'],
            'sin_vehiculo'=>"SELECT COUNT(*) FROM recorrido WHERE estado IN ('Pendiente','En Proceso') AND id_recorrido NOT IN (SELECT p.id_recorrido FROM participa p JOIN usa u USING(id_usa) JOIN vehiculo v USING(id_vehiculo) WHERE v.activo=1 AND v.estado<>'En Mantenimiento')",
            'vehiculo_invalido'=>"SELECT COUNT(DISTINCT v.id_vehiculo) FROM vehiculo v JOIN usa u USING(id_vehiculo) JOIN participa p USING(id_usa) JOIN recorrido r USING(id_recorrido) WHERE r.estado IN ('Pendiente','En Proceso') AND (v.activo=0 OR v.estado='En Mantenimiento')",
            'compartido'=>"SELECT COUNT(*) FROM (SELECT p.id_recorrido FROM participa p JOIN usa u USING(id_usa) JOIN recorrido r USING(id_recorrido) WHERE r.estado IN ('Pendiente','En Proceso') GROUP BY p.id_recorrido HAVING COUNT(DISTINCT u.id_cuadrilla)>1) q"
        ];
        $counts=array_column($data['requiere_atencion'],'cantidad','tipo');foreach($situations as $key=>$sql)$this->assertSame((int)$this->scalar($sql),$counts[$key]??0,$key);
    }
    public function testDashboardRealAgregadosPermisosYLimpieza(): void
    {
        $base=getenv('ZEMYNA_UTF8_HTTP_BASE');if(!$base)$this->markTestSkipped('Integración HTTP optativa.');
        $this->base=rtrim($base,'/');$this->db=(new Database())->getConnection();$before=$this->fingerprints();
        $tag='DB'.bin2hex(random_bytes(4));$password=bin2hex(random_bytes(16));$role=null;
        try {
            $this->http('none','dashboard.php',401);
            $this->http('admin','login.php',200,['email'=>getenv('ZEMYNA_UTF8_ADMIN_EMAIL'),'password'=>getenv('ZEMYNA_UTF8_ADMIN_PASSWORD')]);
            $initial=$this->http('admin','dashboard.php')['data'];$this->compare($initial);
            $this->http('admin','dashboard.php?fecha_desde=2026-02-30',400);
            $this->http('admin','dashboard.php?fecha_desde=2026-02-02&fecha_hasta=2026-01-01',400);
            $this->http('admin','dashboard.php?id_usuario=1',400);$this->http('admin','dashboard.php?sector=INSPECCION',403);
            $center=(int)$this->scalar('SELECT MIN(id_centro) FROM centro');$type=(int)$this->scalar('SELECT MIN(id_tipo_residuo) FROM tipo_residuo');
            $role=$this->insert('rol',['nombre'=>$tag,'descripcion'=>'Prueba temporal de permisos']);
            $this->db->exec("INSERT INTO rol_permiso(id_rol,id_permiso) SELECT $role,id_permiso FROM permiso WHERE nombre IN ('incidencia.consultar','recorrido.consultar','cuadrilla.consultar','vehiculo.consultar','contenedor.consultar')");
            $user=$this->insert('usuario',['nombre'=>$tag,'apellido'=>'Prueba temporal','email'=>$tag.'@example.invalid','contrasena'=>password_hash($password,PASSWORD_DEFAULT),'fecha_registro'=>date('Y-m-d'),'id_centro'=>$center,'activo'=>'Activo']);
            $assignment=$this->insert('usuario_rol',['id_usuario'=>$user,'id_rol'=>$role,'sector'=>'INSPECCION','fecha_desde'=>'2020-01-01']);
            $this->http('restricted','login.php',200,['email'=>$tag.'@example.invalid','password'=>$password]);
            $this->http('restricted','dashboard.php',403);$this->http('restricted','dashboard.php?sector=OPERACIONES',403);
            $this->db->exec("UPDATE usuario_rol SET sector='OPERACIONES' WHERE id_usuario_rol=$assignment");
            $this->http('restricted','dashboard.php',200); // Revalida la autorización vigente, sin relogin.
            $route=$this->insert('ruta',['nombre'=>$tag,'zona'=>'Prueba temporal']);
            $container=$this->insert('contenedor',['codigo'=>$tag,'capacidad'=>100,'direccion'=>'Referencia pública temporal','latitud'=>-34.9,'longitud'=>-56.1,'estado'=>'Disponible','id_tipo_residuo'=>$type,'id_ruta'=>$route]);
            $squad=$this->insert('cuadrilla',['nombre'=>$tag,'turno'=>'Matutino','id_centro'=>$center]);
            $vehicle=$this->insert('vehiculo',['matricula'=>$tag,'marca'=>'Prueba','modelo'=>'Temporal','capacidad_carga'=>100,'estado'=>'En Mantenimiento','id_tipo_residuo'=>$type]);
            $use=$this->insert('usa',['id_cuadrilla'=>$squad,'id_vehiculo'=>$vehicle]);
            $trip=$this->insert('recorrido',['fecha_inicio'=>'1901-01-17 00:00:00','fecha_fin'=>'1901-01-17 23:59:59','estado'=>'Finalizado','id_ruta'=>$route]);
            $this->insert('participa',['id_usa'=>$use,'id_recorrido'=>$trip,'hora_inicio'=>'00:00:00']);
            $this->insert('atencion_contenedor',['id_recorrido'=>$trip,'id_contenedor'=>$container,'id_usuario'=>$user,'fecha_atencion'=>'1901-01-17 23:59:59']);
            $this->insert('recorrido',['fecha_inicio'=>'1901-01-17 08:00:00','estado'=>'Pendiente','id_ruta'=>$route]);
            foreach([
                ['Pendiente','Alta','1901-01-17 00:00:00',null,$container],['En Proceso','Media','1901-01-17 23:59:59',null,$container],
                ['Pendiente','Baja','1901-01-18 00:00:00',null,$container],['Resuelta','Baja','1901-01-16 23:00:00','1901-01-17 01:00:00',$container],
                ['Resuelta','Alta','1901-01-17 08:00:00','1901-01-17 12:00:00',null],['Resuelta','Media','1901-01-17 09:00:00',null,null]
            ] as $i=>[$state,$priority,$reported,$resolved,$point]) $this->insert('incidencia',['tracking_number'=>$tag.$i,'descripcion'=>'Prueba privada temporal','tipo_problema'=>'Contenedor Desbordado','fecha_reporte'=>$reported,'fecha_resolucion'=>$resolved,'estado'=>$state,'prioridad'=>$priority,'id_contenedor'=>$point,'id_ruta'=>$point===null?$route:null]);
            $filtered=$this->http('admin','dashboard.php?fecha_desde=1901-01-17&fecha_hasta=1901-01-17')['data'];$this->compare($filtered,'1901-01-17','1901-01-17');
            $this->assertSame(4,$filtered['periodo_resultados']['incidencias_reportadas']);$this->assertEquals(3.0,$filtered['periodo_resultados']['tiempo_promedio_resolucion_horas']);
            $all=$this->http('admin','dashboard.php')['data'];$this->compare($all);$this->assertSame($all['actual'],$filtered['actual']);
            $this->assertSame($initial['periodo_resultados']['incidencias_reportadas']+6,$all['periodo_resultados']['incidencias_reportadas']);
            $now=new DateTimeImmutable('now',new DateTimeZone('America/Montevideo'));
            $resolving=$this->insert('incidencia',['tracking_number'=>$tag.'R','descripcion'=>'Prueba privada temporal','tipo_problema'=>'Contenedor Desbordado','fecha_reporte'=>$now->modify('-2 hours')->format('Y-m-d H:i:s'),'estado'=>'Pendiente','prioridad'=>'Alta','id_ruta'=>$route]);
            $this->http('admin','incidencias.php',200,['id_incidencia'=>$resolving,'estado'=>'Resuelta'],'PUT');
            $today=$now->format('Y-m-d');$resolved=$this->http('admin','dashboard.php?fecha_desde='.$today.'&fecha_hasta='.$today)['data'];$this->compare($resolved,$today,$today);
            $this->assertNotNull($resolved['periodo_resultados']['tiempo_promedio_resolucion_horas']);
            foreach([
                "SELECT c.codigo,COUNT(*) FROM incidencia i JOIN contenedor c USING(id_contenedor) WHERE i.fecha_reporte>='1901-01-17' AND i.fecha_reporte<'1901-01-18' GROUP BY c.id_contenedor,c.codigo ORDER BY COUNT(*) DESC LIMIT 5",
                "SELECT COUNT(*) FROM cuadrilla c WHERE NOT EXISTS(SELECT 1 FROM usa u JOIN participa p USING(id_usa) JOIN recorrido re USING(id_recorrido) WHERE u.id_cuadrilla=c.id_cuadrilla AND re.estado IN('Pendiente','En Proceso'))",
                "SELECT p.id_recorrido FROM participa p JOIN usa u USING(id_usa) JOIN recorrido r USING(id_recorrido) WHERE r.estado IN('Pendiente','En Proceso') GROUP BY p.id_recorrido HAVING COUNT(DISTINCT u.id_cuadrilla)>1"
            ] as $sql){$plan=$this->db->query('EXPLAIN '.$sql)->fetchAll(PDO::FETCH_ASSOC);$this->assertNotEmpty($plan);}
            $this->http('restricted','logout.php',200,[]);$this->http('admin','logout.php',200,[]);
            fwrite(STDOUT,"\nDashboard HTTP/MariaDB: {$this->requests} respuestas; todos los agregados contrastados; 3 EXPLAIN ejecutados.\n");
        } finally {
            foreach(['atencion_contenedor','participa','incidencia','recorrido','contenedor','usa','vehiculo','ruta','cuadrilla','usuario_rol','usuario'] as $table)
                foreach($this->created[$table]??[] as $id)$this->db->exec('DELETE FROM '.$table.' WHERE id_'.$table.'='.(int)$id);
            if($role){$this->db->exec('DELETE FROM rol_permiso WHERE id_rol='.(int)$role);$this->db->exec('DELETE FROM rol WHERE id_rol='.(int)$role);}
            $this->assertSame($before,$this->fingerprints(),'Limpieza por IDs exactos; todos los registros anteriores permanecen intactos.');
        }
    }
}
