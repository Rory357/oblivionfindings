/* eslint-disable no-restricted-syntax -- The hero footer uses custom on-dark
 * search/clear controls (the sanctioned H&S hero pattern); semantic tokens only. */
import {
    PrivacyActionModal,
    type PrivacyActionKind,
} from '@/components/privacy/privacy-action-modal';
import {
    PrivacyDetailDialog,
    type PrivacyCan,
    type PrivacyDetail,
} from '@/components/privacy/privacy-detail-dialog';
import {
    PrivacyWizard,
    type ClientOption,
    type StaffOption,
} from '@/components/privacy/privacy-wizard';
import {
    getPrivacyWizardConfig,
    type PrivacyWizardDomain,
} from '@/components/privacy/privacy-wizard-configs';
import {
    PrivacyWorklist,
    type WorklistRow,
} from '@/components/privacy/privacy-worklist';
import {
    EntityFilter,
    ShiftContextMenu,
    TabStrip,
    type RosterTabItem,
    type ShiftCtxItem,
    type ShiftCtxState,
} from '@/components/rostering';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import AppLayout from '@/layouts/app-layout';
import {
    HeroCluster,
    HeroClusterTile,
    HeroComplianceBadges,
    HeroMedallion,
    HeroSegmented,
    HeroShell,
    HeroStatusPill,
    fmt,
    type Tone,
} from '@/pages/health-safety/components/hs-hero-kit';
import { titleCase } from '@/pages/privacy/privacy-shared';
import { Head, router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Ban,
    Check,
    Clock,
    Download,
    Eye,
    FileText,
    Fingerprint,
    Lock,
    Pencil,
    Plus,
    Scale,
    Search,
    Shield,
    ShieldAlert,
    ShieldCheck,
    Trash2,
    Users,
    X,
} from 'lucide-react';
import { useEffect, useState } from 'react';

type HeroData = {
    live: {
        new_requests: number;
        in_progress: number;
        completed: number;
        breaches: number;
    };
    attention: {
        overdue: number;
        opc_notify: number;
        subject_notify: number;
        active_holds: number;
        high_risk_dpia: number;
        retention_review: number;
    };
    badges: {
        privacy_act_ok: boolean;
        opc_open: number;
        overdue_requests: number;
        active_holds: number;
        retention_active: number;
    };
};
type Filters = {
    q: string;
    period: string;
    site_id: number | null;
    tab: string;
};
type Paginator = {
    data: WorklistRow[];
    links: { url: string | null; label: string; active: boolean }[];
    last_page: number;
    total: number;
};

type Props = {
    tab: string;
    tabCounts: Record<string, number>;
    hero: HeroData;
    filters: Filters;
    sites: { id: number; name: string }[];
    staff: StaffOption[];
    clients: ClientOption[];
    worklist: Paginator;
    detail: PrivacyDetail | null;
    new: PrivacyWizardDomain | null;
    can: PrivacyCan & {
        viewRequests: boolean;
        manageRetention: boolean;
        manage: boolean;
    };
};

const DETAIL_KIND: Record<string, PrivacyDetail['kind'] | null> = {
    overview: 'request',
    requests: 'request',
    breaches: 'breach',
    legal_holds: 'hold',
    dpia: 'dpia',
    retention: null,
    deletion_logs: null,
};

const PERIOD_ITEMS = [
    { key: 'month', label: 'This month' },
    { key: 'quarter', label: 'Quarter' },
    { key: 'year', label: 'Year' },
    { key: 'all', label: 'All' },
];

