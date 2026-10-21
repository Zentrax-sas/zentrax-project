import { Injectable } from '@angular/core';

@Injectable({providedIn: 'root'})
export class SeguimientoLocalService {
  guardado(tracking: string): boolean { return localStorage.getItem(`seguimiento:${tracking}`) === 'true'; }
  guardar(tracking: string, activo: boolean): void { localStorage.setItem(`seguimiento:${tracking}`, String(activo)); }
}
