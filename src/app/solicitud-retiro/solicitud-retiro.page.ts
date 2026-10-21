import { ErroresService, MENSAJES } from '../errores.service';
import { ChangeDetectorRef, Component, ElementRef, inject, OnDestroy } from '@angular/core';
import { Subscription } from 'rxjs';
import { FotoPickerService } from '../foto-picker.service';
import { DireccionesRetiroService, UbicacionRetiro } from './direcciones-retiro.service';
import { MapaDomicilioComponent } from './mapa-domicilio.component';
import { Router } from '@angular/router';
import { SolicitudRetiroFlowService } from './solicitud-retiro-flow.service';
import { SolicitudesRetiroService, TipoResiduo } from './solicitudes-retiro.service';
import {
  IonBackButton,
  IonButton,
  IonContent,
  IonIcon,
  IonInput,
  IonSelect,
  IonSelectOption,
  IonTextarea
} from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  cameraOutline,
  locationOutline,
  sendOutline
} from 'ionicons/icons';

@Component({
  selector: 'app-solicitud-retiro',
  templateUrl: './solicitud-retiro.page.html',
  styleUrls: ['./solicitud-retiro.page.scss', '../buscar-contenedor/_sugerencias.scss', '../consentimiento-whatsapp.scss', './_domicilio.scss'],
  imports: [
    IonContent,
    IonBackButton,
    IonButton,
    IonIcon,
    IonInput,
    IonSelect,
    IonSelectOption,
    IonTextarea, MapaDomicilioComponent
  ]
})
export class SolicitudRetiroPage implements OnDestroy {
  falloMapa(): void { this.mensaje = MENSAJES.mapa; this.cd.markForCheck(); }
  private readonly errores = inject(ErroresService);
  private readonly router = inject(Router);
  private readonly flujo = inject(SolicitudRetiroFlowService);
  private readonly api = inject(SolicitudesRetiroService);
  private tiposRequest?: Subscription;
  tiposCargando = false;
  errorTipos = '';
  tiposResiduo: TipoResiduo[] = [];
  readonly opcionesTipo = { cssClass: 'retiro-tipos-residuo' };
  private readonly calles = inject(DireccionesRetiroService);
  private ubicacionRequest?: Subscription;
  calle = '';
  numeroReferencia = '';
  ubicacion: UbicacionRetiro | null = null;
  ubicacionConfirmada = false;
  ubicacionCargando = false;
  corrigiendoUbicacion = false;
  ubicacionFallida = false;
  calleElegida = false;
  private readonly cd = inject(ChangeDetectorRef);
  private readonly hostRef = inject(ElementRef<HTMLElement>);
  private readonly fotoPicker = inject(FotoPickerService);
  private sugerenciasTimer?: ReturnType<typeof setTimeout>;
  private sugerenciasRequest?: Subscription;
  sugerencias: string[] = [];
  sugerenciasMaxHeight = 176;
  sugerenciasAbiertas = false;
  sugerenciasCargando = false;
  sugerenciasRealizadas = false;
  sugerenciasError = '';

  readonly maxFotoBytes = 5 * 1024 * 1024;
  get selectorFotoNativo(): boolean { return this.fotoPicker.esNativo; }

  tipoResiduo: number | null = null;
  direccion = '';
  celular = '';
  consentimientoWhatsApp = false;
  descripcion = '';

  foto: File | null = null;
  fotoNombre = '';

  mensaje = '';
  errorFoto = '';

  constructor() {
    addIcons({
      cameraOutline,
      locationOutline,
      sendOutline
    });
  }

