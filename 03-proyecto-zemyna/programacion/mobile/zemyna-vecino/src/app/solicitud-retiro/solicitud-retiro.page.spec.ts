import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { vi } from 'vitest';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { SolicitudRetiroPage } from './solicitud-retiro.page';
import { SolicitudRetiroFlowService } from './solicitud-retiro-flow.service';

describe('Solicitud de retiro: domicilio operativo confirmado', () => {
 let fixture: ComponentFixture<SolicitudRetiroPage>, page: SolicitudRetiroPage, http: HttpTestingController;
 const punto = {calle:'AVENIDA BRASIL',numero_referencia:'2359',direccion:'AVENIDA BRASIL 2359, MONTEVIDEO',latitud:-34.9059331,longitud:-56.1630901};
 beforeEach(async()=>{
  await TestBed.configureTestingModule({providers:[provideRouter([]),provideHttpClient(),provideHttpClientTesting()]}).compileComponents();
  vi.spyOn(TestBed.inject(Router),'navigate').mockResolvedValue(true);
  fixture=TestBed.createComponent(SolicitudRetiroPage);page=fixture.componentInstance;http=TestBed.inject(HttpTestingController);
  page.ionViewWillEnter();tipos();fixture.detectChanges();
 });
 afterEach(()=>{fixture.destroy();http.verify();vi.useRealTimers();vi.restoreAllMocks();TestBed.resetTestingModule();});
 function tipos(){http.expectOne('/backend/api/tipos_residuo.php').flush({success:true,data:[{id_tipo_residuo:91,nombre:'Residuos voluminosos'}]});}
 function escribir(value:string){fixture.nativeElement.querySelector('.input-icon ion-input').dispatchEvent(new CustomEvent('ionInput',{detail:{value}}));fixture.detectChanges();}
 function resolver(){page.seleccionarCalle('AVENIDA BRASIL, MONTEVIDEO, MONTEVIDEO');page.actualizarReferencia('2359');page.buscarUbicacion();http.expectOne(r=>r.url.endsWith('/direcciones-retiro.php')&&r.params.get('view')==='resolve').flush({success:true,data:punto});fixture.detectChanges();}
 function completar(){resolver();page.confirmarUbicacion();page.tipoResiduo=91;page.descripcion='Mesa';}
 it('comienza sin ubicación ni consentimiento y usa el catálogo real',()=>{expect(page.ubicacion).toBeNull();expect(page.consentimientoWhatsApp).toBe(false);expect(page.formularioValido).toBe(false);expect(fixture.nativeElement.querySelector('ion-select-option').textContent).toContain('Residuos voluminosos');});
 it('debounce, nombres parciales y tildes usan solamente el servicio domiciliario',async()=>{
  vi.useFakeTimers();escribir('M');await vi.advanceTimersByTimeAsync(300);http.expectNone(r=>r.url.endsWith('/direcciones-retiro.php'));
  escribir('Mart');await vi.advanceTimersByTimeAsync(200);escribir('Martí');await vi.advanceTimersByTimeAsync(299);http.expectNone(r=>r.url.endsWith('/direcciones-retiro.php'));
  await vi.advanceTimersByTimeAsync(1);const req=http.expectOne(r=>r.url.endsWith('/direcciones-retiro.php')&&r.params.get('q')==='Martí');req.flush({success:true,data:['MARTÍNEZ, MONTEVIDEO']});fixture.detectChanges();expect(fixture.nativeElement.querySelector('.sugerencias button').textContent).toContain('MARTÍNEZ');http.expectNone(r=>r.url.endsWith('/contenedores.php'));
 });
 it('selecciona calle, habilita puerta y no confirma un punto automáticamente',()=>{page.seleccionarCalle('AVENIDA BRASIL, MONTEVIDEO');fixture.changeDetectorRef.markForCheck();fixture.detectChanges();expect(page.calleElegida).toBe(true);expect(page.ubicacionConfirmada).toBe(false);expect(page.direccion).toBe('');});
 it('resuelve puerta, muestra mapa, permite mover y exige confirmar nuevamente',()=>{
  resolver();expect(page.ubicacionConfirmada).toBe(false);expect(fixture.nativeElement.querySelector('app-mapa-domicilio')).not.toBeNull();page.confirmarUbicacion();expect(page.ubicacionConfirmada).toBe(true);
  page.corregirUbicacion();page.moverUbicacion({latitud:-34.906,longitud:-56.164});expect(page.ubicacionConfirmada).toBe(false);page.confirmarUbicacion();expect(page.ubicacion?.latitud).toBe(-34.906);
 });
 it('editar calle o referencia cancela búsqueda pendiente y descarta confirmación y borrador',()=>{
  completar();page.continuar();page.actualizarReferencia('2360');expect(page.ubicacion).toBeNull();expect(TestBed.inject(SolicitudRetiroFlowService).pendiente).toBeNull();
  page.buscarUbicacion();const req=http.expectOne(r=>r.params.get('view')==='resolve');page.actualizarCalle('Br');expect(req.cancelled).toBe(true);expect(page.calleElegida).toBe(false);
 });
 it('no permite enviar dirección libre sin punto confirmado',()=>{page.tipoResiduo=91;page.descripcion='Mesa';page.direccion='Brasil 2359';page.continuar();expect(page.formularioValido).toBe(false);expect(TestBed.inject(Router).navigate).not.toHaveBeenCalled();expect(page.mensaje).toContain('Confirmá');});
 it('número o referencia vacío no consulta al proveedor',()=>{page.seleccionarCalle('Brasil');page.buscarUbicacion();http.expectNone(r=>r.params.get('view')==='resolve');expect(page.mensaje).toContain('referencia');});
 it('sin resultados o error no inventa coordenadas y permite reintentar',()=>{
  page.seleccionarCalle('Brasil');page.actualizarReferencia('2359');page.buscarUbicacion();http.expectOne(r=>r.params.get('view')==='resolve').flush({success:true,data:null});expect(page.ubicacion).toBeNull();
  page.buscarUbicacion();http.expectOne(r=>r.params.get('view')==='resolve').flush({}, {status:503,statusText:'No disponible'});expect(page.mensaje).toContain('No pudimos ubicar esa dirección');expect(page.ubicacionConfirmada).toBe(false);
 });
 it('cancela sugerencias al cerrar, al seleccionar y al salir',async()=>{vi.useFakeTimers();escribir('Br');await vi.advanceTimersByTimeAsync(300);const req=http.expectOne(r=>r.params.get('view')==='streets');page.seleccionarCalle('Brasil');expect(req.cancelled).toBe(true);escribir('Bra');page.ionViewWillLeave();await vi.advanceTimersByTimeAsync(300);http.expectNone(r=>r.params.get('view')==='streets');});
 it('sugerencias vacías y error conservan el texto y no confirman ubicación',async()=>{vi.useFakeTimers();escribir('Br');await vi.advanceTimersByTimeAsync(300);http.expectOne(r=>r.params.get('view')==='streets').flush({success:true,data:[]});expect(page.sugerenciasRealizadas).toBe(true);escribir('Brasil');await vi.advanceTimersByTimeAsync(300);http.expectOne(r=>r.params.get('view')==='streets').flush({}, {status:503,statusText:'Error'});expect(page.calle).toBe('Brasil');expect(page.sugerenciasError).toBeTruthy();expect(page.ubicacionConfirmada).toBe(false);});
 it('guarda coordenadas corregidas, dirección, celular y foto y los restaura desde CAPTCHA',()=>{
  completar();page.corregirUbicacion();page.moverUbicacion({latitud:-34.906,longitud:-56.164});page.confirmarUbicacion();page.celular='099123456';page.consentimientoWhatsApp=true;page.foto=new File(['foto'],'foto.png',{type:'image/png'});page.continuar();
  const b=TestBed.inject(SolicitudRetiroFlowService).pendiente!;expect(b).toMatchObject({calle:'AVENIDA BRASIL',numero_referencia:'2359',latitud:-34.906,longitud:-56.164,consentimiento_whatsapp:true});expect(b.foto).toBe(page.foto);page.ionViewWillEnter();tipos();expect(page.ubicacionConfirmada).toBe(true);expect(page.ubicacion?.longitud).toBe(-56.164);expect(page.consentimientoWhatsApp).toBe(true);expect(page.calle).toBe('AVENIDA BRASIL, MONTEVIDEO, MONTEVIDEO');page.continuar();expect(TestBed.inject(SolicitudRetiroFlowService).pendiente?.calle).toBe('AVENIDA BRASIL');
 });
 it.each([true,false])('preserva consentimiento explícito %s y teléfono con domicilio confirmado',marcado=>{completar();page.celular='099123456';page.consentimientoWhatsApp=marcado;page.continuar();expect(TestBed.inject(SolicitudRetiroFlowService).pendiente?.consentimiento_whatsapp).toBe(marcado);});
 it('sin celular continúa con domicilio confirmado y consentimiento falso',()=>{completar();page.consentimientoWhatsApp=true;page.actualizarCampo('celular','');expect(page.formularioValido).toBe(true);page.continuar();expect(TestBed.inject(SolicitudRetiroFlowService).pendiente?.consentimiento_whatsapp).toBe(false);});
 it('teléfono inválido bloquea envío y tipo eliminado o catálogo fallido no inventa opciones',()=>{completar();page.celular='123';expect(page.formularioValido).toBe(false);page.ionViewWillEnter();http.expectOne('/backend/api/tipos_residuo.php').flush({}, {status:500,statusText:'Error'});expect(page.errorTipos).toBeTruthy();expect(page.tiposResiduo).toEqual([]);});
 it('cancelar vuelve al inicio sin datos operativos ni borrador',()=>{completar();page.continuar();page.cancelarUbicacion();expect(page.ubicacion).toBeNull();expect(TestBed.inject(SolicitudRetiroFlowService).pendiente).toBeNull();expect(TestBed.inject(Router).navigate).toHaveBeenLastCalledWith(['/home'],{replaceUrl:true});});
 it('un retiro nuevo no recupera el punto confirmado del trámite anterior',()=>{completar();page.ionViewWillEnter();tipos();expect(page.ubicacion).toBeNull();expect(page.ubicacionConfirmada).toBe(false);expect(page.calle).toBe('');});
 it('preserva validación de foto no válida, vacía y mayor de 5MB',()=>{for(const foto of [new File(['x'],'x.txt',{type:'text/plain'}),new File([],'x.png',{type:'image/png'}),new File([new Uint8Array(page.maxFotoBytes+1)],'x.png',{type:'image/png'})]){page.seleccionarFoto({target:{files:[foto],value:'x'}} as unknown as Event);expect(page.errorFoto).toBeTruthy();expect(page.foto).toBeNull();}});
 it('ofrece corrección manual tras geocodificación fallida y exige confirmar el punto', () => {
  page.calle='Calle sin resultado';page.numeroReferencia='123';page.calleElegida=true;
  page.buscarUbicacion();
  http.expectOne(r=>r.url.endsWith('/direcciones-retiro.php')).flush({success:true,data:null});
  expect(page.ubicacion).toBeNull();expect(page.ubicacionFallida).toBe(true);
  page.ubicarManualmente();expect(page.ubicacion?.aproximada).toBe(true);
  expect(page.ubicacionConfirmada).toBe(false);expect(page.corrigiendoUbicacion).toBe(true);
    expect(TestBed.inject(SolicitudRetiroFlowService).pendiente).toBeNull();
 });

});

