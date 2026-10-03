import { Button } from '@/components/ui/button';
import { ExportDialog } from '@/pages/emar/reports/_export-dialog';
import { requestJson, type ExportContext } from '@/pages/emar/reports/_types';
import { Download } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';

/** P02 and other record surfaces use the same P09 purpose/review/download flow. */
export function MedicationExportButton({ type = 'mar', clientId, siteId, dateFrom, dateTo, children, className }: { type?: 'mar' | 'cd_register' | 'round_sheet' | 'doses' | 'errors' | 'stock' | 'audit'; clientId?: number; siteId?: number; dateFrom: string; dateTo: string; children?: ReactNode; className?: string }) {
    const [context, setContext] = useState<ExportContext | null>(null), [busy, setBusy] = useState(false), [error, setError] = useState(''), [online, setOnline] = useState(typeof navigator === 'undefined' || navigator.onLine);
    useEffect(() => { const update = () => setOnline(navigator.onLine); window.addEventListener('online', update); window.addEventListener('offline', update); return () => { window.removeEventListener('online', update); window.removeEventListener('offline', update); }; }, []);
    async function open() {
        setBusy(true); setError('');
        try {
            const params = new URLSearchParams({ type, period: 'custom', date_from: dateFrom, date_to: dateTo, ...(clientId ? { client_id: String(clientId) } : {}), ...(siteId ? { site_id: String(siteId) } : {}) });
            setContext(await requestJson(`/emar/reports/export-options?${params}`));
        } catch (e) { setError(e instanceof Error ? e.message : 'The download options could not be loaded.'); }
        finally { setBusy(false); }
    }
    const option = context?.exports.find((item) => item.type === type);
    return <><Button type="button" variant="outline" onClick={open} disabled={!online || busy} className={className}><Download className="size-4" />{busy ? 'Loading options…' : children ?? 'Make MAR PDF'}</Button>{error && <p role="alert" className="text-caption text-status-critical">{error}</p>}{context && option && <ExportDialog option={option} props={context} online={online} onClose={() => setContext(null)} />}</>;
}
