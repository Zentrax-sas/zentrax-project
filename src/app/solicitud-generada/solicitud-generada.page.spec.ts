import { ComprobanteIncidenciaService } from '../incidencia-generada/comprobante-incidencia.service';
import { GuardarWhatsappService } from '../guardar-whatsapp.service';
import { provideHttpClient } from '@angular/common/http';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { afterEach } from 'vitest';
import { SolicitudRetiroFlowService } from '../solicitud-retiro/solicitud-retiro-flow.service';
import { SolicitudGeneradaPage } from './solicitud-generada.page';

describe('SolicitudGeneradaPage', () => {
  let page: SolicitudGeneradaPage;
  let fixture: ComponentFixture<SolicitudGeneradaPage>;
  let navigate: ReturnType<typeof vi.fn>;
  let flujo: SolicitudRetiroFlowService;

  afterEach(() => { vi.restoreAllMocks(); localStorage.clear(); TestBed.resetTestingModule(); });

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideRouter([]),provideHttpClient()] });
    navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    flujo = TestBed.inject(SolicitudRetiroFlowService);
    flujo.registrada = {
      id_solicitud: 42, tracking_number: 'RET-2026-ABCDE', tipo_residuo: 'Mueble',
      direccion: 'Av. Rivera 123', estado: 'Pendiente', fecha: '2026-09-27 12:00:00', celular: null
    };
    fixture = TestBed.createComponent(SolicitudGeneradaPage);
    page = fixture.componentInstance;
    page.ionViewWillEnter();
    fixture.detectChanges();
  });

  it('muestra el código y los datos devueltos por backend', () => {
    const text = fixture.nativeElement.textContent;
    expect(text).toContain('RET-2026-ABCDE');
    expect(text).toContain('Mueble');
    expect(text).toContain('Av. Rivera 123');
    expect(text).toContain('Pendiente');
    expect(text).toContain('27/9/26');
    expect(text).not.toContain('Celular');
  });

  it('limpia el flujo y vuelve a Home', () => {
    page.volverInicio();
    expect(flujo.registrada).toBeNull();
    expect(navigate).toHaveBeenCalledWith(['/home']);
  });
  it('abre WhatsApp con el retiro correcto, con celular y sin celular, y conserva el trámite al cancelar', async () => {
    const abrir = vi.spyOn(TestBed.inject(GuardarWhatsappService), 'abrir').mockResolvedValue('Si cancelás, tu trámite sigue registrado.');
    await page.guardarEnWhatsapp();
    expect(abrir).toHaveBeenLastCalledWith('retiro','RET-2026-ABCDE',null);
    flujo.registrada!.celular = '099123456';
    await page.guardarEnWhatsapp();
    expect(abrir).toHaveBeenLastCalledWith('retiro','RET-2026-ABCDE','099123456');
    expect(flujo.registrada?.tracking_number).toBe('RET-2026-ABCDE');
    expect(page.mensaje).toContain('Si cancelás');
    abrir.mockRejectedValue(new Error('No pudimos abrir WhatsApp.'));
    await page.guardarEnWhatsapp();
    expect(page.mensaje).toContain('No pudimos abrir');
    expect(page.abriendoWhatsapp).toBe(false);
  });
  it('usa los datos reales para el comprobante y conserva estado ante fallo', async () => {
    const pdf = vi.spyOn(TestBed.inject(ComprobanteIncidenciaService), 'generarRetiro').mockResolvedValue();
    await page.descargarComprobante();expect(pdf).toHaveBeenCalledWith(flujo.registrada);
    expect(page.mensaje).toBe('Comprobante generado.');
    pdf.mockRejectedValue(new Error('Sin espacio'));await page.descargarComprobante();
    expect(page.mensaje).toContain('No se pudo generar');expect(page.generandoComprobante).toBe(false);
  });
  it('guarda seguimiento local, lo recupera y consulta con el tracking original', () => {
    page.guardarSeguimiento();expect(localStorage.getItem('seguimiento:RET-2026-ABCDE')).toBe('true');
    page.ionViewWillEnter();expect(page.seguimientoActivo).toBe(true);
    expect(page.mensaje).not.toMatch(/notificaciones|habilitado|disponible/);
    page.consultarSeguimiento();expect(navigate).toHaveBeenCalledWith(['/consultar-estado'],{queryParams:{codigo:'RET-2026-ABCDE'}});
  });
  it('no anuncia guardado si el almacenamiento falla', () => {
    vi.spyOn(Storage.prototype,'setItem').mockImplementation(()=>{throw new Error('bloqueado')});
    page.guardarSeguimiento();expect(page.seguimientoActivo).toBe(false);expect(page.mensaje).toContain('No se pudo actualizar');
  });

});
