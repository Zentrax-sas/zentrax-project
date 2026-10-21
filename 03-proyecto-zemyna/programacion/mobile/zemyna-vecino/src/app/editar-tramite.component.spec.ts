import { TestBed } from '@angular/core/testing';
import { of, throwError } from 'rxjs';
import { HttpErrorResponse } from '@angular/common/http';
import { vi } from 'vitest';
import { EditarTramiteComponent } from './editar-tramite.component';
import { EdicionTramiteService } from './edicion-tramite.service';
import { SolicitudesRetiroService } from './solicitud-retiro/solicitudes-retiro.service';
import { ErrorUsuario } from './errores.service';

describe('Edición del trámite',()=>{
  const original={tracking_number:'RET-2026-ABCDE',descripcion:'Microondas',id_tipo_residuo:1,tipo_residuo:'Pilas',revision:0,editable:true,estado:'Pendiente',fecha:'2026-10-14',direccion:'Brasil 123',tipo_tramite:'Solicitud de retiro'};
  let api:{disponible:ReturnType<typeof vi.fn>;consultar:ReturnType<typeof vi.fn>;guardar:ReturnType<typeof vi.fn>};
  beforeEach(()=>{
    api={disponible:vi.fn(()=>true),consultar:vi.fn(()=>of({...original})),guardar:vi.fn((tracking,data)=>of({...original,...data,revision:1,tipo_residuo:'Electrodoméstico'}))};
    TestBed.configureTestingModule({providers:[{provide:EdicionTramiteService,useValue:api},{provide:SolicitudesRetiroService,useValue:{obtenerTiposResiduo:()=>of([{id_tipo_residuo:1,nombre:'Pilas'},{id_tipo_residuo:2,nombre:'Electrodoméstico'}])}}]});
  });
  async function crear(){const fixture=TestBed.createComponent(EditarTramiteComponent);fixture.componentRef.setInput('tracking',original.tracking_number);fixture.detectChanges();await fixture.componentInstance.abrir();fixture.detectChanges();return fixture;}
  it('precarga datos, corrige tipo/agrega descripción y emite solo confirmación real',async()=>{
    const fixture=await crear(),page=fixture.componentInstance,emitir=vi.spyOn(page.actualizado,'emit');
    expect(page.descripcion).toBe('Microondas');expect(page.tipo).toBe(1);
    page.tipo=2;page.descripcion+=' con cable';await page.guardar();
    expect(api.guardar).toHaveBeenCalledWith(original.tracking_number,{descripcion:'Microondas con cable',revision:0,id_tipo_residuo:2});
    expect(emitir).toHaveBeenCalledWith(expect.objectContaining({tipo_residuo:'Electrodoméstico',revision:1}));
    expect(page.mensaje()).toContain('guardados');
  });
  it('rechaza vacío y conserva borrador/error sin éxito en conflicto',async()=>{
    const fixture=await crear(),page=fixture.componentInstance,emitir=vi.spyOn(page.actualizado,'emit');
    page.descripcion=' ';await page.guardar();expect(api.guardar).not.toHaveBeenCalled();
    page.descripcion='Texto corregido';api.guardar.mockReturnValue(throwError(()=>new HttpErrorResponse({status:409})));
    await page.guardar();expect(page.descripcion).toBe('Texto corregido');expect(page.datos()).not.toBeNull();expect(emitir).not.toHaveBeenCalled();expect(page.mensaje()).toContain('cambió');expect(page.ocupado()).toBe(false);
  });
  it('no guarda registros cerrados y cancelar no altera el original',async()=>{
    api.consultar.mockReturnValue(of({...original,estado:'Finalizada',editable:false}));
    const fixture=await crear(),page=fixture.componentInstance;
    await page.guardar();expect(api.guardar).not.toHaveBeenCalled();page.cancelar();expect(page.datos()).toBeNull();expect(original.descripcion).toBe('Microondas');
  });
  it('conserva mensajes de autorización y conflicto al recibir errores del interceptor real',async()=>{
    const fixture=await crear(),page=fixture.componentInstance;
    api.consultar.mockReturnValue(throwError(()=>new ErrorUsuario('Error seguro','validacion',new HttpErrorResponse({status:403}))));
    page.cancelar();await page.abrir();
    expect(page.mensaje()).toBe('Este dispositivo no tiene autorización para editar el trámite.');
    api.consultar.mockReturnValue(of({...original}));await page.abrir();page.descripcion='Texto que no debe perderse';
    api.guardar.mockReturnValue(throwError(()=>new ErrorUsuario('Error seguro','validacion',new HttpErrorResponse({status:409}))));
    await page.guardar();expect(page.mensaje()).toContain('cambió o está cerrado');expect(page.descripcion).toBe('Texto que no debe perderse');
  });
});
