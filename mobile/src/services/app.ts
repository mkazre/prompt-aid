import appConfig from '../../app.json';
import { API_BASE_URL } from '../api/client';

/** App version from app.json (expo.version) — what the store build ships. */
export function appVersion(): string {
  return (appConfig as { expo?: { version?: string } }).expo?.version ?? '1.0.0';
}

/** Absolute URL for a media path stored on the backend's public disk. */
export function avatarUrl(path?: string | null): string | null {
  if (!path) return null;
  if (/^(https?:)?\/\//.test(path)) return path;
  return `${API_BASE_URL.replace(/\/api\/?$/, '')}/storage/${path.replace(/^\/+/, '')}`;
}
