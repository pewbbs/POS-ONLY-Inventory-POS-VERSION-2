import * as Crypto from 'expo-crypto';
import * as SecureStore from 'expo-secure-store';
import * as SQLite from 'expo-sqlite';
import { File } from 'expo-file-system';
import type { AccountState, Store } from '../core/types';
import { emptyAccount } from './shared';

let opening: Promise<SQLite.SQLiteDatabase> | null = null;

/** File expects a URI, while SQLite returns a filesystem path on Android and a file URI on iOS. */
function databaseDirectoryUri(directory: string | null): string {
  if (!directory || /[\u0000-\u001f]/.test(directory)) { throw new Error('The private database directory cannot be verified. Existing saved sales have not been reset.'); }
  if (directory.startsWith('file://')) {
    const uri = new URL(directory);
    if (uri.protocol !== 'file:' || uri.hostname || uri.search || uri.hash || !uri.pathname.startsWith('/')) {
      throw new Error('The private database directory cannot be verified. Existing saved sales have not been reset.');
    }
    decodeURIComponent(uri.pathname);
    return directory;
  }
  if (!directory.startsWith('/') || directory.startsWith('//') || directory.split('/').some(segment => segment === '.' || segment === '..')) {
    throw new Error('The private database directory cannot be verified. Existing saved sales have not been reset.');
  }
  return `file://${directory.split('/').map(segment => encodeURIComponent(segment)).join('/')}`;
}

async function database(): Promise<SQLite.SQLiteDatabase> {
  if (!opening) {
    opening = (async () => {
      const directory = databaseDirectoryUri(SQLite.defaultDatabaseDirectory);
      let key = await SecureStore.getItemAsync('posonly.database-key.v1');
      if (!key) {
        if (new File(directory, 'posonly-v1.db').exists) { throw new Error('The encryption key is unavailable. Existing saved sales have not been reset. Unlock the device and retry.'); }
        key = [...await Crypto.getRandomBytesAsync(32)].map(value => value.toString(16).padStart(2, '0')).join('');
        await SecureStore.setItemAsync('posonly.database-key.v1', key, { keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY });
      }
      if (!/^[a-f0-9]{64}$/.test(key)) { throw new Error('The device storage key cannot be read. Existing saved sales have not been deleted.'); }
      const db = await SQLite.openDatabaseAsync('posonly-v1.db');
      try {
        await db.execAsync(`PRAGMA key = "x'${key}'";`);
        const cipher = await db.getFirstAsync<{ cipher_version: string }>('PRAGMA cipher_version');
        if (!cipher?.cipher_version) { throw new Error('Encrypted storage requires a POSONLY development or release build. Use the local browser preview for testing instead of Expo Go.'); }
        await db.execAsync('PRAGMA journal_mode = WAL; PRAGMA synchronous = FULL; CREATE TABLE IF NOT EXISTS accounts (owner TEXT PRIMARY KEY NOT NULL, body TEXT NOT NULL);');
        return db;
      } catch (error) {
        await db.closeAsync().catch(() => undefined);
        throw error;
      }
    })().catch(error => { opening = null; throw error; });
  }
  return opening;
}

class EncryptedAccountStore implements Store {
  private pending: Promise<unknown> = Promise.resolve();

  private serial<T>(operation: () => Promise<T>): Promise<T> {
    const result = this.pending.catch(() => undefined).then(operation);
    this.pending = result;
    return result;
  }

  read(owner: string): Promise<AccountState | null> {
    return this.serial(async () => {
      const db = await database();
      const row = await db.getFirstAsync<{ body: string }>('SELECT body FROM accounts WHERE owner = ?', owner);
      const result = row ? JSON.parse(row.body) as AccountState : null;
      if (result && result.owner !== owner) { throw new Error('The saved account identity cannot be verified.'); }
      return result;
    });
  }

  update(owner: string, operation: (state: AccountState) => AccountState): Promise<AccountState> {
    return this.serial(async () => {
      const db = await database();
      let result: AccountState | undefined;
      await db.withTransactionAsync(async () => {
        const row = await db.getFirstAsync<{ body: string }>('SELECT body FROM accounts WHERE owner = ?', owner);
        const previous = row ? JSON.parse(row.body) as AccountState : emptyAccount(owner);
        if (previous.owner !== owner) { throw new Error('The saved account identity cannot be verified.'); }
        result = operation(previous);
        if (result.owner !== owner) { throw new Error('The saved account changed. Retry from its own workspace.'); }
        await db.runAsync('INSERT INTO accounts(owner, body) VALUES(?, ?) ON CONFLICT(owner) DO UPDATE SET body = excluded.body', owner, JSON.stringify(result));
      });
      if (!result) { throw new Error('Saved sales could not be written to this device.'); }
      return result;
    });
  }
}

export const accountStore: Store = new EncryptedAccountStore();
