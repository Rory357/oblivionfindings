/* eslint-disable no-restricted-syntax -- register tab tables/report cards are custom-layout
   bordered surfaces (not Card/Button); all colours are semantic tokens. */
import {
    type CdMedication,
    type StaffOption,
} from '@/components/emar/controlled/types';
import {
    DestructionDetailDialog,
    type DestructionRow,
} from '@/components/emar/destruction-detail-dialog';
import { PageHero, type PageHeroStat } from '@/components/page';
import {
    EntityFilter,
    ShiftContextMenu,
    TabStrip,
    type RosterTabItem,
    type ShiftCtxItem,
    type ShiftCtxState,
} from '@/components/rostering';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import {
    CdPill,
    RecordDestructionDialog,
    VoidDestructionDialog,
} from '@/pages/emar/_cd-dialogs';
import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Ban,
    ClipboardCheck,
    Download,
    Eye,
    FileText,
    Lock,
    Package,
    Plus,
    Search,
    Trash2,
    User,
    X,
} from 'lucide-react';
import { useMemo, useState, type MouseEvent as ReactMouseEvent } from 'react';

type Props = {
    can_record: boolean;
    destructions: DestructionRow[];
    medications: CdMedication[];
    staff: StaffOption[];
    clients: { id: number; first_name: string; last_name: string }[];
    sites: { id: number; name: string }[];
    active_site: { id: number; name: string } | null;
    site_brand_colour: string | null;
};

type Modal =
    | { type: 'record' }
    | { type: 'void'; row: DestructionRow }
    | { type: 'detail'; row: DestructionRow }
    | null;

type Alert = {
    kind: string;
    tone: 'info' | 'warning';
    icon: typeof AlertTriangle;
    message: string;
    tab: string;
};

const DISMISSED_ALERTS_KEY = 'destructions-dismissed-alerts';

/** Per-session dismissed alert kinds (survives Inertia partial reloads + soft nav). */
function readDismissedAlerts(): string[] {
    try {
        const raw = sessionStorage.getItem(DISMISSED_ALERTS_KEY);
        return raw ? (JSON.parse(raw) as string[]) : [];
    } catch {
        return [];
    }
}
function persistDismissedAlerts(kinds: string[]): string[] {
    try {
        sessionStorage.setItem(DISMISSED_ALERTS_KEY, JSON.stringify(kinds));
    } catch {
        /* ignore */
    }
    return kinds;
}

const DAY = 1000 * 60 * 60 * 24;

function withinDays(iso: string | null, days: number): boolean {
    if (!iso) return false;
    const t = new Date(iso).getTime();
    return !Number.isNaN(t) && Date.now() - t <= days * DAY;
}

function relativeDays(iso: string | null): string {
    if (!iso) return 'Never';
    const t = new Date(iso).getTime();
    if (Number.isNaN(t)) return '—';
    const d = Math.floor((Date.now() - t) / DAY);
    return d <= 0 ? 'Today' : d === 1 ? 'Yesterday' : `${d}d ago`;
}

const fmtDate = (iso: string | null) =>
    iso
        ? new Date(iso).toLocaleDateString('en-NZ', {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          })
        : '—';

function csvCell(value: unknown): string {
    const s = value === null || value === undefined ? '' : String(value);
    return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}

function exportCsv(rows: DestructionRow[]) {
    const head = [
        'Date',
        'Client',
        'Site',
        'Medication',
        'Form',
        'Strength',
        'Quantity',
        'Unit',
        'Batch',
        'Expiry',
        'Reason',
        'Method',
        'Controlled drug',
        'Destroyed by',
        'Witness 1',
        'Witness 2',
        'Authorised by',
        'Notes',
        'Voided',
        'Void reason',
    ];
    const lines = rows.map((d) =>
        [
            fmtDate(d.destroyed_at),
            d.client_name,
            d.site_name,
            d.medication_name,
            d.form,
            d.strength,
            d.quantity,
            d.unit,
            d.batch_number,
            d.expiry_date,
            d.reason_label ?? d.reason,
            d.disposal_method_label ?? d.disposal_method,
            d.is_controlled_drug
                ? `Yes${d.controlled_drug_class ? ` (Class ${d.controlled_drug_class})` : ''}`
                : 'No',
            d.destroyed_by_name,
            d.witness_1_name,
            d.witness_2_name,
            d.authorised_by_name,
            d.notes,
            d.is_voided ? 'Yes' : 'No',
            d.void_reason,
        ]
            .map(csvCell)
            .join(','),
    );
    const blob = new Blob([[head.join(','), ...lines].join('\n')], {
        type: 'text/csv;charset=utf-8;',
    });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `destruction-register-${new Date().toISOString().slice(0, 10)}.csv`;
    a.click();
    URL.revokeObjectURL(url);
}

