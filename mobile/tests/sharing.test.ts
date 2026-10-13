/// <reference types="node" />
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';
import { emptyState } from '../src/core/offline';
import type { AccountState, QueueEntry } from '../src/core/types';
import type { exportQueue } from '../src/runtime/sharing';

function outbox(): AccountState {
  const owner = 'https://posonly.example.test/api/v1|1:2';
  const request: QueueEntry = { id: 'private-export-request', owner, kind: 'offline', status: 'pending', createdAt: '2026-10-13T08:00:00.000Z',
    provisionalNumber: 'OFF-PRIVATE-1', company: 'QA company', location: 'QA shop', stockAcknowledged: false,
    payload: { location_id: 1, price_tier: 'retail', items: [{ product_id: 1, quantity: 1 }], discount_percent: 0, tax_percent: 12,
      payments: [{ method: 'cash', amount: 120 }], idempotency_key: 'private-export-original-key', permit: 'opaque-signed-qa-permit',
      recorded_at: '2026-10-13T08:00:00.000Z', expected: { items: [{ product_id: 1, unit_price_cents: 10000 }], subtotal_cents: 10000,
        discount_cents: 0, tax_cents: 1200, total_cents: 11200, cash_session_id: 3 } },
    quote: { items: [{ product_id: 1, unit_price_cents: 10000, quantity: 1, name: 'QA shirt', sku: 'QA-M-BLUE' }],
      subtotal_cents: 10000, discount_cents: 0, tax_cents: 1200, total_cents: 11200 } };
  return { ...emptyState(owner), queue: [request], pendingAction: { kind: 'cash-open', path: '/cash-sessions/open',
    payload: { location_id: 2, opening_float: 500, idempotency_key: 'original-cash-opening-key' }, createdAt: '2026-10-13T08:00:00.000Z' } };
}

/** Exercise the real native export function with its file/share platform I/O replaced. */
function sharing() {
  const control = { available: true, stage: null as 'create' | 'write' | 'share' | null, exists: false, deletions: 0,
    writes: 0, shares: 0, content: '', sharedContent: '' };
  const modules: Record<string, unknown> = {
    'react-native': { Platform: { OS: 'android' } },
    'expo-print': { printToFileAsync: async () => { throw new Error('Outbox export must not print a receipt.'); } },
    'expo-sharing': {
      isAvailableAsync: async () => control.available,
      shareAsync: async () => {
        control.shares++; control.sharedContent = control.content;
        if (control.stage === 'share') { throw new Error('Native sharing interrupted'); }
      },
    },
    'expo-file-system': { Paths: { cache: { uri: 'file:///fake-private-cache/' } }, File: class {
      readonly uri = 'file:///fake-private-cache/posonly-private-outbox.json';
      get exists() { return control.exists; }
      create() { control.exists = true; if (control.stage === 'create') { throw new Error('Native creation interrupted'); } }
      write(content: string) {
        control.writes++; control.content = content;
        if (control.stage === 'write') { control.content = content.slice(0, 80); throw new Error('Native partial write interrupted'); }
      }
      delete() { control.deletions++; control.exists = false; control.content = ''; }
    } },
    './receiptBranding': { receiptHtml: () => '<html>QA receipt</html>' },
  };
  const source = readFileSync(new URL('../src/runtime/sharing.ts', import.meta.url), 'utf8');
  const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
  const output: { exportQueue?: typeof exportQueue } = {};
  runInNewContext(compiled, { exports: output, require: (name: string) => {
    if (!(name in modules)) { throw new Error(`Unexpected sharing dependency: ${name}`); }
    return modules[name];
  } });
  return { control, exportQueue: output.exportQueue! };
}

for (const stage of ['create', 'write', 'share'] as const) {
  test(`native export cleans partial private files after ${stage} failure and keeps immutable outbox requests`, async () => {
    const { exportQueue, control } = sharing();
    const state = outbox();
    const original = JSON.stringify(state);
    control.stage = stage;
    await assert.rejects(exportQueue(state), /Native .*interrupted/);
    assert.equal(control.exists, false);
    assert.equal(control.deletions, 1);
    assert.equal(control.content, '');
    assert.equal(JSON.stringify(state), original);
    if (stage !== 'share') { assert.equal(control.shares, 0); }
  });
}

test('native queue export shares exact original requests then deletes only its temporary copy', async () => {
  const { exportQueue, control } = sharing();
  const state = outbox();
  const original = JSON.stringify(state);
  await exportQueue(state);
  const exported = JSON.parse(control.sharedContent);
  assert.equal(exported.format, 'posonly-mobile-outbox-v1');
  assert.equal(exported.owner, state.owner);
  assert.deepEqual(exported.requests[0].payload, state.queue[0].payload);
  assert.deepEqual(exported.pendingAction, state.pendingAction);
  assert.equal(control.shares, 1);
  assert.equal(control.deletions, 1);
  assert.equal(control.exists, false);
  assert.equal(JSON.stringify(state), original);
});

test('unavailable native sharing does not stage plaintext or mutate the encrypted outbox', async () => {
  const { exportQueue, control } = sharing();
  const state = outbox();
  const original = JSON.stringify(state);
  control.available = false;
  await assert.rejects(exportQueue(state), /Private backup sharing is unavailable/);
  assert.equal(control.writes, 0);
  assert.equal(control.shares, 0);
  assert.equal(control.exists, false);
  assert.equal(JSON.stringify(state), original);
});
