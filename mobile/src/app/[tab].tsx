import { useLocalSearchParams } from 'expo-router';
import { AppView } from '../ui/AppView';
import { useMobileContext } from '../runtime/MobileProvider';
import type { AppTab } from '../ui/types';

export default function Screen() {
  const { tab } = useLocalSearchParams<{ tab: AppTab }>();
  const { model, actions } = useMobileContext();
  const current = ['inventory', 'pos', 'sales', 'settings'].includes(tab) ? tab : 'inventory';
  return <AppView model={{ ...model, tab: current }} actions={actions} />;
}
