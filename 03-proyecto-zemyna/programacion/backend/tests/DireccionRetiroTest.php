<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../models/DireccionRetiro.php';
class DireccionRetiroTest extends TestCase {
    public function testSugerenciasDomiciliariasLimitadasSinContenedores(): void {
        $modelo = new class extends DireccionRetiro {
            protected function consultar(string $servicio, array $params): array { return array_merge([['type'=>'LOCALIDAD','address'=>'Ciudad']], array_map(fn($i)=>['type'=>'CALLE','address'=>'Martínez '.$i],range(1,12))); }
        };
        $this->assertCount(10,$modelo->sugerir('Martí'));
        $this->assertSame('Martínez 1',$modelo->sugerir('Martí')[0]);
    }
    public function testResolverNoConfundeLatitudLongitudNiAceptaPuntoCero(): void {
        $modelo = new class extends DireccionRetiro {
            protected function consultar(string $servicio, array $params): array { return $servicio === 'candidates' ? [['type'=>'CALLE','address'=>'Brasil, Montevideo','idCalle'=>1]] : [['type'=>'LOCALIDAD','lat'=>0,'lng'=>0],['type'=>'CALLEyPORTAL','lat'=>-34.9123456,'lng'=>-56.1654321,'address'=>'Brasil 123, Montevideo','stateMsg'=>'']]; }
        };
        $p=$modelo->resolver('Brasil, Montevideo','123');
        $this->assertSame(-34.9123456,$p['latitud']); $this->assertSame(-56.1654321,$p['longitud']);
        $this->assertSame('Brasil',$p['calle']);$this->assertSame('123',$p['numero_referencia']);
    }
}
