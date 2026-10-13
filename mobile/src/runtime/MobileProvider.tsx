import React, { createContext, useContext, useState } from 'react';
import { Modal, Pressable, StyleSheet, Text, View } from 'react-native';
import { CameraView, useCameraPermissions } from 'expo-camera';
import { router } from 'expo-router';
import { useMobile } from './useMobile';
import type { AppTab } from '../ui/types';
import { shareReceipt, exportQueue } from './sharing';

type MobileContextValue = ReturnType<typeof useMobile>;
const Context = createContext<MobileContextValue | null>(null);

export function MobileProvider({ children }: { children: React.ReactNode }) {
  const [tab, setTab] = useState<AppTab>('inventory');
  const runtime = useMobile(tab, next => { setTab(next); router.replace(`/${next}`); });
  const [permission, requestPermission] = useCameraPermissions();
  const actions = {
    ...runtime.actions,
    onPrintReceipt: () => void runtime.run(async () => { if (runtime.model.receipt) { await shareReceipt(runtime.model.receipt); } }),
    onExportQueue: () => void runtime.run(async () => { if (runtime.account) { await exportQueue(runtime.account); runtime.setNotice('Private queue backup prepared. Keep the original requests on this device until confirmed.'); } }),
  };
  return <Context.Provider value={{ ...runtime, actions }}>
    {children}
    <Modal visible={runtime.scanOpen} animationType="slide" onRequestClose={() => runtime.setScanOpen(false)}>
      <View style={styles.scanner}>
        <View style={styles.heading}><Text style={styles.title}>Scan product barcode</Text><Pressable accessibilityRole="button" accessibilityLabel="Close scanner" onPress={() => runtime.setScanOpen(false)} style={styles.close}><Text style={styles.closeText}>Close</Text></Pressable></View>
        <Text style={styles.copy}>Use a product barcode already saved in your company catalog. You can also type it into Search.</Text>
        {permission?.granted ? <CameraView style={styles.camera} facing="back" barcodeScannerSettings={{ barcodeTypes: ['ean13', 'ean8', 'upc_a', 'upc_e', 'code128', 'code39', 'qr'] }} onBarcodeScanned={runtime.scanOpen ? event => runtime.scan(event.data) : undefined} /> : <View style={styles.prompt}><Text style={styles.copy}>Camera access is optional and is used only to scan product barcodes.</Text><Pressable accessibilityRole="button" style={styles.close} onPress={() => void requestPermission()}><Text style={styles.closeText}>Allow camera access</Text></Pressable></View>}
      </View>
    </Modal>
  </Context.Provider>;
}

export function useMobileContext(): MobileContextValue {
  const value = useContext(Context);
  if (!value) { throw new Error('Open POSONLY inside its app layout.'); }
  return value;
}

const styles = StyleSheet.create({
  scanner: { flex: 1, backgroundColor: '#f4f2ee', paddingTop: 64, paddingHorizontal: 20, paddingBottom: 32 },
  heading: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', gap: 12 },
  title: { fontSize: 22, fontWeight: '700', color: '#191918', flex: 1 },
  copy: { fontSize: 15, lineHeight: 23, color: '#66635c', marginVertical: 18 },
  camera: { flex: 1, borderRadius: 16, overflow: 'hidden' },
  prompt: { flex: 1, justifyContent: 'center', alignItems: 'center' },
  close: { minHeight: 44, padding: 12, backgroundColor: '#982e27', borderRadius: 10 },
  closeText: { color: '#fff', fontWeight: '700' },
});
