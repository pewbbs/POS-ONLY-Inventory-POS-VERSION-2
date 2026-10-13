/// <reference types="node" />
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';
import type { AccountState, Store } from '../src/core/types';
import { emptyState } from '../src/core/offline';

const OWNER = 'https://posonly.example.test/api/v1|1:1';
const KEY = 'ab'.repeat(32);

/** Exercise the native adapter with its platform I/O replaced; this does not simulate SQLCipher itself. */
function nativeStore(directory: string | null = '/fake-private-device-storage') {
  const control = { key: KEY as string | null, fileExists: true, failSetup: false, cipherAvailable: true,
    failClose: false, closes: 0, opens: 0, keyWrites: 0, deletes: 0, statements: [] as string[], checkedFiles: [] as string[], openedNames: [] as string[] };
  const rows = new Map<string, string>();
  const db = {
    async execAsync(statement: string) {
      control.statements.push(statement);
      if (control.failSetup) { throw new Error('Native storage temporarily unavailable'); }
    },
    async getFirstAsync(statement: string, owner?: string) {
      if (statement === 'PRAGMA cipher_version') { return control.cipherAvailable ? { cipher_version: 'fake-platform-cipher' } : null; }
      return rows.has(owner!) ? { body: rows.get(owner!) } : null;
    },
    async withTransactionAsync(operation: () => Promise<void>) {
      const before = new Map(rows);
      try { await operation(); } catch (error) { rows.clear(); before.forEach((value, key) => rows.set(key, value)); throw error; }
    },
    async runAsync(_statement: string, owner: string, body: string) { rows.set(owner, body); },
    async closeAsync() { control.closes++; if (control.failClose) { throw new Error('Native close also failed'); } },
  };
  const modules: Record<string, unknown> = {
    'expo-crypto': { getRandomBytesAsync: async () => new Uint8Array(32).fill(171) },
    'expo-secure-store': {
      WHEN_UNLOCKED_THIS_DEVICE_ONLY: 'private-keychain',
      getItemAsync: async () => control.key,
      setItemAsync: async (_name: string, key: string) => { control.keyWrites++; control.key = key; },
      deleteItemAsync: async () => { control.deletes++; },
    },
    'expo-sqlite': { defaultDatabaseDirectory: directory, openDatabaseAsync: async (name: string) => { control.opens++; control.openedNames.push(name); return db; } },
    'expo-file-system': { File: class {
      constructor(uri: string, name: string) {
        const parsed = new URL(uri);
        if (parsed.protocol !== 'file:' || parsed.hostname) { throw new Error('URI is not absolute'); }
        control.checkedFiles.push(`${uri.replace(/\/$/, '')}/${name}`);
      }
      get exists() { return control.fileExists; }
    } },
    './shared': { emptyAccount: emptyState },
  };
  const source = readFileSync(new URL('../src/storage/store.native.ts', import.meta.url), 'utf8');
  const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, esModuleInterop: true } }).outputText;
  const output: { accountStore?: Store } = {};
  runInNewContext(compiled, { exports: output, URL, require: (name: string) => {
    if (!(name in modules)) { throw new Error(`Unexpected native dependency: ${name}`); }
    return modules[name];
  } });
  return { store: output.accountStore!, control, rows };
}

test('native initialization failure closes the handle and retries the same preserved database and key', async () => {
  const { store, control, rows } = nativeStore();
  const queued = { ...emptyState(OWNER), revision: 3 };
  rows.set(OWNER, JSON.stringify(queued));
  control.failSetup = true;
  await assert.rejects(store.read(OWNER), /Native storage temporarily unavailable/);
  assert.equal(control.closes, 1);
  assert.equal(control.keyWrites, 0);
  assert.equal(control.deletes, 0);
  assert.equal(control.key, KEY);
  assert.equal(rows.get(OWNER), JSON.stringify(queued));
  control.failSetup = false;
  assert.equal(JSON.stringify(await store.read(OWNER)), JSON.stringify(queued));
  assert.equal(control.opens, 2);
  assert.ok(control.statements.some(statement => statement.includes('PRAGMA synchronous = FULL')));
});

test('an unavailable cipher fails closed without deleting existing device records', async () => {
  const { store, control, rows } = nativeStore();
  rows.set(OWNER, JSON.stringify(emptyState(OWNER)));
  control.cipherAvailable = false;
  await assert.rejects(store.read(OWNER), /Encrypted storage requires/);
  assert.equal(control.closes, 1);
  assert.equal(rows.size, 1);
  assert.equal(control.keyWrites, 0);
  control.cipherAvailable = true;
  assert.equal((await store.read(OWNER))!.owner, OWNER);
});