export default function Destructions({
    can_record: canRecord,
    destructions,
    medications,
    staff,
    clients,
    sites,
    active_site: activeSite,
    site_brand_colour: brandColour,
}: Props) {
    const [activeTab, setActiveTab] = useState('log');
    const [siteFilter, setSiteFilter] = useState<number | null>(
        activeSite?.id ?? null,
    );
    const [clientFilter, setClientFilter] = useState<number | null>(null);
    const [search, setSearch] = useState('');
    const [modal, setModal] = useState<Modal>(null);
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [dismissed, setDismissed] = useState<string[]>(() =>
        readDismissedAlerts(),
    );
    const dismiss = (kind: string) =>
        setDismissed((prev) => persistDismissedAlerts([...prev, kind]));

    const live = useMemo(
        () => destructions.filter((d) => !d.is_voided),
        [destructions],
    );
    const controlled = useMemo(
        () => destructions.filter((d) => d.is_controlled_drug),
        [destructions],
    );

    // Client + text search face the loaded register client-side (the Site filter
    // round-trips to the server). One standardised control row drives every tab.
    const matches = useMemo(() => {
        const q = search.trim().toLowerCase();
        return (d: DestructionRow) => {
            if (clientFilter && d.client_id !== clientFilter) return false;
            if (q) {
                const hay =
                    `${d.client_name} ${d.medication_name ?? ''} ${d.batch_number ?? ''} ${d.witness_1_name ?? ''} ${d.witness_2_name ?? ''} ${d.authorised_by_name ?? ''}`.toLowerCase();
                if (!hay.includes(q)) return false;
            }
            return true;
        };
    }, [search, clientFilter]);

    const logRows = useMemo(
        () => destructions.filter(matches),
        [destructions, matches],
    );
    const controlledRows = useMemo(
        () => controlled.filter(matches),
        [controlled, matches],
    );
    const liveFiltered = useMemo(() => live.filter(matches), [live, matches]);
    const isFiltered = clientFilter !== null || search.trim() !== '';

    const destroyed30 = live.filter((d) =>
        withinDays(d.destroyed_at, 30),
    ).length;
    const cd30 = controlled.filter(
        (d) => !d.is_voided && withinDays(d.destroyed_at, 30),
    ).length;
    const voidedCount = destructions.length - live.length;
    const lastAt =
        live
            .map((d) => d.destroyed_at)
            .filter(Boolean)
            .sort()
            .slice(-1)[0] ?? null;

    // Report aggregates respect the active client/search facet (live records only).
    const byReason = useMemo(
        () =>
            tally(
                liveFiltered.map(
                    (d) => d.reason_label ?? d.reason ?? 'Unspecified',
                ),
            ),
        [liveFiltered],
    );
    const byMethod = useMemo(
        () =>
            tally(
                liveFiltered.map(
                    (d) =>
                        d.disposal_method_label ??
                        d.disposal_method ??
                        'Unspecified',
                ),
            ),
        [liveFiltered],
    );

    // Stacked, dismissible (per session) alert strip built from already-loaded data.
    const alerts: Alert[] = [
        cd30 > 0 && {
            kind: 'cd30',
            tone: 'info' as const,
            icon: Lock,
            message: `${cd30} controlled-drug destruction${cd30 === 1 ? '' : 's'} in the last 30 days.`,
            tab: 'controlled',
        },
        voidedCount > 0 && {
            kind: 'voided',
            tone: 'warning' as const,
            icon: Ban,
            message: `${voidedCount} record${voidedCount === 1 ? '' : 's'} voided — review.`,
            tab: 'log',
        },
    ].filter(
        (a): a is Alert => Boolean(a) && !dismissed.includes((a as Alert).kind),
    );

    const openDetail = (row: DestructionRow) =>
        setModal({ type: 'detail', row });

    const openRowCtx = (e: ReactMouseEvent, d: DestructionRow) => {
        e.preventDefault();
        const t = d.is_voided
            ? {
                  tag: 'VOIDED',
                  bg: 'var(--muted)',
                  color: 'var(--muted-foreground)',
              }
            : d.is_controlled_drug
              ? {
                    tag: 'CD',
                    bg: 'var(--status-critical-bg)',
                    color: 'var(--status-critical)',
                }
              : {
                    tag: 'MED',
                    bg: 'var(--status-info-bg)',
                    color: 'var(--status-info)',
                };
        // Immutable-safe items only — append-only register (MoD Regs 1977). No
        // edit/delete; Void is the sole correction path and only while live.
        const items: ShiftCtxItem[] = [
            {
                icon: <Eye className="h-3.5 w-3.5" />,
                label: 'View details',
                sub: `${d.medication_name ?? 'Destruction'}${d.destroyed_at ? ` · ${fmtDate(d.destroyed_at)}` : ''}`,
                tone: 'primary',
                onClick: () => openDetail(d),
            },
            ...(d.client_id
                ? [
                      {
                          icon: <User className="h-3.5 w-3.5" />,
                          label: 'View client',
                          onClick: () =>
                              router.visit(
                                  `/operations/clients/${d.client_id}?tab=mar`,
                              ),
                      } satisfies ShiftCtxItem,
                  ]
                : []),
            ...(d.mar_url
                ? [
                      {
                          icon: <FileText className="h-3.5 w-3.5" />,
                          label: 'Open on MAR chart',
                          onClick: () => router.visit(d.mar_url!),
                      } satisfies ShiftCtxItem,
                  ]
                : []),
            {
                icon: <Download className="h-3.5 w-3.5" />,
                label: 'Export this record',
                onClick: () => exportCsv([d]),
            },
            ...(d.is_voided || !canRecord
                ? []
                : [
                      { sep: true } as ShiftCtxItem,
                      {
                          icon: <Ban className="h-3.5 w-3.5" />,
                          label: 'Void record',
                          sub: 'The only correction path',
                          tone: 'critical',
                          onClick: () => setModal({ type: 'void', row: d }),
                      } satisfies ShiftCtxItem,
                  ]),
        ];
        setCtx({
            x: e.clientX,
            y: e.clientY,
            tag: t.tag,
            tagBg: t.bg,
            tagColor: t.color,
            meta: `${d.client_name} · ${d.medication_name ?? 'Destruction'} · ${fmtDate(d.destroyed_at)}`,
            items,
        });
    };

    // Shared row interactivity: click → detail, right-click → menu, keyboard-focusable.
    const rowProps = (d: DestructionRow) => ({
        tabIndex: 0,
        role: 'button' as const,
        onClick: () => openDetail(d),
        onContextMenu: (e: ReactMouseEvent) => openRowCtx(e, d),
        onKeyDown: (e: React.KeyboardEvent) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                openDetail(d);
            }
        },
        className: `${cnRow(d)} cursor-pointer transition-colors hover:bg-muted/40 focus:bg-muted/40 focus:outline-none`,
    });

    const TABS: RosterTabItem[] = [
        {
            id: 'log',
            label: 'Destruction log',
            icon: ClipboardCheck,
            tone: 'primary',
            badge: live.length || undefined,
        },
        {
            id: 'controlled',
            label: 'Controlled drugs',
            icon: Lock,
            tone: 'critical',
            badge: controlled.filter((d) => !d.is_voided).length || undefined,
        },
        {
            id: 'reports',
            label: 'Reports & export',
            icon: FileText,
            tone: 'info',
        },
    ];

    const heroStats: PageHeroStat[] = [
        { label: 'Live records', value: live.length },
        { label: 'Destroyed (30d)', value: destroyed30 },
        {
            label: 'CD destructions (30d)',
            value: cd30,
            tone: cd30 > 0 ? 'warning' : 'neutral',
        },
        { label: 'Last destruction', value: relativeDays(lastAt) },
    ];

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'eMAR', href: '/emar' },
                { title: 'Destructions', href: '/emar/destructions' },
            ]}
        >
            <Head title="Medication Destruction Register" />
            <div className="flex flex-col gap-6 p-6">
                <PageHero
                    variant="hero"
                    category="ops"
                    brandColour={brandColour}
                    icon={Trash2}
                    title={
                        <span>
                            <span className="flex items-center gap-2 text-[10.5px] font-semibold tracking-wide text-primary-foreground/80 uppercase">
                                <span
                                    aria-hidden
                                    className="relative inline-flex h-2 w-2"
                                >
                                    <span className="absolute inset-0 animate-ping rounded-full bg-status-success/70" />
                                    <span className="relative inline-flex h-2 w-2 rounded-full bg-status-success" />
                                </span>
                                Disposal register · immutable
                            </span>
                            <span className="mt-1 block text-[26px] leading-tight font-bold">
                                Medication disposal &amp; destruction for{' '}
                                <span className="border-b-2 border-primary-foreground/40">
                                    {activeSite?.name ?? 'your services'}
                                </span>
                            </span>
                        </span>
                    }
                    description="Witnessed disposal of medication and controlled drugs — append-only and retained. Erroneous entries are voided, never deleted."
                    stats={heroStats}
                    actions={
                        <>
                            {canRecord ? (
                                <Button
                                    className="bg-primary-foreground text-primary hover:bg-primary-foreground/90"
                                    onClick={() => setModal({ type: 'record' })}
                                >
                                    <Plus className="h-4 w-4" />
                                    Record destruction
                                </Button>
                            ) : null}
                            <Button
                                variant="outline"
                                className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20"
                                onClick={() => exportCsv(destructions)}
                                disabled={destructions.length === 0}
                            >
                                <Download className="h-4 w-4" />
                                Export register
                            </Button>
                        </>
                    }
                    footer={
                        <div className="flex flex-col items-stretch gap-2 py-3 md:flex-row md:items-center md:justify-end">
                            <div className="flex flex-wrap items-center gap-2 md:ml-auto md:justify-end">
                                <div className="relative w-full max-w-xs md:w-[280px]">
                                    <Search className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                    {/* eslint-disable-next-line no-restricted-syntax -- white pill search on the dark hero per the design handoff. */}
                                    <input
                                        value={search}
                                        onChange={(e) =>
                                            setSearch(e.target.value)
                                        }
                                        placeholder="Search client, medication, batch or witness…"
                                        aria-label="Search the destruction register"
                                        className="h-8 w-full rounded-full border-0 bg-primary-foreground pr-3 pl-9 text-[13px] text-foreground shadow-sm outline-none placeholder:text-muted-foreground/80 focus:ring-2 focus:ring-primary-foreground/50"
                                    />
                                    {search ? (
                                        // eslint-disable-next-line no-restricted-syntax -- inline clear affordance inside the pill search input.
                                        <button
                                            type="button"
                                            aria-label="Clear search"
                                            onClick={() => setSearch('')}
                                            className="absolute top-1/2 right-2 grid h-5 w-5 -translate-y-1/2 place-items-center rounded-full text-muted-foreground hover:bg-muted"
                                        >
                                            <X className="h-3.5 w-3.5" />
                                        </button>
                                    ) : null}
                                </div>
                                {sites.length > 0 ? (
                                    <EntityFilter
                                        label="Site"
                                        allLabel="All sites"
                                        items={sites}
                                        value={siteFilter}
                                        onChange={(id) => {
                                            setSiteFilter(id);
                                            router.get(
                                                '/emar/destructions',
                                                id ? { site_id: id } : {},
                                                {
                                                    preserveState: true,
                                                    preserveScroll: true,
                                                },
                                            );
                                        }}
                                        onDark
                                    />
                                ) : null}
                                <EntityFilter
                                    label="Client"
                                    allLabel="All clients"
                                    items={clients.map((c) => ({
                                        id: c.id,
                                        name: `${c.first_name} ${c.last_name}`.trim(),
                                    }))}
                                    value={clientFilter}
                                    onChange={setClientFilter}
                                    onDark
                                />
                            </div>
                        </div>
                    }
                />

                {alerts.length > 0 && (
                    <div className="flex flex-col gap-2">
                        {alerts.map((a) => (
                            <AlertRow
                                key={a.kind}
                                alert={a}
                                onReview={() => setActiveTab(a.tab)}
                                onDismiss={() => dismiss(a.kind)}
                            />
                        ))}
                    </div>
                )}

                <TabStrip
                    value={activeTab}
                    onChange={setActiveTab}
                    items={TABS}
                    ariaLabel="Destruction register views"
                />

                {activeTab === 'log' && (
                    <TableCard
                        head={[
                            'Date',
                            'Client',
                            'Medication',
                            'Qty',
                            'Reason',
                            'Method',
                            'Destroyed by',
                            'Witness',
                            '',
                        ]}
                        empty={emptyLabel(
                            logRows.length,
                            destructions.length,
                            'No destruction records yet.',
                        )}
                    >
                        {logRows.map((d) => (
                            <LogRow
                                key={d.id}
                                d={d}
                                rowProps={rowProps(d)}
                                onVoid={
                                    canRecord
                                        ? () =>
                                              setModal({
                                                  type: 'void',
                                                  row: d,
                                              })
                                        : undefined
                                }
                            />
                        ))}
                    </TableCard>
                )}

                {activeTab === 'controlled' && (
                    <TableCard
                        head={[
                            'Date',
                            'Client',
                            'Controlled drug',
                            'Qty',
                            'Witness 1',
                            'Witness 2',
                            'Authorised by',
                            '',
                        ]}
                        empty={emptyLabel(
                            controlledRows.length,
                            controlled.length,
                            'No controlled-drug destructions recorded.',
                        )}
                    >
                        {controlledRows.map((d) => (
                            <tr key={d.id} {...rowProps(d)}>
                                <td className="px-4 py-3 text-muted-foreground">
                                    {fmtDate(d.destroyed_at)}
                                </td>
                                <td className="px-4 py-3">{d.client_name}</td>
                                <td className="px-4 py-3 font-medium">
                                    <span
                                        className={
                                            d.is_voided ? 'line-through' : ''
                                        }
                                    >
                                        {d.medication_name}
                                    </span>
                                    {d.controlled_drug_class && (
                                        <CdPill
                                            label={`Class ${d.controlled_drug_class}`}
                                            tone="ml-2 bg-status-critical-bg text-status-critical"
                                        />
                                    )}
                                    {d.is_voided && (
                                        <span className="ml-2 align-middle">
                                            <CdPill
                                                label="Voided"
                                                tone="bg-muted text-muted-foreground"
                                            />
                                        </span>
                                    )}
                                </td>
                                <td className="px-4 py-3 tabular-nums">
                                    {d.quantity} {d.unit}
                                </td>
                                <td className="px-4 py-3 text-muted-foreground">
                                    {d.witness_1_name ?? '—'}
                                </td>
                                <td className="px-4 py-3 text-muted-foreground">
                                    {d.witness_2_name ?? '—'}
                                </td>
                                <td className="px-4 py-3 text-muted-foreground">
                                    {d.authorised_by_name ?? '—'}
                                </td>
                                <td className="px-4 py-3 text-right">
                                    {d.is_voided ? (
                                        <CdPill
                                            label="Voided"
                                            tone="bg-muted text-muted-foreground"
                                        />
                                    ) : canRecord ? (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                setModal({
                                                    type: 'void',
                                                    row: d,
                                                });
                                            }}
                                        >
                                            Void
                                        </Button>
                                    ) : null}
                                </td>
                            </tr>
                        ))}
                    </TableCard>
                )}

                {activeTab === 'reports' && (
                    <div className="flex flex-col gap-4">
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <StatCard
                                label="Live records"
                                value={liveFiltered.length}
                                hint={
                                    isFiltered
                                        ? 'Matching filters'
                                        : 'Excludes voided'
                                }
                            />
                            <StatCard
                                label="Controlled-drug disposals"
                                value={
                                    controlledRows.filter((d) => !d.is_voided)
                                        .length
                                }
                                hint={
                                    isFiltered ? 'Matching filters' : 'All time'
                                }
                            />
                            <StatCard
                                label="Destroyed (30 days)"
                                value={
                                    liveFiltered.filter((d) =>
                                        withinDays(d.destroyed_at, 30),
                                    ).length
                                }
                                hint="Rolling"
                            />
                            <StatCard
                                label="Voided records"
                                value={
                                    logRows.filter((d) => d.is_voided).length
                                }
                                hint="Retained, superseded"
                            />
                        </div>
                        <div className="grid gap-4 lg:grid-cols-2">
                            <BreakdownCard title="By reason" rows={byReason} />
                            <BreakdownCard
                                title="By disposal method"
                                rows={byMethod}
                            />
                        </div>
                        <div className="flex items-center justify-between gap-3 rounded-2xl border bg-card p-4 shadow-sm">
                            <div>
                                <div className="text-sm font-medium">
                                    Export disposal register
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    Download the full register (including voided
                                    records) as CSV for audit.
                                </div>
                            </div>
                            <Button
                                variant="outline"
                                onClick={() => exportCsv(destructions)}
                                disabled={destructions.length === 0}
                            >
                                <Download className="h-4 w-4" />
                                Export CSV
                            </Button>
                        </div>
                    </div>
                )}
            </div>

            {canRecord && modal?.type === 'record' && (
                <RecordDestructionDialog
                    medications={medications}
                    staff={staff}
                    sites={sites}
                    defaultSiteId={siteFilter}
                    onClose={() => setModal(null)}
                />
            )}
            {canRecord && modal?.type === 'void' && (
                <VoidDestructionDialog
                    destruction={modal.row}
                    onClose={() => setModal(null)}
                />
            )}
            {modal?.type === 'detail' && (
                <DestructionDetailDialog
                    record={modal.row}
                    onClose={() => setModal(null)}
                    onVoid={
                        canRecord
                            ? () => setModal({ type: 'void', row: modal.row })
                            : undefined
                    }
                    onExport={() => exportCsv([modal.row])}
                />
            )}
            {ctx && <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} />}
        </AppLayout>
    );
}