export default function PrivacyDashboard({
    tab,
    tabCounts,
    hero,
    filters,
    sites,
    staff,
    clients,
    worklist,
    detail,
    new: newDomain,
    can,
}: Props) {
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [wizard, setWizard] = useState<PrivacyWizardDomain | null>(newDomain);
    const [detailAction, setDetailAction] = useState<PrivacyActionKind | null>(
        null,
    );
    const [rowAction, setRowAction] = useState<{
        kind: PrivacyActionKind;
        id: number;
    } | null>(null);

    // Strip ?new from the URL once consumed so a reload doesn't reopen the wizard.
    useEffect(() => {
        if (newDomain && typeof window !== 'undefined') {
            const url = new URL(window.location.href);
            url.searchParams.delete('new');
            window.history.replaceState({}, '', url.toString());
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const baseQuery = () => {
        const q: Record<string, string | number> = { tab };
        if (filters.q) q.q = filters.q;
        if (filters.period && filters.period !== 'month')
            q.period = filters.period;
        if (filters.site_id) q.site_id = filters.site_id;
        return q;
    };
    const go = (
        extra: Record<string, string | number | null>,
        opts: Record<string, unknown> = {},
    ) =>
        router.get(
            '/privacy/dashboard',
            { ...baseQuery(), ...extra },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                ...opts,
            },
        );

    const setTab = (id: string) =>
        router.get(
            '/privacy/dashboard',
            { ...baseQuery(), tab: id },
            { preserveScroll: true },
        );
    const openDetail = (
        kind: string,
        id: number,
        action?: PrivacyActionKind,
    ) => {
        setDetailAction(action ?? null);
        go({ [kind]: id }, { only: ['detail'] });
    };
    const closeDetail = () => {
        setDetailAction(null);
        go({}, { only: ['detail'] });
    };
    const clearFilters = () =>
        router.get('/privacy/dashboard', { tab }, { preserveScroll: true });
    const hasFilters =
        Boolean(filters.q) ||
        (filters.period && filters.period !== 'month') ||
        Boolean(filters.site_id);

    /* ---- row open / right-click ---- */
    const openRow = (row: WorklistRow) => {
        const kind = DETAIL_KIND[tab];
        if (kind) openDetail(kind, row.id);
        else if (tab === 'retention')
            router.visit(`/privacy/retention/${row.id}/edit`);
    };

    const icon = (I: typeof Eye) => <I className="h-3.5 w-3.5" />;

    const openRowCtx = (e: React.MouseEvent, row: WorklistRow) => {
        e.preventDefault();
        const kind = DETAIL_KIND[tab];
        const items: ShiftCtxItem[] = [];

        if (kind) {
            items.push({
                icon: icon(Eye),
                label: `View ${kind}`,
                sub: row.reference,
                tone: 'primary',
                onClick: () => openDetail(kind, row.id),
            });
        }
        if (tab === 'overview' || tab === 'requests') {
            const open = !['completed', 'rejected', 'withdrawn'].includes(
                row.status,
            );
            if (open && can.processRequests) {
                if (row.status !== undefined)
                    items.push({
                        icon: icon(Fingerprint),
                        label: 'Verify identity',
                        onClick: () => openDetail('request', row.id, 'verify'),
                    });
                items.push({
                    icon: icon(Clock),
                    label: 'Extend deadline',
                    onClick: () => openDetail('request', row.id, 'extend'),
                });
                items.push({
                    icon: icon(Check),
                    label: 'Mark complete',
                    onClick: () => openDetail('request', row.id, 'complete'),
                });
                items.push({
                    icon: icon(Ban),
                    label: 'Refuse request',
                    tone: 'critical',
                    onClick: () => openDetail('request', row.id, 'refuse'),
                });
            }
            if (can.processRequests)
                items.push({
                    icon: icon(Download),
                    label: 'Export data package',
                    onClick: () => openDetail('request', row.id, 'export'),
                });
            if (row.client)
                items.push(
                    { sep: true },
                    {
                        icon: icon(Users),
                        label: 'View subject',
                        onClick: () =>
                            router.visit(
                                `/operations/clients/${row.client.id}`,
                            ),
                    },
                );
        } else if (tab === 'breaches' && can.reportBreaches) {
            if (row.opc_required && !row.opc_notified)
                items.push({
                    icon: icon(ShieldAlert),
                    label: 'Notify OPC',
                    sub: 'as soon as practicable',
                    tone: 'critical',
                    onClick: () => openDetail('breach', row.id, 'notify-opc'),
                });
            if (row.subject_required && !row.subject_notified)
                items.push({
                    icon: icon(AlertTriangle),
                    label: 'Notify affected subjects',
                    onClick: () =>
                        openDetail('breach', row.id, 'notify-subjects'),
                });
            if (row.status !== 'resolved')
                items.push({
                    icon: icon(Check),
                    label: 'Resolve breach',
                    onClick: () => openDetail('breach', row.id, 'resolve'),
                });
        } else if (tab === 'legal_holds' && can.manageLegalHolds) {
            if (row.status === 'active')
                items.push({
                    icon: icon(Ban),
                    label: 'Release hold',
                    tone: 'critical',
                    onClick: () => openDetail('hold', row.id, 'release'),
                });
        } else if (tab === 'dpia' && can.conductDPIA) {
            if (!row.outcome) {
                items.push({
                    icon: icon(Check),
                    label: 'Approve DPIA',
                    onClick: () => openDetail('dpia', row.id, 'approve'),
                });
                items.push({
                    icon: icon(ShieldAlert),
                    label: 'Send for review',
                    onClick: () => openDetail('dpia', row.id, 'review'),
                });
            }
        } else if (tab === 'retention' && can.manageRetention) {
            items.push({
                icon: icon(Pencil),
                label: 'Edit policy',
                onClick: () =>
                    router.visit(`/privacy/retention/${row.id}/edit`),
            });
            items.push({
                icon: icon(Eye),
                label: 'Create execution preview',
                onClick: () =>
                    router.post(`/privacy/retention/${row.id}/preview`, {}),
            });
            if (row.execution_state === 'previewed')
                items.push({
                    icon: icon(Check),
                    label: 'Approve preview',
                    sub: 'independent reviewer',
                    onClick: () =>
                        router.post(`/privacy/retention/${row.id}/approve`, {}),
                });
            if (row.execution_state === 'approved')
                items.push({
                    icon: icon(Trash2),
                    label: 'Execute approved retention',
                    sub: 'governed outcome',
                    tone: 'critical',
                    onClick: () =>
                        setRowAction({ kind: 'execute', id: row.id }),
                });
        }

        if (!items.length) return;
        const tag = String(
            row.status ?? row.outcome ?? (row.active ? 'active' : 'policy'),
        );
        setCtx({
            x: e.clientX,
            y: e.clientY,
            tag: tag ? titleCase(tag) : 'Record',
            meta: row.reference ?? row.policy_name ?? '',
            items,
        });
    };

    const openHeroCtx = (e: React.MouseEvent) => {
        e.preventDefault();
        if (!can.manage) return;
        const items: ShiftCtxItem[] = [
            {
                icon: icon(FileText),
                label: 'New privacy request',
                tone: 'primary',
                onClick: () => setWizard('request'),
            },
            {
                icon: icon(AlertTriangle),
                label: 'Log data breach',
                tone: 'critical',
                onClick: () => setWizard('breach'),
            },
            {
                icon: icon(Scale),
                label: 'New legal hold',
                onClick: () => setWizard('hold'),
            },
            {
                icon: icon(Lock),
                label: 'New retention policy',
                onClick: () => setWizard('retention'),
            },
            {
                icon: icon(ShieldCheck),
                label: 'New DPIA',
                onClick: () => setWizard('dpia'),
            },
            { sep: true },
            {
                icon: icon(Download),
                label: 'Export compliance report',
                sub: 'CSV',
                onClick: () => downloadReport('full', filters.period),
            },
        ];
        setCtx({
            x: e.clientX,
            y: e.clientY,
            tag: 'New',
            meta: 'Privacy command centre',
            items,
        });
    };

    // Per-domain least-privilege: only show the tabs the user can view (mirrors
    // the dedicated pages' permissions). Overview/Requests need only viewRequests.
    const TABS: RosterTabItem[] = [
        {
            id: 'overview',
            label: 'Overview',
            icon: Activity,
            tone: 'primary',
            badge: tabCounts.overview || undefined,
        },
        {
            id: 'requests',
            label: 'Requests',
            icon: FileText,
            tone: 'info',
            badge: tabCounts.requests || undefined,
        },
        ...(can.reportBreaches
            ? [
                  {
                      id: 'breaches',
                      label: 'Breaches',
                      icon: AlertTriangle,
                      tone: 'critical',
                      badge: tabCounts.breaches || undefined,
                  } as RosterTabItem,
              ]
            : []),
        ...(can.manageLegalHolds
            ? [
                  {
                      id: 'legal_holds',
                      label: 'Legal holds',
                      icon: Scale,
                      tone: 'warning',
                      badge: tabCounts.legal_holds || undefined,
                  } as RosterTabItem,
              ]
            : []),
        ...(can.manageRetention
            ? [
                  {
                      id: 'retention',
                      label: 'Retention',
                      icon: Lock,
                      tone: 'primary',
                      badge: tabCounts.retention || undefined,
                  } as RosterTabItem,
              ]
            : []),
        ...(can.conductDPIA
            ? [
                  {
                      id: 'dpia',
                      label: 'DPIA',
                      icon: ShieldCheck,
                      tone: 'success',
                      badge: tabCounts.dpia || undefined,
                  } as RosterTabItem,
              ]
            : []),
        ...(can.manageRetention
            ? [
                  {
                      id: 'deletion_logs',
                      label: 'Deletion logs',
                      icon: Trash2,
                      tone: 'warning',
                      badge: tabCounts.deletion_logs || undefined,
                  } as RosterTabItem,
              ]
            : []),
    ];

    return (
        <AppLayout
            breadcrumbs={[{ title: 'Privacy', href: '/privacy/dashboard' }]}
        >
            <Head title="Privacy Dashboard" />

            <div className="flex flex-col gap-6 p-6">
                <HeroShell
                    footer={
                        <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                            <HeroSegmented
                                label="Period"
                                variant="pill"
                                ariaLabel="Reporting period"
                                items={PERIOD_ITEMS}
                                value={filters.period || 'month'}
                                onChange={(p) => go({ period: p })}
                            />
                            {sites.length ? (
                                <EntityFilter
                                    label="Site"
                                    allLabel="All sites"
                                    items={sites}
                                    value={filters.site_id}
                                    onChange={(id) => go({ site_id: id })}
                                    onDark
                                />
                            ) : null}
                            <div className="relative ml-auto">
                                <Search className="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-primary-foreground/60" />
                                <input
                                    type="search"
                                    defaultValue={filters.q}
                                    placeholder="Search privacy records…"
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter')
                                            go({
                                                q: (
                                                    e.target as HTMLInputElement
                                                ).value,
                                            });
                                    }}
                                    className="w-52 rounded-lg border border-primary-foreground/20 bg-primary-foreground/10 py-1.5 pr-2.5 pl-8 text-xs text-primary-foreground placeholder:text-primary-foreground/50 focus-visible:ring-2 focus-visible:ring-primary-foreground/40 focus-visible:outline-none"
                                />
                            </div>
                            {hasFilters ? (
                                <button
                                    type="button"
                                    onClick={clearFilters}
                                    className="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-medium text-primary-foreground/80 hover:bg-primary-foreground/10"
                                >
                                    <X className="h-3.5 w-3.5" /> Clear
                                </button>
                            ) : null}
                        </div>
                    }
                >
                    <div
                        onContextMenu={openHeroCtx}
                        className="flex flex-col gap-5"
                    >
                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div className="flex items-start gap-4">
                                <HeroMedallion icon={Shield} />
                                <div className="flex flex-col gap-1.5">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <HeroStatusPill>
                                            Privacy &amp; data protection ·
                                            synced just now
                                        </HeroStatusPill>
                                        <span className="inline-flex items-center gap-1 rounded-full border border-primary-foreground/25 px-2 py-0.5 text-[10px] font-semibold tracking-wide text-primary-foreground/80 uppercase">
                                            <ShieldCheck className="h-3 w-3" />{' '}
                                            Privacy Act 2020 · OPC
                                        </span>
                                    </div>
                                    <h1 className="text-2xl font-bold tracking-tight text-primary-foreground md:text-[28px]">
                                        Privacy Dashboard
                                    </h1>
                                    <p className="max-w-xl text-sm text-primary-foreground/70">
                                        The command centre for the whole privacy
                                        module — access &amp; correction
                                        requests, notifiable breaches, legal
                                        holds, retention and DPIAs. Right-click
                                        anywhere for quick actions.
                                    </p>
                                </div>
                            </div>
                            <div className="flex items-center gap-2">
                                {can.manage ? (
                                    <Button
                                        size="sm"
                                        className="bg-primary-foreground text-primary hover:bg-primary-foreground/90"
                                        onClick={() => setWizard('request')}
                                    >
                                        <Plus className="mr-1.5 h-4 w-4" /> New
                                        privacy request
                                    </Button>
                                ) : null}
                                <Popover>
                                    <PopoverTrigger asChild>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className="border-primary-foreground/25 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20"
                                        >
                                            <FileText className="mr-1.5 h-4 w-4" />{' '}
                                            Compliance reports
                                        </Button>
                                    </PopoverTrigger>
                                    <PopoverContent
                                        align="end"
                                        className="w-64 p-1.5"
                                    >
                                        {[
                                            {
                                                type: 'opc_register',
                                                icon: ShieldAlert,
                                                label: 'OPC breach register',
                                            },
                                            {
                                                type: 'sla',
                                                icon: Clock,
                                                label: 'Access-request SLA report',
                                            },
                                            {
                                                type: 'retention',
                                                icon: Lock,
                                                label: 'Retention compliance',
                                            },
                                            {
                                                type: 'full',
                                                icon: ShieldCheck,
                                                label: 'Full compliance report',
                                            },
                                        ].map((r) => (
                                            <a
                                                key={r.type}
                                                href={`/privacy/reports/export?type=${r.type}&period=${filters.period || 'year'}`}
                                                className="flex items-center gap-2.5 rounded-md px-2.5 py-2 text-sm transition-colors hover:bg-muted"
                                            >
                                                <r.icon className="h-4 w-4 text-primary" />{' '}
                                                {r.label}
                                            </a>
                                        ))}
                                    </PopoverContent>
                                </Popover>
                            </div>
                        </div>

                        <div className="grid gap-3 lg:grid-cols-2">
                            <HeroCluster
                                title="Live · this period"
                                icon={Activity}
                            >
                                <HeroClusterTile
                                    href="/privacy/dashboard?tab=requests"
                                    label="New requests"
                                    value={fmt(hero.live.new_requests)}
                                    caption="this period"
                                    tone="neutral"
                                />
                                <HeroClusterTile
                                    href="/privacy/dashboard?tab=requests"
                                    label="In progress"
                                    value={fmt(hero.live.in_progress)}
                                    caption="active"
                                    tone="neutral"
                                />
                                <HeroClusterTile
                                    href="/privacy/dashboard?tab=requests"
                                    label="Completed"
                                    value={fmt(hero.live.completed)}
                                    caption="this period"
                                    tone="success"
                                />
                                <HeroClusterTile
                                    href="/privacy/dashboard?tab=breaches"
                                    label="Breaches"
                                    value={fmt(hero.live.breaches)}
                                    caption="logged"
                                    tone="warning"
                                />
                            </HeroCluster>
                            <HeroCluster
                                title="Needs attention"
                                icon={AlertTriangle}
                            >
                                <HeroClusterTile
                                    href="/privacy/dashboard?tab=requests"
                                    label="Overdue"
                                    value={fmt(hero.attention.overdue)}
                                    caption="20 wd passed"
                                    tone="critical"
                                />
                                <HeroClusterTile
                                    href="/privacy/dashboard?tab=breaches"
                                    label="OPC notify"
                                    value={fmt(hero.attention.opc_notify)}
                                    caption="asap"
                                    tone={
                                        (hero.attention.opc_notify
                                            ? 'critical'
                                            : 'neutral') as Tone
                                    }
                                />
                                <HeroClusterTile
                                    href="/privacy/dashboard?tab=breaches"
                                    label="Subject notify"
                                    value={fmt(hero.attention.subject_notify)}
                                    caption="due"
                                    tone={
                                        (hero.attention.subject_notify
                                            ? 'warning'
                                            : 'neutral') as Tone
                                    }
                                />
                                <HeroClusterTile
                                    href="/privacy/dashboard?tab=legal_holds"
                                    label="Active holds"
                                    value={fmt(hero.attention.active_holds)}
                                    caption="preserving"
                                    tone="neutral"
                                />
                                <HeroClusterTile
                                    href="/privacy/dashboard?tab=dpia"
                                    label="High-risk DPIA"
                                    value={fmt(hero.attention.high_risk_dpia)}
                                    caption="in review"
                                    tone={
                                        (hero.attention.high_risk_dpia
                                            ? 'critical'
                                            : 'neutral') as Tone
                                    }
                                />
                                <HeroClusterTile
                                    href="/privacy/dashboard?tab=retention"
                                    label="Retention"
                                    value={fmt(hero.attention.retention_review)}
                                    caption="review due"
                                    tone={
                                        (hero.attention.retention_review
                                            ? 'warning'
                                            : 'neutral') as Tone
                                    }
                                />
                            </HeroCluster>
                        </div>

                        <HeroComplianceBadges
                            items={[
                                {
                                    icon: ShieldCheck,
                                    tone: 'success',
                                    label: 'Privacy Act 2020 · Compliant',
                                },
                                {
                                    icon: AlertTriangle,
                                    tone: hero.badges.opc_open
                                        ? 'warning'
                                        : 'success',
                                    label: `OPC-notifiable · ${hero.badges.opc_open} open`,
                                },
                                {
                                    icon: Clock,
                                    tone: hero.badges.overdue_requests
                                        ? 'critical'
                                        : 'success',
                                    label: `Overdue access requests · ${hero.badges.overdue_requests}`,
                                },
                                {
                                    icon: Scale,
                                    tone: 'success',
                                    label: `Active legal holds · ${hero.badges.active_holds}`,
                                },
                                {
                                    icon: Lock,
                                    tone: 'success',
                                    label: `Retention policies · ${hero.badges.retention_active} active`,
                                },
                            ]}
                        />
                    </div>
                </HeroShell>

                <TabStrip
                    value={tab}
                    onChange={setTab}
                    items={TABS}
                    ariaLabel="Privacy views"
                />

                <Card>
                    <CardContent className="p-0">
                        <PrivacyWorklist
                            tab={tab}
                            rows={worklist.data}
                            total={worklist.total}
                            onOpen={openRow}
                            onRowCtx={openRowCtx}
                        />
                    </CardContent>
                </Card>

                {worklist.last_page > 1 ? (
                    <LaravelPagination links={worklist.links} />
                ) : null}
            </div>

            {ctx ? (
                <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} />
            ) : null}
            {detail ? (
                <PrivacyDetailDialog
                    detail={detail}
                    can={can}
                    open
                    onClose={closeDetail}
                    initialAction={detailAction}
                />
            ) : null}
            {wizard ? (
                <PrivacyWizard
                    config={getPrivacyWizardConfig(wizard)}
                    open
                    onClose={() => setWizard(null)}
                    staff={staff}
                    clients={clients}
                    onCreated={() => undefined}
                />
            ) : null}
            {rowAction ? (
                <PrivacyActionModal
                    kind={rowAction.kind}
                    recordId={rowAction.id}
                    open
                    onClose={() => setRowAction(null)}
                />
            ) : null}
        </AppLayout>
    );
}

function downloadReport(type: string, period: string) {
    if (typeof window !== 'undefined') {
        window.location.href = `/privacy/reports/export?type=${type}&period=${period || 'year'}`;
    }
}
