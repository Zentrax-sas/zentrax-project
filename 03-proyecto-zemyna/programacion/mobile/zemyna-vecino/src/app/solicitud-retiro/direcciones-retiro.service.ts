import { inject, Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { map, timeout } from 'rxjs';
import { API_BASE_URL } from '../api.config';
export interface UbicacionRetiro { calle: string; numero_referencia: string; direccion: string; latitud: number; longitud: number; aproximada?: boolean; }
@Injectable({ providedIn: 'root' })
export class DireccionesRetiroService {
  private readonly http = inject(HttpClient);
  sugerirCalles(q: string) {
    return this.http.get<{success: boolean; data: string[]}>(`${API_BASE_URL}/direcciones-retiro.php`, {params: {view: 'streets', q}}).pipe(timeout(20000), map(r => {
      if (!r.success || !Array.isArray(r.data) || r.data.some(v => typeof v !== 'string')) throw new Error('Sugerencias inválidas.');
      return r.data.slice(0, 10);
    }));
  }
  resolver(q: string, detalle: string) {
    return this.http.get<{success: boolean; data: UbicacionRetiro | null}>(`${API_BASE_URL}/direcciones-retiro.php`, {params: {view: 'resolve', q, detalle}}).pipe(timeout(20000), map(r => {
      if (!r.success || (r.data && (!Number.isFinite(r.data.latitud) || !Number.isFinite(r.data.longitud) || Math.abs(r.data.latitud) > 85 || Math.abs(r.data.longitud) > 180 || (r.data.latitud === 0 && r.data.longitud === 0) || !r.data.direccion))) throw new Error('Ubicación inválida.');
      return r.data;
    }));
  }
}
