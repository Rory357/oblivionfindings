import { Empty, Panel, State } from '@/components/assets/profile/presentation';
import {
    FilePreviewDialog,
    type PreviewFile,
} from '@/components/files/file-preview-dialog';
import {
    isJsonObject,
    useVehicleRecordCommand,
} from '@/components/fleet-assets/vehicle-workspace/record-command';
import { VehicleSearchSelect as SearchSelect } from '@/components/fleet-assets/vehicle-workspace/search-select';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderRail,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/fleet-utils';
import { Head, Link, router } from '@inertiajs/react';
import {
    Download,
    History,
    LayoutGrid,
    Loader2,
    Package,
    Printer,
    QrCode,
    Search,
    X,
} from 'lucide-react';
import { useState } from 'react';

type LabelAsset = {
    id: number;
    name: string;
    tag: string;
    site: string | null;
    status: string;
    has_qr: boolean;
};
type Layout = {
    paper: 'a4' | 'label';
    width: number;
    height: number;
    margin: number;
    gap: number;
    copies: number;
    start: number;
    logo: boolean;
};
type Batch = {
    id: number;
    count: number;
    layout: Layout;
    status: string;
    created_at: string;
    expired: boolean;
    expires_at: string;
};
type Props = {
    assets: {
        data: LabelAsset[];
        total: number;
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    matching: LabelAsset[];
    filters: {
        search?: string;
        site_id?: string;
        category?: string;
        status?: string;
    };
    initialSelection: LabelAsset[];
    sites: { id: number; name: string }[];
    categories: string[];
    batches: Batch[];
};
const endpoint = '/fleet-assets/asset-register/labels';
const defaultLayout: Layout = {
    paper: 'a4',
    width: 63.5,
    height: 46.6,
    margin: 8,
    gap: 0,
    copies: 1,
    start: 1,
    logo: true,
};

export default function AssetLabels({
    assets,
    matching,
    filters,
    initialSelection,
    sites,
    categories,
    batches,
}: Props) {
    const [tab, setTab] = useState('select');
    const [selected, setSelected] = useState<LabelAsset[]>(initialSelection);
    const [layout, setLayout] = useState<Layout>(defaultLayout);
    const [search, setSearch] = useState(filters.search || '');
    const [error, setError] = useState('');
    const [key, setKey] = useState(() => crypto.randomUUID());
    const [preview, setPreview] = useState<PreviewFile | null>(null);
    const command = useVehicleRecordCommand(isJsonObject);
    const columns =
        layout.paper === 'label'
            ? 1
            : Math.floor(
                  (210 - 2 * layout.margin + layout.gap) /
                      (layout.width + layout.gap),
              );
    const rows =
        layout.paper === 'label'
            ? 1
            : Math.floor(
                  (297 - 2 * layout.margin + layout.gap) /
                      (layout.height + layout.gap),
              );
    const slots = columns * rows;
    const labelCount = selected.length * layout.copies;
    const pages =
        slots > 0
            ? Math.ceil(
                  (labelCount +
                      (layout.paper === 'a4' ? layout.start - 1 : 0)) /
                      slots,
              )
            : 0;
    const valid =
        selected.length > 0 &&
        selected.length <= 200 &&
        labelCount <= 1000 &&
        Number.isInteger(layout.copies) &&
        layout.copies >= 1 &&
        layout.copies <= 20 &&
        layout.width >= 50 &&
        layout.width <= 190 &&
        layout.height >= 46 &&
        layout.height <= 277 &&
        slots > 0 &&
        Number.isInteger(layout.start) &&
        layout.start >= 1 &&
        layout.start <= slots &&
        layout.margin >= (layout.paper === 'a4' ? 5 : 0) &&
        layout.margin <= 30 &&
        layout.gap >= 0 &&
        layout.gap <= 15;
    const apply = (value: Record<string, string>) =>
        router.get(
            `${endpoint}/workspace`,
            { ...filters, search, ...value },
            { preserveState: true, preserveScroll: true },
        );
    const change = (patch: Partial<Layout>) => {
        setLayout({ ...layout, ...patch });
        setError('');
    };
    const select = (asset: LabelAsset) => {
        setError('');
        setSelected((current) =>
            current.some((item) => item.id === asset.id)
                ? current.filter((item) => item.id !== asset.id)
                : current.length < 200
                  ? [...current, asset]
                  : current,
        );
    };
    const openPdf = (id: number) =>
        setPreview({
            id: `batch-${id}`,
            name: `Asset label batch ${id}`,
            filename: `asset-labels-${id}.pdf`,
            mime: 'application/pdf',
            previewUrl: `${endpoint}/${id}/pdf`,
            downloadUrl: `${endpoint}/${id}/pdf`,
        });
    const generate = async () => {
        if (!valid) {
            setError(
                'Select 1–200 assets and a layout that fits. Use up to 20 copies per asset and 1,000 labels per batch.',
            );
            return;
        }
        const result = await command.submit(endpoint, {
            request_id: key,
            asset_ids: selected.map((asset) => asset.id),
            layout,
        });
        if (result && typeof result.id === 'number') {
            setKey(crypto.randomUUID());
            openPdf(result.id);
            router.reload({ only: ['batches'] });
        }
    };
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Assets', href: '/fleet-assets/assets' },
                { title: 'QR labels', href: `${endpoint}/workspace` },
            ]}
        >
            <Head title="Asset QR labels" />
            <div className="min-w-0 space-y-5">
                <PageHeader
                    className="overflow-clip!"
                    title="Asset QR labels"
                    icon={QrCode}
                    subline="Select assets, preview branded labels and export for printing"
                    actions={
                        <PageHeaderGlassButton
                            icon={Package}
                            onClick={() => router.get('/fleet-assets/assets')}
                        >
                            Assets register
                        </PageHeaderGlassButton>
                    }
                    meters={
                        <>
                            <PageHeaderMeterBlock
                                label="Matching assets"
                                onClick={() => setTab('select')}
                            >
                                <PageHeaderMeterBig>
                                    {assets.total}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    Current filters · approved records
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Selected"
                                onClick={() => setTab('select')}
                            >
                                <PageHeaderMeterBig>
                                    {selected.length} asset
                                    {selected.length === 1 ? '' : 's'}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    Up to 200 per batch
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Print layout"
                                onClick={() => setTab('layout')}
                            >
                                <PageHeaderMeterBig>
                                    {labelCount} label
                                    {labelCount === 1 ? '' : 's'}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    {pages}{' '}
                                    {layout.paper === 'a4'
                                        ? `A4 sheet${pages === 1 ? '' : 's'}`
                                        : `label page${pages === 1 ? '' : 's'}`}
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Recent exports"
                                onClick={() => setTab('history')}
                            >
                                <PageHeaderMeterBig>
                                    {batches.length}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    Your latest 20 batches
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        </>
                    }
                    rail={
                        <PageHeaderRail
                            items={[
                                {
                                    key: 'select',
                                    label: 'Select assets',
                                    icon: Package,
                                },
                                {
                                    key: 'layout',
                                    label: 'Layout & export',
                                    icon: LayoutGrid,
                                },
                                {
                                    key: 'history',
                                    label: 'Export history',
                                    icon: History,
                                },
                            ]}
                            value={tab}
                            onSelect={setTab}
                        />
                    }
                />
                {(error || command.message) && (
                    <p
                        role="alert"
                        className="text-subtle border-status-danger/30 bg-status-danger-bg rounded-lg border p-4"
                    >
                        {error || command.message}
                    </p>
                )}
                {command.requiresReload && (
                    <Button variant="outline" onClick={() => router.reload()}>
                        Reload current records
                    </Button>
                )}
                {tab === 'select' && (
                    <Panel
                        title="Choose assets for labels"
                        icon={Package}
                        actions={
                            <Button
                                disabled={!selected.length}
                                onClick={() => setTab('layout')}
                            >
                                Continue to layout
                            </Button>
                        }
                    >
                        <fieldset
                            disabled={command.locked}
                            className="space-y-4"
                        >
                            <form
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    apply({});
                                }}
                                className="grid gap-3 sm:grid-cols-2 xl:grid-cols-[2fr_1fr_1fr_1fr_auto]"
                            >
                                <Input
                                    aria-label="Search assets for labels"
                                    placeholder="Search name, tag or serial…"
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                />
                                <SearchSelect
                                    label="Site"
                                    value={
                                        filters.site_id
                                            ? String(filters.site_id)
                                            : 'all'
                                    }
                                    options={[
                                        {
                                            value: 'all',
                                            label: 'All approved sites',
                                        },
                                        ...sites.map((site) => ({
                                            value: String(site.id),
                                            label: site.name,
                                        })),
                                    ]}
                                    onChange={(value) =>
                                        apply({
                                            site_id:
                                                value === 'all' ? '' : value,
                                        })
                                    }
                                />
                                <SearchSelect
                                    label="Category"
                                    value={filters.category || 'all'}
                                    options={[
                                        {
                                            value: 'all',
                                            label: 'All categories',
                                        },
                                        ...categories.map((category) => ({
                                            value: category,
                                            label: category,
                                        })),
                                    ]}
                                    onChange={(value) =>
                                        apply({
                                            category:
                                                value === 'all' ? '' : value,
                                        })
                                    }
                                />
                                <SearchSelect
                                    label="Lifecycle"
                                    value={filters.status || 'all'}
                                    options={[
                                        {
                                            value: 'all',
                                            label: 'All lifecycle states',
                                        },
                                        { value: 'active', label: 'Active' },
                                        {
                                            value: 'out_of_service',
                                            label: 'Maintenance',
                                        },
                                        { value: 'retired', label: 'Retired' },
                                    ]}
                                    onChange={(value) =>
                                        apply({
                                            status:
                                                value === 'all' ? '' : value,
                                        })
                                    }
                                />
                                <Button type="submit" variant="outline">
                                    <Search />
                                    Search
                                </Button>
                            </form>
                            <div className="flex flex-wrap items-center gap-3">
                                <Button
                                    variant="outline"
                                    disabled={
                                        matching.length > 200 ||
                                        !matching.length
                                    }
                                    onClick={() => setSelected(matching)}
                                >
                                    Select all {assets.total} matching
                                </Button>
                                <Button
                                    variant="ghost"
                                    disabled={!selected.length}
                                    onClick={() => setSelected([])}
                                >
                                    Clear selection
                                </Button>
                                <p className="text-caption text-muted-foreground">
                                    {selected.length} selected across pages
                                    {matching.length > 200
                                        ? ' · Narrow the filters to select all (maximum 200).'
                                        : ''}
                                </p>
                            </div>
                            {assets.data.length ? (
                                <div className="grid gap-3 lg:grid-cols-2">
                                    {assets.data.map((asset) => (
                                        <label
                                            key={asset.id}
                                            className="flex min-h-16 cursor-pointer items-start gap-3 rounded-lg border p-4"
                                        >
                                            <input
                                                type="checkbox"
                                                className="mt-1 size-4"
                                                checked={selected.some(
                                                    (item) =>
                                                        item.id === asset.id,
                                                )}
                                                disabled={
                                                    selected.length >= 200 &&
                                                    !selected.some(
                                                        (item) =>
                                                            item.id ===
                                                            asset.id,
                                                    )
                                                }
                                                onChange={() => select(asset)}
                                            />
                                            <span className="min-w-0 flex-1">
                                                <strong className="text-subtle">
                                                    {asset.tag} · {asset.name}
                                                </strong>
                                                <span className="text-caption mt-1 block text-muted-foreground">
                                                    {asset.site ||
                                                        'Site not recorded'}{' '}
                                                    · {asset.status}
                                                    {!asset.has_qr
                                                        ? ' · QR creation requires edit permission'
                                                        : ''}
                                                </span>
                                            </span>
                                        </label>
                                    ))}
                                </div>
                            ) : (
                                <Empty>
                                    No authorised assets match these filters.
                                </Empty>
                            )}
                            <div className="flex items-center justify-between gap-3">
                                <Button
                                    variant="outline"
                                    disabled={!assets.prev_page_url}
                                    onClick={() =>
                                        router.get(
                                            assets.prev_page_url!,
                                            {},
                                            {
                                                preserveState: true,
                                                preserveScroll: true,
                                            },
                                        )
                                    }
                                >
                                    Previous
                                </Button>
                                <span className="text-caption">
                                    Page {assets.current_page} of{' '}
                                    {assets.last_page}
                                </span>
                                <Button
                                    variant="outline"
                                    disabled={!assets.next_page_url}
                                    onClick={() =>
                                        router.get(
                                            assets.next_page_url!,
                                            {},
                                            {
                                                preserveState: true,
                                                preserveScroll: true,
                                            },
                                        )
                                    }
                                >
                                    Next
                                </Button>
                            </div>
                        </fieldset>
                    </Panel>
                )}
                {tab === 'layout' && (
                    <div className="grid gap-5 xl:grid-cols-[1.2fr_1fr]">
                        <Panel title="Paper & label layout" icon={LayoutGrid}>
                            <fieldset
                                disabled={command.locked}
                                className="space-y-5"
                            >
                                <div
                                    role="group"
                                    aria-label="Paper format"
                                    className="flex flex-wrap gap-2"
                                >
                                    <Button
                                        variant={
                                            layout.paper === 'a4'
                                                ? 'default'
                                                : 'outline'
                                        }
                                        aria-pressed={layout.paper === 'a4'}
                                        onClick={() => change(defaultLayout)}
                                    >
                                        A4 sheets
                                    </Button>
                                    <Button
                                        variant={
                                            layout.paper === 'label'
                                                ? 'default'
                                                : 'outline'
                                        }
                                        aria-pressed={layout.paper === 'label'}
                                        onClick={() =>
                                            change({
                                                paper: 'label',
                                                width: 60,
                                                height: 50,
                                                margin: 0,
                                                gap: 0,
                                                start: 1,
                                            })
                                        }
                                    >
                                        Label printer
                                    </Button>
                                </div>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    {(
                                        [
                                            [
                                                'width',
                                                'Label width (mm)',
                                                50,
                                                190,
                                            ],
                                            [
                                                'height',
                                                'Label height (mm)',
                                                46,
                                                277,
                                            ],
                                            [
                                                'copies',
                                                'Copies per asset',
                                                1,
                                                20,
                                            ],
                                            ...(layout.paper === 'a4'
                                                ? [
                                                      [
                                                          'margin',
                                                          'Sheet margin (mm)',
                                                          5,
                                                          30,
                                                      ],
                                                      [
                                                          'gap',
                                                          'Gap between labels (mm)',
                                                          0,
                                                          15,
                                                      ],
                                                      [
                                                          'start',
                                                          'Start at label position',
                                                          1,
                                                          Math.max(1, slots),
                                                      ],
                                                  ]
                                                : []),
                                        ] as [
                                            keyof Layout,
                                            string,
                                            number,
                                            number,
                                        ][]
                                    ).map(([field, label, min, max]) => (
                                        <div key={field}>
                                            <Label htmlFor={`layout-${field}`}>
                                                {label}
                                            </Label>
                                            <Input
                                                id={`layout-${field}`}
                                                type="number"
                                                min={min}
                                                max={max}
                                                step={
                                                    field === 'copies' ||
                                                    field === 'start'
                                                        ? 1
                                                        : 0.1
                                                }
                                                value={Number(layout[field])}
                                                onChange={(event) =>
                                                    change({
                                                        [field]: Number(
                                                            event.target.value,
                                                        ),
                                                    })
                                                }
                                            />
                                        </div>
                                    ))}
                                </div>
                                <label className="flex min-h-11 items-center gap-3">
                                    <input
                                        type="checkbox"
                                        checked={layout.logo}
                                        onChange={(event) =>
                                            change({
                                                logo: event.target.checked,
                                            })
                                        }
                                    />
                                    Use company logo from Branding settings
                                </label>
                                <p className="text-caption text-muted-foreground">
                                    The logo sits above the QR code. If it is
                                    unavailable, the company name is used. Each
                                    QR keeps its existing asset identity.
                                </p>
                            </fieldset>
                        </Panel>
                        <Panel title="Review & export" icon={Printer}>
                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <p className="text-caption text-muted-foreground">
                                        Selected assets
                                    </p>
                                    <strong className="text-page-title">
                                        {selected.length}
                                    </strong>
                                </div>
                                <div>
                                    <p className="text-caption text-muted-foreground">
                                        Labels / pages
                                    </p>
                                    <strong className="text-page-title">
                                        {labelCount} / {pages}
                                    </strong>
                                </div>
                            </div>
                            <p className="text-subtle">
                                {layout.paper === 'a4'
                                    ? `${columns} columns × ${rows} rows per A4 sheet · start at slot ${layout.start}`
                                    : `One ${layout.width} × ${layout.height} mm label per PDF page`}
                            </p>
                            <details className="rounded-lg border p-3">
                                <summary className="text-subtle cursor-pointer font-medium">
                                    Review selected assets ({selected.length})
                                </summary>
                                <div className="mt-3 max-h-64 space-y-2 overflow-y-auto">
                                    {selected.map((asset) => (
                                        <div
                                            key={asset.id}
                                            className="flex items-center justify-between gap-3"
                                        >
                                            <span className="text-subtle">
                                                {asset.tag} · {asset.name}
                                            </span>
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                aria-label={`Remove ${asset.tag}`}
                                                disabled={command.locked}
                                                onClick={() => select(asset)}
                                            >
                                                <X />
                                            </Button>
                                        </div>
                                    ))}
                                </div>
                            </details>
                            <p className="text-subtle">
                                Print at 100% / actual size with scaling off.
                                Confirm the media size and scan one test label
                                before printing the batch.
                            </p>
                            <Button
                                onClick={generate}
                                disabled={
                                    command.processing ||
                                    command.requiresReload ||
                                    !valid
                                }
                            >
                                {command.processing ? (
                                    <Loader2 className="animate-spin" />
                                ) : (
                                    <Printer />
                                )}
                                {command.uncertain
                                    ? 'Retry same label batch'
                                    : 'Generate & preview PDF'}
                            </Button>
                            <p className="text-caption text-muted-foreground">
                                Exports are available in your history for 7
                                days. Downloading records an export, not a
                                confirmed physical print.
                            </p>
                        </Panel>
                    </div>
                )}
                {tab === 'history' && (
                    <Panel title="Your export history" icon={History}>
                        {batches.length ? (
                            batches.map((batch) => (
                                <article
                                    key={batch.id}
                                    className="space-y-3 rounded-lg border p-4"
                                >
                                    <div className="flex flex-wrap justify-between gap-3">
                                        <div>
                                            <strong>
                                                Batch {batch.id} · {batch.count}{' '}
                                                assets
                                            </strong>
                                            <p className="text-caption mt-1 text-muted-foreground">
                                                {formatDateTime(
                                                    batch.created_at,
                                                )}{' '}
                                                ·{' '}
                                                {batch.layout.paper === 'label'
                                                    ? 'Label printer'
                                                    : 'A4 sheets'}{' '}
                                                · {batch.layout.copies} copies
                                                each
                                            </p>
                                        </div>
                                        <State
                                            value={
                                                batch.expired
                                                    ? 'expired'
                                                    : batch.status
                                            }
                                        />
                                    </div>
                                    <p className="text-caption">
                                        {batch.expired
                                            ? 'Expired — select the current records to create another batch.'
                                            : `Available until ${formatDateTime(batch.expires_at)}. Access is checked again at download.`}
                                    </p>
                                    {!batch.expired && (
                                        <div className="flex flex-wrap gap-2">
                                            <Button
                                                variant="outline"
                                                onClick={() =>
                                                    openPdf(batch.id)
                                                }
                                            >
                                                <Printer />
                                                Preview PDF
                                            </Button>
                                            <Button variant="outline" asChild>
                                                <a
                                                    href={`${endpoint}/${batch.id}/pdf`}
                                                    download
                                                >
                                                    <Download />
                                                    PDF
                                                </a>
                                            </Button>
                                            <Button variant="outline" asChild>
                                                <a
                                                    href={`${endpoint}/${batch.id}/zip`}
                                                    download
                                                >
                                                    <Download />
                                                    PNG & SVG ZIP
                                                </a>
                                            </Button>
                                        </div>
                                    )}
                                </article>
                            ))
                        ) : (
                            <Empty>
                                No label exports yet. Select assets and create a
                                batch.
                            </Empty>
                        )}
                    </Panel>
                )}
                <Link
                    href="/fleet-assets/assets"
                    className="text-subtle text-primary"
                >
                    Return to Assets register
                </Link>
            </div>
            <FilePreviewDialog
                file={preview}
                onClose={() => {
                    setPreview(null);
                    router.reload({ only: ['batches'] });
                }}
            />
        </AppLayout>
    );
}