  ionViewWillEnter(): void {
    this.cerrarSugerencias();
    this.cargarTipos();
    const borrador = this.flujo.pendiente;
    this.consentimientoWhatsApp = borrador?.consentimiento_whatsapp === true;
    if (!borrador) {
      this.invalidarUbicacion(); this.calle = ''; this.numeroReferencia = ''; this.calleElegida = false;
      return;
    }
    this.tipoResiduo = borrador.id_tipo_residuo;
    this.direccion = borrador.direccion;
    this.calle = borrador.calle_busqueda ?? borrador.calle ?? '';
    this.numeroReferencia = borrador.numero_referencia ?? '';
    this.calleElegida = !!this.calle;
    this.ubicacion = typeof borrador.latitud === 'number' && typeof borrador.longitud === 'number'
      ? {calle: borrador.calle ?? this.calle, numero_referencia: this.numeroReferencia, direccion: borrador.direccion, latitud: borrador.latitud, longitud: borrador.longitud} : null;
    this.ubicacionConfirmada = this.ubicacion !== null;
    this.celular = borrador.celular;
    this.descripcion = borrador.descripcion;
    this.foto = borrador.foto;
    this.fotoNombre = borrador.foto?.name ?? '';
    this.errorFoto = '';
    void this.fotoPicker.consumirFotoRestaurada('retiro').then(file => {
      if (file) this.procesarFoto(file);
    }).catch(error => { this.errorFoto = this.errores.mensaje(error, 'foto'); this.cd.markForCheck(); });
  }

  cargarTipos(): void {
    this.tiposRequest?.unsubscribe();
    this.tiposCargando = true;
    this.errorTipos = '';
    this.tiposResiduo = [];
    this.cd.markForCheck();
    this.tiposRequest = this.api.obtenerTiposResiduo().subscribe({
      next: tipos => {
        this.tiposResiduo = tipos;
        this.tiposCargando = false;
        if (!tipos.length) this.errorTipos = 'No hay tipos de residuo disponibles.';
        if (!tipos.some(tipo => tipo.id_tipo_residuo === this.tipoResiduo)) this.tipoResiduo = null;
        this.cd.markForCheck();
      },
      error: error => {
        this.tiposCargando = false;
        this.errorTipos = this.errores.mensaje(error, 'conexion');
        this.desplazarA('.mensaje-formulario[role=alert]');
        this.cd.markForCheck();
      }
    });
  }

  actualizarTipo(value: number | null | undefined): void {
    this.tipoResiduo = value ?? null;
    this.mensaje = '';
  }

  actualizarCampo(
    campo: 'direccion' | 'celular' | 'descripcion',
    value: string | null | undefined
  ): void {
    this[campo] = value ?? '';
    if (campo === 'celular' && !this.celular.trim()) this.consentimientoWhatsApp = false;
    this.mensaje = '';
    if (campo === 'direccion') this.sugerirCalles();
  }

  sugerirCalles(): void {
    this.cerrarSugerencias();
    const q = this.calle.trim();
    if (Array.from(q).length < 2 || Array.from(q).length > 80) return;
    this.sugerenciasAbiertas = true;
    this.sugerenciasCargando = true;
    this.sugerenciasTimer = setTimeout(() => {
      const input = this.hostRef.nativeElement.querySelector('.input-icon ion-input');
      const viewportHeight = window.visualViewport?.height ?? window.innerHeight;
      const content = this.hostRef.nativeElement.querySelector('.retiro-content');
      const limiteInferior = Math.min(
        viewportHeight,
        content?.getBoundingClientRect().bottom ?? viewportHeight
      );
      const espacioDisponible = input
        ? limiteInferior - input.getBoundingClientRect().bottom
        : 176;
      this.sugerenciasMaxHeight = Math.min(176, Math.max(0, espacioDisponible));
      this.cd.markForCheck();
      this.sugerenciasRequest = this.calles.sugerirCalles(q).subscribe({
        next: data => {
          this.sugerencias = data;
          this.sugerenciasCargando = false;
          this.sugerenciasRealizadas = true;
          if (!data.length) { this.sugerenciasError = MENSAJES.direccion; this.ubicacionFallida = true; }
          this.cd.markForCheck();
        },
        error: () => {
          this.sugerenciasCargando = false;
          this.sugerenciasError = MENSAJES.direccion; this.ubicacionFallida = true;
          this.cd.markForCheck();
        },
      });
    }, 300);
  }

