import { Capacitor } from '@capacitor/core';
import { ANDROID_API_BASE_URL } from './api.android.config';

export function resolveApiBaseUrl(native: boolean, platform: string, androidUrl: string): string {
  // El navegador conserva el proxy de desarrollo y el mismo origen en producción.
  if (!native || platform !== 'android') return '/backend/api';

  const value = androidUrl.trim();
  let url: URL;
  try {
    url = new URL(value);
  } catch {
    throw new Error('Configurar ANDROID_API_BASE_URL en api.android.config.ts con una URL absoluta HTTP(S).');
  }
  if (!/^https?:\/\//i.test(value) || !['http:', 'https:'].includes(url.protocol) ||
      url.username || url.password || url.search || url.hash || value.includes('\\')) {
    throw new Error('ANDROID_API_BASE_URL debe ser HTTP(S), sin credenciales, query ni fragmento.');
  }
  return `${url.origin}${url.pathname.replace(/\/{2,}/g, '/').replace(/\/+$/, '')}`;
}

// Una configuración Android ausente/inválida detiene el arranque: no se usa localhost como fallback.
export const API_BASE_URL = resolveApiBaseUrl(
  Capacitor.isNativePlatform(), Capacitor.getPlatform(), ANDROID_API_BASE_URL,
);
