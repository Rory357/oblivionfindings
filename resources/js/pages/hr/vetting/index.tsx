import { StatTile } from '@/components/page/stat-tile';
import { Button } from '@/components/ui/button';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    CheckCircle2,
    Clock,
    Download,
    FileText,
    MoreVertical,
    Pencil,
    Plus,
    Search,
    ShieldCheck,
    UserCheck,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import {
    AvatarBubble,
    ComplianceContextMenu,
    useContextMenu,
    type CtxItem,
} from '@/pages/hr/compliance/components/compliance-bits';
import { ComplianceHubHeader, type HeroPayload } from '@/pages/hr/compliance/components/compliance-hub-header';
import { ComplianceWizards, type ReqOption, type RoleOption, type WizardState } from '@/pages/hr/compliance/components/compliance-wizards';
import type { PersonOption } from '@/components/hr/people-picker';

interface Check {
    id: number;
    user?: { id: number; name: string };
    check_type: string;
    provider: string | null;
    reference_number: string | null;
    status: string;
    expires_at: string | null;
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
    hero: HeroPayload;
    checks: Paginator<Check>;
    summary: { total: number; clear: number; pending: number; flagged: number; expired: number; expiring: number };
    wizard: { people: PersonOption[]; requirements: ReqOption[]; roles: RoleOption[]; siteTypes: string[] };
    filters: { status: string | null; q: string };
    can: { manage: boolean; compliance_manage: boolean; driver_manage: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Staff compliance', href: '/hr/compliance' },
    { title: 'Vetting', href: '/hr/compliance/vetting' },
];

function statusVariant(s: string): 'success' | 'warning' | 'critical' | 'neutral' {
    if (s === 'clear') return 'success';
    if (['expired', 'failed', 'flagged'].includes(s)) return 'critical';
    if (['conditional', 'renewal_due', 'pending', 'requested', 'in_progress'].includes(s)) return 'warning';
    return 'neutral';
}

function fmtDate(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' });
}

export default function VettingIndex({ hero, checks, summary, wizard, filters, can }: Props) {
    const [wz, setWz] = useState<WizardState>(null);
    const [search, setSearch] = useState(filters.q ?? '');
    const searchRef = useRef<HTMLInputElement>(null);
    const { ctx, open: openCtx, close: closeCtx } = useContextMenu();

    useEffect(() => {
        const t = setTimeout(() => {
            if (search === (filters.q ?? '')) return;
            router.get('/hr/compliance/vetting', { q: search || undefined, status: filters.status || undefined }, { preserveState: true, preserveScroll: true, replace: true });
        }, 350);
        return () => clearTimeout(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const post = (url: string, msg: string) =>
        router.post(url, {}, { preserveScroll: true, onSuccess: () => toast.success(msg), onError: () => toast.error('Action failed.') });

    const rowMenu = (c: Check): CtxItem[] => [
        { icon: ShieldCheck, label: 'Open', onClick: () => router.visit(`/hr/compliance/vetting/${c.id}`) },
        ...(can.manage
            ? [
                  { icon: Pencil, label: 'Edit', onClick: () => router.visit(`/hr/compliance/vetting/${c.id}/edit`) },
                  { icon: CheckCircle2, label: 'Mark cleared', tone: 'success' as const, onClick: () => post(`/hr/compliance/vetting/${c.id}/clear`, 'Marked cleared.') },
                  { icon: Clock, label: 'Request renewal', onClick: () => post(`/hr/compliance/vetting/${c.id}/renew`, 'Renewal requested.') },
                  {
                      icon: FileText,
                      label: 'Record consent',
                      onClick: () =>
                          router.post(`/hr/compliance/vetting/${c.id}/consent`, { consent_given: true }, { preserveScroll: true, onSuccess: () => toast.success('Consent recorded.') }),
                  },
              ]
            : []),
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Vetting register" />
            <div className="space-y-4 px-4 py-4 lg:px-6">
                <ComplianceHubHeader hero={hero} active="vetting" can={{ manage: can.compliance_manage, vetting: can.manage, driver: can.driver_manage }} onWizard={(type) => setWz({ type })} />

                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    <StatTile label="Total" value={summary.total} icon={UserCheck} tone="info" />
                    <StatTile label="Clear" value={summary.clear} icon={CheckCircle2} tone="success" />
                    <StatTile label="Pending" value={summary.pending} icon={Clock} tone="warning" />
                    <StatTile label="Flagged" value={summary.flagged} icon={ShieldCheck} tone="critical" />
                    <StatTile label="Expired" value={summary.expired} icon={ShieldCheck} tone="critical" />
                </div>

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="text-[12.5px] text-muted-foreground">Police vetting · MOJ criminal record · Children's Act safety checks</p>
                    <div className="flex gap-2">
                        <Button variant="outline" onClick={() => (window.location.href = '/hr/compliance/export?dataset=vetting')}>
                            <Download className="h-4 w-4" /> Export
                        </Button>
                        {can.manage && (
                            <Button onClick={() => setWz({ type: 'vetting' })}>
                                <Plus className="h-4 w-4" /> Add vetting check
                            </Button>
                        )}
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-2.5 rounded-xl border border-border bg-card p-2.5">
                    <div className="relative min-w-[220px] flex-1">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <input
                            ref={searchRef}
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search staff…"
                            className="h-9 w-full rounded-lg border border-border bg-background pl-9 pr-3 text-sm outline-none focus:ring-2 focus:ring-primary"
                        />
                    </div>
                    <Select value={filters.status || 'all'} onValueChange={(v) => router.get('/hr/compliance/vetting', { status: v === 'all' ? undefined : v, q: filters.q || undefined }, { preserveScroll: true, preserveState: true })}>
                        <SelectTrigger className="h-9 w-[160px]" aria-label="Status filter">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All statuses</SelectItem>
                            <SelectItem value="clear">Clear</SelectItem>
                            <SelectItem value="pending">Pending</SelectItem>
                            <SelectItem value="flagged">Flagged</SelectItem>
                            <SelectItem value="expired">Expired</SelectItem>
                            <SelectItem value="renewal_due">Renewal due</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div className="overflow-hidden rounded-xl border border-border bg-card">
                    <table className="w-full text-[13px]">
                        <thead>
                            <tr className="border-b border-border bg-muted text-left text-muted-foreground">
                                <th className="px-3 py-3 font-semibold">Staff member</th>
                                <th className="px-3 py-3 font-semibold">Check type</th>
                                <th className="px-3 py-3 font-semibold">Provider</th>
                                <th className="px-3 py-3 font-semibold">Reference</th>
                                <th className="px-3 py-3 font-semibold">Status</th>
                                <th className="px-3 py-3 font-semibold">Expires</th>
                                <th className="w-10" />
                            </tr>
                        </thead>
                        <tbody>
                            {checks.data.length === 0 ? (
                                <tr>
                                    <td colSpan={7} className="px-4 py-12 text-center text-muted-foreground">
                                        <UserCheck className="mx-auto mb-2 h-8 w-8 opacity-40" />
                                        No vetting checks recorded.
                                    </td>
                                </tr>
                            ) : (
                                checks.data.map((c) => (
                                    <tr key={c.id} onContextMenu={(e) => openCtx(e, rowMenu(c))} className="border-b border-border last:border-0 hover:bg-muted/60">
                                        <td className="px-3 py-2.5">
                                            <div className="flex items-center gap-2.5">
                                                <AvatarBubble name={c.user?.name ?? '?'} size={30} />
                                                <Link href={`/hr/compliance/vetting/${c.id}`} className="font-semibold text-primary hover:underline">
                                                    {c.user?.name ?? 'Unknown'}
                                                </Link>
                                            </div>
                                        </td>
                                        <td className="px-3 py-2.5">{c.check_type.replace(/_/g, ' ')}</td>
                                        <td className="px-3 py-2.5 text-muted-foreground">{c.provider ?? '—'}</td>
                                        <td className="px-3 py-2.5 font-mono text-xs text-muted-foreground">{c.reference_number ?? '—'}</td>
                                        <td className="px-3 py-2.5">
                                            <StatusBadge variant={statusVariant(c.status)}>{c.status.replace(/_/g, ' ')}</StatusBadge>
                                        </td>
                                        <td className="px-3 py-2.5 text-muted-foreground">{fmtDate(c.expires_at)}</td>
                                        <td className="px-3 py-2.5 text-right">
                                            <button onClick={(e) => openCtx(e, rowMenu(c))} aria-label="Check actions" className="grid h-7 w-7 place-items-center rounded-md text-muted-foreground hover:bg-accent">
                                                <MoreVertical className="h-4 w-4" />
                                            </button>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                    <div className="flex items-center justify-between border-t border-border px-4 py-3 text-[12.5px] text-muted-foreground">
                        <span>Showing {checks.from ?? 0}–{checks.to ?? 0} of {checks.total}</span>
                        {checks.last_page > 1 && <LaravelPagination links={checks.links} />}
                    </div>
                </div>
            </div>

            <ComplianceContextMenu ctx={ctx} onClose={closeCtx} />
            <ComplianceWizards state={wz} onClose={() => setWz(null)} people={wizard.people} requirements={wizard.requirements} roles={wizard.roles} siteTypes={wizard.siteTypes} />
        </AppLayout>
    );
}
