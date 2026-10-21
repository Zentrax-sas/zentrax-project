import { inject, Injectable } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { ContenedorPublico } from './contenedores.service';

export type Metodo = 'qr' | 'gps' | 'calle' | 'mapa';
export interface ReportePendiente {
  id_contenedor: string;
  codigo_contenedor: string;
  direccion: string;
  metodo: Metodo;
  motivo: string;
  descripcion: string;
  celular: string;
  consentimiento_whatsapp?: boolean;
  foto: File | null;
}
export interface IncidenciaRegistrada {
  descripcion?: string;
  celular?: string;
  id_incidencia: number;
  tracking_number: string;
  id_contenedor: string;
  codigo_contenedor: string;
  direccion: string;
  motivo: string;
  fecha?: string;
  estado?: string;
  aviso?: string;
}
@Injectable({ providedIn: 'root' })
export class FlujoIncidenciaService {
  contenedor: ContenedorPublico | null = null;
  metodo: Metodo = 'calle';
  pendiente: ReportePendiente | null = null;
  registrada: IncidenciaRegistrada | null = null;
  identificar(contenedor: ContenedorPublico, metodo: Metodo): void {
    this.contenedor = contenedor;
    this.metodo = metodo;
    this.pendiente = null;
    this.registrada = null;
  }
  reiniciar(): void {
    this.contenedor = null;
    this.pendiente = null;
    this.registrada = null;
  }
}
export const contenedorIdentificado: CanActivateFn = () =>
  !!inject(FlujoIncidenciaService).contenedor || inject(Router).parseUrl('/reporte-incidencia');
export const reportePreparado: CanActivateFn = () =>
  !!inject(FlujoIncidenciaService).pendiente || inject(Router).parseUrl('/reporte-incidencia');
export const incidenciaRegistrada: CanActivateFn = () =>
  !!inject(FlujoIncidenciaService).registrada || inject(Router).parseUrl('/reporte-incidencia');
