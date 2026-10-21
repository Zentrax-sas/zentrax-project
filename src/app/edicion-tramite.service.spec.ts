import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting, HttpTestingController } from '@angular/common/http/testing';
import { EdicionTramiteService } from './edicion-tramite.service';
import { API_BASE_URL } from './api.config';
import { SolicitudRetiroFlowService } from './solicitud-retiro/solicitud-retiro-flow.service';
describe('Claves privadas de edición',()=>{
  beforeEach(()=>TestBed.configureTestingModule({providers:[provideHttpClient(),provideHttpClientTesting()]}));
  afterEach(()=>{TestBed.inject(HttpTestingController).verify();localStorage.clear();});
  it('persiste autorización y la transmite en header, nunca en URL',()=>{
    const api=TestBed.inject(EdicionTramiteService),http=TestBed.inject(HttpTestingController),tracking='RET-2026-ABCDE',token='a'.repeat(64);
    api.recordar({tracking_number:tracking,edicion_token:token});expect(api.disponible(tracking)).toBe(true);
    api.consultar(tracking).subscribe();const req=http.expectOne(r=>r.url===`${API_BASE_URL}/edicion-tramite.php`);
    expect(req.request.headers.get('Authorization')).toBe(`Bearer ${token}`);expect(req.request.urlWithParams).not.toContain(token);
    req.flush({success:true,data:{tracking_number:tracking,revision:0}});
  });
  it('ignora claves inválidas sin impedir alta existente',()=>{
    const api=TestBed.inject(EdicionTramiteService);api.recordar({tracking_number:'INC-2026-ABCDE',edicion_token:null});expect(api.disponible('INC-2026-ABCDE')).toBe(false);
  });
  it('actualiza la confirmación en memoria al editar desde seguimiento',()=>{
    const api=TestBed.inject(EdicionTramiteService),http=TestBed.inject(HttpTestingController),flujo=TestBed.inject(SolicitudRetiroFlowService);
    flujo.registrada={id_solicitud:1,tracking_number:'RET-2026-ABCDE',tipo_residuo:'Pilas',direccion:'Brasil 123',estado:'Pendiente',fecha:'2026-10-14',celular:null};
    api.guardar('RET-2026-ABCDE',{descripcion:'Microondas',revision:0,id_tipo_residuo:2}).subscribe();
    const req=http.expectOne(r=>r.url===`${API_BASE_URL}/edicion-tramite.php`);
    req.flush({success:true,data:{tracking_number:'RET-2026-ABCDE',descripcion:'Microondas',tipo_residuo:'Electrodoméstico',estado:'Pendiente',revision:1}});
    expect(flujo.registrada.tipo_residuo).toBe('Electrodoméstico');expect(flujo.registrada.descripcion).toBe('Microondas');expect(flujo.registrada.fecha).toBe('2026-10-14');
  });
  it('cancelación usa autorización privada y solo envía revisión',()=>{
    const api=TestBed.inject(EdicionTramiteService),http=TestBed.inject(HttpTestingController),tracking='INC-2026-ABCDE';
    api.recordar({tracking_number:tracking,edicion_token:'a'.repeat(64)});
    let actual='';api.cancelar(tracking,3).subscribe(dato=>actual=dato.estado);
    const req=http.expectOne(r=>r.url===`${API_BASE_URL}/cancelacion.php`);
    expect(req.request.method).toBe('POST');expect(req.request.body).toEqual({revision:3});expect(req.request.headers.get('Authorization')).toBe('Bearer '+'a'.repeat(64));
    req.flush({success:true,data:{tracking_number:tracking,estado:'Cancelada',fecha_cancelacion:'2026-10-14 12:00:00'}});expect(actual).toBe('Cancelada');
  });
});
