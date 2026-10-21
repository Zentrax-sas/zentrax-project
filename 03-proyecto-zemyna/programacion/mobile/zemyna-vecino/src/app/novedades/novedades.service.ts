import { inject, Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { map, timeout } from 'rxjs';
import { API_BASE_URL } from '../api.config';
import { Novedad } from './novedad.model';

@Injectable({ providedIn: 'root' })
export class NovedadesService {
  private readonly http = inject(HttpClient);
  listar() {
    return this.http.get<{ success: boolean; data: Novedad[] }>(`${API_BASE_URL}/novedades.php`).pipe(
      timeout(15000),
      map(response => {
        if (!response.success || !Array.isArray(response.data) || response.data.length > 20 ||
            response.data.some(n => !n || !Number.isSafeInteger(n.id) || n.id <= 0 || n.tipo !== 'Incidencia' ||
              typeof n.titulo !== 'string' || !n.titulo.trim() ||
              typeof n.fecha !== 'string' || !/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(n.fecha) ||
              !Number.isFinite(Date.parse(n.fecha.replace(' ', 'T'))) ||
              (n.direccion !== null && typeof n.direccion !== 'string') ||
              !['Pendiente', 'En Proceso', 'Resuelta'].includes(n.estado)) ||
            new Set(response.data.map(n => n.id)).size !== response.data.length) {
          throw new Error('Respuesta de novedades inválida.');
        }
        return response.data.map(({ id, tipo, titulo, fecha, direccion, estado }) =>
          ({ id, tipo, titulo, fecha, direccion, estado })
        ).sort((a, b) => b.fecha.localeCompare(a.fecha) || b.id - a.id);
      }),
    );
  }
}
