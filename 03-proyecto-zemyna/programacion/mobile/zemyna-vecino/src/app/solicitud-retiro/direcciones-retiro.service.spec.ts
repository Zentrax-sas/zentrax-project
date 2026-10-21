import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { firstValueFrom } from 'rxjs';
import { DireccionesRetiroService } from './direcciones-retiro.service';
describe('Fuente domiciliaria independiente', () => {
 let http: HttpTestingController, api: DireccionesRetiroService;
 beforeEach(()=>{TestBed.configureTestingModule({providers:[provideHttpClient(),provideHttpClientTesting()]});http=TestBed.inject(HttpTestingController);api=TestBed.inject(DireccionesRetiroService);});
 afterEach(()=>{http.verify();TestBed.resetTestingModule();});
 it('preserva tildes y limita sugerencias a diez sin consultar contenedores',async()=>{
  const p=firstValueFrom(api.sugerirCalles('Martí'));const r=http.expectOne(q=>q.url.endsWith('/direcciones-retiro.php')&&q.params.get('q')==='Martí');r.flush({success:true,data:Array.from({length:12},(_,i)=>'Martínez '+i)});expect(await p).toHaveLength(10);http.expectNone(q=>q.url.endsWith('/contenedores.php'));
 });
 it.each([null,{latitud:0,longitud:0,direccion:'No válido'},{latitud:91,longitud:-56,direccion:'No válido'}])('distingue vacío de coordenadas inválidas: %s',async data=>{
  const p=firstValueFrom(api.resolver('Brasil, Montevideo','2359'));http.expectOne(r=>r.params.get('view')==='resolve').flush({success:true,data});
  if(data===null)expect(await p).toBeNull();else await expect(p).rejects.toThrow('Ubicación inválida');
 });
});
