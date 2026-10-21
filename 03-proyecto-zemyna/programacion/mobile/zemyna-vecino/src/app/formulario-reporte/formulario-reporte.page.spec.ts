import { provideHttpClient } from '@angular/common/http';
import { provideRouter, Router } from '@angular/router';
import { vi } from 'vitest';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { FormularioReportePage } from './formulario-reporte.page';
import { FlujoIncidenciaService } from '../reporte-incidencia/flujo-incidencia.service';

describe('FormularioReportePage', () => {
  let component: FormularioReportePage;
  let fixture: ComponentFixture<FormularioReportePage>;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideRouter([])] });
    vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    fixture = TestBed.createComponent(FormularioReportePage);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
  it.each([true, false])('conserva consentimiento explícito %s al preparar y volver del CAPTCHA', marcado => {
    const flujo = TestBed.inject(FlujoIncidenciaService);
    flujo.identificar({ id_contenedor: 1, codigo: 'CTN-001', direccion: 'Brasil', estado: 'Disponible', latitud: null, longitud: null }, 'calle');
    component.ionViewWillEnter(); component.problema = component.motivos[0].value;
    component.actualizarCelular('099 123 456'); fixture.detectChanges();
    const checkbox = fixture.nativeElement.querySelector('input[type="checkbox"]') as HTMLInputElement;
    expect(checkbox.disabled).toBe(false); expect(checkbox.checked).toBe(false);
    checkbox.checked = marcado; checkbox.dispatchEvent(new Event('change'));
    component.enviarReporte();
    expect(flujo.pendiente?.consentimiento_whatsapp).toBe(marcado);
    component.consentimientoWhatsApp = false; component.ionViewWillEnter();
    expect(component.consentimientoWhatsApp).toBe(marcado);
  });
  it('sin celular mantiene el consentimiento falso y permite el reporte', () => {
    component.contenedorId = '1'; component.problema = component.motivos[0].value;
    component.consentimientoWhatsApp = true; component.actualizarCelular(''); fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('input[type="checkbox"]').disabled).toBe(true);
    expect(component.formularioValido).toBe(true); component.enviarReporte();
    expect(TestBed.inject(FlujoIncidenciaService).pendiente?.consentimiento_whatsapp).toBe(false);
  });
});
