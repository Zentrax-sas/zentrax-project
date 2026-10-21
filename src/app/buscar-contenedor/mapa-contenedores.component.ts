import { Component, input, output, signal, OnInit, OnChanges, SimpleChanges } from '@angular/core';
import { IonIcon } from '@ionic/angular';
import { addIcons } from 'ionicons';
import { locateOutline } from 'ionicons/icons';
import { ContenedorPublico, LimitesMapa } from '../reporte-incidencia/contenedores.service';

@Component({
  selector: 'app-mapa-contenedores', standalone: true,
  template: `
    <div class="controles" aria-label="Mover el mapa">
      <button (click)="mover(0, -128)" aria-label="Norte">↑</button>
      <button (click)="mover(-128, 0)" aria-label="Oeste">←</button>
      <button (click)="mover(128, 0)" aria-label="Este">→</button>
      <button (click)="mover(0, 128)" aria-label="Sur">↓</button>
      <button (click)="acercar(1)" [disabled]="zoom === 19" aria-label="Acercar">+</button>
      <button (click)="acercar(-1)" [disabled]="zoom === zoomMinimo" aria-label="Alejar">−</button>
    </div>
    <div class="mapa" aria-label="Contenedores en la zona visible"
      (pointerdown)="iniciarArrastre($event)" (pointerup)="terminarArrastre($event)" (pointercancel)="cancelarArrastre()">
      <svg viewBox="0 0 320 320" role="img" aria-label="Mapa de calles">
        @for (tile of tiles; track tile.url) {
          <image (error)="fallo.emit()" [attr.href]="tile.url" [attr.x]="tile.x" [attr.y]="tile.y" width="256" height="256" />
        }
      </svg>
      @if (permitirUbicacion()) {
      <button class="localizar" type="button" [disabled]="ubicacionCargando()"
        aria-label="Centrar en mi ubicación" (click)="solicitarUbicacion.emit()">
        <ion-icon name="locate-outline" aria-hidden="true"></ion-icon>
      </button>
      }
      @if (ubicacionUsuario(); as usuario) {
        @if (visible(usuario)) {
          <img class="user-location-marker" src="assets/icons/user-location.svg" alt="Mi ubicación actual"
            draggable="false" [style.left.%]="punto(usuario)[0] / 3.2" [style.top.%]="punto(usuario)[1] / 3.2" />
        }
      }
      @for (c of contenedores(); track c.id_contenedor) {
        @if (visible(c)) {
          <button class="punto" [style.left.%]="punto(c)[0] / 3.2" [style.top.%]="punto(c)[1] / 3.2"
            [class.seleccionado]="esSeleccionado(c)" [attr.aria-pressed]="esSeleccionado(c)"
            [attr.aria-label]="c.codigo + ': ' + c.direccion" [title]="c.direccion" (click)="seleccionar.emit(c)">●</button>
        }
      }
    </div>
    <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">© OpenStreetMap contributors</a>
  `,
  imports: [IonIcon],
  styleUrls: ['./user-location-marker.scss'],
  styles: `
    :host { display: block; }
    :host-context(.calle-screen) .mapa { width: min(100%, clamp(200px, 28dvh, 260px)); margin-inline: auto; }
    :host-context(.mapa-screen) { container-type: size; display: flex; flex-direction: column; align-items: center; gap: 3px; }
    :host-context(.mapa-screen) .controles { flex: none; width: 100%; margin-bottom: 0; }
    :host-context(.mapa-screen) .mapa { flex: none; width: min(100cqw, calc(100cqh - 59px)); }
    :host-context(.mapa-screen) a { flex: none; line-height: 13px; }
    .mapa { position: relative; width: 100%; aspect-ratio: 1; overflow: hidden; background: #263416; border-radius: 12px; touch-action: none; }
    svg { display: block; width: 100%; height: 100%; }
    .controles { display: flex; gap: 4px; margin-bottom: 8px; }
    button { min-width: 40px; min-height: 40px; color: #a8e600; background: #151c19; border: 1px solid #36403b; border-radius: 8px; cursor: pointer; }
    .localizar { position: absolute; top: 8px; right: 8px; z-index: 1; display: grid; place-items: center; }
    .localizar ion-icon { font-size: 20px; }
    .punto { position: absolute; transform: translate(-50%, -50%); min-width: 24px; min-height: 24px; border-radius: 50%; }
    .punto.seleccionado { background:#a8e600; color:#0b0f0d; border:3px solid #0b0f0d; outline:2px solid #a8e600; min-width:32px; min-height:32px; z-index:2; }
    button:focus-visible { outline: 2px solid #a8e600; }
    a { color: #c0c9c4; font-size: 11px; }
  `,
})
export class MapaContenedoresComponent implements OnInit, OnChanges {
  fallo = output<void>();
  readonly ubicacionUsuario = signal<{ latitud: number; longitud: number } | null>(null);
  mostrarUbicacionUsuario(latitud: number, longitud: number): void {
    this.ubicacionUsuario.set({ latitud, longitud });
  }
  contenedores = input.required<ContenedorPublico[]>();
  seleccionadoId = input<string | number | null>(null);
  ubicacionCargando = input(false);
  permitirUbicacion = input(true);
  ajustarResultados = input(false);
  get zoomMinimo(): number { return this.ajustarResultados() ? 1 : 15; }
  seleccionar = output<ContenedorPublico>();
  solicitarUbicacion = output<void>();
  zona = output<LimitesMapa>();
  zoom = 16;
  private lat = -34.905;
  private lon = -56.185;
  private arrastre?: { id: number; x: number; y: number; ancho: number };
  esSeleccionado(c: ContenedorPublico): boolean { return String(c.id_contenedor) === String(this.seleccionadoId()); }
  iniciarArrastre(event: PointerEvent): void {
    if ((event.target as Element).closest('button') || this.arrastre || event.button !== 0) return;
    const mapa = event.currentTarget as HTMLElement;
    this.arrastre = { id:event.pointerId, x:event.clientX, y:event.clientY, ancho:mapa.getBoundingClientRect().width };
    mapa.setPointerCapture(event.pointerId);
  }
  terminarArrastre(event: PointerEvent): void {
    const inicio = this.arrastre;
    if (!inicio || inicio.id !== event.pointerId) return;
    this.arrastre = undefined;
    if (inicio.ancho > 0 && Math.hypot(event.clientX-inicio.x, event.clientY-inicio.y) > 5) {
      this.mover((inicio.x-event.clientX)*320/inicio.ancho, (inicio.y-event.clientY)*320/inicio.ancho);
    }
  }
  cancelarArrastre(): void { this.arrastre = undefined; }
  tiles: { url: string; x: number; y: number }[] = [];
  private get escala() { return 256 * 2 ** this.zoom; }
  constructor() { addIcons({ locateOutline }); }
  private proyectar(lat: number, lon: number): number[] {
    const sin = Math.sin(lat * Math.PI / 180);
    return [(lon + 180) / 360 * this.escala, (0.5 - Math.log((1 + sin) / (1 - sin)) / (4 * Math.PI)) * this.escala];
  }
  private coordenadas(x: number, y: number): number[] {
    return [Math.atan(Math.sinh(Math.PI * (1 - 2 * y / this.escala))) * 180 / Math.PI, x / this.escala * 360 - 180];
  }
  punto(c: Pick<ContenedorPublico, 'latitud' | 'longitud'>): number[] {
    const p = this.proyectar(Number(c.latitud), Number(c.longitud));
    const center = this.proyectar(this.lat, this.lon);
    return [p[0] - center[0] + 160, p[1] - center[1] + 160];
  }
  visible(c: Pick<ContenedorPublico, 'latitud' | 'longitud'>): boolean {
    return c.latitud !== null && c.longitud !== null && this.punto(c).every(v => Number.isFinite(v) && v >= 0 && v <= 320);
  }
  ngOnInit(): void { queueMicrotask(() => this.actualizar()); }
  ngOnChanges(changes: SimpleChanges): void {
    if (!this.ajustarResultados() || !changes['contenedores']) return;
    const puntos = this.contenedores().filter(c => c.latitud !== null && c.longitud !== null &&
      String(c.latitud).trim() !== '' && String(c.longitud).trim() !== '' &&
      Number.isFinite(Number(c.latitud)) && Number.isFinite(Number(c.longitud)) &&
      Math.abs(Number(c.latitud)) <= 85 && Math.abs(Number(c.longitud)) <= 180);
    if (!puntos.length) return;
    this.zoom = 19;
    let proyectados = puntos.map(c => this.proyectar(Number(c.latitud), Number(c.longitud)));
    const limites = (indice: number) => [Math.min(...proyectados.map(p => p[indice])), Math.max(...proyectados.map(p => p[indice]))];
    while (this.zoom > this.zoomMinimo && [0, 1].some(i => limites(i)[1] - limites(i)[0] > 256)) {
      this.zoom--;
      proyectados = puntos.map(c => this.proyectar(Number(c.latitud), Number(c.longitud)));
    }
    const x = limites(0), y = limites(1);
    [this.lat, this.lon] = this.coordenadas((x[0] + x[1]) / 2, (y[0] + y[1]) / 2);
    this.zoom = Math.min(this.zoom, 17);
    this.actualizar();
  }
  mover(x: number, y: number): void {
    const center = this.proyectar(this.lat, this.lon);
    [this.lat, this.lon] = this.coordenadas(center[0] + x, center[1] + y);
    this.lat = Math.max(-85, Math.min(85, this.lat));
    this.lon = ((this.lon + 540) % 360) - 180;
    this.actualizar();
  }
  acercar(delta: number): void { this.zoom = Math.max(this.zoomMinimo, Math.min(19, this.zoom + delta)); this.actualizar(); }
  centrarEn(lat: number, lon: number, zoom = 17): void {
    this.lat = Math.max(-85, Math.min(85, lat));
    this.lon = ((lon + 540) % 360) - 180;
    this.zoom = Math.max(15, Math.min(19, zoom));
    this.actualizar();
  }
  private actualizar(): void {
    const [x, y] = this.proyectar(this.lat, this.lon);
    const [north, west] = this.coordenadas(x - 160, y - 160);
    const [south, east] = this.coordenadas(x + 160, y + 160);
    this.tiles = [];
    for (let tx = Math.floor((x - 160) / 256); tx <= Math.floor((x + 160) / 256); tx++) {
      for (let ty = Math.floor((y - 160) / 256); ty <= Math.floor((y + 160) / 256); ty++) {
        this.tiles.push({ url: `https://tile.openstreetmap.org/${this.zoom}/${tx}/${ty}.png`, x: tx * 256 - x + 160, y: ty * 256 - y + 160 });
      }
    }
    this.zona.emit({ north, south, east, west, zoom: this.zoom });
  }
}
