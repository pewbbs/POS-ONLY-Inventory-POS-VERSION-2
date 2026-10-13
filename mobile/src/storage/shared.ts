import type { AccountState } from '../core/types';

export function emptyAccount(owner: string): AccountState {
  return { owner, bootstrap: null, snapshot: null, queue: [], revision: 0, refreshGeneration: 0 };
}

export interface DeviceSession {
  apiUrl: string;
  owner: string;
  token: string | null;
  expiresAt: string;
}

export interface SessionVault {
  read(): Promise<DeviceSession | null>;
  write(session: DeviceSession): Promise<void>;
  remove(): Promise<void>;
}
