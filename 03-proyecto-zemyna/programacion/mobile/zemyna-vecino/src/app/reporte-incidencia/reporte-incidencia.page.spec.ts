import { provideHttpClient } from '@angular/common/http';
import { provideRouter, Router } from '@angular/router';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { ReporteIncidenciaPage } from './reporte-incidencia.page';
import { vi } from 'vitest';
import { FlujoIncidenciaService, Metodo } from './flujo-incidencia.service';

describe('ReporteIncidenciaPage', () => {
  let component: ReporteIncidenciaPage;
  let fixture: ComponentFixture<ReporteIncidenciaPage>;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideRouter([])] });
    fixture = TestBed.createComponent(ReporteIncidenciaPage);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });

  it.each(['qr', 'gps', 'mapa', 'calle'] as Metodo[])('abre el método %s y descarta el reporte anterior', metodo => {
    const router = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    const flujo = TestBed.inject(FlujoIncidenciaService);
    flujo.identificar({ id_contenedor: 1, codigo: 'CTN-001', direccion: 'Brasil', estado: 'Disponible', latitud: null, longitud: null }, 'calle');
    const indice = ['qr', 'gps', 'calle', 'mapa'].indexOf(metodo);
    (fixture.nativeElement.querySelectorAll('.metodo-card')[indice] as HTMLButtonElement).click();
    expect(router).toHaveBeenCalledWith(['/buscar-contenedor'], { queryParams: { metodo } });
    expect(flujo.contenedor).toBeNull();
    router.mockRestore();
  });

  it('restablece las opciones al volver de una búsqueda cancelada', () => {
    component.metodoSeleccionado = 'gps';
    component.ionViewWillEnter();
    expect(component.metodoSeleccionado).toBeNull();
  });
});
