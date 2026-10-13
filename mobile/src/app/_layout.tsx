import { Slot } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { MobileProvider } from '../runtime/MobileProvider';

export default function Layout() {
  return <SafeAreaProvider><StatusBar style="dark" /><MobileProvider><Slot /></MobileProvider></SafeAreaProvider>;
}
