import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import vm from 'node:vm';
import ts from 'typescript';

const require = createRequire(import.meta.url);
const testServer = require('../testServer');
const source = readFileSync(new URL('../app.config.js', import.meta.url), 'utf8');
function config(environment: Record<string, string | undefined> = {}) {
  const context = { module: { exports: {} }, process: { env: environment }, URL, require: (name: string) => {
    assert.equal(name, './testServer');
    return testServer;
  } };
  vm.runInNewContext(source, context);
  return context.module.exports as { expo: {
    name: string; extra: { apiUrl: string; isolated: boolean; testLanHost?: string };
    android: { package: string; allowBackup: boolean };
    ios: { bundleIdentifier: string; infoPlist: Record<string, unknown> };
  } };
}

const runtimeSource = ts.transpileModule(readFileSync(new URL('../src/config.ts', import.meta.url), 'utf8'), {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, esModuleInterop: true },
}).outputText;

function runtime(extra: Record<string, unknown>, platform = 'ios', browserOrigin?: string) {
  const module = { exports: {} };
  const context = { module, exports: module.exports, URL,
    ...(browserOrigin ? { window: { location: { origin: browserOrigin } } } : {}),
    require: (name: string) => {
      if (name === 'expo-constants') { return { expoConfig: { extra } }; }
      if (name === 'react-native') { return { Platform: { OS: platform } }; }
      assert.equal(name, '../testServer');
      return testServer;
    },
  };
  vm.runInNewContext(runtimeSource, context);
  return module.exports as { apiUrl: string; isolated: boolean; assertClientEnvironment: () => void };
}

test('release builds use only the fixed production HTTPS API and disable Android backups', () => {
  for (const environment of [
    { POSONLY_TEST_API_URL: 'http://untrusted.example/api/v1', POSONLY_TEST_LAN_HOST: 'not-an-ip' },
    { POSONLY_BUILD_ENV: 'production', POSONLY_TEST_API_URL: 'http://192.168.1.42:8092/api/v1', POSONLY_TEST_LAN_HOST: '192.168.1.42' },
  ]) {
    const { expo } = config(environment);
    assert.equal(expo.extra.apiUrl, 'https://posonly.com/api/v1');
    assert.equal(expo.extra.isolated, false);
    assert.equal(expo.extra.testLanHost, undefined);
    assert.equal(expo.name, 'POSONLY');
    assert.equal(expo.android.package, 'com.posonly.mobile');
    assert.equal(expo.android.allowBackup, false);
    assert.equal(expo.ios.bundleIdentifier, 'com.posonly.mobile');
    assert.equal(expo.ios.infoPlist.NSAppTransportSecurity, undefined);
    assert.equal(expo.ios.infoPlist.NSLocalNetworkUsageDescription, undefined);
    assert.doesNotThrow(() => runtime(expo.extra).assertClientEnvironment());
  }
});

test('isolated profiles have separate identities and permit only local test hosts', () => {
  for (const hostname of ['127.0.0.1', 'localhost', '10.0.2.2']) {
    const { expo } = config({ POSONLY_BUILD_ENV: 'isolated', POSONLY_TEST_API_URL: `http://${hostname}:8092/api/v1` });
    assert.equal(expo.extra.isolated, true);
    assert.equal(expo.android.package, 'com.posonly.mobile.test');
    assert.equal(expo.ios.bundleIdentifier, 'com.posonly.mobile.test');
    assert.equal(expo.android.allowBackup, false);
    assert.doesNotThrow(() => runtime(expo.extra).assertClientEnvironment());
  }
  assert.throws(() => config({ POSONLY_BUILD_ENV: 'isolated', POSONLY_TEST_API_URL: 'https://posonly.com:8092/api/v1' }), /local test backend/);
});

test('build configuration never embeds credentials, query secrets, or arbitrary API paths', () => {
  for (const target of ['http://user:secret@127.0.0.1:8092/api/v1', 'http://127.0.0.1:8092/api/v1?key=secret', 'http://127.0.0.1:8092/other', 'ftp://127.0.0.1/api/v1']) {
    assert.throws(() => config({ POSONLY_BUILD_ENV: 'isolated', POSONLY_TEST_API_URL: target }), /credential-free/);
  }
});

test('explicit RFC1918 LAN hosts enable only their own isolated HTTP endpoint and exact ATS exception', () => {
  for (const host of ['10.1.2.3', '172.16.0.1', '172.31.255.254', '192.168.1.42']) {
    const { expo } = config({ POSONLY_BUILD_ENV: 'isolated', POSONLY_TEST_API_URL: `http://${host}:8092/api/v1`, POSONLY_TEST_LAN_HOST: host });
    assert.equal(expo.extra.apiUrl, `http://${host}:8092/api/v1`);
    assert.equal(expo.extra.testLanHost, host);
    assert.equal(expo.ios.bundleIdentifier, 'com.posonly.mobile.test');
    const transport = expo.ios.infoPlist.NSAppTransportSecurity as Record<string, unknown>;
    assert.deepEqual(JSON.parse(JSON.stringify(transport)), {
      NSAllowsLocalNetworking: true,
      NSExceptionDomains: { [host]: { NSExceptionAllowsInsecureHTTPLoads: true } },
    });
    assert.equal(transport.NSAllowsArbitraryLoads, undefined);
    assert.equal(typeof expo.ios.infoPlist.NSLocalNetworkUsageDescription, 'string');
    assert.doesNotThrow(() => runtime(expo.extra).assertClientEnvironment());
  }
});

