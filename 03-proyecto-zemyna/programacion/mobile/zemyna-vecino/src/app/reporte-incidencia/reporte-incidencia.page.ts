import { ChangeDetectorRef, Component, inject } from '@angular/core';
import { Router } from '@angular/router';
import { IonContent, IonBackButton, IonIcon } from '@ionic/angular';
import { addIcons } from 'ionicons';
import { chevronForwardOutline, locationOutline, mapOutline, navigateOutline, qrCodeOutline } from 'ionicons/icons';
import { FlujoIncidenciaService, Metodo } from './flujo-incidencia.service';

@Component({
  selector: 'app-reporte-incidencia',
  standalone: true,
  templateUrl: './reporte-incidencia.page.html',
  styleUrls: ['./reporte-incidencia.page.scss'],
  imports: [IonContent, IonBackButton, IonIcon],
})
export class ReporteIncidenciaPage {
  private readonly cd = inject(ChangeDetectorRef);
  private readonly router = inject(Router);
  private readonly flujo = inject(FlujoIncidenciaService);
  metodoSeleccionado: Metodo | null = null;
  constructor() {
    addIcons({ chevronForwardOutline, locationOutline, mapOutline, navigateOutline, qrCodeOutline });
  }
  seleccionarMetodo(metodo: Metodo): void {
    this.metodoSeleccionado = metodo;
    this.flujo.reiniciar();
    void this.router.navigate(['/buscar-contenedor'], { queryParams: { metodo } });
  }
  ionViewWillEnter(): void { this.metodoSeleccionado = null; this.cd.markForCheck(); }
}
