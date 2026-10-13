const { validateTestLanHost, validateIsolatedApiUrl } = require('./testServer');

const isolated = process.env.POSONLY_BUILD_ENV === 'isolated';
const testLanHost = isolated ? validateTestLanHost(process.env.POSONLY_TEST_LAN_HOST) : null;
const apiUrl = isolated
  ? validateIsolatedApiUrl(process.env.POSONLY_TEST_API_URL || 'http://127.0.0.1:8092/api/v1', testLanHost)
  : 'https://posonly.com/api/v1';
const target = new URL(apiUrl);
const testTransportSecurity = isolated ? {
  NSAllowsLocalNetworking: true,
  ...(testLanHost && target.hostname === testLanHost && target.protocol === 'http:' ? {
    NSExceptionDomains: { [testLanHost]: { NSExceptionAllowsInsecureHTTPLoads: true } },
  } : {}),
} : { NSAllowsLocalNetworking: false, NSAllowsArbitraryLoads: false };

module.exports = {
  expo: {
    name: isolated ? 'POSONLY Test' : 'POSONLY',
    slug: 'posonly-mobile',
    scheme: isolated ? 'posonly-test' : 'posonly',
    version: '1.0.0',
    orientation: 'default',
    icon: './assets/icon.png',
    userInterfaceStyle: 'light',
    ios: {
      bundleIdentifier: isolated ? 'com.posonly.mobile.test' : 'com.posonly.mobile',
      supportsTablet: true,
      infoPlist: {
        ITSAppUsesNonExemptEncryption: true,
        NSAppTransportSecurity: testTransportSecurity,
        ...(isolated ? { NSLocalNetworkUsageDescription: 'Connect to the isolated POSONLY test server on this Mac.' } : {}),
      },
    },
    android: {
      package: isolated ? 'com.posonly.mobile.test' : 'com.posonly.mobile',
      allowBackup: false,
      permissions: ['android.permission.CAMERA'],
      blockedPermissions: ['android.permission.RECORD_AUDIO'],
      adaptiveIcon: { foregroundImage: './assets/icon.png', backgroundColor: '#982e27' },
    },
    web: { bundler: 'metro', favicon: './assets/favicon.png' },
    plugins: [
      'expo-router',
      ['expo-build-properties', { ios: { enableSceneSupport: true } }],
      ['expo-secure-store', { configureAndroidBackup: true }],
      ['expo-sqlite', { useSQLCipher: true }],
      ['expo-camera', { cameraPermission: 'Scan product barcodes for POSONLY checkout.', recordAudioAndroid: false }],
      'expo-sharing',
      './withAndroidNetworkPolicy',
    ],
    extra: { apiUrl, isolated, ...(isolated && testLanHost ? { testLanHost } : {}) },
  },
};