test('isolated HTTPS and loopback endpoints do not receive an unrelated LAN HTTP exception', () => {
  for (const target of ['https://192.168.1.42:8092/api/v1', 'http://127.0.0.1:8092/api/v1']) {
    const { expo } = config({ POSONLY_BUILD_ENV: 'isolated', POSONLY_TEST_API_URL: target, POSONLY_TEST_LAN_HOST: '192.168.1.42' });
    const transport = expo.ios.infoPlist.NSAppTransportSecurity as Record<string, unknown>;
    assert.equal(transport.NSExceptionDomains, undefined);
    assert.equal(transport.NSAllowsArbitraryLoads, undefined);
    assert.doesNotThrow(() => runtime(expo.extra).assertClientEnvironment());
  }
});

test('LAN allowlists reject public, noncanonical, invalid and non-IPv4 hosts at build and runtime', () => {
  for (const host of ['8.8.8.8', '172.15.1.1', '172.32.1.1', '192.169.1.1', '192.168.1.256', '192.168.001.1', ' 192.168.1.42', '0xc0a8012a', '3232235818', 'localhost', 'posonly.com', '[::1]', '192.168.1.42:8092', '192.168.1.42\n']) {
    assert.throws(() => config({ POSONLY_BUILD_ENV: 'isolated', POSONLY_TEST_LAN_HOST: host }), /canonical RFC1918/);
    assert.throws(() => runtime({ isolated: true, apiUrl: 'http://127.0.0.1:8092/api/v1', testLanHost: host }).assertClientEnvironment(), /canonical RFC1918/);
  }
});

test('private and public hosts are blocked without the exact compiled LAN allowlist', () => {
  for (const [url, host] of [
    ['http://192.168.1.42:8092/api/v1', undefined],
    ['http://192.168.1.43:8092/api/v1', '192.168.1.42'],
    ['http://8.8.8.8:8092/api/v1', '192.168.1.42'],
    ['https://posonly.com:8092/api/v1', '192.168.1.42'],
  ]) {
    assert.throws(() => config({ POSONLY_BUILD_ENV: 'isolated', POSONLY_TEST_API_URL: url!, ...(host ? { POSONLY_TEST_LAN_HOST: host } : {}) }), /local test backend/);
    assert.throws(() => runtime({ isolated: true, apiUrl: url, testLanHost: host }).assertClientEnvironment(), /local test backend/);
  }
});

test('LAN endpoints reject wrong ports, credentials, query, hash and paths at both gates', () => {
  for (const target of [
    'http://192.168.1.42/api/v1', 'http://192.168.1.42:8081/api/v1', 'https://192.168.1.42:443/api/v1',
    'http://user:secret@192.168.1.42:8092/api/v1', 'http://192.168.1.42:8092/api/v1?key=secret',
    'http://192.168.1.42:8092/api/v1#secret', 'http://192.168.1.42:8092/other',
    'http://192.168.1.42:8092/', 'http://192.168.1.42:8092/other/../api/v1',
    'ftp://192.168.1.42:8092/api/v1',
  ]) {
    assert.throws(() => config({ POSONLY_BUILD_ENV: 'isolated', POSONLY_TEST_API_URL: target, POSONLY_TEST_LAN_HOST: '192.168.1.42' }), /credential-free/);
    assert.throws(() => runtime({ isolated: true, apiUrl: target, testLanHost: '192.168.1.42' }).assertClientEnvironment(), /credential-free/);
  }
});

test('runtime accepts the isolated localhost browser proxy and rejects foreign browser origins', () => {
  const { expo } = config({ POSONLY_BUILD_ENV: 'isolated' });
  for (const origin of ['http://127.0.0.1:8081', 'http://localhost:8081']) {
    const client = runtime(expo.extra, 'web', origin);
    assert.equal(client.apiUrl, `${origin}/api/v1`);
    assert.doesNotThrow(() => client.assertClientEnvironment());
  }
  for (const origin of ['http://192.168.1.42:8081', 'https://posonly.com', 'http://untrusted.example:8081']) {
    assert.throws(() => runtime(expo.extra, 'web', origin).assertClientEnvironment(), /localhost API proxy/);
  }
  assert.throws(() => runtime(expo.extra, 'web').assertClientEnvironment(), /localhost API proxy/);
});

test('runtime release configuration refuses a changed endpoint and production browser access', () => {
  for (const apiUrl of ['http://192.168.1.42:8092/api/v1', 'https://untrusted.example/api/v1']) {
    assert.throws(() => runtime({ isolated: false, apiUrl, testLanHost: '192.168.1.42' }).assertClientEnvironment(), /unrecognized production server/);
  }
  assert.throws(() => runtime({ isolated: false, apiUrl: 'https://posonly.com/api/v1' }, 'web', 'http://localhost:8081').assertClientEnvironment(), /browser preview is for isolated tests/);
});
