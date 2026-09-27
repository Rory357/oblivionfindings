import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { router } from '@inertiajs/react';
import { Download, QrCode } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { api, download, type Page, REGISTER, stamp } from './api';
import { ErrorNotice, Paging } from './controls';

type Layout = {
    width: number;
    height: number;
    margin: number;
    gap: number;
    copies: number;
    start: number;
    columns?: number;
    rows?: number;
};
type LabelBatch = {
    id: number;
    asset_ids: number[];
    layout: Layout;
    status: string;
    expires_at: string;
    created_at: string;
    downloads: { at: string; format: string; status: string }[];
};

export function Labels({
    status,
    selected,
    onInventory,
    onClear,
}: {
    status: string;
    selected: number[];
    onInventory: () => void;
    onClear: () => void;
}) {
    const [layout, setLayout] = useState<Layout>({
        width: 60,
        height: 45,
        margin: 10,
        gap: 3,
        copies: 1,
        start: 1,
    });
    const [batch, setBatch] = useState<LabelBatch | null>(null);
    const [history, setHistory] = useState<Page<LabelBatch>>();
    const [page, setPage] = useState(1);
    const [revision, setRevision] = useState(0);
    useEffect(() => setPage(1), [status]);
    const [busy, setBusy] = useState('');
    const [error, setError] = useState('');
    const [message, setMessage] = useState('');
    const requestId = useRef(crypto.randomUUID());
    const aborter = useRef<AbortController | null>(null);
    const columns = Math.max(
        0,
        Math.floor(
            (210 - layout.margin * 2 + layout.gap) /
                (layout.width + layout.gap),
        ),
    );
    const rows = Math.max(
        0,
        Math.floor(
            (297 - layout.margin * 2 + layout.gap) /
                (layout.height + layout.gap),
        ),
    );
    const total = selected.length * layout.copies;
    const sheets =
        columns * rows
            ? Math.ceil((total + layout.start - 1) / (columns * rows))
            : 0;
    const valid =
        selected.length > 0 &&
        selected.length <= 200 &&
        total <= 1000 &&
        columns * rows > 0 &&
        layout.start <= columns * rows &&
        layout.width >= 40 &&
        layout.height >= 40;
    useEffect(() => {
        const controller = new AbortController();
        void api<Page<LabelBatch>>(
            `/labels?page=${page}&status=${encodeURIComponent(status)}`,
            'GET',
            undefined,
            controller.signal,
        )
            .then(setHistory)
            .catch((e) => {
                if (e.name !== 'AbortError') setError(e.message);
            });
        return () => controller.abort();
    }, [page, revision, status]);
    async function generate() {
        setBusy('batch');
        setError('');
        setMessage('');
        try {
            setBatch(
                await api<LabelBatch>('/labels', 'POST', {
                    request_id: requestId.current,
                    asset_ids: selected,
                    layout,
                }),
            );
            setRevision((v) => v + 1);
            router.reload({ only: ['workflow_metrics'] });
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy('');
        }
    }
    async function exportBatch(item: LabelBatch, format: string) {
        setBusy(`${item.id}-${format}`);
        setError('');
        setMessage('Preparing your labels…');
        const controller = new AbortController();
        aborter.current = controller;
        try {
            await download(
                `${REGISTER}/labels/${item.id}/${format}`,
                `asset-labels-${item.id}.${format}`,
                controller.signal,
            );
            setMessage(
                'Download prepared. Check the file before printing at 100% / actual size.',
            );
        } catch (e) {
            if ((e as Error).name === 'AbortError')
                setMessage(
                    'Download cancelled. The saved batch can be downloaded again.',
                );
            else {
                setError((e as Error).message);
                setMessage('');
            }
        } finally {
            setBusy('');
            aborter.current = null;
            setRevision((v) => v + 1);
            router.reload({ only: ['workflow_metrics'] });
        }
    }
    const change = (key: keyof Layout, value: number) => {
        setLayout({ ...layout, [key]: value });
        setBatch(null);
        requestId.current = crypto.randomUUID();
    };
    return (
        <section className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-lg font-semibold">
                        Create asset labels
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Select assets in Inventory, choose a sheet layout, then
                        download your labels.
                    </p>
                </div>
                <Button variant="outline" onClick={onInventory}>
                    Choose assets in inventory
                </Button>
            </div>
            <ErrorNotice message={error} />
            <Card
                unstyled
                className="grid gap-5 rounded-xl border bg-card p-5 lg:grid-cols-[1fr_360px]"
            >
                <div className="space-y-5">
                    <div className="flex items-center justify-between rounded-xl bg-primary/5 p-4">
                        <div>
                            <strong className="text-2xl">
                                {selected.length}
                            </strong>
                            <span className="ml-2 text-sm">
                                assets selected
                            </span>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Up to 200 assets / 1,000 labels per batch.
                            </p>
                        </div>
                        {selected.length > 0 && (
                            <Button
                                variant="ghost"
                                onClick={() => {
                                    onClear();
                                    setBatch(null);
                                    requestId.current = crypto.randomUUID();
                                }}
                            >
                                Clear selection
                            </Button>
                        )}
                    </div>
                    <label className="block text-sm font-semibold">
                        Sheet preset
                        <select
                            className="mt-2 block h-11 w-full rounded-md border bg-background px-3 font-normal"
                            defaultValue="standard"
                            onChange={(e) => {
                                const next =
                                    e.target.value === 'large'
                                        ? {
                                              width: 90,
                                              height: 60,
                                              margin: 10,
                                              gap: 4,
                                              copies: 1,
                                              start: 1,
                                          }
                                        : {
                                              width: 60,
                                              height: 45,
                                              margin: 10,
                                              gap: 3,
                                              copies: 1,
                                              start: 1,
                                          };
                                setLayout(next);
                                setBatch(null);
                                requestId.current = crypto.randomUUID();
                            }}
                        >
                            <option value="standard">
                                A4 · 60 × 45 mm labels
                            </option>
                            <option value="large">
                                A4 · 90 × 60 mm labels
                            </option>
                        </select>
                    </label>
                    <div className="grid grid-cols-2 gap-4">
                        {(
                            [
                                ['width', 'Label width (mm)'],
                                ['height', 'Label height (mm)'],
                                ['margin', 'Page margin (mm)'],
                                ['gap', 'Gap (mm)'],
                                ['copies', 'Copies per asset'],
                                ['start', 'First label position'],
                            ] as const
                        ).map(([key, label]) => (
                            <label key={key} className="text-xs font-semibold">
                                {label}
                                <Input
                                    type="number"
                                    aria-label={label}
                                    className="mt-1.5 h-11"
                                    min={key === 'gap' ? 0 : 1}
                                    value={layout[key]}
                                    onChange={(e) =>
                                        change(key, Number(e.target.value))
                                    }
                                />
                            </label>
                        ))}
                    </div>
                    <p className="text-sm">
                        {columns} columns × {rows} rows · {total} labels ·{' '}
                        {sheets} A4 {sheets === 1 ? 'sheet' : 'sheets'}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        QR identities stay the same when labels are regenerated.
                        Asset assignment and ownership details are excluded from
                        labels and manifests.
                    </p>
                    <Button
                        className="min-h-11"
                        disabled={!valid || !!busy}
                        onClick={() => void generate()}
                    >
                        <QrCode />
                        {busy === 'batch'
                            ? 'Preparing batch…'
                            : 'Prepare label batch'}
                    </Button>
                    {!selected.length && (
                        <p className="text-sm text-muted-foreground">
                            Choose one or more assets in Inventory to continue.
                        </p>
                    )}
                    {batch && (
                        <div className="space-y-3 rounded-xl border p-4">
                            <strong>Batch QR-{batch.id} is ready</strong>
                            <p className="text-xs text-muted-foreground">
                                {batch.asset_ids.length} assets · Download
                                available until {stamp(batch.expires_at)}
                            </p>
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    disabled={!!busy}
                                    onClick={() =>
                                        void exportBatch(batch, 'pdf')
                                    }
                                >
                                    <Download />
                                    Download label PDF
                                </Button>
                                <Button
                                    variant="outline"
                                    disabled={!!busy}
                                    onClick={() =>
                                        void exportBatch(batch, 'zip')
                                    }
                                >
                                    <Download />
                                    PNG / SVG ZIP
                                </Button>
                            </div>
                        </div>
                    )}
                </div>
                <aside className="rounded-xl bg-muted p-4">
                    <h3 className="mb-3 font-semibold">A4 placement preview</h3>
                    <div
                        className="relative w-full overflow-hidden border bg-white shadow-sm"
                        style={{ aspectRatio: '210/297' }}
                    >
                        {Array.from(
                            { length: Math.min(columns * rows, 80) },
                            (_, index) => (
                                <div
                                    key={index}
                                    className="absolute flex flex-col items-center justify-center overflow-hidden border border-dashed border-border text-black"
                                    style={{
                                        left: `${((layout.margin + (index % columns) * (layout.width + layout.gap)) / 210) * 100}%`,
                                        top: `${((layout.margin + Math.floor(index / columns) * (layout.height + layout.gap)) / 297) * 100}%`,
                                        width: `${(layout.width / 210) * 100}%`,
                                        height: `${(layout.height / 297) * 100}%`,
                                        opacity:
                                            index < layout.start - 1 ? 0.25 : 1,
                                    }}
                                >
                                    <QrCode className="size-8" />
                                    <span className="text-[8px]">
                                        {index < layout.start - 1
                                            ? 'Skip'
                                            : `Position ${index + 1}`}
                                    </span>
                                </div>
                            ),
                        )}
                    </div>
                    <p className="mt-3 text-xs text-muted-foreground">
                        This shows sheet positions. Open the generated PDF to
                        check the actual logo, asset names and scannable labels.
                        Print at actual size with no fit-to-page scaling.
                    </p>
                </aside>
            </Card>
            {message && (
                <div
                    role="status"
                    className="flex items-center justify-between rounded-xl bg-muted p-4 text-sm"
                >
                    <span>{message}</span>
                    {busy && busy !== 'batch' && (
                        <Button
                            variant="outline"
                            onClick={() => aborter.current?.abort()}
                        >
                            Cancel download
                        </Button>
                    )}
                </div>
            )}
            <Card unstyled className="rounded-xl border bg-card p-4">
                <h3 className="font-semibold">Your label batches</h3>
                <p className="mb-3 text-xs text-muted-foreground">
                    Generation history records prepared files. It does not
                    confirm physical printing.
                </p>
                {history?.data.map((item) => (
                    <div
                        key={item.id}
                        className="flex flex-wrap items-center justify-between gap-3 border-t py-3"
                    >
                        <div className="text-sm">
                            <strong>
                                QR-{item.id} · {item.asset_ids.length} assets
                            </strong>
                            <p className="text-xs text-muted-foreground">
                                {stamp(item.created_at)} · {item.layout.width} ×{' '}
                                {item.layout.height} mm · {item.layout.copies}{' '}
                                copies
                            </p>
                            <StatusBadge
                                variant={
                                    item.status === 'failed'
                                        ? 'warning'
                                        : 'neutral'
                                }
                            >
                                {new Date(item.expires_at) < new Date()
                                    ? 'Expired'
                                    : item.status === 'failed'
                                      ? 'Generation failed · retry available'
                                      : `${item.downloads.filter((d) => d.status === 'generated').length} files generated`}
                            </StatusBadge>
                        </div>
                        <div className="flex gap-2">
                            <Button
                                variant="outline"
                                disabled={
                                    !!busy ||
                                    new Date(item.expires_at) < new Date()
                                }
                                onClick={() => void exportBatch(item, 'pdf')}
                            >
                                PDF
                            </Button>
                            <Button
                                variant="outline"
                                disabled={
                                    !!busy ||
                                    new Date(item.expires_at) < new Date()
                                }
                                onClick={() => void exportBatch(item, 'zip')}
                            >
                                ZIP
                            </Button>
                        </div>
                    </div>
                ))}
                {history && !history.data.length && (
                    <p className="text-sm text-muted-foreground">
                        Generated batches will appear here.
                    </p>
                )}
                {history && (
                    <Paging
                        page={history.current_page}
                        last={history.last_page}
                        total={history.total}
                        onChange={setPage}
                    />
                )}
            </Card>
        </section>
    );
}
