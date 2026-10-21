import { inject, Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { map, throwError, timeout } from 'rxjs';
import { API_BASE_URL } from '../api.config';

export const FORMATO_TRACKING = /^(INC|RET|REF)-\d{4}-[A-F0-9]{5}$/;
export interface Seguimiento {
  tipo_problema?: string;
  tipo_residuo?: string;
  updated_at?: string | null;
  revision?: number;
  asignada?: boolean;
  cancelable?: boolean;
  fecha_cancelacion?: string | null;
  tracking_number: string;
  tipo_tramite: string;
  estado: string;
  fecha: string;
  direccion: string | null;
  descripcion: string | null;
}

@Injectable({ providedIn: 'root' })
export class SeguimientoService {
  private readonly http = inject(HttpClient);

  consultar(codigo: string) {
    const tracking = codigo.trim().toUpperCase();
    if (!FORMATO_TRACKING.test(tracking)) return throwError(() => new Error('Código inválido.'));
    return this.http.get<{ success: boolean; data: Seguimiento }>(`${API_BASE_URL}/incidencias.php`, {
      params: { tracking_number: tracking, detalle: '1' },
    }).pipe(timeout(15000), map(response => {
      const data = response.data;
      if (!response.success || data?.tracking_number !== tracking ||
          ![data?.tipo_tramite, data?.estado, data?.fecha].every(v => typeof v === 'string' && v.trim()) ||
          ![data?.direccion, data?.descripcion].every(v => v === null || typeof v === 'string')) {
        throw new Error('Respuesta de seguimiento incompleta.');
      }
      return data;
    }));
  }
}
