import * as SecureStore from 'expo-secure-store';
import type { DeviceSession, SessionVault } from './shared';

const key = 'posonly.device-session.v1';
export const sessionVault: SessionVault = {
  async read() {
    const value = await SecureStore.getItemAsync(key);
    return value ? JSON.parse(value) : null;
  },
  async write(session: DeviceSession) {
    await SecureStore.setItemAsync(key, JSON.stringify(session), { keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY });
  },
  async remove() { await SecureStore.deleteItemAsync(key); },
};