test('a missing device key with an existing database is preserved for unlock recovery', async () => {
  const { store, control, rows } = nativeStore();
  rows.set(OWNER, JSON.stringify(emptyState(OWNER)));
  control.key = null;
  await assert.rejects(store.read(OWNER), /encryption key is unavailable/);
  assert.equal(control.opens, 0);
  assert.equal(control.keyWrites, 0);
  assert.equal(rows.size, 1);
  control.key = KEY;
  assert.equal((await store.read(OWNER))!.owner, OWNER);
});

test('a secondary native close failure preserves the original initialization error', async () => {
  const { store, control } = nativeStore();
  control.failSetup = true;
  control.failClose = true;
  await assert.rejects(store.read(OWNER), /Native storage temporarily unavailable/);
  assert.equal(control.closes, 1);
  control.failSetup = false;
  control.failClose = false;
  assert.equal(await store.read(OWNER), null);
});

test('native updates reject mismatched account identity and roll back instead of replacing saved records', async () => {
  const { store, rows } = nativeStore();
  const original = emptyState(OWNER);
  rows.set(OWNER, JSON.stringify(original));
  await assert.rejects(store.update(OWNER, value => ({ ...value, owner: 'another-company-user' })), /saved account changed/);
  assert.equal(rows.get(OWNER), JSON.stringify(original));
  await store.update(OWNER, value => ({ ...value, revision: value.revision + 1 }));
  assert.equal((await store.read(OWNER))!.revision, 1);
  rows.set(OWNER, JSON.stringify({ ...original, owner: 'another-company-user' } as AccountState));
  await assert.rejects(store.read(OWNER), /identity cannot be verified/);
});

test('Android absolute SQLite paths become encoded file URIs before an existing-database key check', async () => {
  const directory = '/data/user/0/com.posonly.mobile.test/files/QA space #?%/SQLite';
  const { store, control, rows } = nativeStore(directory);
  const original = emptyState(OWNER);
  rows.set(OWNER, JSON.stringify(original));
  control.key = null;
  await assert.rejects(store.read(OWNER), /encryption key is unavailable/);
  assert.equal(control.checkedFiles[0], 'file:///data/user/0/com.posonly.mobile.test/files/QA%20space%20%23%3F%25/SQLite/posonly-v1.db');
  assert.equal(control.opens, 0);
  assert.equal(control.keyWrites, 0);
  assert.equal(rows.get(OWNER), JSON.stringify(original));
  control.key = KEY;
  assert.equal((await store.read(OWNER))!.owner, OWNER);
  assert.deepEqual(control.openedNames, ['posonly-v1.db']);
});

test('iOS encoded file URIs are preserved without double encoding during the missing-key safeguard', async () => {
  const directory = 'file:///private/var/mobile/Containers/Data/Application/QA%20space%20%23%3F%25/Documents/SQLite/';
  const { store, control } = nativeStore(directory);
  control.key = null;
  await assert.rejects(store.read(OWNER), /encryption key is unavailable/);
  assert.equal(control.checkedFiles[0], directory + 'posonly-v1.db');
  assert.ok(!control.checkedFiles[0].includes('%2520'));
  assert.equal(control.keyWrites, 0);
  assert.equal(control.opens, 0);
});

test('a fresh Android directory creates one private key and opens the original SQLite filename', async () => {
  const { store, control } = nativeStore('/data/user/0/com.posonly.mobile.test/files/SQLite');
  control.key = null;
  control.fileExists = false;
  assert.equal(await store.read(OWNER), null);
  assert.equal(control.checkedFiles[0], 'file:///data/user/0/com.posonly.mobile.test/files/SQLite/posonly-v1.db');
  assert.equal(control.keyWrites, 1);
  assert.deepEqual(control.openedNames, ['posonly-v1.db']);
  assert.equal(control.deletes, 0);
});

test('unexpected SQLite directories fail closed before new keys, files or database handles are created', async () => {
  for (const directory of [null, '', 'relative/SQLite', 'content://private/SQLite', 'https://example.test/SQLite',
    '//server/SQLite', '/data/../SQLite', 'file://other-host/private/SQLite', 'file:///private/SQLite?key=unexpected']) {
    const { store, control } = nativeStore(directory);
    control.key = null;
    await assert.rejects(store.read(OWNER), /private database directory cannot be verified/);
    assert.equal(control.keyWrites, 0);
    assert.equal(control.opens, 0);
    assert.equal(control.checkedFiles.length, 0);
    assert.equal(control.deletes, 0);
  }
});