  seleccionarCalle(nombre: string): void {
    this.invalidarUbicacion();
    this.calle = nombre;
    this.calleElegida = true;
    this.mensaje = '';
    this.cerrarSugerencias();
  }
  actualizarCalle(value: string | null | undefined): void {
    this.calle = value ?? ''; this.calleElegida = false; this.invalidarUbicacion(); this.sugerirCalles();
  }
  actualizarReferencia(value: string | null | undefined): void {
    this.numeroReferencia = value ?? ''; this.invalidarUbicacion();
  }
  private invalidarUbicacion(): void {
    this.ubicacionRequest?.unsubscribe(); this.ubicacionCargando = false;
    this.ubicacionFallida = false; this.ubicacion = null; this.ubicacionConfirmada = false; this.corrigiendoUbicacion = false; this.direccion = '';
    this.flujo.pendiente = null;
  }
  buscarUbicacion(): void {
    if (!this.calleElegida || !this.numeroReferencia.trim()) { this.mensaje = 'Seleccioná una calle e ingresá el número o referencia.'; return; }
    this.invalidarUbicacion(); this.cerrarSugerencias(); this.ubicacionCargando = true; this.mensaje = '';
    this.ubicacionRequest = this.calles.resolver(this.calle, this.numeroReferencia.trim()).subscribe({
      next: punto => { this.ubicacion = punto; this.ubicacionCargando = false; if(!punto){this.mensaje=MENSAJES.direccion;this.ubicacionFallida=true;this.desplazarA('.mensaje-formulario[role=alert]');} this.cd.markForCheck(); },
      error: () => { this.ubicacionCargando = false; this.mensaje = MENSAJES.direccion; this.ubicacionFallida = true; this.desplazarA('.mensaje-formulario[role=alert]'); this.cd.markForCheck(); }
    });
  }
  private desplazarA(selector: string): void {
    requestAnimationFrame(() => this.hostRef.nativeElement.querySelector(selector)?.scrollIntoView?.({block:'nearest'}));
  }
  ubicarManualmente(): void {
    this.calleElegida = true; this.cerrarSugerencias();
    this.ubicacion = {calle:this.calle, numero_referencia:this.numeroReferencia, latitud:-34.905, longitud:-56.185, direccion:[this.calle, this.numeroReferencia].filter(Boolean).join(' '), aproximada:true};
    this.ubicacionConfirmada = false; this.corrigiendoUbicacion = true; this.ubicacionFallida = false;
    this.mensaje = 'Mové el mapa hasta el lugar del retiro y confirmá el punto.';
    this.cd.markForCheck(); this.desplazarA('.confirmacion-domicilio .mapa');
  }
  corregirUbicacion(): void { this.ubicacionConfirmada = false; this.corrigiendoUbicacion = true; this.flujo.pendiente = null; }
  moverUbicacion(punto: {latitud: number; longitud: number}): void {
    if(this.ubicacion && Number.isFinite(punto.latitud) && Number.isFinite(punto.longitud)) {
      this.ubicacion = {...this.ubicacion, ...punto}; this.ubicacionConfirmada = false;
    }
  }
  confirmarUbicacion(): void {
    if(!this.ubicacion)return;
    this.ubicacionConfirmada = true; this.corrigiendoUbicacion = false;
    this.direccion = this.ubicacion.direccion; this.mensaje = '';
  }
  cancelarUbicacion(): void {
    this.cerrarSugerencias(); this.invalidarUbicacion(); this.flujo.reiniciar();
    this.calle = ''; this.numeroReferencia = ''; this.calleElegida = false;
    this.cd.markForCheck(); void this.router.navigate(['/home'], {replaceUrl: true});
  }

  cerrarSugerencias(): void {
    clearTimeout(this.sugerenciasTimer);
    this.sugerenciasRequest?.unsubscribe();
    this.sugerenciasAbiertas = false;
    this.sugerencias = [];
    this.sugerenciasCargando = false;
    this.sugerenciasRealizadas = false;
    this.sugerenciasError = '';
  }

  navegarSugerencias(event: Event): void {
    const teclado = event as KeyboardEvent;
    if (teclado.key !== 'ArrowDown' && teclado.key !== 'ArrowUp') return;
    const botones = Array.from((event.currentTarget as HTMLElement).querySelectorAll<HTMLButtonElement>('.sugerencias button'));
    if (!botones.length) return;
    teclado.preventDefault();
    const actual = botones.indexOf(document.activeElement as HTMLButtonElement);
    const siguiente = actual < 0
      ? (teclado.key === 'ArrowDown' ? 0 : botones.length - 1)
      : (actual + (teclado.key === 'ArrowDown' ? 1 : -1) + botones.length) % botones.length;
    botones[siguiente].focus();
  }

