import { Platform } from 'react-native';
import * as Print from 'expo-print';
import * as Sharing from 'expo-sharing';
import { File, Paths } from 'expo-file-system';
import type { AccountState } from '../core/types';
import type { ReceiptView } from '../ui/types';
import { receiptHtml } from './receiptBranding';

export async function shareReceipt(receipt: ReceiptView): Promise<void> {
  const html = receiptHtml(receipt);
  if (Platform.OS === 'web') { await printPreviewReceipt(html); return; }
  if (!await Sharing.isAvailableAsync()) { throw new Error('Receipt sharing is unavailable on this device. The original receipt is retained.'); }
  const pdf = await Print.printToFileAsync({ html });
  try { await Sharing.shareAsync(pdf.uri, { mimeType: 'application/pdf', dialogTitle: 'POSONLY receipt' }); }
  finally { const file = new File(pdf.uri); if (file.exists) { file.delete(); } }
}

/** Expo Print prints the current web page; this frame keeps the isolated preview limited to the receipt. */
async function printPreviewReceipt(html: string): Promise<void> {
  await new Promise<void>((resolve, reject) => {
    const frame = document.createElement('iframe');
    frame.title = 'POSONLY receipt';
    frame.setAttribute('aria-hidden', 'true');
    Object.assign(frame.style, { position: 'fixed', left: '-10000px', top: '0', width: '380px', height: '1px', border: '0' });
    frame.onload = () => {
      const target = frame.contentWindow;
      if (!target) { frame.remove(); reject(new Error('The receipt preview could not be opened. The saved sale is retained.')); return; }
      const cleanup = () => frame.remove();
      target.addEventListener('afterprint', cleanup, { once: true });
      try { target.focus(); target.print(); resolve(); }
      catch { cleanup(); reject(new Error('Receipt printing is unavailable. The saved sale is retained.')); }
    };
    frame.onerror = () => { frame.remove(); reject(new Error('The receipt preview could not be loaded. The saved sale is retained.')); };
    frame.srcdoc = html;
    document.body.appendChild(frame);
  });
}

export async function exportQueue(state: AccountState): Promise<void> {
  const content = JSON.stringify({ format: 'posonly-mobile-outbox-v1', owner: state.owner, exportedAt: new Date().toISOString(), requests: state.queue.filter(entry => entry.status !== 'confirmed'), pendingAction: state.pendingAction }, null, 2);
  const filename = `posonly-queue-${new Date().toISOString().slice(0, 10)}.json`;
  if (Platform.OS === 'web') {
    const url = URL.createObjectURL(new Blob([content], { type: 'application/json' }));
    const link = document.createElement('a'); link.href = url; link.download = filename; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000); return;
  }
  const file = new File(Paths.cache, filename);
  if (!await Sharing.isAvailableAsync()) { throw new Error('Private backup sharing is unavailable. Keep the original saved sales on this device.'); }
  try {
    file.create({ overwrite: true }); file.write(content);
    await Sharing.shareAsync(file.uri, { mimeType: 'application/json', dialogTitle: 'Save a private POSONLY queue backup' });
  }
  finally { if (file.exists) { file.delete(); } }
}
