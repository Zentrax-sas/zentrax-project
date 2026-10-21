import { Component, inject } from '@angular/core';
import { IonApp, IonRouterOutlet } from '@ionic/angular';
import { FotoPickerService } from './foto-picker.service';

@Component({
  selector: 'app-root',
  templateUrl: 'app.component.html',
  imports: [IonApp, IonRouterOutlet],
})
export class AppComponent {
  private readonly fotoPicker = inject(FotoPickerService);

  constructor() {
    this.fotoPicker.registrarResultadoRestaurado();
  }
}
