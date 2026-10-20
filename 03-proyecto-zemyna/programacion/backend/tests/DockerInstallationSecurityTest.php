<?php

use PHPUnit\Framework\TestCase;
require_once __DIR__.'/fixtures/mariadb_sql.php';

/** Static contracts and SQLite semantics; never starts Docker or connects to MySQL. */
final class DockerInstallationSecurityTest extends TestCase
{
    private function root(): string { return dirname(__DIR__, 2); }
    private function migration(): string {
        return file_get_contents($this->root().'/base-datos/database/sql/migration_v20_incidencia_upload_token.sql');
    }
    private function apache(): string {
        return file_get_contents($this->root().'/docker/apache-zemyna.conf');
    }

    public function testSchemaAndMigrationHaveIdenticalV20Definitions(): void {
        $schema = file_get_contents($this->root().'/base-datos/database/sql/schema.sql');
        $start = strpos($schema, '-- V20:');
        $end = strpos($schema, '-- V21 STRUCTURE');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $this->assertSame(str_replace("\r\n", "\n", trim($this->migration())), str_replace("\r\n", "\n", trim(substr($schema, $start, $end-$start))));
        preg_match('/CONSTRAINT chk_iut_consumo CHECK (.*?)\n\) ENGINE/s', $this->migration(), $check);
        $this->assertNotEmpty($check);
        $this->assertStringNotContainsString('id_foto', $check[1]);
        $this->assertStringContainsString('ON DELETE SET NULL', $this->migration());
        $this->assertStringContainsString('ON DELETE CASCADE', $this->migration());
        $this->assertStringContainsString('ON DELETE RESTRICT', $this->migration());
    }

    public function testPdoAdapterPreservesBothTriggersAndFollowingV21Sql(): void {
        $sql = mariaDbSqlForPdo(file_get_contents($this->root().'/base-datos/database/sql/schema.sql'));
        $this->assertStringNotContainsString('DELIMITER', $sql);
        $this->assertStringNotContainsString('END$$', $sql);
        $this->assertSame(2, substr_count($sql, 'CREATE TRIGGER IF NOT EXISTS trg_iut_foto_'));
        $this->assertSame(2, substr_count($sql, "SIGNAL SQLSTATE '45000'"));
        $this->assertStringContainsString('ALTER TABLE solicitud', $sql);
        $this->assertSame(2, substr_count(str_replace("\r\n", "\n", $sql), "END IF;\nEND;"));
        $this->expectException(InvalidArgumentException::class);
        mariaDbSqlForPdo("DELIMITER //\nSELECT 1//");
    }