/** Empty-state message: nothing yet vs. nothing matching the active filters. */
function emptyLabel(shown: number, total: number, none: string): string | null {
    if (shown > 0) return null;
    return total === 0 ? none : 'No records match these filters.';
}

function LogRow({
    d,
    rowProps,
    onVoid,
}: {
    d: DestructionRow;
    rowProps: React.HTMLAttributes<HTMLTableRowElement> & { tabIndex: number };
    onVoid?: () => void;
}) {
    return (
        <tr {...rowProps}>
            <td className="px-4 py-3 text-muted-foreground">
                {fmtDate(d.destroyed_at)}
            </td>
            <td className="px-4 py-3">{d.client_name}</td>
            <td className="px-4 py-3 font-medium">
                <span className={d.is_voided ? 'line-through' : ''}>
                    {d.medication_name}
                </span>
                {d.is_controlled_drug && (
                    <CdPill
                        label="CD"
                        tone="ml-2 bg-status-critical-bg text-status-critical"
                    />
                )}
                {d.is_voided && (
                    <span className="ml-2 align-middle">
                        <CdPill
                            label="Voided"
                            tone="bg-muted text-muted-foreground"
                        />
                    </span>
                )}
                {d.is_voided && d.void_reason && (
                    <div className="mt-0.5 text-xs font-normal text-muted-foreground">
                        Voided
                        {d.voided_by_name
                            ? ` by ${d.voided_by_name}`
                            : ''}: {d.void_reason}
                    </div>
                )}
            </td>
            <td className="px-4 py-3 tabular-nums">
                {d.quantity} {d.unit}
            </td>
            <td className="px-4 py-3 text-muted-foreground">
                {d.reason_label ?? d.reason ?? '—'}
            </td>
            <td className="px-4 py-3 text-muted-foreground">
                {d.disposal_method_label ?? d.disposal_method ?? '—'}
            </td>
            <td className="px-4 py-3 text-muted-foreground">
                {d.destroyed_by_name ?? '—'}
            </td>
            <td className="px-4 py-3 text-muted-foreground">
                {[d.witness_1_name, d.witness_2_name]
                    .filter(Boolean)
                    .join(', ') || '—'}
            </td>
            <td className="px-4 py-3 text-right">
                {d.is_voided ? (
                    <span className="text-xs text-muted-foreground">
                        {fmtDate(d.voided_at)}
                    </span>
                ) : onVoid ? (
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={(e) => {
                            e.stopPropagation();
                            onVoid();
                        }}
                    >
                        Void
                    </Button>
                ) : null}
            </td>
        </tr>
    );
}

