import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideHttpClient, HttpErrorResponse } from '@angular/common/http';
import { AlertController } from '@ionic/angular';
import { of, throwError } from 'rxjs';
import { vi } from 'vitest';
import { ConsultarEstadoPage } from './consultar-estado.page';
import { EdicionTramiteService } from '../edicion-tramite.service';
import { ErrorUsuario } from '../errores.service';
import { Seguimiento } from './seguimiento.service';

describe('Cancelación exclusiva en Consultar estado',()=>{
  const api={cancelar:vi.fn()};
  const alerta={present:vi.fn(),onDidDismiss:vi.fn()};
  const alertas={create:vi.fn()};
  beforeEach(()=>{
    vi.clearAllMocks();alerta.present.mockResolvedValue(undefined);alerta.onDidDismiss.mockResolvedValue({role:'confirm'});alertas.create.mockResolvedValue(alerta);
    TestBed.configureTestingModule({providers:[provideRouter([]),provideHttpClient(),{provide:AlertController,useValue:alertas},{provide:EdicionTramiteService,useValue:api}]});
  });
  function datos(prefijo='INC'):Seguimiento{return {tracking_number:`${prefijo}-2026-ABCDE`,tipo_tramite:prefijo==='INC'?'Incidencia':'Solicitud de retiro',estado:'Pendiente',fecha:'2026-10-14',direccion:'Brasil 123',descripcion:'Texto original',revision:0,cancelable:true,asignada:false};}
  function crear(dato=datos()) {const fixture=TestBed.createComponent(ConsultarEstadoPage);fixture.componentInstance.resultado.set(dato);fixture.detectChanges();return fixture;}
  for(const prefijo of ['INC','RET'])it(`${prefijo} confirma y cancela una sola vez, conservando tracking y datos`,async()=>{
    const original=datos(prefijo),fixture=crear(original),page=fixture.componentInstance;
    api.cancelar.mockReturnValue(of({...original,estado:'Cancelada',cancelable:false,revision:1,fecha_cancelacion:'2026-10-14 12:30:00'}));
    await page.cancelar();fixture.detectChanges();
    expect(alertas.create).toHaveBeenCalledWith(expect.objectContaining({header:prefijo==='INC'?'¿Querés cancelar esta incidencia?':'¿Querés cancelar esta solicitud?'}));
    expect(api.cancelar).toHaveBeenCalledWith(original.tracking_number,0);
    expect(page.resultado()).toEqual(expect.objectContaining({estado:'Cancelada',tracking_number:original.tracking_number,descripcion:original.descripcion,fecha:original.fecha}));
    expect(fixture.nativeElement.querySelector('.cancelar-registro')).toBeNull();
    expect(fixture.nativeElement.textContent).toContain(original.tracking_number);expect(fixture.nativeElement.textContent).not.toMatch(/trámite/i);
    await page.cancelar();expect(api.cancelar).toHaveBeenCalledTimes(1);
  });
  it('volver en la confirmación no hace ninguna petición',async()=>{
    alerta.onDidDismiss.mockResolvedValue({role:'cancel'});const page=crear().componentInstance;await page.cancelar();expect(api.cancelar).not.toHaveBeenCalled();expect(page.resultado()?.estado).toBe('Pendiente');
  });
  for(const prefijo of ['INC','RET'])it(`${prefijo} bloquea asignación, en camino, proceso y estados finales`,async()=>{
    const fixture=crear(datos(prefijo)),page=fixture.componentInstance;
    for(const estado of ['Pendiente','Aceptada','En camino','En Proceso','Programada','Resuelta','Finalizada','Cancelada']){
      page.resultado.set({...datos(prefijo),estado,asignada:true,cancelable:false});fixture.detectChanges();await page.cancelar();
      expect(fixture.nativeElement.querySelector('.cancelar-registro')).toBeNull();
      if(!page.finalizada(page.resultado()!))expect(fixture.nativeElement.textContent).toContain(`Esta ${prefijo==='INC'?'incidencia':'solicitud'} ya fue asignada. Para solicitar la cancelación, comunicate con Atención al Cliente al 0800 0800.`);
    }
    expect(alertas.create).not.toHaveBeenCalled();expect(api.cancelar).not.toHaveBeenCalled();
  });
  it('si cambió entre consulta y POST, actualiza estado y ofrece contacto sin falso éxito',async()=>{
    const fixture=crear(datos('RET')),page=fixture.componentInstance;
    const actual={...datos('RET'),estado:'Programada',asignada:true,cancelable:false};
    api.cancelar.mockReturnValue(throwError(()=>new ErrorUsuario('Error seguro','validacion',new HttpErrorResponse({status:409,error:{data:actual}}))));
    await page.cancelar();fixture.detectChanges();
    expect(page.resultado()?.estado).toBe('Programada');expect(page.aviso()).toBe('');expect(page.error()).toContain('estado cambió');expect(fixture.nativeElement.textContent).toContain('0800 0800');expect(fixture.nativeElement.querySelector('.cancelar-registro')).toBeNull();
  });
  it('sin autorización conserva los datos y muestra bloqueo específico',async()=>{
    const fixture=crear(),page=fixture.componentInstance;api.cancelar.mockReturnValue(throwError(()=>new ErrorUsuario('Error seguro','validacion',new HttpErrorResponse({status:403}))));
    await page.cancelar();expect(page.error()).toBe('Este dispositivo no tiene autorización para cancelar esta incidencia.');expect(page.resultado()?.estado).toBe('Pendiente');expect(page.aviso()).toBe('');
  });
});
