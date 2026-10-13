import Constants from 'expo-constants';
import { Platform } from 'react-native';

const extra = Constants.expoConfig?.extra;
export const isolated = extra?.isolated === true;
export const apiUrl: string = Platform.OS === 'web' && isolated && typeof window !== 'undefined'
  ? `${window.location.origin}/api/v1` : extra?.apiUrl || 'https://posonly.com/api/v1';

export function assertClientEnvironment(): void {
  const target = new URL(apiUrl);
  if (!isolated && apiUrl !== 'https://posonly.com/api/v1') { throw new Error('This build has an unrecognized production server.'); }
  if (isolated && !['127.0.0.1', 'localhost', '10.0.2.2'].includes(target.hostname)) { throw new Error('This test build can only connect to the isolated local server.'); }
  if (Platform.OS === 'web' && !isolated) { throw new Error('The browser preview is for isolated tests only. Use the Android or iPhone app for production accounts.'); }
}
