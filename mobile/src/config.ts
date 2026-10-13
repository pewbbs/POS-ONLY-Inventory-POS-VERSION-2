import Constants from 'expo-constants';
import { Platform } from 'react-native';
import { validateIsolatedApiUrl, validateIsolatedBrowserApiUrl } from '../testServer';

const extra = Constants.expoConfig?.extra;
export const isolated = extra?.isolated === true;
export const apiUrl: string = Platform.OS === 'web' && isolated && typeof window !== 'undefined'
  ? `${window.location.origin}/api/v1` : extra?.apiUrl || 'https://posonly.com/api/v1';

export function assertClientEnvironment(): void {
  if (!isolated && apiUrl !== 'https://posonly.com/api/v1') { throw new Error('This build has an unrecognized production server.'); }
  if (Platform.OS === 'web' && !isolated) { throw new Error('The browser preview is for isolated tests only. Use the Android or iPhone app for production accounts.'); }
  if (isolated) {
    validateIsolatedApiUrl(extra?.apiUrl || apiUrl, extra?.testLanHost);
    if (Platform.OS === 'web') {
      validateIsolatedBrowserApiUrl(apiUrl, typeof window === 'undefined' ? undefined : window.location.origin);
    }
  }
}
