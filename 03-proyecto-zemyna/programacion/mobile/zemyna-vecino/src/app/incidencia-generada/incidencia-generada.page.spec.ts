import { provideHttpClient } from '@angular/common/http';
import { provideRouter } from '@angular/router';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { IncidenciaGeneradaPage } from './incidencia-generada.page';
import { afterEach, vi } from 'vitest';
import { ComprobanteIncidenciaService } from './comprobante-incidencia.service';
import { GuardarWhatsappService } from '../guardar-whatsapp.service';

describe('IncidenciaGeneradaPage', () => {
  let component: IncidenciaGeneradaPage;
  let fixture: ComponentFixture<IncidenciaGeneradaPage>;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideRouter([])] });
    fixture = TestBed.createComponent(IncidenciaGeneradaPage);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });

  it('abre WhatsApp con tracking y celular de la incidencia sin alterar datos', async () => {
    const abrir = vi.spyOn(TestBed.inject(GuardarWhatsappService), 'abrir').mockResolvedValue('Revisá el mensaje en WhatsApp.');
    component.incidencia = {tracking_number:'INC-2026-ABCDE', celular:'099123456'};
    fixture.detectChanges();
    const botones = [...fixture.nativeElement.querySelectorAll('button')] as HTMLButtonElement[];
    botones.find(b => b.textContent?.includes('Guardar en WhatsApp'))!.click();
    await fixture.whenStable();
    expect(abrir).toHaveBeenCalledWith('incidencia','INC-2026-ABCDE','099123456');
    expect(component.incidencia.celular).toBe('099123456');
    abrir.mockRejectedValue(new Error('No pudimos abrir WhatsApp.'));
    await component.guardarEnWhatsapp();
    expect(component.mensaje).toBe('No pudimos abrir WhatsApp.');
    expect(component.abriendoWhatsapp).toBe(false);
  });

  afterEach(() => vi.restoreAllMocks());

  it('el botón existente invoca el generador con los datos reales', async () => {
    const generar = vi.spyOn(TestBed.inject(ComprobanteIncidenciaService), 'generar').mockResolvedValue();
    component.incidencia = { tracking_number: 'INC-2026-ABCDE', motivo: 'Desbordado' };
    const boton = [...fixture.nativeElement.querySelectorAll('button')] as HTMLButtonElement[];
    boton.find(b => b.textContent?.includes('comprobante'))!.click();
    await fixture.whenStable();
    expect(generar).toHaveBeenCalledWith(expect.objectContaining(component.incidencia));
    expect(component.mensaje).toBe('Comprobante generado.');
  });

  it('controla falta de tracking y fallo del generador', async () => {
    const generar = vi.spyOn(TestBed.inject(ComprobanteIncidenciaService), 'generar').mockRejectedValue(new Error('Error nativo'));
    await component.descargarComprobante();
    expect(generar).not.toHaveBeenCalled();
    expect(component.mensaje).toContain('Falta el número de seguimiento');
    component.incidencia = { tracking_number: 'INC-2026-ABCDE' };
    await component.descargarComprobante();
    expect(component.mensaje).toContain('No se pudo generar o compartir');
  });

  it('ignora un segundo clic mientras se genera el comprobante', async () => {
    let terminar!: () => void;
    const generar = vi.spyOn(TestBed.inject(ComprobanteIncidenciaService), 'generar').mockReturnValue(new Promise<void>(resolve => terminar = resolve));
    component.incidencia = { tracking_number: 'INC-2026-ABCDE' };
    const primero = component.descargarComprobante();
    await component.descargarComprobante();
    expect(generar).toHaveBeenCalledTimes(1);
    terminar();
    await primero;
  });

  it('guarda el seguimiento local y permite desactivarlo', () => {
    const escribir = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => undefined);
    component.incidencia = { tracking_number: 'INC-2026-ABCDE' };
    component.activarSeguimiento();
    expect(escribir).toHaveBeenCalledWith('seguimiento:INC-2026-ABCDE', 'true');
    expect(component.seguimientoActivo).toBe(true);
    expect(component.mensaje).toContain('Seguimiento guardado en este dispositivo.');
    component.activarSeguimiento();
    expect(escribir).toHaveBeenLastCalledWith('seguimiento:INC-2026-ABCDE', 'false');
    expect(component.seguimientoActivo).toBe(false);
    expect(component.mensaje).toBe('Guardado local desactivado.');
  });

  it.each([false, true])('conserva el estado %s y no anuncia éxito si falla el almacenamiento', previo => {
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('Storage bloqueado'); });
    component.incidencia = { tracking_number: 'INC-2026-ABCDE' };
    component.seguimientoActivo = previo;
    component.activarSeguimiento();
    expect(component.seguimientoActivo).toBe(previo);
    expect(component.mensaje).toBe('No se pudo actualizar el guardado en este dispositivo. Intentá nuevamente.');
  });
});
