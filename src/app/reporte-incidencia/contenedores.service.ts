import { inject, Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { map, Observable, timeout } from 'rxjs';
import { API_BASE_URL } from '../api.config';

export interface ContenedorPublico {
  id_contenedor: number | string;
  codigo: string;
  direccion: string;
  latitud: number | string | null;
  longitud: number | string | null;
  estado: string;
  esquina?: string | null;
  referencia?: string | null;
}
export interface LimitesMapa { north: number; south: number; east: number; west: number; zoom: number; }

@Injectable({ providedIn: 'root' })
export class ContenedoresService {
  private readonly http = inject(HttpClient);

  enMapa(limites: LimitesMapa) {
    return this.http.get<{ success: boolean; data: ContenedorPublico[]; meta: { hasMore: boolean } }>(
      `${API_BASE_URL}/contenedores.php`, { params: { view: 'map', ...limites } }
    ).pipe(timeout(15000), map(response => {
      if (!response?.success || !Array.isArray(response.data) || typeof response.meta?.hasMore !== 'boolean' || response.data.some(c => !c || !c.id_contenedor || typeof c.codigo !== 'string')) throw new Error('No se pudo cargar la zona.');
      return response;
    }));
  }

  sugerirCalles(q: string, calle = ''): Observable<string[]> {
    return this.http.get<{ success: boolean; data: string[] }>(
      `${API_BASE_URL}/contenedores.php`, { params: { view: 'streets', q, ...(calle ? { calle } : {}) } }
    ).pipe(timeout(15000), map(response => {
      if (!response.success || !Array.isArray(response.data) || response.data.some(item => typeof item !== 'string')) {
        throw new Error('Respuesta de sugerencias inválida.');
      }
      return response.data.slice(0, 10);
    }));
  }

  buscar(q: string, esquina?: string): Observable<ContenedorPublico[]> {
    return this.http.get<{ success: boolean; data: ContenedorPublico[] }>(
      `${API_BASE_URL}/contenedores.php`, { params: esquina === undefined
        ? { view: 'search', q }
        : { view: 'search', calle: q, esquina } }
    ).pipe(
      timeout(15000),
      map(response => {
        if (!response.success || !Array.isArray(response.data)) {
          throw new Error('Respuesta de búsqueda inválida.');
        }
        return response.data;
      })
    );
  }
}
