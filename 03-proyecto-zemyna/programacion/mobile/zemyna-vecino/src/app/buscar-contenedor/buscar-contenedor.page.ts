import { ErroresService, MENSAJES } from '../errores.service';
import { ChangeDetectorRef, Component, ElementRef, inject, OnDestroy, ViewChild } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { IonContent, IonBackButton, IonButton, IonInput } from '@ionic/angular';
import { finalize, Subscription } from 'rxjs';
import { ContenedoresService, ContenedorPublico, LimitesMapa } from '../reporte-incidencia/contenedores.service';
import { ResultadosContenedoresComponent } from '../reporte-incidencia/resultados-contenedores.component';
import { FlujoIncidenciaService, Metodo } from '../reporte-incidencia/flujo-incidencia.service';
import { MapaContenedoresComponent } from './mapa-contenedores.component';

import { codigoDesdeQr } from './codigo-qr';
import { LECTOR_QR } from './lector-qr';

const MAX_RESULTADOS_GPS = 5;

@Component({
  selector: 'app-buscar-contenedor', standalone: true,
  templateUrl: './buscar-contenedor.page.html', styleUrls: ['./buscar-contenedor.page.scss', './_sugerencias.scss', './_calle-mapa.scss'],
  imports: [IonContent, IonBackButton, IonButton, IonInput, ResultadosContenedoresComponent, MapaContenedoresComponent],
})
export class BuscarContenedorPage implements OnDestroy {
  falloMapa(): void { this.aviso = MENSAJES.mapa; this.cd.markForCheck(); }
  private readonly errores = inject(ErroresService);
  private readonly api = inject(ContenedoresService);
  private readonly flujo = inject(FlujoIncidenciaService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly cd = inject(ChangeDetectorRef);
  private readonly lector = inject(LECTOR_QR);
  private lectura?: Subscription;
  escaneando = false;
  private request?: Subscription;
  private sugerenciasRequest?: Subscription;
  private sugerenciasTimer?: ReturnType<typeof setTimeout>;
  private calleSeleccionada = '';
  campoSugerencias: 'calle' | 'esquina' | null = null;
  sugerencias: string[] = [];
  sugerenciasCargando = false;
  sugerenciasRealizadas = false;
  sugerenciasError = '';
  private ultimaZona?: LimitesMapa;
  private generation = 0;
  @ViewChild('video') video?: ElementRef<HTMLVideoElement>;
  @ViewChild('campos') campos?: ElementRef<HTMLElement>;
  @ViewChild(MapaContenedoresComponent) private mapa?: MapaContenedoresComponent;
  metodo: Metodo = this.metodoDeRuta();
  calle = '';
  esquina = '';
  cargando = false;
  realizada = false;
  error = '';
  aviso = '';
  resultados: ContenedorPublico[] = [];
  distanciasGps = new Map<string, number>();
  seleccionado: ContenedorPublico | null = null;
  readonly titulos = { calle: 'Buscar dirección', gps: 'Mi ubicación', qr: 'Escanear QR', mapa: 'Seleccionar en mapa' };

  private metodoDeRuta(): Metodo {
    const metodo = this.route.snapshot.queryParamMap.get('metodo');
    return metodo === 'qr' || metodo === 'gps' || metodo === 'mapa' ? metodo : 'calle';
  }
  ionViewWillEnter(): void {
    const metodo = this.metodoDeRuta();
    if (metodo !== 'qr' && (metodo !== this.metodo || this.flujo.contenedor !== this.seleccionado)) {
      this.seleccionado = null;
    }
    if (metodo !== this.metodo) {
      this.cerrarSugerencias();
      this.limpiar();
      this.ultimaZona = undefined;
      this.metodo = metodo;
    } else if (metodo === 'mapa' && !this.cargando && !this.realizada && this.ultimaZona) {
      this.buscarZona(this.ultimaZona);
    }
    this.cd.markForCheck();
  }
  actualizar(campo: 'calle' | 'esquina', value: string | null | undefined): void {
    if (this.metodo === 'qr') this.detenerCamara();
    this[campo] = value ?? '';
    if (campo === 'calle') this.calleSeleccionada = '';
    this.limpiar();
    this.sugerir(campo);
  }
  cerrarSugerencias(): void {
    clearTimeout(this.sugerenciasTimer);
    this.sugerenciasRequest?.unsubscribe();
    this.campoSugerencias = null;
    this.sugerencias = []; this.sugerenciasCargando = false;
    this.sugerenciasRealizadas = false; this.sugerenciasError = '';
  }
  sugerir(campo: 'calle' | 'esquina'): void {
    this.cerrarSugerencias();
    const q = this[campo].trim();
    if (this.metodo !== 'calle' || Array.from(q).length < 2 || Array.from(q).length > 80) return;
    this.campoSugerencias = campo;
    this.sugerenciasCargando = true;
    this.sugerenciasTimer = setTimeout(() => {
      this.sugerenciasRequest = this.api.sugerirCalles(q, campo === 'esquina' ? this.calleSeleccionada : '').subscribe({
        next: data => {
          this.sugerencias = data; this.sugerenciasCargando = false; this.sugerenciasRealizadas = true;
          this.cd.markForCheck();
        },
        error: () => {
          this.sugerenciasCargando = false;
          this.sugerenciasError = MENSAJES.mapa;
          this.cd.markForCheck();
        },
      });
    }, 300);
  }
  seleccionarSugerencia(campo: 'calle' | 'esquina', nombre: string): void {
    this[campo] = nombre;
    if (campo === 'calle') this.calleSeleccionada = nombre;
    this.cerrarSugerencias();
    this.limpiar();
  }
  navegarSugerencias(event: Event): void {
    const teclado = event as KeyboardEvent;
    if (teclado.key !== 'ArrowDown' && teclado.key !== 'ArrowUp') return;
    const botones = Array.from(this.campos?.nativeElement.querySelectorAll<HTMLButtonElement>('.sugerencias button') ?? []);
    if (!botones.length) return;
    teclado.preventDefault();
    const actual = botones.indexOf(document.activeElement as HTMLButtonElement);
    const siguiente = actual < 0
      ? (teclado.key === 'ArrowDown' ? 0 : botones.length - 1)
      : (actual + (teclado.key === 'ArrowDown' ? 1 : -1) + botones.length) % botones.length;
    botones[siguiente].focus();
  }
  private limpiar(): void {
    this.request?.unsubscribe();
    if (this.metodo === 'calle') { this.seleccionado = null; this.flujo.reiniciar(); }
    this.cargando = false; this.realizada = false; this.error = ''; this.aviso = ''; this.resultados = []; this.distanciasGps.clear();
  }
  buscar(): void {
    if (this.metodo === 'qr') this.detenerCamara();
    this.limpiar();
    this.cerrarSugerencias();
    const calle = this.calle.trim(), esquina = this.esquina.trim();
    const q = this.metodo === 'qr' ? codigoDesdeQr(calle) : calle;
    if (q === null) {
      this.error = 'QR inválido. Ingresá el código del contenedor (ej.: CTN-001) o una URL con codigo o codigo_contenedor.';
      return;
    }
    if (Array.from(q).length < 2 || Array.from(q).length > 80 || (this.metodo === 'calle' && Array.from(esquina).length > 80)) {
      this.error = 'Ingresá entre 2 y 80 caracteres para buscar.'; return;
    }
    this.cargando = true;
    this.request = this.api.buscar(q, this.metodo === 'calle' ? esquina : undefined).subscribe({
      next: data => {
        if (this.metodo === 'qr') {
          const coincidencias = data.filter(c => typeof c?.codigo === 'string' && c.codigo.toUpperCase() === q);
          if (coincidencias.length > 1 || coincidencias.some(c => !/^[1-9]\d*$/.test(String(c.id_contenedor)) || !Number.isSafeInteger(Number(c.id_contenedor)) || typeof c.direccion !== 'string' || typeof c.estado !== 'string')) {
            this.fallar('No se pudo confirmar el contenedor. Intentá nuevamente.'); return;
          }
          this.resultados = coincidencias;
          if (!coincidencias.length) this.error = data.length >= 20
            ? 'La búsqueda no pudo confirmar ese código. Revisalo e intentá nuevamente.'
            : 'No se encontró un contenedor activo con ese código. Revisalo e intentá nuevamente.';
        } else {
          this.resultados = data;
          if (this.metodo === 'calle' && data.some(c => !this.ubicacion(c))) {
            this.aviso = 'Hay contenedores sin ubicación registrada que no se pueden mostrar en el mapa.';
          }
        }
        this.cargando = false; this.realizada = true; this.cd.markForCheck();
      },
      error: error => this.fallar(this.errores.mensaje(error, 'mapa')),
    });
  }
  buscarZona(zona: LimitesMapa, ubicacionReferencia?: { latitud: number; longitud: number }): void {
    if (this.metodo !== 'qr' && this.metodo !== 'mapa' && this.seleccionado) return;
    this.ultimaZona = zona;
    const anteriores = this.resultados;
    this.limpiar(); this.cargando = true;
    if (this.metodo === 'mapa') this.resultados = anteriores;
    this.request = this.api.enMapa(zona).subscribe({
      next: response => {
        if (ubicacionReferencia) {
          const cercanos = response.data
            .filter(contenedor => this.ubicacion(contenedor) !== null)
            .map(contenedor => ({
              contenedor,
              distancia: this.distanciaEnMetros(contenedor, ubicacionReferencia),
            }))
            .sort((a, b) => a.distancia - b.distancia)
            .slice(0, MAX_RESULTADOS_GPS);
          this.resultados = cercanos.map(resultado => resultado.contenedor);
          this.distanciasGps = new Map(cercanos.map(resultado => [
            String(resultado.contenedor.id_contenedor), resultado.distancia,
          ]));
        } else {
          this.resultados = response.data;
          this.distanciasGps.clear();
        }
        if (this.metodo === 'mapa' && this.seleccionado) {
          const actualizado = this.resultados.find(c => String(c.id_contenedor) === String(this.seleccionado!.id_contenedor));
          // Conserva la elección explícita aunque quede fuera de la nueva zona.
          if (actualizado) this.seleccionado = actualizado;
          else this.resultados = [this.seleccionado, ...this.resultados];
        }
        this.aviso = response.meta.hasMore ? (this.metodo === 'gps'
          ? 'Hay más contenedores en esta zona. Podés cambiar al método mapa para explorarla.'
          : 'Acercá el mapa para ver todos los contenedores de esta zona.') : '';
        this.cargando = false; this.realizada = true; this.cd.markForCheck();
      },
      error: error => this.fallar(this.errores.mensaje(error, 'mapa')),
    });
  }
  localizar(): void {
    this.obtenerUbicacion((lat, lon) => {
      this.buscarZona(
        { north: lat + .005, south: lat - .005, east: lon + .005, west: lon - .005, zoom: 17 },
        { latitud: lat, longitud: lon },
      );
    });
  }
  localizarEnMapa(): void {
    this.obtenerUbicacion((lat, lon) => {
      if (!this.mapa) {
        this.fallar(MENSAJES.ubicacion);
        return;
      }
      this.mapa.mostrarUbicacionUsuario(lat, lon);
      this.mapa.centrarEn(lat, lon, 17);
    });
  }
  private obtenerUbicacion(alEncontrar: (lat: number, lon: number) => void): void {
    this.limpiar();
    const generation = ++this.generation;
    if (!navigator.geolocation) { this.fallar(MENSAJES.ubicacion); return; }
    this.cargando = true;
    navigator.geolocation.getCurrentPosition(position => {
      if (generation !== this.generation) return;
      const { latitude: lat, longitude: lon } = position.coords;
      if (!Number.isFinite(lat) || !Number.isFinite(lon) || Math.abs(lat) > 90 || Math.abs(lon) > 180) {
        this.fallar(MENSAJES.ubicacion);
        return;
      }
      alEncontrar(lat, lon);
    }, () => {
      if (generation === this.generation) this.fallar(MENSAJES.ubicacion);
    }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 30000 });
  }
  escanear(): void {
    this.detenerCamara(); this.limpiar();
    if (!this.video) return;
    this.escaneando = true;
    let codigoLeido = false;
    this.lectura = this.lector.leer(this.video.nativeElement).pipe(finalize(() => {
      this.escaneando = false; this.cd.markForCheck();
    })).subscribe({
      next: codigo => { codigoLeido = true; this.calle = codigo; this.buscar(); this.cd.markForCheck(); },
      error: (error: Error) => this.fallar(this.errores.mensaje(error, 'qr')),
      complete: () => { if (!codigoLeido) this.fallar(MENSAJES.qrCancelada); },
    });
  }
  private fallar(message: string): void { this.cargando = false; this.error = message; this.cd.markForCheck(); }
  seleccionarContenedor(contenedor: ContenedorPublico): void {
    if (this.metodo === 'qr') return;
    if ((this.metodo === 'mapa' || this.metodo === 'calle') && (this.cargando || !this.ubicacion(contenedor))) return;
    if (!this.resultados.includes(contenedor) || !/^[1-9]\d*$/.test(String(contenedor.id_contenedor)) ||
        !Number.isSafeInteger(Number(contenedor.id_contenedor)) || typeof contenedor.codigo !== 'string' ||
        !contenedor.codigo.trim() || typeof contenedor.direccion !== 'string' || typeof contenedor.estado !== 'string') {
      this.fallar('No se pudo confirmar ese contenedor. Volvé a buscar.'); return;
    }
    this.generation++;
    this.request?.unsubscribe();
    this.cerrarSugerencias();
    this.cargando = false;
    this.error = '';
    this.seleccionado = contenedor;
    this.cd.markForCheck();
  }
  continuar(): void {
    if ((this.metodo === 'mapa' || this.metodo === 'calle') && !this.seleccionMapaValida) return;
    if (this.metodo !== 'qr' && this.seleccionado && this.resultados.includes(this.seleccionado)) {
      this.elegir(this.seleccionado);
    }
  }
  get seleccionMapaValida(): boolean {
    const c = this.seleccionado;
    return !!c && !this.cargando && !this.error && this.resultados.includes(c) &&
      /^[1-9]\d*$/.test(String(c.id_contenedor)) && Number.isSafeInteger(Number(c.id_contenedor)) &&
      typeof c.codigo === 'string' && !!c.codigo.trim() && typeof c.direccion === 'string' &&
      typeof c.estado === 'string' && !!this.ubicacion(c);
  }
  seleccionarDesdeMapa(id: string): void {
    if (this.metodo !== 'mapa' || this.cargando) return;
    this.seleccionado = null;
    const c = this.resultados.find(item => String(item.id_contenedor) === id);
    if (!c) return;
    this.seleccionarContenedor(c);
    if (this.seleccionMapaValida) this.mapa?.centrarEn(Number(c.latitud), Number(c.longitud));
  }
  cambiarContenedor(): void {
    if (this.metodo === 'qr') return;
    this.seleccionado = null;
    this.flujo.reiniciar();
    this.cd.markForCheck();
  }
  cancelar(): void {
    if (this.metodo === 'qr') return;
    this.generation++;
    this.cerrarSugerencias();
    this.limpiar();
    this.seleccionado = null;
    this.flujo.reiniciar();
    this.cd.markForCheck();
    void this.router.navigate(['/reporte-incidencia'], { replaceUrl: true });
  }
  elegir(contenedor: ContenedorPublico): void {
    if (this.metodo === 'qr' && !this.resultados.includes(contenedor)) return;
    this.detenerCamara();
    this.flujo.identificar(contenedor, this.metodo);
    void this.router.navigate(['/formulario-reporte'], { state: {
      id_contenedor: contenedor.id_contenedor, codigo: contenedor.codigo, direccion: contenedor.direccion, metodo: this.metodo,
    } });
  }
  detenerCamara(): void {
    this.generation++; this.lectura?.unsubscribe(); this.escaneando = false;
  }
  ubicacion(contenedor: ContenedorPublico): string | null {
    const lat = Number(contenedor.latitud), lon = Number(contenedor.longitud);
    if (contenedor.latitud === null || contenedor.longitud === null ||
        String(contenedor.latitud).trim() === '' || String(contenedor.longitud).trim() === '' ||
        !Number.isFinite(lat) || !Number.isFinite(lon) || Math.abs(lat) > 90 || Math.abs(lon) > 180) return null;
    return `${lat.toFixed(5)}, ${lon.toFixed(5)}`;
  }
  private distanciaEnMetros(contenedor: ContenedorPublico, referencia: { latitud: number; longitud: number }): number {
    const latitud = Number(contenedor.latitud) * Math.PI / 180;
    const latitudReferencia = referencia.latitud * Math.PI / 180;
    const diferenciaLatitud = (Number(contenedor.latitud) - referencia.latitud) * Math.PI / 180;
    const diferenciaLongitud = (Number(contenedor.longitud) - referencia.longitud) * Math.PI / 180;
    const senoLatitud = Math.sin(diferenciaLatitud / 2);
    const senoLongitud = Math.sin(diferenciaLongitud / 2);
    const haversine = senoLatitud ** 2 + Math.cos(latitudReferencia) * Math.cos(latitud) * senoLongitud ** 2;
    return 6371000 * 2 * Math.asin(Math.sqrt(Math.min(1, haversine)));
  }
  ionViewWillLeave(): void { this.cerrarSugerencias(); this.request?.unsubscribe(); this.cargando = false; this.detenerCamara(); }
  ngOnDestroy(): void { this.ionViewWillLeave(); }
}

