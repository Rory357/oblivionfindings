import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { router } from '@inertiajs/react';
import { Download, QrCode } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { api, download, type Page, REGISTER, stamp } from './api';
import { ErrorNotice, Paging } from './controls';

type Layout = {
    paper?: 'a4' | 'label';
    logo?: boolean;
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
        paper: 'a4',
        logo: true,
        width: 60,
        height: 50,
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
    const [preparedDownload, setPreparedDownload] = useState<{
        href: string;
        name: string;
    } | null>(null);
    const requestId = useRef(crypto.randomUUID());
    const aborter = useRef<AbortController | null>(null);
    const sheet = layout.paper !== 'label';
    const columns = sheet
        ? Math.max(
              0,
              Math.floor(
                  (210 - layout.margin * 2 + layout.gap) /
                      (layout.width + layout.gap),
              ),
          )
        : 1;
    const rows = sheet
        ? Math.max(
              0,
              Math.floor(
                  (297 - layout.margin * 2 + layout.gap) /
                      (layout.height + layout.gap),
              ),
          )
        : 1;
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
        layout.start >= 1 &&
        layout.start <= columns * rows &&
        layout.width >= 50 &&
        layout.width <= 190 &&
        layout.height >= 46 &&
        layout.height <= 277 &&
        layout.margin >= (sheet ? 5 : 0) &&
        layout.margin <= 30 &&
        layout.gap >= 0 &&
        layout.gap <= 15 &&
        Number.isInteger(layout.copies) &&
        layout.copies >= 1 &&
        layout.copies <= 20 &&
        Number.isInteger(layout.start);
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
        setPreparedDownload(null);
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
        setPreparedDownload(null);
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
            setPreparedDownload({
                href: `${REGISTER}/labels/${item.id}/${format}`,
                name: `asset-labels-${item.id}.${format}`,
            });
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
    const change = (key: keyof Layout, value: number | boolean) => {
        setLayout({ ...layout, [key]: value });
        setBatch(null);
        setMessage('');
        setPreparedDownload(null);
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
                        Select assets in Inventory, choose a print layout, then
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
                        Print preset
                        <select
                            className="mt-2 block h-11 w-full rounded-md border bg-background px-3 font-normal"
                            defaultValue="standard"
                            onChange={(e) => {
                                const next =
                                    e.target.value === 'large'
                                        ? {
                                              paper: 'a4' as const,
                                              logo: layout.logo,
                                              width: 90,
                                              height: 60,
                                              margin: 10,
                                              gap: 4,
                                              copies: 1,
                                              start: 1,
                                          }
                                        : e.target.value === 'label'
                                          ? {
                                                paper: 'label' as const,
                                                logo: layout.logo,
                                                width: 60,
                                                height: 50,
                                                margin: 0,
                                                gap: 0,
                                                copies: 1,
                                                start: 1,
                                            }
                                          : {
                                                paper: 'a4' as const,
                                                logo: layout.logo,
                                                width: 60,
                                                height: 50,
                                                margin: 10,
                                                gap: 3,
                                                copies: 1,
                                                start: 1,
                                            };
                                setLayout(next);
                                setBatch(null);
                                setMessage('');
                                setPreparedDownload(null);
                                requestId.current = crypto.randomUUID();
                            }}
                        >
                            <option value="standard">
                                A4 · 60 × 50 mm labels
                            </option>
                            <option value="large">
                                A4 · 90 × 60 mm labels
                            </option>
                            <option value="label">
                                Label printer · custom size
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
                                    min={
                                        key === 'width'
                                            ? 50
                                            : key === 'height'
                                              ? 46
                                              : key === 'margin'
                                                ? sheet
                                                    ? 5
                                                    : 0
                                                : key === 'gap'
                                                  ? 0
                                                  : 1
                                    }
                                    max={
                                        key === 'width'
                                            ? 190
                                            : key === 'height'
                                              ? 277
                                              : key === 'margin'
                                                ? 30
                                                : key === 'gap'
                                                  ? 15
                                                  : key === 'copies'
                                                    ? 20
                                                    : Math.max(
                                                          1,
                                                          columns * rows,
                                                      )
                                    }
                                    disabled={
                                        !sheet &&
                                        ['margin', 'gap', 'start'].includes(key)
                                    }
                                    value={layout[key]}
                                    onChange={(e) =>
                                        change(key, Number(e.target.value))
                                    }
                                />
                            </label>
                        ))}
                    </div>
                    <label className="flex min-h-11 items-center gap-3 text-sm">
                        <Checkbox
                            checked={layout.logo ?? true}
                            onCheckedChange={(value) =>
                                change('logo', value === true)
                            }
                        />
                        Include company logo from Branding settings
                    </label>
                    <p className="text-sm">
                        {columns} columns × {rows} rows · {total} labels ·{' '}
                        {sheets}{' '}
                        {sheet
                            ? sheets === 1
                                ? 'A4 sheet'
                                : 'A4 sheets'
                            : 'label pages'}
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
                    <h3 className="mb-3 font-semibold">
                        {sheet
                            ? 'A4 placement preview'
                            : 'Label placement preview'}
                    </h3>
                    <div
                        className="relative w-full overflow-hidden border bg-white shadow-sm"
                        style={{
                            aspectRatio: sheet
                                ? '210/297'
                                : `${layout.width}/${layout.height}`,
                        }}
                    >
                        {Array.from(
                            { length: Math.min(columns * rows, 80) },
                            (_, index) => (
                                <div
                                    key={index}
                                    className="absolute flex flex-col items-center justify-center overflow-hidden border border-dashed border-border text-black"
                                    style={{
                                        left: sheet
                                            ? `${((layout.margin + (index % columns) * (layout.width + layout.gap)) / 210) * 100}%`
                                            : 0,
                                        top: sheet
                                            ? `${((layout.margin + Math.floor(index / columns) * (layout.height + layout.gap)) / 297) * 100}%`
                                            : 0,
                                        width: sheet
                                            ? `${(layout.width / 210) * 100}%`
                                            : '100%',
                                        height: sheet
                                            ? `${(layout.height / 297) * 100}%`
                                            : '100%',
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
                        This shows print positions. Open the generated PDF to
                        check the actual logo, asset names and scannable labels.
                        Print at actual size with no fit-to-page scaling.
                    </p>
                </aside>
            </Card>
            {message && (
                <div
                    role="status"
                    className="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-muted p-4 text-sm"
                >
                    <span>{message}</span>
                    {preparedDownload && !busy && (
                        <div className="flex flex-wrap items-center gap-2">
                            <span>If the download did not start:</span>
                            <Button variant="outline" asChild>
                                <a
                                    href={preparedDownload.href}
                                    download={preparedDownload.name}
                                >
                                    Save file directly
                                </a>
                            </Button>
                        </div>
                    )}
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
