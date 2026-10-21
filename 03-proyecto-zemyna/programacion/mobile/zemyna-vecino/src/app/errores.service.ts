import { ToastController } from '@ionic/angular';
import { Injectable, ErrorHandler } from '@angular/core';
import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { catchError, throwError, timeout } from 'rxjs';
import { inject } from '@angular/core';
import { API_BASE_URL } from './api.config';

export const MENSAJES = {
  conexion: 'No pudimos conectarnos al sistema. Verificá tu conexión e intentá nuevamente.',
  ubicacion: 'No pudimos obtener tu ubicación. Activá la ubicación del teléfono o elegí otra forma de identificar el lugar.',
  mapa: 'No pudimos cargar los datos del mapa. Intentá nuevamente.',
  direccion: 'No pudimos ubicar esa dirección. Revisá los datos o corregí el punto manualmente en el mapa.',
  incidencia: 'No pudimos registrar la incidencia. No se generó ningún reporte. Intentá nuevamente.',
  retiro: 'No pudimos registrar la solicitud de retiro. No se generó ninguna solicitud. Intentá nuevamente.',
  captcha: 'La respuesta no es correcta o la validación venció. Resolvé la nueva operación e intentá nuevamente.',
  sesion: 'La sesión venció. Generá una nueva operación e intentá nuevamente.',
  fotoPermiso: 'No pudimos abrir la cámara. Permití el acceso en los ajustes del teléfono o elegí una foto de la galería.',
  fotoHardware: 'Este dispositivo no tiene una cámara disponible. Elegí una foto de la galería.',
  fotoGaleria: 'No pudimos abrir la galería. Permití el acceso a las fotos en los ajustes del teléfono.',
  fotoCancelada: 'Cancelaste la selección de la foto. Podés continuar sin adjuntarla.',
  fotoFormato: 'Adjuntá una imagen JPG, PNG o WEBP.',
  fotoTamano: 'La foto debe pesar como máximo 5 MB.',
  foto: 'No pudimos leer la foto. Elegí otra imagen e intentá nuevamente.',
  qrCancelada: 'Cancelaste la lectura del QR. Podés intentarlo nuevamente o ingresar el código.',
  qr: 'No pudimos leer el QR. Intentá nuevamente o ingresá el código del contenedor.',
  qrPermiso: 'No pudimos abrir la cámara. Permití el acceso en los ajustes o ingresá el código del contenedor.',
  tracking: 'No encontramos un trámite con ese código. Revisalo e intentá de nuevo.',
  estado: 'No pudimos consultar el estado. Intentá nuevamente.',
  novedades: 'No pudimos cargar las novedades. Intentá nuevamente.',
} as const;
export type ContextoError = 'conexion' | 'ubicacion' | 'mapa' | 'direccion' | 'incidencia' | 'retiro' | 'captcha' | 'foto' | 'qr' | 'estado' | 'novedades';
export type CategoriaError = 'recuperable' | 'validacion' | 'servidor' | 'dispositivo';
export class ErrorUsuario extends Error {
  constructor(public readonly mensaje: string, public readonly categoria: CategoriaError, public readonly original: unknown, public readonly enviado = true) { super(mensaje); }
}
@Injectable({providedIn: 'root'})
export class ErroresService {
  describir(error: unknown, contexto: ContextoError = 'conexion'): ErrorUsuario {
    if (error instanceof ErrorUsuario) return error;
    const http = error instanceof HttpErrorResponse ? error : null;
    const info = error as {name?: string; code?: string; message?: string} | null;
    const dispositivo = contexto === 'ubicacion' || contexto === 'foto' || contexto === 'qr';
    const permission = info?.name === 'NotAllowedError' || /permission|denied|permisos/i.test(info?.message ?? '') || ['OS-PLUG-CAMR-0003','OS-PLUG-CAMR-0007','OS-PLUG-CAMR-0008'].includes(info?.code ?? '');
    let mensaje: string = MENSAJES[contexto];
    let categoria: CategoriaError = dispositivo ? 'dispositivo' : http && [400,422].includes(http.status) ? 'validacion' : http && http.status >= 500 ? 'servidor' : 'recuperable';
    if (contexto === 'foto' && permission) mensaje = MENSAJES.fotoPermiso;
    if (contexto === 'foto' && info?.code === 'OS-PLUG-CAMR-0007') mensaje = MENSAJES.fotoHardware;
    if (contexto === 'foto' && info?.code === 'OS-PLUG-CAMR-0005') mensaje = MENSAJES.fotoGaleria;
    if (contexto === 'foto' && /JPG|PNG|WEBP/.test(info?.message ?? '')) mensaje = MENSAJES.fotoFormato;
    if (contexto === 'qr' && permission) mensaje = MENSAJES.qrPermiso;
    if (http?.status === 413) { mensaje = MENSAJES.fotoTamano; categoria = 'validacion'; }
    else if (http?.status === 400 && /JPG|PNG|WEBP|imagen/i.test(typeof http.error?.message === 'string' ? http.error.message : '')) { mensaje = MENSAJES.fotoFormato; categoria = 'validacion'; }
    else if (http?.error?.errors?.captcha_respuesta) { mensaje = MENSAJES.captcha; categoria = 'validacion'; }
    else if (['captcha','incidencia','retiro'].includes(contexto) && [401,403,419].includes(http?.status ?? -1)) mensaje = MENSAJES.sesion;
    else if (contexto === 'estado' && http?.status === 404) { mensaje = MENSAJES.tracking; categoria = 'validacion'; }
    else if (contexto === 'incidencia' || contexto === 'retiro') {
      if (http && [400,422].includes(http.status)) categoria = 'validacion';
      else {
        const nombre = contexto === 'incidencia' ? 'la incidencia' : 'la solicitud de retiro';
        mensaje = `No pudimos confirmar el registro de ${nombre}. Conservamos tus datos. Consultá el estado antes de volver a enviarlo.`;
      }
    } else if (!dispositivo && contexto !== 'direccion' && (http || info?.name === 'TimeoutError')) mensaje = MENSAJES.conexion;
    return new ErrorUsuario(mensaje, categoria, error);
  }
  mensaje(error: unknown, contexto: ContextoError = 'conexion'): string { return this.describir(error, contexto).mensaje; }
}
export const erroresApiInterceptor: HttpInterceptorFn = (req, next) => {
  if (!req.url.startsWith(API_BASE_URL + '/')) return next(req);
  const errores = inject(ErroresService);
  let contexto: ContextoError = req.url.includes('direcciones-retiro.php') ? 'direccion' : req.url.includes('captcha.php') ? 'captcha' : req.url.includes('foto.php') ? 'foto' : req.url.includes('contenedores.php') ? 'mapa' : req.url.includes('novedades.php') ? 'novedades' : 'estado';
  if (req.method === 'POST' && req.url.includes('incidencias.php')) contexto = 'incidencia';
  if (req.method === 'POST' && req.url.includes('solicitud-retiro.php')) contexto = 'retiro';
  if (typeof navigator !== 'undefined' && navigator.onLine === false) {
    const mensaje = contexto === 'incidencia' || contexto === 'retiro' ? MENSAJES[contexto] : MENSAJES.conexion;
    return throwError(() => new ErrorUsuario(mensaje, 'recuperable', null, false));
  }
  return next(req).pipe(timeout(45000), catchError(error => throwError(() => errores.describir(error, contexto))));
};

export function registroConfirmado(data: unknown, tipo: 'INC' | 'RET'): boolean {
  if (!data || typeof data !== 'object') return false;
  const d = data as Record<string, unknown>;
  const id = tipo === 'INC' ? d['id_incidencia'] : d['id_solicitud'];
  return Number.isSafeInteger(Number(id)) && Number(id) > 0 && typeof d['tracking_number'] === 'string' && new RegExp(`^${tipo}-\\d{4}-[A-F0-9]{5}$`).test(d['tracking_number']);
}

@Injectable()
export class ErroresGlobales implements ErrorHandler {
  private readonly toast = inject(ToastController);
  private mostrando = false;
  handleError(error: unknown): void {
    console.error(error);
    if (this.mostrando) return;
    this.mostrando = true;
    void this.mostrar().catch(() => undefined).finally(() => { this.mostrando = false; });
  }
  private async mostrar(): Promise<void> {
    const toast = await this.toast.create({message:'No pudimos completar esa acción. Intentá nuevamente.', position:'bottom', duration:6000, buttons:[{text:'Cerrar', role:'cancel'}]});
    await toast.present(); await toast.onDidDismiss();
  }
}
