import { inject, Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { map, timeout } from 'rxjs';
import { API_BASE_URL } from '../api.config';
import { SolicitudRetiroBorrador, SolicitudRetiroRegistrada } from './solicitud-retiro-flow.service';
import { EdicionTramiteService } from '../edicion-tramite.service';

interface Respuesta<T> {
  success: boolean;
  data: T;
  message: string;
  errors: Record<string, string>;
}

interface DatosCaptcha { pregunta: string; }
export interface TipoResiduo { id_tipo_residuo: number; nombre: string; }

@Injectable({ providedIn: 'root' })
export class SolicitudesRetiroService {
  private readonly edicion=inject(EdicionTramiteService);
  private readonly http = inject(HttpClient);

  obtenerTiposResiduo() {
    return this.http.get<Respuesta<TipoResiduo[]>>(`${API_BASE_URL}/tipos_residuo.php`, { withCredentials: true }).pipe(
      timeout(15000),
      map(response => {
        if (!response.success || !Array.isArray(response.data) || response.data.some(tipo =>
          !tipo || !Number.isSafeInteger(Number(tipo.id_tipo_residuo)) || Number(tipo.id_tipo_residuo) <= 0 ||
          typeof tipo.nombre !== 'string' || !tipo.nombre.trim())) {
          throw new Error('No se pudieron cargar los tipos de residuo.');
        }
        return response.data.map(tipo => ({ id_tipo_residuo: Number(tipo.id_tipo_residuo), nombre: tipo.nombre }));
      })
    );
  }

  obtenerCaptcha() {
    return this.http.get<Respuesta<DatosCaptcha>>(`${API_BASE_URL}/captcha.php`, { withCredentials: true }).pipe(
      timeout(15000),
      map(response => {
        if (!response.success || typeof response.data?.pregunta !== 'string') {
          throw new Error('No se pudo cargar la validación.');
        }
        return response.data.pregunta;
      })
    );
  }

  crear(borrador: SolicitudRetiroBorrador, captchaRespuesta: string) {
    const formData = new FormData();
    formData.append('id_tipo_residuo', String(borrador.id_tipo_residuo));
    formData.append('direccion', borrador.direccion);
    formData.append('descripcion', borrador.descripcion);
    formData.append('calle', borrador.calle ?? '');
    formData.append('numero_referencia', borrador.numero_referencia ?? '');
    formData.append('latitud', borrador.latitud === undefined ? '' : String(borrador.latitud));
    formData.append('longitud', borrador.longitud === undefined ? '' : String(borrador.longitud));
    if (borrador.celular) formData.append('celular', borrador.celular);
    formData.append('consentimiento_whatsapp', borrador.consentimiento_whatsapp === true ? '1' : '0');
    formData.append('captcha_respuesta', captchaRespuesta);
    if (borrador.foto) formData.append('foto', borrador.foto, borrador.foto.name);

    return this.http.post<Respuesta<SolicitudRetiroRegistrada>>(
      `${API_BASE_URL}/solicitud-retiro.php`, formData, { withCredentials: true }
    ).pipe(timeout(45000), map(response => {
      const solicitud = response.data;
      if (!response.success || !Number.isSafeInteger(solicitud?.id_solicitud) || solicitud.id_solicitud <= 0 ||
          !/^RET-\d{4}-[A-F0-9]{5}$/.test(solicitud?.tracking_number ?? '') ||
          !solicitud.fecha || !solicitud.estado || !solicitud.tipo_residuo || !solicitud.direccion) {
        throw new Error('El servidor no confirmó el registro de la solicitud.');
      }
      this.edicion.recordar(solicitud);
      return solicitud;
    }));
  }
}
