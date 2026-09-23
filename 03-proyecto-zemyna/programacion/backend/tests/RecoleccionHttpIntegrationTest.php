<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../config/database.php';

/** Integración optativa XAMPP: usa el entorno de Utf8HttpIntegrationTest.
 * Solo crea/borra datos propios identificados y no altera asignaciones existentes. */
final class RecoleccionHttpIntegrationTest extends TestCase
{
    private PDO $db;
    private string $base;
    private array $cookies = [];
    private array $created = [];
    private int $requests = 0;
    private function insert(string $table, array $fields): int {
        $s = $this->db->prepare('INSERT INTO ' . $table . ' (' . implode(',', array_keys($fields)) . ') VALUES (' . implode(',', array_fill(0,count($fields),'?')) . ')');
        $s->execute(array_values($fields)); $id = (int)$this->db->lastInsertId(); $this->created[$table][] = $id; return $id;
    }
    private function http(string $session, int $expected, string $path = 'recoleccion.php', ?array $body = null, string $method = ''): array {
        $curl=curl_init($this->base.'/backend/api/'.$path);
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_CUSTOMREQUEST=>$method ?: ($body === null ? 'GET' : 'POST'),
            CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_HEADERFUNCTION=>function($c,$line)use($session){if(preg_match('/^Set-Cookie:\s*([^;]+)/i',$line,$m))$this->cookies[$session]=$m[1];return strlen($line);}]);
        if(isset($this->cookies[$session]))curl_setopt($curl,CURLOPT_COOKIE,$this->cookies[$session]);
        if($body!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($body));
        $raw=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);$this->requests++;
        $this->assertSame($expected,$status,'HTTP '.$path.' '.$method);
        $data=json_decode((string)$raw,true);$this->assertIsArray($data);return $data;
    }
    private function concurrent(array $sessions, array $body): array {
        $multi = curl_multi_init(); $handles = [];
        foreach ($sessions as $session) {
            $c = curl_init($this->base.'/backend/api/recoleccion.php');
            curl_setopt_array($c, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>20, CURLOPT_POST=>true,
                CURLOPT_HTTPHEADER=>['Content-Type: application/json'], CURLOPT_COOKIE=>$this->cookies[$session], CURLOPT_POSTFIELDS=>json_encode($body)]);
            curl_multi_add_handle($multi,$c); $handles[]=$c;
        }
        do { curl_multi_exec($multi,$running); if($running)curl_multi_select($multi,0.1); } while($running);
        $statuses=[];$result=[];
        foreach($handles as $c){$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);$statuses[]=$status;if($status===200)$result=json_decode(curl_multi_getcontent($c),true);curl_multi_remove_handle($multi,$c);curl_close($c);$this->requests++;}
        curl_multi_close($multi);sort($statuses);$this->assertSame([200,409],$statuses,'Dos sesiones simultáneas: una operación y un conflicto');
        return $result;
    }
    public function testCicloRealAisladoEnMariaDB(): void {
        $base=getenv('ZEMYNA_UTF8_HTTP_BASE');if(!$base)$this->markTestSkipped('Integración optativa: configurar entorno HTTP UTF-8.');
        $this->base=rtrim($base,'/');$db=(new Database())->getConnection();$this->assertInstanceOf(PDO::class,$db);$this->db=$db;
        $this->assertStringContainsString('MariaDB',$db->query('SELECT VERSION()')->fetchColumn());
        $tag='RC'.bin2hex(random_bytes(4));$password=bin2hex(random_bytes(16));
        $center=(int)$db->query('SELECT id_centro FROM centro ORDER BY id_centro LIMIT 1')->fetchColumn();
        $type=(int)$db->query('SELECT id_tipo_residuo FROM tipo_residuo ORDER BY id_tipo_residuo LIMIT 1')->fetchColumn();
        $role=(int)$db->query("SELECT id_rol FROM rol WHERE nombre='OPERARIO'")->fetchColumn();
        try {
            $user=$this->insert('usuario',['nombre'=>$tag,'apellido'=>'Prueba temporal','email'=>$tag.'@example.invalid','contrasena'=>password_hash($password,PASSWORD_DEFAULT),'fecha_registro'=>date('Y-m-d'),'id_centro'=>$center,'activo'=>'Activo']);
            $roleAssignment=$this->insert('usuario_rol',['id_usuario'=>$user,'id_rol'=>$role,'sector'=>'PUNTOS_Y_DESTINOS','fecha_desde'=>'2020-01-01']);
            $squad=$this->insert('cuadrilla',['nombre'=>$tag,'turno'=>'Matutino','id_centro'=>$center]);
            $otherSquad=$this->insert('cuadrilla',['nombre'=>$tag.' otra','turno'=>'Matutino','id_centro'=>$center]);
            $route=$this->insert('ruta',['nombre'=>$tag,'zona'=>'Prueba temporal']);$otherRoute=$this->insert('ruta',['nombre'=>$tag.' otra','zona'=>'Prueba temporal']);
            $truck=$this->insert('vehiculo',['id_tipo_residuo'=>$type,'matricula'=>$tag,'marca'=>'Prueba','modelo'=>'Temporal','capacidad_carga'=>100,'estado'=>'Disponible']);
            $use=$this->insert('usa',['id_cuadrilla'=>$squad,'id_vehiculo'=>$truck]);
            $trip=$this->insert('recorrido',['fecha_inicio'=>'2020-01-01 08:00:00','estado'=>'Pendiente','id_ruta'=>$route]);
            $point=$this->insert('contenedor',['codigo'=>$tag,'capacidad'=>100,'direccion'=>'Prueba temporal','latitud'=>-34.9,'longitud'=>-56.1,'estado'=>'Disponible','id_tipo_residuo'=>$type,'id_ruta'=>$route]);
            $foreign=$this->insert('contenedor',['codigo'=>$tag.'X','capacidad'=>100,'direccion'=>'Prueba temporal','latitud'=>-34.9,'longitud'=>-56.1,'estado'=>'Disponible','id_tipo_residuo'=>$type,'id_ruta'=>$otherRoute]);
            $this->http('none',401);
            $this->http('admin',200,'login.php',['email'=>getenv('ZEMYNA_UTF8_ADMIN_EMAIL'),'password'=>getenv('ZEMYNA_UTF8_ADMIN_PASSWORD')]);
            $excluded=$this->http('admin',200,'recoleccion.php?view=integrantes&id_cuadrilla='.$squad);
            $reason=array_values(array_filter($excluded['data']['no_elegibles'],fn($u)=>$u['nombre']===$tag));
            $this->assertSame('Sector Puntos y destinos',$reason[0]['motivo']);
            $this->http('admin',400,'recoleccion.php',['accion'=>'asignar','integrante'=>$user,'destino'=>$squad]);
            $db->exec("UPDATE usuario_rol SET sector='OPERACIONES' WHERE id_usuario_rol=$roleAssignment");
            $this->http('worker',200,'login.php',['email'=>$tag.'@example.invalid','password'=>$password]);
            $this->http('worker',409);$this->http('worker',403,'recoleccion.php?view=administracion');
            $this->http('worker',403,'recoleccion.php',['accion'=>'asignar','integrante'=>$user,'destino'=>$squad]);
            $adminRole=(int)$db->query("SELECT id_rol FROM rol WHERE nombre='ADMINISTRADOR_TI'")->fetchColumn();
            $adminUser=$this->insert('usuario',['nombre'=>$tag.' admin','apellido'=>'Prueba temporal','email'=>$tag.'admin@example.invalid','contrasena'=>password_hash($password,PASSWORD_DEFAULT),'fecha_registro'=>date('Y-m-d'),'id_centro'=>$center,'activo'=>'Activo']);
            $this->insert('usuario_rol',['id_usuario'=>$adminUser,'id_rol'=>$adminRole,'sector'=>'TI','fecha_desde'=>'2020-01-01']);
            $this->http('admin2',200,'login.php',['email'=>$tag.'admin@example.invalid','password'=>$password]);
            $this->http('worker2',200,'login.php',['email'=>$tag.'@example.invalid','password'=>$password]);
            $assigned=$this->concurrent(['admin','admin2'],['accion'=>'asignar','integrante'=>$user,'destino'=>$squad]);$member=$assigned['data']['pertenencia']['id_usuario_cuadrilla'];
            $this->http('admin',409,'recoleccion.php',['accion'=>'asignar','integrante'=>$user,'destino'=>$otherSquad]);
            $this->assertSame(1,(int)$db->query('SELECT COUNT(*) FROM usuario_cuadrilla WHERE id_usuario='.$user.' AND fecha_fin IS NULL')->fetchColumn());
            // Restricción real MariaDB, independiente de la validación de la aplicación.
            try{$db->exec("INSERT INTO usuario_cuadrilla(id_usuario,id_cuadrilla,fecha_inicio,id_usuario_asigna) VALUES($user,$otherSquad,NOW(),$user)");$this->fail('Doble pertenencia vigente permitida');}
            catch(PDOException $e){$this->assertSame(1062,(int)$e->errorInfo[1]);}
            $this->http('worker',409);
            $this->http('worker',403,'recoleccion.php?view=asignables&id_cuadrilla='.$squad);
            $this->http('worker',403,'recoleccion.php',['accion'=>'asignar_recorrido','destino'=>$squad,'id_recorrido'=>$trip,'id_usa'=>$use]);
            $available=$this->http('admin',200,'recoleccion.php?view=asignables&id_cuadrilla='.$squad);
            $this->assertContains($trip,array_map('intval',array_column($available['data']['items'],'id_recorrido')));
            $tripAssignment=['accion'=>'asignar_recorrido','destino'=>$squad,'id_recorrido'=>$trip,'id_usa'=>$use];
            $this->http('admin',400,'recoleccion.php',$tripAssignment+['id_usuario'=>$user]);
            $this->concurrent(['admin','admin2'],$tripAssignment);
            $this->assertSame(1,(int)$db->query('SELECT COUNT(*) FROM participa WHERE id_recorrido='.$trip)->fetchColumn());
            $this->assertSame('08:00:00',$db->query('SELECT hora_inicio FROM participa WHERE id_recorrido='.$trip)->fetchColumn());
            $this->http('admin',409,'recoleccion.php',$tripAssignment);
            $this->http('admin2',409,'recoleccion.php',array_replace($tripAssignment,['destino'=>$otherSquad]));
            $listed=$this->http('admin',200,'recoleccion.php?view=administracion&id_cuadrilla='.$squad);
            $this->assertSame($trip,(int)$listed['data']['recorrido_actual']['id_recorrido']);
            $process = proc_open(['node', __DIR__.'/../../frontend/tests/recoleccion-request.cjs', $this->base.'/frontend/public/recoleccion.html'],
                [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
            $this->assertIsResource($process); fclose($pipes[0]);
            $captured=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
            $this->assertSame(0,proc_close($process),$errors);
            $request=json_decode($captured,true,512,JSON_THROW_ON_ERROR);
            $this->assertSame('GET',$request['method']); $this->assertNull($request['body']);
            $this->assertSame($this->base.'/backend/api/recoleccion.php',$request['url']);
            $own=$this->http('worker',200,basename(parse_url($request['url'],PHP_URL_PATH)));$this->assertSame($trip,(int)$own['data']['recorrido']['id_recorrido']);
            $this->assertSame($squad,(int)$own['data']['pertenencia']['id_cuadrilla']);
            $this->http('worker',400,'recoleccion.php?id_cuadrilla='.$otherSquad);
            $this->http('worker',400,'recoleccion.php?id_usuario=1');
            $this->http('worker',404,'recoleccion.php?id_recorrido=2147483647');
            $this->http('worker',400,'recoleccion.php',['accion'=>'iniciar','id_recorrido'=>$trip,'id_usuario'=>1]);
            $this->http('worker',409,'recoleccion.php',['accion'=>'atender','id_recorrido'=>$trip,'id_contenedor'=>$point]);
            $this->http('worker',409,'recoleccion.php',['accion'=>'finalizar','id_recorrido'=>$trip]);
            $start=$this->concurrent(['worker','worker2'],['accion'=>'iniciar','id_recorrido'=>$trip]);
            $this->assertSame($user,(int)$start['data']['recorrido']['id_usuario_inicio']);
            $this->http('worker',409,'recoleccion.php',['accion'=>'iniciar','id_recorrido'=>$trip]);
            $this->http('worker',404,'recoleccion.php',['accion'=>'atender','id_recorrido'=>$trip,'id_contenedor'=>$foreign]);
            $attended=$this->concurrent(['worker','worker2'],['accion'=>'atender','id_recorrido'=>$trip,'id_contenedor'=>$point]);
            $this->assertSame(['total'=>1,'atendidos'=>1,'pendientes'=>0],$attended['data']['recorrido']['progreso']);
            $this->http('worker',409,'recoleccion.php',['accion'=>'atender','id_recorrido'=>$trip,'id_contenedor'=>$point]);
            $record=$db->query('SELECT * FROM atencion_contenedor WHERE id_recorrido='.$trip)->fetch(PDO::FETCH_ASSOC);
            $this->assertSame($user,(int)$record['id_usuario']);$this->assertNotEmpty($record['fecha_atencion']);
            $this->http('worker',200,'recoleccion.php',['accion'=>'finalizar','id_recorrido'=>$trip]);
            $this->http('worker',409,'recoleccion.php',['accion'=>'finalizar','id_recorrido'=>$trip]);
            $summary=$this->http('worker',200,'recoleccion.php?id_recorrido='.$trip);
            $this->assertSame('Finalizado',$summary['data']['recorrido']['estado']);
            $persisted=$db->query('SELECT * FROM recorrido WHERE id_recorrido='.$trip)->fetch(PDO::FETCH_ASSOC);
            $this->assertSame($user,(int)$persisted['id_usuario_fin']);$this->assertSame($user,(int)$persisted['id_usuario_inicio']);
            $this->assertNotEmpty($persisted['fecha_fin']);$this->assertGreaterThanOrEqual($persisted['fecha_inicio'],$persisted['fecha_fin']);
            $this->http('admin',409,'recorridos.php',['id_recorrido'=>$trip,'fecha_inicio'=>'2020-01-01 00:00:00','estado'=>'Pendiente','id_ruta'=>$route],'PUT');
            $this->http('admin',409,'recorridos.php',['id_recorrido'=>$trip],'DELETE');
            $admin=$this->http('admin',200,'recoleccion.php?view=administracion&id_cuadrilla='.$squad.'&id_recorrido='.$trip);
            $this->assertSame(1,$admin['data']['recorrido']['progreso']['atendidos']);
            $moved=$this->http('admin',200,'recoleccion.php',['accion'=>'trasladar','integrante'=>$user,'destino'=>$otherSquad,'pertenencia'=>$member]);
            $this->http('worker',404,'recoleccion.php?id_recorrido='.$trip);
            $this->assertSame(2,(int)$db->query('SELECT COUNT(*) FROM usuario_cuadrilla WHERE id_usuario='.$user)->fetchColumn());
            $this->http('admin',200,'recoleccion.php?view=integrantes&id_cuadrilla='.$squad);
            $newMember=$moved['data']['pertenencia']['id_usuario_cuadrilla'];
            $this->http('admin',200,'recoleccion.php',['accion'=>'finalizar_pertenencia','integrante'=>$user,'pertenencia'=>$newMember]);$this->http('worker',409);
            $db->exec("UPDATE usuario_rol SET sector='INSPECCION' WHERE id_usuario_rol=$roleAssignment");
            $this->http('worker',403);$this->http('worker',403,'recoleccion.php',['accion'=>'iniciar','id_recorrido'=>$trip]);
            $db->exec("UPDATE usuario_rol SET sector='OPERACIONES' WHERE id_usuario_rol=$roleAssignment");
            $this->http('admin2',200,'logout.php',[]);$this->http('worker2',200,'logout.php',[]);
            $this->http('worker',200,'logout.php',[]);$this->http('admin',200,'logout.php',[]);
            fwrite(STDOUT, "\nRecolección HTTP/MariaDB: {$this->requests} respuestas verificadas; flujo y persistencia correctos.\n");
        } finally {
            // IDs exactos de esta prueba, orden de claves foráneas. Nunca borra registros ajenos.
            foreach($this->created['recorrido']??[] as $id){$db->exec('DELETE FROM atencion_contenedor WHERE id_recorrido='.(int)$id);$db->exec('DELETE FROM participa WHERE id_recorrido='.(int)$id);}
            foreach($this->created['usuario']??[] as $id){$db->exec('DELETE FROM usuario_cuadrilla WHERE id_usuario='.(int)$id);}
            foreach(['participa','recorrido','contenedor','usa','vehiculo','ruta','cuadrilla','usuario_rol','usuario'] as $table)foreach($this->created[$table]??[] as $id)$db->exec('DELETE FROM '.$table.' WHERE id_'.$table.'='.(int)$id);
            foreach($this->created as $table=>$ids) foreach($ids as $id) $this->assertSame(0,(int)$db->query('SELECT COUNT(*) FROM '.$table.' WHERE id_'.$table.'='.(int)$id)->fetchColumn(), 'Datos temporales eliminados: '.$table);
        }
    }
}
