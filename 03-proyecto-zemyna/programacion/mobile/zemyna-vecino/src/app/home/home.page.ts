import { Component } from '@angular/core';
import { RouterLink } from '@angular/router';
import {
  IonContent,
  IonIcon
} from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  chevronForwardOutline,
  trashOutline,
  qrCodeOutline,
  documentTextOutline,
  warningOutline
} from 'ionicons/icons';

@Component({
  selector: 'app-home',
  templateUrl: 'home.page.html',
  styleUrls: ['home.page.scss'],
  imports: [
    IonContent,
    IonIcon,
    RouterLink
  ]
})
export class HomePage {
  constructor() {
    addIcons({ trashOutline, chevronForwardOutline, qrCodeOutline, documentTextOutline, warningOutline });
  }
}
