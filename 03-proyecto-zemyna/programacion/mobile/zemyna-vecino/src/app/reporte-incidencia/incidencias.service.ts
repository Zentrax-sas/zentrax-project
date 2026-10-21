import { inject, Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { map, timeout } from 'rxjs';
import { API_BASE_URL } from '../api.config';
import { ReportePendiente } from './flujo-incidencia.service';
import { EdicionTramiteService } from '../edicion-tramite.service';
interface Respuesta<T> { success: boolean; data: T; }
interface Alta { id_incidencia: number; tracking_number: string; edicion_token?:string|null; }
interface Seguimiento { fecha_reporte: string; estado: string; }
@Injectable({ providedIn: 'root' })
export class IncidenciasService {
    private readonly edicion=inject(EdicionTramiteService);
  private readonly http = inject(HttpClient);
  crear(reporte: ReportePendiente) {
    return this.http.post<Respuesta<Alta>>(`${API_BASE_URL}/incidencias.php`, {
      id_contenedor: Number(reporte.id_contenedor), tipo_problema: reporte.motivo,
      descripcion: reporte.descripcion, direccion: reporte.direccion,
      celular: reporte.celular,
      consentimiento_whatsapp: reporte.consentimiento_whatsapp === true,
    }).pipe(timeout(30000), map(response => {
      if (!response.success || !Number.isSafeInteger(Number(response.data?.id_incidencia)) ||
          Number(response.data.id_incidencia) <= 0 || !/^INC-\d{4}-[A-F0-9]{5}$/.test(response.data?.tracking_number)) {
        throw new Error('El servidor no confirmó el registro.');
      }
      this.edicion.recordar(response.data);
      return { ...response.data, id_incidencia: Number(response.data.id_incidencia) };
    }));
  }
  subirFoto(id: number, foto: File) {
    const data = new FormData();
    data.append('id_incidencia', String(id));
    data.append('foto', foto, foto.name);
    return this.http.post<Respuesta<unknown>>(`${API_BASE_URL}/foto.php`, data).pipe(
      timeout(45000), map(response => { if (!response.success) throw new Error('No se pudo adjuntar la foto.'); })
    );
  }
  seguimiento(tracking: string) {
    return this.http.get<Respuesta<Seguimiento>>(`${API_BASE_URL}/incidencias.php`, {
      params: { tracking_number: tracking },
    }).pipe(timeout(15000), map(response => {
      if (!response.success) throw new Error('No se pudo consultar el estado.');
      return response.data;
    }));
  }
}
