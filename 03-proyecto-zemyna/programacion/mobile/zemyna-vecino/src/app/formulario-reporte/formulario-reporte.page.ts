import { ErroresService, MENSAJES } from '../errores.service';
import { ChangeDetectorRef, Component, inject } from '@angular/core';
import { Router } from '@angular/router';
import { FotoPickerService } from '../foto-picker.service';
import { FlujoIncidenciaService } from '../reporte-incidencia/flujo-incidencia.service';
import {
  IonBackButton,
  IonButton,
  IonContent,
  IonIcon,
  IonInput,
  IonSelect,
  IonSelectOption
} from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  cameraOutline,
  checkmarkCircleOutline,
  locationOutline,
  sendOutline
} from 'ionicons/icons';

@Component({
  selector: 'app-formulario-reporte',
  templateUrl: './formulario-reporte.page.html',
  styleUrls: ['./formulario-reporte.page.scss', '../consentimiento-whatsapp.scss'],
  imports: [
    IonContent,
    IonBackButton,
    IonButton,
    IonIcon,
    IonInput,
    IonSelect,
    IonSelectOption
  ]
})
export class FormularioReportePage {
  private readonly errores = inject(ErroresService);
  private readonly cd = inject(ChangeDetectorRef);
  private readonly flujo = inject(FlujoIncidenciaService);
  private readonly router = inject(Router);
  private readonly fotoPicker = inject(FotoPickerService);

  get selectorFotoNativo(): boolean { return this.fotoPicker.esNativo; }

  contenedorId = '';
  contenedorCodigo = '';
  direccion = '';
  metodo = '';

  problema = '';
  celular = '';
  consentimientoWhatsApp = false;

  foto: File | null = null;
  fotoNombre = '';

  mensaje = '';

  readonly motivos = [
    {
      value: 'Contenedor Desbordado',
      label: 'Contenedor desbordado'
    },
    {
      value: 'Contenedor Roto/Dañado',
      label: 'Contenedor roto / dañado'
    },
    {
      value: 'Obstruido por Vehículo',
      label: 'Obstruido por vehículo'
    },
    {
      value: 'Incendio/Vandalismo',
      label: 'Incendio / vandalismo'
    }
  ];

  constructor() {
    addIcons({
      cameraOutline,
      checkmarkCircleOutline,
      locationOutline,
      sendOutline
    });

  }

  ionViewWillEnter(): void {
    const contenedor = this.flujo.contenedor;
    if (!contenedor) { void this.router.navigate(['/reporte-incidencia'], { replaceUrl: true }); return; }
    if (this.flujo.registrada) { void this.router.navigate(['/incidencia-generada'], { replaceUrl: true }); return; }
    this.contenedorId = String(contenedor.id_contenedor);
    this.contenedorCodigo = contenedor.codigo;
    this.direccion = contenedor.direccion;
    this.metodo = this.flujo.metodo;
    const pendiente = this.flujo.pendiente;
    this.problema = pendiente?.motivo ?? '';
    this.celular = pendiente?.celular ?? '';
    this.consentimientoWhatsApp = pendiente?.consentimiento_whatsapp === true;
    this.foto = pendiente?.foto ?? null;
    this.fotoNombre = this.foto?.name ?? '';
    this.mensaje = '';
    void this.fotoPicker.consumirFotoRestaurada('incidencia').then(file => {
      if (file) this.procesarFoto(file);
    }).catch(error => { this.mensaje = this.errores.mensaje(error, 'foto'); this.cd.markForCheck(); });
    this.cd.markForCheck();
  }

  actualizarProblema(value: string | null | undefined): void {
    this.problema = value ?? '';
    this.mensaje = '';
  }

  actualizarCelular(value: string | null | undefined): void {
    this.celular = value ?? '';
    if (!this.celular.trim()) this.consentimientoWhatsApp = false;
    this.mensaje = '';
  }

  seleccionarFoto(event: Event): void {
    const input = event.target as HTMLInputElement;

    if (!input.files?.length) { this.mensaje = MENSAJES.fotoCancelada; this.cd.markForCheck(); return; }

    this.procesarFoto(input.files[0], input);
  }

  async abrirSelectorFoto(event: MouseEvent): Promise<void> {
    if (!this.fotoPicker.esNativo) return;
    event.preventDefault();
    event.stopPropagation();
    try {
      const file = await this.fotoPicker.seleccionarFoto('incidencia');
      if (file) this.procesarFoto(file);
      else { this.mensaje = MENSAJES.fotoCancelada; this.cd.markForCheck(); }
    } catch (error) {
      this.mensaje = this.errores.mensaje(error, 'foto');
      this.cd.markForCheck();
    }
  }

  private procesarFoto(file: File, input?: HTMLInputElement): void {
    if (file.size > 5 * 1024 * 1024 || !['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || !/\.(jpe?g|png|webp)$/i.test(file.name)) {
      this.foto = null;
      this.fotoNombre = '';
      if (input) input.value = '';
      this.mensaje = file.size > 5 * 1024 * 1024 ? MENSAJES.fotoTamano : MENSAJES.fotoFormato;
      return;
    }
    this.foto = file;
    this.fotoNombre = this.foto.name;
    this.mensaje = '';
    this.cd.markForCheck();
  }

  get celularValido(): boolean {
    if (!this.celular.trim()) return true;

    const soloNumeros = this.celular.replace(/\D/g, '');
    return soloNumeros.length >= 8;
  }

  get formularioValido(): boolean {
    return Boolean(
      this.contenedorId &&
      this.motivos.some(m => m.value === this.problema) &&
      this.celularValido && (!this.consentimientoWhatsApp || !!this.celular.trim())
    );
  }

  enviarReporte(): void {
    if (!this.contenedorId) {
      this.mensaje = 'No hay un contenedor seleccionado.';
      return;
    }

    if (!this.motivos.some(m => m.value === this.problema)) {
      this.mensaje = 'Seleccioná el motivo de la incidencia.';
      return;
    }

    if (!this.celularValido || (this.consentimientoWhatsApp && !this.celular.trim())) {
      this.mensaje = 'Ingresá un celular válido o dejalo vacío.';
      return;
    }

    this.mensaje = '';

    this.flujo.pendiente = {
      id_contenedor: this.contenedorId,
      codigo_contenedor: this.contenedorCodigo,
      direccion: this.direccion,
      metodo: this.flujo.metodo,
      motivo: this.problema,
      descripcion: `${this.problema}. Contenedor: ${this.contenedorCodigo}. Dirección: ${this.direccion}.`,
      celular: this.celular.trim(),
      consentimiento_whatsapp: this.consentimientoWhatsApp,
      foto: this.foto,
    };
    void this.router.navigate(['/captcha-reporte']);
  }
}