const cnRow = (d: DestructionRow) =>
    `border-b last:border-b-0${d.is_voided ? ' bg-muted/30 text-muted-foreground' : ''}`;

/** One row of the hero alert strip — icon + message + Review jump + per-session dismiss. */
function AlertRow({
    alert,
    onReview,
    onDismiss,
}: {
    alert: Alert;
    onReview: () => void;
    onDismiss: () => void;
}) {
    const Icon = alert.icon;
    const tone =
        alert.tone === 'warning'
            ? 'border-status-warning/30 bg-status-warning-bg/60 text-status-warning'
            : 'border-status-info/30 bg-status-info-bg/60 text-status-info';
    return (
        <div
            className={`flex items-center justify-between gap-3 rounded-xl border px-4 py-3 ${tone}`}
        >
            <span className="flex items-center gap-2 text-sm font-medium">
                <Icon className="h-4 w-4 shrink-0" />
                {alert.message}
            </span>
            <span className="flex items-center gap-1.5">
                <Button size="sm" variant="outline" onClick={onReview}>
                    Review
                </Button>
                {/* eslint-disable-next-line no-restricted-syntax -- inline dismiss affordance on the alert strip. */}
                <button
                    type="button"
                    aria-label="Dismiss alert"
                    onClick={onDismiss}
                    className="grid h-7 w-7 place-items-center rounded-md opacity-70 hover:bg-foreground/10 hover:opacity-100"
                >
                    <X className="h-4 w-4" />
                </button>
            </span>
        </div>
    );
}

