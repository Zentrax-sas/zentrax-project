import { Routes } from '@angular/router';
import { contenedorIdentificado, reportePreparado, incidenciaRegistrada } from './reporte-incidencia/flujo-incidencia.service';
import { solicitudRetiroPreparada, solicitudRetiroRegistrada } from './solicitud-retiro/solicitud-retiro-flow.service';

export const routes: Routes = [
  { path: 'consultar-estado', loadComponent: () => import('./consultar-estado/consultar-estado.page').then(m => m.ConsultarEstadoPage) },
  { path: 'buscar-contenedor', loadComponent: () => import('./buscar-contenedor/buscar-contenedor.page').then(m => m.BuscarContenedorPage) },
  {
    path: 'novedades',
    loadComponent: () => import('./novedades/novedades.page').then((m) => m.NovedadesPage),
  },
  {
    path: 'home',
    loadComponent: () => import('./home/home.page').then((m) => m.HomePage),
  },
  {
    path: '',
    redirectTo: 'home',
    pathMatch: 'full',
  },
  {
    path: 'reporte-incidencia',
    loadComponent: () => import('./reporte-incidencia/reporte-incidencia.page').then( m => m.ReporteIncidenciaPage)
  },
  {
    path: 'formulario-reporte',
    canActivate: [contenedorIdentificado],
    loadComponent: () => import('./formulario-reporte/formulario-reporte.page').then( m => m.FormularioReportePage)
  },
  {
    path: 'captcha-reporte',
    canActivate: [reportePreparado],
    loadComponent: () => import('./captcha-reporte/captcha-reporte.page').then( m => m.CaptchaReportePage)
  },
  {
    path: 'incidencia-generada',
    canActivate: [incidenciaRegistrada],
    loadComponent: () => import('./incidencia-generada/incidencia-generada.page').then( m => m.IncidenciaGeneradaPage)
  },
  {
    path: 'solicitud-retiro',
    loadComponent: () => import('./solicitud-retiro/solicitud-retiro.page').then( m => m.SolicitudRetiroPage)
  },
  {
    path: 'captcha-retiro',
    canActivate: [solicitudRetiroPreparada],
    loadComponent: () => import('./captcha-retiro/captcha-retiro.page').then(m => m.CaptchaRetiroPage)
  },
  {
    path: 'solicitud-generada',
    canActivate: [solicitudRetiroRegistrada],
    loadComponent: () => import('./solicitud-generada/solicitud-generada.page').then(m => m.SolicitudGeneradaPage)
  },

];