    private function memory(): PDO {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA foreign_keys=ON');
        foreach (['incidencia'=>'id_incidencia', 'usuario'=>'id_usuario', 'foto'=>'id_foto'] as $table=>$id) {
            $db->exec("CREATE TABLE $table ($id INTEGER PRIMARY KEY)");
            $db->exec("INSERT INTO $table VALUES (1)");
        }
        // Reuse the real CHECK/FK expressions. Adapt only indexes/engine/trigger dialect.
        preg_match('/CREATE TABLE IF NOT EXISTS incidencia_upload_token .*?\) ENGINE=.*?;/s', $this->migration(), $ddl);
        $this->assertNotEmpty($ddl);
        $sql = preg_replace('/^\h*KEY .*?,\r?$/m', '', $ddl[0]);
        $sql = preg_replace('/UNIQUE KEY \w+ /', 'UNIQUE ', $sql);
        $sql = preg_replace('/ ENGINE=.*?;$/s', ';', $sql);
        $db->exec($sql);
        preg_match_all('/CREATE TRIGGER IF NOT EXISTS (\w+)\s+BEFORE (INSERT|UPDATE).*?IF (.*?) THEN\s+SIGNAL.*?END\$\$/s', $this->migration(), $triggers, PREG_SET_ORDER);
        $this->assertCount(2, $triggers);
        foreach ($triggers as $trigger) {
            $db->exec("CREATE TRIGGER {$trigger[1]} BEFORE {$trigger[2]} ON incidencia_upload_token
                WHEN {$trigger[3]} BEGIN SELECT RAISE(ABORT, 'Invalid token photo'); END;");
        }
        return $db;
    }

    private function insert(PDO $db, ?string $consumed, ?int $photo, string $hash='one', string $expires='2026-10-19 10:10:00'): void {
        $stmt = $db->prepare('INSERT INTO incidencia_upload_token
            (token_hash,id_incidencia,id_usuario_emisor,fecha_creacion,fecha_expiracion,fecha_consumo,id_foto)
            VALUES (?,1,1,?,?,?,?)');
        $stmt->execute([$hash,'2026-10-19 10:00:00',$expires,$consumed,$photo]);
    }

    public static function tokenCases(): array {
        return [
            'unused'=>[null,null,true],
            'used'=>['2026-10-19 10:01:00',1,true],
            'used photo removed'=>['2026-10-19 10:01:00',null,true],
            'photo without consumption'=>[null,1,false],
            'before creation'=>['2026-10-19 09:59:59',1,false],
            'at expiration'=>['2026-10-19 10:10:00',1,false],
            'after expiration'=>['2026-10-19 10:11:00',null,false],
            'missing photo FK'=>['2026-10-19 10:01:00',999,false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tokenCases')]
    public function testTokenIntegrity(?string $consumed, ?int $photo, bool $valid): void {
        $db = $this->memory();
        if (!$valid) $this->expectException(PDOException::class);
        $this->insert($db,$consumed,$photo);
        $this->assertSame(1,(int)$db->query('SELECT COUNT(*) FROM incidencia_upload_token')->fetchColumn());
    }

    public function testUpdateRejectsPhotoWithoutConsumptionAndRollsBack(): void {
        $db = $this->memory();
        $this->insert($db,null,null);
        try { $db->exec('UPDATE incidencia_upload_token SET id_foto=1'); $this->fail('Invalid update accepted'); }
        catch (PDOException $e) { $this->assertNull($db->query('SELECT id_foto FROM incidencia_upload_token')->fetchColumn()); }
        $db->exec("UPDATE incidencia_upload_token SET fecha_consumo='2026-10-19 10:01:00',id_foto=1");
        $db->exec('DELETE FROM foto WHERE id_foto=1');
        $row = $db->query('SELECT fecha_consumo,id_foto FROM incidencia_upload_token')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('2026-10-19 10:01:00',$row['fecha_consumo']);
        $this->assertNull($row['id_foto']);
    }

    public function testPhotoUniquenessAndExpiryArePreserved(): void {
        $db = $this->memory();
        $this->insert($db,'2026-10-19 10:01:00',1);
        try { $this->insert($db,'2026-10-19 10:02:00',1,'two'); $this->fail('Duplicate photo accepted'); }
        catch (PDOException $e) { $this->assertSame(1,(int)$db->query('SELECT COUNT(*) FROM incidencia_upload_token')->fetchColumn()); }
        $this->expectException(PDOException::class);
        $this->insert($db,null,null,'three','2026-10-19 10:00:00');
    }

    public function testApacheDefaultDenyAndExplicitPublicEntrypoints(): void {
        $conf = $this->apache();
        $this->assertStringContainsString('DocumentRoot /var/www/html',$conf);
        $this->assertStringContainsString('RedirectMatch 302 "^/$" "/frontend/public/index.html"',$conf);
        $this->assertStringContainsString('RedirectMatch 302 "^/frontend/?$" "/frontend/public/index.html"',$conf);
        preg_match_all('~<Directory "([^"]+)">(.*?)</Directory>~s',$conf,$blocks,PREG_SET_ORDER);
        $this->assertCount(4,$blocks);
        $expected=['/var/www/html','/var/www/html/frontend/public','/var/www/html/frontend/src/styles','/var/www/html/backend/api'];
        $this->assertSame($expected,array_column($blocks,1));
        foreach ($blocks as $block) $this->assertStringContainsString('Require all denied',$block[2]);
        $this->assertStringContainsString('AllowOverride None',$blocks[0][2]);
        $this->assertStringContainsString('Options -Indexes -MultiViews -ExecCGI -Includes',$blocks[0][2]);
        $this->assertStringNotContainsString('Require all granted',$blocks[0][2]);
        $this->assertStringContainsString('AcceptPathInfo Off',$conf);
        $this->assertStringNotContainsString('Alias',$conf);
    }

    public function testApacheFilenameRulesRejectPrivateAndBackupFiles(): void {
        $conf = $this->apache();
        preg_match_all('~<FilesMatch "([^"]+)">~',$conf,$files);
        preg_match('~<LocationMatch "([^"]+)">~',$conf,$location);
        $this->assertCount(3,$files[1]);
        $private='~'.$location[1].'~';
        foreach (['/base-datos/database/sql/schema.sql','/.env','/.env.docker','/backend/config/database.php',
            '/backend/tests/F1SecurityTest.php','/backend/models/Usuario.php','/backend/sesion/access.log',
            '/frontend/public/.env.js','/frontend/public/backup.sql.js','/frontend/public/file.bak.css','/frontend/public/source.php.js',
            '/README-Docker.md','/Dockerfile','/compose.yaml','/frontend/src/main.js'] as $path) {
            $allowed=0;
            foreach ([1=>'/frontend/public/',2=>'/frontend/src/styles/',3=>'/backend/api/'] as $index=>$prefix) {
                if (str_starts_with($path,$prefix)) $allowed=preg_match('~'.$files[1][$index-1].'~',basename($path));
            }
            $this->assertFalse($allowed===1 && preg_match($private,$path)!==1,$path);
        }
        foreach ([['/frontend/public/index.html',0],['/frontend/public/assets/js/zemyna-map.js',0],
            ['/frontend/public/assets/css/admin.css',0],['/frontend/src/styles/login.css',1],
            ['/backend/api/login.php',2],['/backend/api/foto.php',2]] as [$path,$index]) {
            $this->assertSame(1,preg_match('~'.$files[1][$index].'~',basename($path)),$path);
            $this->assertSame(0,preg_match($private,$path),$path);
        }
    }
}
