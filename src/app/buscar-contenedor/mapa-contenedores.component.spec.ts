import { TestBed } from '@angular/core/testing';
import { vi } from 'vitest';
import { MapaContenedoresComponent } from './mapa-contenedores.component';

describe('Mapa: interacción manual', () => {
  it('arrastra con escala de pantalla, cancela gestos y mantiene zoom sin seleccionar', async () => {
    const fixture = TestBed.createComponent(MapaContenedoresComponent);
    fixture.componentRef.setInput('contenedores', []);
    fixture.detectChanges(); await fixture.whenStable();
    const mapa = fixture.componentInstance;
    const zona = vi.spyOn(mapa.zona, 'emit');
    const seleccion = vi.spyOn(mapa.seleccionar, 'emit');
    const elemento = fixture.nativeElement.querySelector('.mapa') as HTMLElement;
    elemento.setPointerCapture = vi.fn();
    vi.spyOn(elemento, 'getBoundingClientRect').mockReturnValue({ width:320 } as DOMRect);
    const evento = (x:number) => ({ target:elemento, currentTarget:elemento, pointerId:1, button:0, clientX:x, clientY:100 } as unknown as PointerEvent);
    mapa.iniciarArrastre(evento(100)); mapa.terminarArrastre(evento(160));
    expect(zona).toHaveBeenCalledTimes(1);
    mapa.iniciarArrastre(evento(100)); mapa.cancelarArrastre(); mapa.terminarArrastre(evento(160));
    expect(zona).toHaveBeenCalledTimes(1);
    mapa.acercar(1); expect(mapa.zoom).toBe(17);
    expect(seleccion).not.toHaveBeenCalled();
    fixture.destroy(); TestBed.resetTestingModule();
  });
  it('dibuja la ubicación real sin cambiar zona ni selección y la conserva al mover el mapa', async () => {
    const fixture = TestBed.createComponent(MapaContenedoresComponent);
    fixture.componentRef.setInput('contenedores', [{id_contenedor:1, codigo:'IDM-1', direccion:'Calle', estado:'Activo', latitud:-34.905, longitud:-56.185}]);
    fixture.componentRef.setInput('seleccionadoId', 1);
    fixture.detectChanges(); await fixture.whenStable();
    const mapa = fixture.componentInstance;
    const zona = vi.spyOn(mapa.zona, 'emit');
    const seleccion = vi.spyOn(mapa.seleccionar, 'emit');
    const zoom = mapa.zoom;
    mapa.mostrarUbicacionUsuario(-34.905, -56.185);
    fixture.detectChanges();
    const indicador = fixture.nativeElement.querySelector('.user-location-marker') as HTMLImageElement;
    expect(indicador.alt).toBe('Mi ubicación actual');
    expect(indicador.getAttribute('src')).toBe('assets/icons/user-location.svg');
    expect(indicador.style.left).toBe('50%');
    expect(fixture.nativeElement.querySelectorAll('.punto.seleccionado').length).toBe(1);
    expect(zona).not.toHaveBeenCalled();
    expect(seleccion).not.toHaveBeenCalled();
    expect(mapa.zoom).toBe(zoom);
    mapa.mover(40, 0); fixture.changeDetectorRef.markForCheck(); fixture.detectChanges();
    expect(mapa.punto(mapa.ubicacionUsuario()!)[0]).toBeCloseTo(120, 5);
    expect(mapa.ubicacionUsuario()).toEqual({latitud:-34.905, longitud:-56.185});
    expect(seleccion).not.toHaveBeenCalled();
    fixture.destroy(); TestBed.resetTestingModule();
  });

});
