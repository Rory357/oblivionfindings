import { StatTile } from '@/components/page/stat-tile';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Ban,
    Bell,
    Car,
    Check,
    CheckCircle2,
    ClipboardCheck,
    Clock,
    Download,
    MoreVertical,
    Search,
    ShieldCheck,
    UserCheck,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import type { PersonOption } from '@/components/hr/people-picker';
import { Button as GuardrailButton } from '@/components/ui/button';
import { Card as GuardrailCard } from '@/components/ui/card';
import { complianceExportHref } from '@/lib/hr/compliance-export';
import {
    AvatarBubble,
    ComplianceContextMenu,
    ComplianceRing,
    complianceStatusBadge,
    DriverChip,
    trackedCount,
    useContextMenu,
    VettingChip,
    type CtxItem,
} from './components/compliance-bits';
import {
    ComplianceHubHeader,
    type HeroPayload,
} from './components/compliance-hub-header';
import {
    ComplianceWizards,
    type ReqOption,
    type RoleOption,
    type WizardState,
} from './components/compliance-wizards';

interface StaffRow {
    user_id: number;
    user_name: string;
    user_email: string;
    total_requirements: number;
    compliant_count: number;
    expired_count: number;
    expiring_soon_count: number;
    not_started_count: number;
    compliance_percent: number;
    future_shifts_affected?: number;
    vetting_status?: string;
    driver_status?: string;
}

interface Paginator<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

interface Props {
    staffStatuses: Paginator<StaffRow>;
    summary: {
        total_staff: number;
        fully_compliant: number;
        has_expired: number;
        has_expiring: number;
        expiring_total: number;
        expired_total: number;
        hard_stops: number;
        shifts_affected: number;
    };
    requirements: { id: number; name: string; type: string }[];
    hero: HeroPayload;
    wizard: {
        people: PersonOption[];
        requirements: ReqOption[];
        roles: RoleOption[];
        siteTypes: string[];
    };
    filters: {
        q: string;
        status: string | null;
        requirement_id: string | null;
    };
    can: {
        export: boolean;
        manage: boolean;
        vetting: boolean;
        driver: boolean;
        vetting_manage: boolean;
        driver_manage: boolean;
    };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Staff compliance', href: '/hr/compliance' },
];

