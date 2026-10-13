import type { AccountState, Store } from '../core/types';
import { emptyAccount } from './shared';

const opening = () => new Promise<IDBDatabase>((resolve, reject) => {
  const request = indexedDB.open('posonly-mobile-preview-v1', 1);
  request.onupgradeneeded = () => request.result.createObjectStore('accounts', { keyPath: 'owner' });
  request.onsuccess = () => resolve(request.result);
  request.onerror = () => reject(new Error('Allow storage for this isolated preview. No sale has been sent.'));
});

class PreviewAccountStore implements Store {
  private connection: Promise<IDBDatabase> | null = null;
  private database(): Promise<IDBDatabase> { return this.connection ||= opening(); }

  async read(owner: string): Promise<AccountState | null> {
    const db = await this.database();
    return new Promise((resolve, reject) => {
      const transaction = db.transaction('accounts', 'readonly');
      const request = transaction.objectStore('accounts').get(owner);
      let result: AccountState | null = null;
      request.onsuccess = () => { result = request.result || null; if (result && result.owner !== owner) { transaction.abort(); } };
      transaction.oncomplete = () => resolve(result);
      transaction.onerror = transaction.onabort = () => reject(new Error('The saved account could not be read.'));
    });
  }

  async update(owner: string, operation: (state: AccountState) => AccountState): Promise<AccountState> {
    const db = await this.database();
    return new Promise((resolve, reject) => {
      const transaction = db.transaction('accounts', 'readwrite');
      const store = transaction.objectStore('accounts');
      const request = store.get(owner);
      let result: AccountState;
      let failure: unknown;
      request.onsuccess = () => {
        try {
          const previous = request.result || emptyAccount(owner);
          if (previous.owner !== owner) { throw new Error('The saved account identity cannot be verified.'); }
          result = operation(previous);
          if (result.owner !== owner) { throw new Error('The saved account changed.'); }
          store.put(result);
        } catch (error) { failure = error; transaction.abort(); }
      };
      transaction.oncomplete = () => resolve(result);
      transaction.onerror = transaction.onabort = () => reject(failure || new Error('The request could not be saved. No sale has been sent.'));
    });
  }
}

export const accountStore: Store = new PreviewAccountStore();
