import { ErroresService, MENSAJES } from '../errores.service';
import { Component, inject, OnDestroy, signal } from '@angular/core';
import { IonHeader, IonToolbar, IonTitle, IonButtons, IonBackButton, IonContent, IonIcon } from '@ionic/angular';
import { addIcons } from 'ionicons';
import { megaphoneOutline } from 'ionicons/icons';
import { Subscription } from 'rxjs';
import { NovedadesService } from './novedades.service';
import { Novedad } from './novedad.model';

@Component({
  selector: 'app-novedades', standalone: true,
  templateUrl: './novedades.page.html', styleUrls: ['./novedades.page.scss'],
  imports: [IonHeader, IonToolbar, IonTitle, IonButtons, IonBackButton, IonContent, IonIcon],
})
export class NovedadesPage implements OnDestroy {
  private readonly errores = inject(ErroresService);
  constructor() { addIcons({ megaphoneOutline }); }
  private readonly api = inject(NovedadesService);
  private request?: Subscription;
  readonly novedades = signal<Novedad[]>([]);
  readonly cargando = signal(true);
  readonly error = signal('');
  ionViewWillEnter(): void { this.cargar(); }
  cargar(): void {
    this.request?.unsubscribe();
    this.cargando.set(true); this.error.set(''); this.novedades.set([]);
    this.request = this.api.listar().subscribe({
      next: data => { this.novedades.set(data); this.cargando.set(false); },
      error: error => {
        this.error.set(this.errores.mensaje(error, 'novedades'));
        this.cargando.set(false);
      },
    });
  }
  // La BD guarda hora local sin zona: se presenta sin convertirla a UTC.
  fechaVisible(fecha: string): string {
    const [dia, hora] = fecha.split(' ');
    const [anio, mes, numero] = dia.split('-');
    return `${numero}/${mes}/${anio} · ${hora.slice(0, 5)}`;
  }
  ionViewWillLeave(): void { this.request?.unsubscribe(); }
  ngOnDestroy(): void { this.ionViewWillLeave(); }
}