  ionViewWillLeave(): void { this.cerrarSugerencias(); this.ubicacionRequest?.unsubscribe(); this.ubicacionCargando = false; }
  ngOnDestroy(): void { this.ionViewWillLeave(); this.tiposRequest?.unsubscribe(); }

  seleccionarFoto(event: Event): void {
    const input = event.target as HTMLInputElement;

    if (!input.files?.length) { this.errorFoto = ''; this.mensaje = MENSAJES.fotoCancelada; this.cd.markForCheck(); return; }

    this.procesarFoto(input.files[0], input);
  }

  async abrirSelectorFoto(event: MouseEvent): Promise<void> {
    if (!this.fotoPicker.esNativo) return;
    event.preventDefault();
    event.stopPropagation();
    try {
      const file = await this.fotoPicker.seleccionarFoto('retiro');
      if (file) this.procesarFoto(file);
      else { this.errorFoto = ''; this.mensaje = MENSAJES.fotoCancelada; this.cd.markForCheck(); }
    } catch (error) {
      this.errorFoto = this.errores.mensaje(error, 'foto');
      this.cd.markForCheck();
    }
  }

  private procesarFoto(archivo: File, input?: HTMLInputElement): void {
    this.errorFoto = '';
    const tiposPermitidos = ['image/jpeg', 'image/png', 'image/webp'];
    if (!tiposPermitidos.includes(archivo.type)) {
      this.foto = null;
      this.fotoNombre = '';
      this.errorFoto = MENSAJES.fotoFormato;
      if (input) input.value = '';
      return;
    }
    if (archivo.size === 0 || archivo.size > this.maxFotoBytes) {
      this.foto = null;
      this.fotoNombre = '';
      this.errorFoto = MENSAJES.fotoTamano;
      if (input) input.value = '';
      return;
    }

    this.foto = archivo;
    this.fotoNombre = archivo.name;
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
      !this.tiposCargando && !this.errorTipos && this.tiposResiduo.some(tipo => tipo.id_tipo_residuo === this.tipoResiduo) &&
      this.direccion.trim() && this.ubicacionConfirmada && this.ubicacion &&
      this.descripcion.trim() &&
      this.celularValido && (!this.consentimientoWhatsApp || !!this.celular.trim()) &&
      !this.errorFoto
    );
  }

  continuar(): void {
    const tipo = this.tiposResiduo.find(tipo => tipo.id_tipo_residuo === this.tipoResiduo);
    if (!tipo || this.tiposCargando || this.errorTipos) {
      this.mensaje = 'Seleccioná el tipo de residuo.';
      return;
    }

    if (!this.direccion.trim() || !this.ubicacionConfirmada || !this.ubicacion) {
      this.mensaje = 'Confirmá el lugar del retiro.';
      return;
    }

    if (!this.descripcion.trim()) {
      this.mensaje = 'Ingresá una breve descripción u observación.';
      return;
    }

    if (!this.celularValido || (this.consentimientoWhatsApp && !this.celular.trim())) {
      this.mensaje = 'Ingresá un celular válido o dejalo vacío.';
      return;
    }

    this.mensaje = '';

    this.flujo.pendiente = {
      id_tipo_residuo: tipo.id_tipo_residuo,
      tipo_residuo: tipo.nombre,
      direccion: this.direccion.trim(),
      calle: this.ubicacion.calle,
      calle_busqueda: this.calle,
      numero_referencia: this.numeroReferencia.trim(),
      latitud: this.ubicacion.latitud,
      longitud: this.ubicacion.longitud,
      descripcion: this.descripcion.trim(),
      celular: this.celular.trim(),
      consentimiento_whatsapp: this.consentimientoWhatsApp,
      foto: this.foto,
    };
    void this.router.navigate(['/captcha-retiro']);
  }
}
