import { SolicitudRetiroRegistrada } from '../solicitud-retiro/solicitud-retiro-flow.service';
import { Injectable } from '@angular/core';
import { Capacitor } from '@capacitor/core';
import { Directory, Filesystem } from '@capacitor/filesystem';
import { Share } from '@capacitor/share';
import { IncidenciaRegistrada, Metodo } from '../reporte-incidencia/flujo-incidencia.service';

export type DatosComprobante = Partial<IncidenciaRegistrada> & { metodo?: Metodo; celular?: string; tramite?: 'retiro'; tipo_residuo?: string; latitud?: number; longitud?: number };

export function fechaUruguay(valor?: string): string {
  if (!valor) return 'No especificado';
  // PHP entrega DATETIME sin zona: ya representa la hora de America/Montevideo.
  // Se conservan esos componentes; los timestamps con zona se convierten con Intl.
  const local = /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/.test(valor);
  const fecha = new Date(local ? valor.replace(' ', 'T') + 'Z' : valor);
  if (Number.isNaN(fecha.getTime())) return 'No especificado';
  return new Intl.DateTimeFormat('es-UY', {
    dateStyle: 'short', timeStyle: 'medium', hour12: false, timeZone: local ? 'UTC' : 'America/Montevideo',
  }).format(fecha) + ' (Uruguay)';
}

@Injectable({ providedIn: 'root' })
export class ComprobanteIncidenciaService {
  readonly nativo = {
    guardar: (nombre: string, data: string) => Filesystem.writeFile({ path: nombre, data, directory: Directory.Cache }),
    compartir: (uri: string, title = 'Comprobante de incidencia') => Share.share({ title, files: [uri] }),
  };
  async generarRetiro(datos: SolicitudRetiroRegistrada): Promise<void> {
    await this.generar({...datos, celular:datos.celular ?? undefined, tramite:'retiro'});
  }
  async generar(datos: DatosComprobante): Promise<void> {
    const tracking = datos.tracking_number?.trim();
    if (!tracking) throw new Error('Falta el número de seguimiento.');
    const nombre = `comprobante-${datos.tramite === 'retiro' ? 'retiro' : 'incidencia'}-${tracking.replace(/[^a-zA-Z0-9_-]/g, '_')}.pdf`;
    const pdf = await this.crearPdf(datos);
    await this.entregar(pdf, nombre);
  }

  async crearPdf(datos: DatosComprobante): Promise<ArrayBuffer> {
    const { jsPDF } = await import('jspdf');
    const respuesta = await fetch('assets/logo_sin_fondo2.png');
    if (!respuesta.ok) throw new Error('No se pudo cargar el logo del comprobante.');
    const logo = new Uint8Array(await respuesta.arrayBuffer());
    const doc = new jsPDF();
    const retiro = datos.tramite === 'retiro';
    const titulo = retiro ? 'Comprobante de solicitud de retiro' : 'Comprobante de incidencia';
    doc.setProperties({ title: titulo, author: 'Zemyna' });
    const dimensiones = doc.getImageProperties(logo);
    const escala = Math.min(55 / dimensiones.width, 28 / dimensiones.height);
    doc.addImage(logo, 'PNG', 20, 15, dimensiones.width * escala, dimensiones.height * escala);
    doc.setFontSize(18);
    doc.text(titulo, 20, 57);
    doc.setFontSize(11);
    let y = 72;
    const escribir = (texto: string) => {
      for (const linea of doc.splitTextToSize(texto, 170) as string[]) {
        if (y > 265) { doc.addPage(); y = 22; }
        doc.text(linea, 20, y);
        y += 6;
      }
      y += 4;
    };
    const dato = (etiqueta: string, valor?: string) => escribir(`${etiqueta}: ${valor?.trim() || 'No especificado'}`);
    dato('Número de seguimiento', datos.tracking_number);
    dato('Fecha y hora', fechaUruguay(datos.fecha));
    dato('Estado', datos.estado);
    if (retiro) dato('Tipo de residuo', datos.tipo_residuo);
    else {
      dato('Tipo/categoría de incidencia', datos.motivo);
      dato('Código del contenedor', datos.codigo_contenedor);
      dato('ID del contenedor', datos.id_contenedor);
    }
    dato('Dirección', datos.direccion);
    if (retiro && Number.isFinite(datos.latitud) && Number.isFinite(datos.longitud)) dato('Ubicación (latitud, longitud)', `${datos.latitud}, ${datos.longitud}`);
    if (datos.metodo) dato('Medio de identificación', { qr: 'QR', gps: 'GPS', mapa: 'Mapa', calle: 'Calle' }[datos.metodo]);
    if (datos.celular) dato('Celular', datos.celular);
    escribir(retiro ? 'La solicitud de retiro fue registrada correctamente en Zemyna. Conservá este comprobante para consultar el estado del trámite.' : 'La incidencia fue registrada correctamente en Zemyna. Conservá este comprobante para consultar el estado del trámite.');
    doc.setFontSize(9);
    doc.text('Este comprobante fue generado automáticamente por la app Zemyna.', 20, 282);
    return doc.output('arraybuffer');
  }

  async entregar(pdf: ArrayBuffer, nombre: string): Promise<void> {
    if (Capacitor.isNativePlatform()) {
      const bytes = new Uint8Array(pdf);
      let binario = '';
      for (let i = 0; i < bytes.length; i += 8192) binario += String.fromCharCode(...bytes.subarray(i, i + 8192));
      const archivo = await this.nativo.guardar(nombre, btoa(binario));
      if (nombre.startsWith('comprobante-retiro-')) await this.nativo.compartir(archivo.uri, 'Comprobante de solicitud de retiro');
      else await this.nativo.compartir(archivo.uri);
      return;
    }
    const url = URL.createObjectURL(new Blob([pdf], { type: 'application/pdf' }));
    const link = document.createElement('a');
    try {
      link.href = url;
      link.download = nombre;
      document.body.appendChild(link);
      link.click();
    } finally {
      link.remove();
      setTimeout(() => URL.revokeObjectURL(url), 60_000);
    }
  }
}
