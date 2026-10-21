import { inject, Injectable, InjectionToken } from '@angular/core';
import { Capacitor, registerPlugin } from '@capacitor/core';
import { firstValueFrom } from 'rxjs';
import { Seguimiento, SeguimientoService } from './consultar-estado/seguimiento.service';

interface WhatsAppExternoPlugin {
  abrir(options: { nativa: string; web: string }): Promise<void>;
}
export const WHATSAPP_EXTERNO = new InjectionToken<WhatsAppExternoPlugin>('WhatsAppExterno', {
  providedIn: 'root', factory: () => ({
    abrir: options => registerPlugin<WhatsAppExternoPlugin>('WhatsAppExterno').abrir(options),
  }),
});

export function celularUruguay(celular?: string | null): string {
  if (!celular || !/^\+?[\d\s().-]+$/.test(celular.trim())) return '';
  let numero = celular.replace(/\D/g, '');
  if (numero.startsWith('00598')) numero = numero.slice(2);
  if (/^09\d{7}$/.test(numero)) numero = numero.slice(1);
  if (/^9\d{7}$/.test(numero)) numero = `598${numero}`;
  return /^5989\d{7}$/.test(numero) ? numero : '';
}

export function enlaceWhatsApp(tipo: 'incidencia' | 'retiro', tracking: string, celular?: string | null, actual?: Seguimiento) {
  const codigo = tracking.trim();
  if (!new RegExp(`^${tipo === 'incidencia' ? 'INC' : '(RET|REF)'}-\\d{4}-[A-F0-9]{5}$`).test(codigo)) {
    throw new Error('No se pudo encontrar un número de seguimiento válido.');
  }
  const asunto = tipo === 'incidencia' ? 'incidencia' : 'solicitud de retiro';
  const cierre = tipo === 'incidencia' ? 'incidencia' : 'solicitud';
  const detalles=actual?`\nEstado: ${actual.estado}${actual.tipo_residuo ? `\nTipo de residuo: ${actual.tipo_residuo}` : ''}${actual.tipo_problema ? `\nMotivo: ${actual.tipo_problema}` : ''}${actual.descripcion ? `\nDescripción: ${actual.descripcion}` : ''}`:'';
  const texto = encodeURIComponent(`Zemyna - Seguimiento de ${asunto}\nCódigo: ${codigo}${detalles}\nGuardá este mensaje para consultar el estado de tu ${cierre}.`);
  const telefono = celularUruguay(celular);
  return {
    nativa: `whatsapp://send?${telefono ? `phone=${telefono}&` : ''}text=${texto}`,
    web: `https://wa.me/${telefono}?text=${texto}`,
  };
}

@Injectable({ providedIn: 'root' })
export class GuardarWhatsappService {
  private readonly seguimiento=inject(SeguimientoService);
  private readonly nativo = inject(WHATSAPP_EXTERNO);
  async abrir(tipo: 'incidencia' | 'retiro', tracking: string, celular?: string | null): Promise<string> {
    enlaceWhatsApp(tipo,tracking,celular); // Validar antes de abrir una ventana.
    const ventana=Capacitor.getPlatform()!=='android'?window.open('', '_blank'):null;
    if(Capacitor.getPlatform()!=='android'&&!ventana)throw new Error('No pudimos abrir WhatsApp. Intentá nuevamente o copiá el número de seguimiento.');
    let enlaces:ReturnType<typeof enlaceWhatsApp>;
    try { enlaces=enlaceWhatsApp(tipo,tracking,celular,await firstValueFrom(this.seguimiento.consultar(tracking))); }
    catch { ventana?.close();throw new Error('No pudimos consultar los datos actuales del trámite. Intentá nuevamente.'); }
    try {
      if (Capacitor.getPlatform() === 'android') {
        await this.nativo.abrir(enlaces);
      } else {
        ventana!.opener = null;
        ventana!.location.href = enlaces.web;
      }
    } catch {
      ventana?.close();
      throw new Error('No pudimos abrir WhatsApp. Intentá nuevamente o copiá el número de seguimiento.');
    }
    return 'Revisá el mensaje en WhatsApp y envialo si querés guardarlo. Si cancelás, tu trámite sigue registrado.';
  }
}