function tally(values: string[]): { label: string; count: number }[] {
    const map = new Map<string, number>();
    values.forEach((v) => map.set(v, (map.get(v) ?? 0) + 1));
    return [...map.entries()]
        .map(([label, count]) => ({ label, count }))
        .sort((a, b) => b.count - a.count);
}

function TableCard({
    head,
    empty,
    children,
}: {
    head: string[];
    empty: string | null;
    children: React.ReactNode;
}) {
    return (
        <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
            {empty ? (
                <div className="flex flex-col items-center gap-2 px-5 py-12 text-center text-sm text-muted-foreground">
                    <Trash2
                        className="h-8 w-8 text-muted-foreground/50"
                        aria-hidden
                    />
                    {empty}
                </div>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[720px] text-sm">
                        <thead>
                            <tr className="bg-muted text-left text-[11px] tracking-wide text-muted-foreground uppercase">
                                {head.map((h, i) => (
                                    <th key={i} className="px-4 py-2.5">
                                        {h}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>{children}</tbody>
                    </table>
                </div>
            )}
        </div>
    );
}

function StatCard({
    label,
    value,
    hint,
}: {
    label: string;
    value: number | string;
    hint?: string;
}) {
    return (
        <div className="rounded-2xl border bg-card p-4 shadow-sm">
            <div className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {label}
            </div>
            <div className="mt-1 text-2xl font-bold tabular-nums">{value}</div>
            {hint && (
                <div className="text-xs text-muted-foreground">{hint}</div>
            )}
        </div>
    );
}

function BreakdownCard({
    title,
    rows,
}: {
    title: string;
    rows: { label: string; count: number }[];
}) {
    const max = Math.max(1, ...rows.map((r) => r.count));
    return (
        <div className="rounded-2xl border bg-card p-4 shadow-sm">
            <div className="mb-3 flex items-center gap-2 text-sm font-semibold">
                <Package className="h-4 w-4 text-muted-foreground" />
                {title}
            </div>
            {rows.length === 0 ? (
                <div className="py-6 text-center text-sm text-muted-foreground">
                    No records.
                </div>
            ) : (
                <div className="flex flex-col gap-2">
                    {rows.map((r) => (
                        <div key={r.label} className="flex items-center gap-3">
                            <div className="w-40 shrink-0 truncate text-sm">
                                {r.label}
                            </div>
                            <div className="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                                <div
                                    className="h-full rounded-full bg-primary"
                                    style={{
                                        width: `${(r.count / max) * 100}%`,
                                    }}
                                />
                            </div>
                            <div className="w-8 text-right text-sm text-muted-foreground tabular-nums">
                                {r.count}
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
