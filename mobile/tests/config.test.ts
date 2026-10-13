import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../app.config.js', import.meta.url), 'utf8');
function config(environment: Record<string, string> = {}) {
  const context = { module: { exports: {} }, process: { env: environment }, URL };
  vm.runInNewContext(source, context);
  return context.module.exports as { expo: {
    name: string; extra: { apiUrl: string; isolated: boolean };
    android: { package: string; allowBackup: boolean };
    ios: { bundleIdentifier: string; infoPlist: Record<string, unknown> };
  } };
}

test('release builds use only the fixed production HTTPS API and disable Android backups', () => {
  const { expo } = config({ POSONLY_TEST_API_URL: 'http://untrusted.example/api/v1' });
  assert.equal(expo.extra.apiUrl, 'https://posonly.com/api/v1');
  assert.equal(expo.extra.isolated, false);
  assert.equal(expo.name, 'POSONLY');
  assert.equal(expo.android.package, 'com.posonly.mobile');
  assert.equal(expo.android.allowBackup, false);
  assert.equal(expo.ios.bundleIdentifier, 'com.posonly.mobile');
  assert.equal(expo.ios.infoPlist.NSAppTransportSecurity, undefined);
});

test('isolated profiles have separate identities and permit only local test hosts', () => {
  for (const hostname of ['127.0.0.1', 'localhost', '10.0.2.2']) {
    const { expo } = config({ POSONLY_BUILD_ENV: 'isolated', POSONLY_TEST_API_URL: `http://${hostname}:8092/api/v1` });
    assert.equal(expo.extra.isolated, true);
    assert.equal(expo.android.package, 'com.posonly.mobile.test');
    assert.equal(expo.ios.bundleIdentifier, 'com.posonly.mobile.test');
    assert.equal(expo.android.allowBackup, false);
  }
  assert.throws(() => config({ POSONLY_BUILD_ENV: 'isolated', POSONLY_TEST_API_URL: 'https://posonly.com/api/v1' }), /local test backend/);
});

test('build configuration never embeds credentials, query secrets, or arbitrary API paths', () => {
  for (const target of ['http://user:secret@127.0.0.1:8092/api/v1', 'http://127.0.0.1:8092/api/v1?key=secret', 'http://127.0.0.1:8092/other', 'ftp://127.0.0.1/api/v1']) {
    assert.throws(() => config({ POSONLY_BUILD_ENV: 'isolated', POSONLY_TEST_API_URL: target }), /credential-free/);
  }
});
