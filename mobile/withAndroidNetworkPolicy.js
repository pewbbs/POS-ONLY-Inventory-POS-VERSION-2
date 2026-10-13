const { AndroidConfig, withAndroidManifest } = require('expo/config-plugins');

module.exports = function withAndroidNetworkPolicy(config) {
  return withAndroidManifest(config, modConfig => {
    const application = AndroidConfig.Manifest.getMainApplicationOrThrow(modConfig.modResults);
    application.$['android:usesCleartextTraffic'] = config.extra?.isolated === true ? 'true' : 'false';
    return modConfig;
  });
};
