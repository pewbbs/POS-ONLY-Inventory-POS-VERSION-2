import type { DeviceSession, SessionVault } from './shared';

const key = 'posonly.isolated-preview-session.v1';
export const sessionVault: SessionVault = {
  async read() { const value = sessionStorage.getItem(key); return value ? JSON.parse(value) : null; },
  async write(session: DeviceSession) { sessionStorage.setItem(key, JSON.stringify(session)); },
  async remove() { sessionStorage.removeItem(key); },
};
