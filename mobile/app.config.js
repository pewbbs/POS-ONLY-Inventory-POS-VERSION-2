const isolated = process.env.POSONLY_BUILD_ENV === 'isolated';
const apiUrl = isolated ? (process.env.POSONLY_TEST_API_URL || 'http://127.0.0.1:8092/api/v1') : 'https://posonly.com/api/v1';
const target = new URL(apiUrl);
if (target.username || target.password || target.search || target.hash || !['http:', 'https:'].includes(target.protocol)
  || !['', '/', '/api/v1', '/api/v1/'].includes(target.pathname)) {
  throw new Error('Use a credential-free POSONLY API address without query parameters.');
}
if (isolated && !['127.0.0.1', 'localhost', '10.0.2.2'].includes(target.hostname)) {
  throw new Error('Isolated builds may only use the local test backend.');
}

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
        ...(isolated ? { NSAppTransportSecurity: { NSAllowsLocalNetworking: true }, NSLocalNetworkUsageDescription: 'Connect to the isolated POSONLY test server on this Mac.' } : {}),
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
      ['expo-secure-store', { configureAndroidBackup: true }],
      ['expo-sqlite', { useSQLCipher: true }],
      ['expo-camera', { cameraPermission: 'Scan product barcodes for POSONLY checkout.', recordAudioAndroid: false }],
      'expo-sharing',
      './withAndroidNetworkPolicy',
    ],
    extra: { apiUrl, isolated },
  },
};
