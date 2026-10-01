import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { router } from '@inertiajs/react';
import { Download, FileUp, Upload } from 'lucide-react';
import { useEffect, useState } from 'react';
import { api, type Option, type Page, stamp } from './api';
import { ErrorNotice, Paging, SearchPicker } from './controls';

type ImportRow = {
    number: number;
    raw: Record<string, string>;
    data: Record<string, string | null>;
    errors: string[];
    status: string;
    asset_id: number | null;
};
type Batch = {
    id: number;
    filename: string;
    status: string;
    version: number;
    headers: string[];
    mapping: Record<string, string>;
    rows: ImportRow[];
    updated_at: string;
};
const fields: Record<string, string> = {
    name: 'Asset name *',
    asset_tag: 'Asset tag',
    serial_number: 'Serial number',
    category: 'Category',
    site_id: 'Site ID *',
    site_room_id: 'Room ID',
    manufacturer: 'Manufacturer',
    model: 'Model',
    purchase_date: 'Purchase date',
    warranty_expires_at: 'Warranty expiry',
    notes: 'Notes',
};

export function Imports({
    sites,
    status,
}: {
    sites: Option[];
    status: string;
}) {
    const [batch, setBatch] = useState<Batch | null>(null);
    const [mapping, setMapping] = useState<Record<string, string>>({});
    const [selected, setSelected] = useState<number[]>([]);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [history, setHistory] = useState<Page<Batch>>();
    const [page, setPage] = useState(1);
    const [revision, setRevision] = useState(0);
    const [site, setSite] = useState('');
    const [rooms, setRooms] = useState<Option[]>([]);
    useEffect(() => setPage(1), [status]);
    useEffect(() => {
        const controller = new AbortController();
        void api<Page<Batch>>(
            `/imports?page=${page}&status=${encodeURIComponent(status)}`,
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
    useEffect(() => {
        if (!site) {
            setRooms([]);
            return;
        }
        const controller = new AbortController();
        void api<Option[]>(
            `/rooms?site_id=${site}`,
            'GET',
            undefined,
            controller.signal,
        )
            .then(setRooms)
            .catch((e) => {
                if (e.name !== 'AbortError') setError(e.message);
            });
        return () => controller.abort();
    }, [site]);
    function applyBatch(value: Batch) {
        setBatch(value);
        setMapping(value.mapping);
        setSelected(
            value.rows
                .filter((row) => ['ready', 'failed'].includes(row.status))
                .map((row) => row.number),
        );
    }
    async function upload(file?: File) {
        if (!file) return;
        if (
            file.size > 1024 * 1024 ||
            !file.name.toLowerCase().endsWith('.csv')
        ) {
            setError('Choose a CSV file up to 1 MB and 200 rows.');
            return;
        }
        setBusy(true);
        setError('');
        try {
            const form = new FormData();
            form.append('file', file);
            applyBatch(await api<Batch>('/imports', 'POST', form));
            setRevision((v) => v + 1);
            router.reload({ only: ['workflow_metrics'] });
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    }
    async function open(id: number) {
        setBusy(true);
        setError('');
        try {
            applyBatch(await api<Batch>(`/imports/${id}`));
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    }
    async function act(action: 'validate' | 'import') {
        if (!batch) return;
        setBusy(true);
        setError('');
        try {
            applyBatch(
                await api<Batch>(`/imports/${batch.id}`, 'PATCH', {
                    version: batch.version,
                    action,
                    mapping,
                    row_numbers: selected,
                }),
            );
            setRevision((v) => v + 1);
            router.reload({ only: ['workflow_metrics'] });
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    }
    function template() {
        const content =
            'name,asset_tag,serial_number,category,site_id,site_room_id,purchase_date,warranty_expires_at,notes\r\n';
        const url = URL.createObjectURL(
            new Blob([content], { type: 'text/csv;charset=utf-8' }),
        );
        const link = document.createElement('a');
        link.href = url;
        link.download = 'inventory-template.csv';
        link.click();
        window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    }
    const imported =
        batch?.rows.filter((row) => row.status === 'imported').length || 0;
    const phase =
        !batch || batch.status === 'mapping'
            ? 0
            : batch.status === 'validated'
              ? 1
              : 2;
    return (
        <section className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-lg font-semibold">
                        Import & reconcile
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Map columns, check every row, then import the rows you
                        choose.
                    </p>
                </div>
                <Button variant="outline" onClick={template}>
                    <Download />
                    Download CSV template
                </Button>
            </div>
            <ErrorNotice message={error} />
            {error && batch && (
                <Button
                    variant="outline"
                    disabled={busy}
                    onClick={() => void open(batch.id)}
                >
                    Reload saved results
                </Button>
            )}
            <Card unstyled className="rounded-xl border bg-card p-5">
                <ol className="mb-6 flex flex-wrap gap-5 border-b pb-4">
                    {[
                        'File & mapping',
                        'Validate & select',
                        'Results & recovery',
                    ].map((label, i) => (
                        <li
                            key={label}
                            className={`flex items-center gap-2 text-sm ${phase === i ? 'font-semibold text-primary' : 'text-muted-foreground'}`}
                        >
                            <span
                                className={`grid size-7 place-items-center rounded-full ${phase === i ? 'bg-primary-fill text-primary-fill-foreground' : 'bg-muted'}`}
                            >
                                {i + 1}
                            </span>
                            {label}
                        </li>
                    ))}
                </ol>
                {!batch ? (
                    <div className="grid gap-6 lg:grid-cols-[1.5fr_1fr]">
                        <label
                            onDragOver={(e) => e.preventDefault()}
                            onDrop={(e) => {
                                e.preventDefault();
                                if (!busy) void upload(e.dataTransfer.files[0]);
                            }}
                            className="flex min-h-60 cursor-pointer flex-col items-center justify-center gap-3 rounded-xl border-2 border-dashed bg-muted/30 p-6 text-center"
                        >
                            <Upload className="size-9 text-primary" />
                            <strong>
                                {busy
                                    ? 'Reading file…'
                                    : 'Drop an inventory CSV here'}
                            </strong>
                            <span className="text-sm text-muted-foreground">
                                or choose a file · UTF-8 · 1 MB · up to 200 rows
                            </span>
                            <Input
                                type="file"
                                accept=".csv,text/csv"
                                aria-label="Choose inventory CSV"
                                disabled={busy}
                                className="max-w-xs"
                                onChange={(e) =>
                                    void upload(e.target.files?.[0])
                                }
                            />
                        </label>
                        <div className="space-y-3 rounded-xl bg-muted p-5">
                            <h3 className="font-semibold">Before you import</h3>
                            <p className="text-sm">
                                Use the permitted site and room IDs below. Keep
                                original asset tags and serial numbers. Leave
                                unknown dates blank; use YYYY-MM-DD for known
                                dates.
                            </p>
                            <p className="text-sm">
                                Existing records are checked for conflicts. An
                                import creates new assets; it does not overwrite
                                records or establish physical custody.
                            </p>
                        </div>
                    </div>
                ) : phase === 0 ? (
                    <>
                        <div className="mb-4">
                            <h3 className="font-semibold">
                                Map {batch.filename}
                            </h3>
                            <p className="text-sm text-muted-foreground">
                                {batch.rows.length} rows · Match each field to a
                                column in the file.
                            </p>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                            {Object.entries(fields).map(([field, label]) => (
                                <label
                                    key={field}
                                    className="text-xs font-semibold"
                                >
                                    {label}
                                    <select
                                        className="mt-1.5 block h-11 w-full rounded-md border bg-background px-3 text-sm font-normal"
                                        value={mapping[field] || ''}
                                        onChange={(e) =>
                                            setMapping({
                                                ...mapping,
                                                [field]: e.target.value,
                                            })
                                        }
                                    >
                                        <option value="">Do not import</option>
                                        {batch.headers.map((header) => (
                                            <option key={header}>
                                                {header}
                                            </option>
                                        ))}
                                    </select>
                                    <span className="mt-1 block truncate text-muted-foreground">
                                        Example:{' '}
                                        {batch.rows[0]?.raw[mapping[field]] ||
                                            '—'}
                                    </span>
                                </label>
                            ))}
                        </div>
                    </>
                ) : (
                    <>
                        <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h3 className="font-semibold">
                                    {batch.filename} · IMP-{batch.id}
                                </h3>
                                <p className="text-sm text-muted-foreground">
                                    {imported} imported ·{' '}
                                    {
                                        batch.rows.filter(
                                            (r) => r.status === 'invalid',
                                        ).length
                                    }{' '}
                                    need correction · {selected.length} selected
                                </p>
                            </div>
                            <Button
                                variant="outline"
                                disabled={busy}
                                onClick={() =>
                                    setSelected(
                                        batch.rows
                                            .filter((r) =>
                                                ['ready', 'failed'].includes(
                                                    r.status,
                                                ),
                                            )
                                            .map((r) => r.number),
                                    )
                                }
                            >
                                Select unfinished valid rows
                            </Button>
                        </div>
                        <div className="max-h-[480px] overflow-auto rounded-lg border">
                            <table className="w-full text-left text-sm">
                                <thead className="sticky top-0 bg-muted">
                                    <tr>
                                        <th className="p-3">Select</th>
                                        <th className="p-3">Row / asset</th>
                                        <th className="p-3">Site / room ID</th>
                                        <th className="p-3">Result</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {batch.rows.map((row) => (
                                        <tr
                                            key={row.number}
                                            className="border-t"
                                        >
                                            <td className="p-3">
                                                <input
                                                    aria-label={`Select import row ${row.number}`}
                                                    type="checkbox"
                                                    disabled={
                                                        busy ||
                                                        ![
                                                            'ready',
                                                            'failed',
                                                        ].includes(row.status)
                                                    }
                                                    checked={selected.includes(
                                                        row.number,
                                                    )}
                                                    onChange={(e) =>
                                                        setSelected(
                                                            e.target.checked
                                                                ? [
                                                                      ...selected,
                                                                      row.number,
                                                                  ]
                                                                : selected.filter(
                                                                      (n) =>
                                                                          n !==
                                                                          row.number,
                                                                  ),
                                                        )
                                                    }
                                                />
                                            </td>
                                            <td className="p-3">
                                                <strong>
                                                    {row.number} ·{' '}
                                                    {row.data.name ||
                                                        'Missing name'}
                                                </strong>
                                                <p className="text-xs text-muted-foreground">
                                                    {row.data.asset_tag ||
                                                        'No tag'}{' '}
                                                    ·{' '}
                                                    {row.data.serial_number ||
                                                        'No serial'}
                                                </p>
                                            </td>
                                            <td className="p-3">
                                                {row.data.site_id || '—'} /{' '}
                                                {row.data.site_room_id ||
                                                    'No room'}
                                            </td>
                                            <td className="p-3">
                                                <StatusBadge
                                                    variant={
                                                        row.status ===
                                                        'imported'
                                                            ? 'success'
                                                            : row.status ===
                                                                'ready'
                                                              ? 'info'
                                                              : 'warning'
                                                    }
                                                >
                                                    {row.status}
                                                </StatusBadge>
                                                {row.errors.map(
                                                    (message, i) => (
                                                        <p
                                                            key={i}
                                                            className="mt-1 text-xs text-status-critical"
                                                        >
                                                            {message}
                                                        </p>
                                                    ),
                                                )}
                                                {row.asset_id && (
                                                    <a
                                                        className="ml-3 text-primary underline"
                                                        href={`/fleet-assets/assets/${row.asset_id}`}
                                                    >
                                                        Open asset
                                                    </a>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </>
                )}
                {batch && (
                    <div className="mt-5 flex flex-wrap items-center justify-between gap-3 border-t pt-4">
                        <div className="flex gap-2">
                            <Button
                                variant="outline"
                                disabled={busy}
                                onClick={() => {
                                    setBatch(null);
                                    setError('');
                                }}
                            >
                                New file
                            </Button>
                            {phase > 0 && imported === 0 && (
                                <Button
                                    variant="outline"
                                    disabled={busy}
                                    onClick={() =>
                                        setBatch({
                                            ...batch,
                                            status: 'mapping',
                                        })
                                    }
                                >
                                    Back to mapping
                                </Button>
                            )}
                        </div>
                        <span className="text-xs text-muted-foreground">
                            {busy ? 'Saving…' : 'Saved to import history'}
                        </span>
                        <Button
                            disabled={busy || (phase > 0 && !selected.length)}
                            onClick={() =>
                                void act(phase === 0 ? 'validate' : 'import')
                            }
                        >
                            {busy
                                ? 'Working…'
                                : phase === 0
                                  ? 'Validate rows'
                                  : phase === 2
                                    ? 'Retry / import selected rows'
                                    : `Import ${selected.length} selected rows`}
                        </Button>
                    </div>
                )}
            </Card>
            <details className="rounded-xl border bg-card p-4">
                <summary className="cursor-pointer font-semibold">
                    Site and room IDs for your CSV
                </summary>
                <div className="mt-4 max-w-lg space-y-3">
                    <SearchPicker
                        label="Site reference"
                        options={sites.map((s) => ({
                            ...s,
                            name: `${s.name} · ID ${s.id}`,
                        }))}
                        value={site}
                        onChange={setSite}
                        empty="Search a permitted site"
                    />
                    {site && (
                        <div className="text-sm">
                            <p>
                                Site ID: <strong>{site}</strong>
                            </p>
                            {rooms.map((r) => (
                                <p key={r.id}>
                                    {r.name} · Room ID <strong>{r.id}</strong>
                                </p>
                            ))}
                            {!rooms.length && (
                                <p className="text-muted-foreground">
                                    No canonical rooms recorded at this site.
                                </p>
                            )}
                        </div>
                    )}
                </div>
            </details>
            <Card unstyled className="rounded-xl border bg-card p-4">
                <h3 className="mb-3 font-semibold">Your import history</h3>
                {history?.data.map((item) => (
                    <Button
                        key={item.id}
                        variant="ghost"
                        className="mb-1 h-auto min-h-14 w-full justify-between border-b text-left"
                        disabled={busy}
                        onClick={() => void open(item.id)}
                    >
                        <span className="flex items-center gap-3">
                            <FileUp className="size-5 text-primary" />
                            <span>
                                {item.filename}
                                <span className="block text-xs font-normal text-muted-foreground">
                                    IMP-{item.id} · {stamp(item.updated_at)}
                                </span>
                            </span>
                        </span>
                        <StatusBadge status={item.status} />
                    </Button>
                ))}
                {history && !history.data.length && (
                    <p className="text-sm text-muted-foreground">
                        Your saved imports will appear here.
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