export default function ComplianceOverview({
    staffStatuses,
    summary,
    requirements,
    hero,
    wizard,
    filters,
    can,
}: Props) {
    const [wz, setWz] = useState<WizardState>(null);
    const [selected, setSelected] = useState<Record<number, boolean>>({});
    const [search, setSearch] = useState(filters.q ?? '');
    const searchRef = useRef<HTMLInputElement>(null);
    const { ctx, open: openCtx, close: closeCtx } = useContextMenu();

    const pct =
        summary.total_staff > 0
            ? Math.round((summary.fully_compliant / summary.total_staff) * 100)
            : 0;
    const exportHref = complianceExportHref('overview', can.export);
    const selectedIds = Object.keys(selected)
        .filter((k) => selected[Number(k)])
        .map(Number);

    // Debounced search → server.
    useEffect(() => {
        const t = setTimeout(() => {
            if (search === (filters.q ?? '')) return;
            router.get(
                '/hr/compliance',
                { ...cleanFilters(filters), q: search || undefined },
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 350);
        return () => clearTimeout(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    // Keyboard: "/" focuses search, "r" opens record wizard.
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            const tag = (e.target as HTMLElement)?.tagName;
            if (tag === 'INPUT' || tag === 'TEXTAREA') return;
            if (e.key === '/') {
                e.preventDefault();
                searchRef.current?.focus();
            } else if (e.key.toLowerCase() === 'r' && can.manage) {
                e.preventDefault();
                setWz({ type: 'record' });
            }
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [can.manage]);

    const applyFilter = (key: string, value: string | undefined) => {
        router.get(
            '/hr/compliance',
            { ...cleanFilters(filters), [key]: value || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const toggleRow = (id: number) =>
        setSelected((s) => ({ ...s, [id]: !s[id] }));
    const allSelected =
        staffStatuses.data.length > 0 &&
        staffStatuses.data.every((r) => selected[r.user_id]);
    const toggleAll = () =>
        setSelected(
            allSelected
                ? {}
                : Object.fromEntries(
                      staffStatuses.data.map((r) => [r.user_id, true]),
                  ),
        );
    const clearSelection = () => setSelected({});

    const sendReminder = useCallback((userIds: number[], label: string) => {
        router.post(
            '/hr/compliance/bulk-remind',
            { user_ids: userIds },
            {
                preserveScroll: true,
                onSuccess: () => toast.success(label),
                onError: () => toast.error('Could not send reminders.'),
            },
        );
    }, []);

    const rowMenu = (r: StaffRow): CtxItem[] => [
        {
            icon: ShieldCheck,
            label: 'Open',
            onClick: () => router.visit(`/hr/compliance/staff/${r.user_id}`),
        },
        ...(can.manage
            ? [
                  {
                      icon: ClipboardCheck,
                      label: 'Record compliance',
                      kbd: 'R',
                      onClick: () =>
                          setWz({
                              type: 'record',
                              preset: { person: String(r.user_id) },
                          }),
                  },
                  {
                      icon: Ban,
                      label: 'Waive a requirement',
                      onClick: () =>
                          setWz({
                              type: 'waive',
                              preset: { person: String(r.user_id) },
                          }),
                  },
                  {
                      icon: Bell,
                      label: 'Send reminder',
                      onClick: () =>
                          sendReminder(
                              [r.user_id],
                              `Reminder sent to ${r.user_name}.`,
                          ),
                  },
              ]
            : []),
        ...(can.vetting
            ? [
                  {
                      icon: UserCheck,
                      label: 'View vetting',
                      onClick: () => router.visit('/hr/compliance/vetting'),
                  },
              ]
            : []),
        ...(exportHref
            ? [
                  {
                      icon: Download,
                      label: 'Export',
                      onClick: () => (window.location.href = exportHref),
                  },
              ]
            : []),
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Staff compliance" />
            <div className="space-y-4 px-4 py-4 lg:px-6">
                <ComplianceHubHeader
                    hero={hero}
                    active="overview"
                    counts={{ matrix: wizard.requirements.length || undefined }}
                    can={{
                        export: can.export,
                        manage: can.manage,
                        vetting: can.vetting_manage,
                        driver: can.driver_manage,
                    }}
                    onWizard={(type) => setWz({ type })}
                />

                {/* KPI tiles */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    <StatTile
                        label="Fully compliant"
                        value={`${pct}%`}
                        icon={CheckCircle2}
                        tone="success"
                        subtitle={`${summary.fully_compliant} of ${summary.total_staff}`}
                    />
                    <StatTile
                        label="Expiring"
                        value={summary.expiring_total}
                        icon={Clock}
                        tone="warning"
                        subtitle="next 30 days"
                    />
                    <StatTile
                        label="Expired"
                        value={summary.expired_total}
                        icon={AlertTriangle}
                        tone="critical"
                        subtitle={`${summary.has_expired} staff`}
                    />
                    <StatTile
                        label="Hard-stops"
                        value={summary.hard_stops}
                        icon={Ban}
                        tone="critical"
                        subtitle="blocked from shifts"
                    />
                    <StatTile
                        label="Shifts affected"
                        value={summary.shifts_affected}
                        icon={Car}
                        tone="info"
                        subtitle="upcoming"
                    />
                </div>

                {/* Filter bar */}
                <GuardrailCard
                    unstyled
                    className="flex flex-wrap items-center gap-2.5 rounded-xl border border-border bg-card p-2.5"
                >
                    <div className="relative min-w-[220px] flex-1">
                        <Search className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <input
                            ref={searchRef}
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search staff by name or email…  ( / )"
                            className="h-9 w-full rounded-lg border border-border bg-background pr-3 pl-9 text-sm outline-none focus:ring-2 focus:ring-primary"
                        />
                    </div>
                    <Select
                        value={filters.status || 'all'}
                        onValueChange={(v) =>
                            applyFilter('status', v === 'all' ? undefined : v)
                        }
                    >
                        <SelectTrigger
                            className="h-9 w-[150px]"
                            aria-label="Status filter"
                        >
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All staff</SelectItem>
                            <SelectItem value="fully_compliant">
                                Fully compliant
                            </SelectItem>
                            <SelectItem value="has_expired">
                                Has expired
                            </SelectItem>
                            <SelectItem value="has_expiring">
                                Expiring
                            </SelectItem>
                            <SelectItem value="incomplete">
                                Incomplete
                            </SelectItem>
                            <SelectItem value="hard_stop">
                                Failed hard-stop
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <Select
                        value={filters.requirement_id || 'all'}
                        onValueChange={(v) =>
                            applyFilter(
                                'requirement_id',
                                v === 'all' ? undefined : v,
                            )
                        }
                    >
                        <SelectTrigger
                            className="h-9 w-[180px]"
                            aria-label="Requirement filter"
                        >
                            <SelectValue placeholder="Requirement" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">
                                All requirements
                            </SelectItem>
                            {requirements.map((r) => (
                                <SelectItem key={r.id} value={String(r.id)}>
                                    {r.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </GuardrailCard>

                {/* Bulk bar */}
                {selectedIds.length > 0 && (
                    <div className="flex flex-wrap items-center gap-3 rounded-xl border border-primary/30 bg-accent p-2.5 motion-safe:animate-in motion-safe:zoom-in-95">
                        <span className="font-bold text-primary">
                            {selectedIds.length} selected
                        </span>
                        <div className="flex flex-wrap gap-2">
                            {can.manage && (
                                <>
                                    <BulkBtn
                                        onClick={() =>
                                            setWz({
                                                type: 'record',
                                                userIds: selectedIds,
                                            })
                                        }
                                    >
                                        Record compliance
                                    </BulkBtn>
                                    <BulkBtn
                                        onClick={() =>
                                            sendReminder(
                                                selectedIds,
                                                `Reminders sent to ${selectedIds.length} staff.`,
                                            )
                                        }
                                    >
                                        Send reminders
                                    </BulkBtn>
                                    <BulkBtn
                                        onClick={() =>
                                            setWz({
                                                type: 'waive',
                                                userIds: selectedIds,
                                            })
                                        }
                                    >
                                        Waive…
                                    </BulkBtn>
                                </>
                            )}
                            {exportHref && (
                                <BulkBtn
                                    onClick={() =>
                                        (window.location.href = exportHref)
                                    }
                                >
                                    Export
                                </BulkBtn>
                            )}
                        </div>
                        <GuardrailButton
                            unstyled
                            onClick={clearSelection}
                            className="ml-auto text-sm font-semibold text-muted-foreground hover:text-foreground"
                        >
                            Clear
                        </GuardrailButton>
                    </div>
                )}

                {/* Table */}
                <GuardrailCard
                    unstyled
                    className="overflow-hidden rounded-xl border border-border bg-card"
                >
                    <table className="w-full text-[13px]">
                        <thead>
                            <tr className="border-b border-border bg-muted text-left text-muted-foreground">
                                <th className="w-10 px-3 py-3">
                                    <Checkbox
                                        checked={allSelected}
                                        onChange={toggleAll}
                                    />
                                </th>
                                <Th>Staff member</Th>
                                <Th>Compliance</Th>
                                <Th>Status</Th>
                                <Th>Vetting</Th>
                                <Th>Driver</Th>
                                <Th>Shifts</Th>
                                <th className="w-10" />
                            </tr>
                        </thead>
                        <tbody>
                            {staffStatuses.data.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={8}
                                        className="px-4 py-12 text-center text-muted-foreground"
                                    >
                                        <ShieldCheck className="mx-auto mb-2 h-8 w-8 opacity-40" />
                                        No staff match these filters.
                                    </td>
                                </tr>
                            ) : (
                                staffStatuses.data.map((r) => {
                                    const sb = complianceStatusBadge(r);
                                    const sel = !!selected[r.user_id];
                                    return (
                                        <tr
                                            key={r.user_id}
                                            onContextMenu={(e) =>
                                                openCtx(e, rowMenu(r))
                                            }
                                            className={`border-b border-border transition-colors last:border-0 hover:bg-muted/60 ${sel ? 'bg-accent/50' : ''}`}
                                        >
                                            <td className="px-3 py-2.5">
                                                <Checkbox
                                                    checked={sel}
                                                    onChange={() =>
                                                        toggleRow(r.user_id)
                                                    }
                                                />
                                            </td>
                                            <td className="px-3 py-2.5">
                                                <div className="flex items-center gap-2.5">
                                                    <AvatarBubble
                                                        name={r.user_name}
                                                    />
                                                    <div className="min-w-0">
                                                        <Link
                                                            href={`/hr/compliance/staff/${r.user_id}`}
                                                            className="font-semibold text-primary hover:underline"
                                                        >
                                                            {r.user_name}
                                                        </Link>
                                                        <div className="truncate text-[11px] text-muted-foreground">
                                                            {r.user_email}
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="px-3 py-2.5">
                                                {trackedCount(r) === 0 ? (
                                                    <span className="text-muted-foreground">
                                                        —
                                                    </span>
                                                ) : (
                                                    <ComplianceRing
                                                        pct={
                                                            r.compliance_percent
                                                        }
                                                    />
                                                )}
                                            </td>
                                            <td className="px-3 py-2.5">
                                                <StatusBadge
                                                    variant={sb.variant}
                                                >
                                                    {sb.label}
                                                </StatusBadge>
                                            </td>
                                            <td className="px-3 py-2.5">
                                                <VettingChip
                                                    status={r.vetting_status}
                                                />
                                            </td>
                                            <td className="px-3 py-2.5">
                                                <DriverChip
                                                    status={r.driver_status}
                                                />
                                            </td>
                                            <td className="px-3 py-2.5">
                                                {r.future_shifts_affected ? (
                                                    <StatusBadge variant="critical">
                                                        {
                                                            r.future_shifts_affected
                                                        }{' '}
                                                        shift
                                                        {r.future_shifts_affected >
                                                        1
                                                            ? 's'
                                                            : ''}
                                                    </StatusBadge>
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        —
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-3 py-2.5 text-right">
                                                <GuardrailButton
                                                    unstyled
                                                    onClick={(e) =>
                                                        openCtx(e, rowMenu(r))
                                                    }
                                                    aria-label="Row actions"
                                                    className="grid h-7 w-7 place-items-center rounded-md text-muted-foreground hover:bg-accent"
                                                >
                                                    <MoreVertical className="h-4 w-4" />
                                                </GuardrailButton>
                                            </td>
                                        </tr>
                                    );
                                })
                            )}
                        </tbody>
                    </table>
                    <div className="flex items-center justify-between border-t border-border px-4 py-3 text-[12.5px] text-muted-foreground">
                        <span>
                            Showing {staffStatuses.from ?? 0}–
                            {staffStatuses.to ?? 0} of {staffStatuses.total}{' '}
                            staff
                        </span>
                        {staffStatuses.last_page > 1 && (
                            <LaravelPagination links={staffStatuses.links} />
                        )}
                    </div>
                </GuardrailCard>
            </div>

            <ComplianceContextMenu ctx={ctx} onClose={closeCtx} />
            <ComplianceWizards
                state={wz}
                onClose={() => setWz(null)}
                people={wizard.people}
                requirements={wizard.requirements}
                roles={wizard.roles}
                siteTypes={wizard.siteTypes}
            />
        </AppLayout>
    );
}

function cleanFilters(filters: Props['filters']): Record<string, string> {
    const out: Record<string, string> = {};
    if (filters.status) out.status = filters.status;
    if (filters.requirement_id) out.requirement_id = filters.requirement_id;
    if (filters.q) out.q = filters.q;
    return out;
}

function Th({ children }: { children: React.ReactNode }) {
    return <th className="px-3 py-3 font-semibold">{children}</th>;
}

function Checkbox({
    checked,
    onChange,
}: {
    checked: boolean;
    onChange: () => void;
}) {
    return (
        <GuardrailButton
            unstyled
            type="button"
            onClick={onChange}
            aria-checked={checked}
            role="checkbox"
            className={`grid h-[18px] w-[18px] place-items-center rounded-[5px] border-[1.5px] ${checked ? 'border-primary bg-primary text-primary-foreground' : 'border-border'}`}
        >
            {checked ? <Check className="h-3 w-3" strokeWidth={3} /> : null}
        </GuardrailButton>
    );
}

function BulkBtn({
    onClick,
    children,
}: {
    onClick: () => void;
    children: React.ReactNode;
}) {
    return (
        <GuardrailButton
            unstyled
            onClick={onClick}
            className="rounded-lg border border-primary/25 bg-card px-2.5 py-1.5 text-[12.5px] font-semibold text-primary transition-colors hover:bg-primary/5"
        >
            {children}
        </GuardrailButton>
    );
}
