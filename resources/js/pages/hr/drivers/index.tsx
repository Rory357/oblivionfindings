import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { StatTile } from '@/components/page/stat-tile';
import { Button } from '@/components/ui/button';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
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
import { Ban, Car, CheckCircle2, Clock, Download, FileText, MoreVertical, Pencil, Plus, Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import {
    AvatarBubble,
    ComplianceContextMenu,
    DRIVER_BADGE,
    useContextMenu,
    type CtxItem,
} from '@/pages/hr/compliance/components/compliance-bits';
import { ComplianceHubHeader, type HeroPayload } from '@/pages/hr/compliance/components/compliance-hub-header';
import { ComplianceWizards, type ReqOption, type RoleOption, type WizardState } from '@/pages/hr/compliance/components/compliance-wizards';
import type { PersonOption } from '@/components/hr/people-picker';
import { Card as GuardrailCard } from '@/components/ui/card';

interface DriverRecord {
    id: number;
    user?: { id: number; name: string };
    licence_class: string | null;
    licence_number: string | null;
    licence_endorsements: string[] | null;
    licence_expires_at: string | null;
    status: string;
    can_drive_clients: boolean;
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
    records: Paginator<DriverRecord>;
    summary: { total: number; eligible: number; expiring: number; suspended: number; pending: number };
    wizard: { people: PersonOption[]; requirements: ReqOption[]; roles: RoleOption[]; siteTypes: string[] };
    filters: { status: string | null; q: string };
    can: { manage: boolean; compliance_manage: boolean; vetting_manage: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Staff compliance', href: '/hr/compliance' },
    { title: 'Drivers', href: '/hr/compliance/drivers' },
];

function fmtDate(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' });
}
const isExpired = (iso: string | null) => !!iso && new Date(iso).getTime() < Date.now();

export default function DriversIndex({ hero, records, summary, wizard, filters, can }: Props) {
    const [wz, setWz] = useState<WizardState>(null);
    const [search, setSearch] = useState(filters.q ?? '');
    const [suspend, setSuspend] = useState<DriverRecord | null>(null);
    const [reason, setReason] = useState('');
    const searchRef = useRef<HTMLInputElement>(null);
    const { ctx, open: openCtx, close: closeCtx } = useContextMenu();

    useEffect(() => {
        const t = setTimeout(() => {
            if (search === (filters.q ?? '')) return;
            router.get('/hr/compliance/drivers', { q: search || undefined, status: filters.status || undefined }, { preserveState: true, preserveScroll: true, replace: true });
        }, 350);
        return () => clearTimeout(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const approve = (d: DriverRecord) =>
        router.post(`/hr/compliance/drivers/${d.id}/approve`, {}, { preserveScroll: true, onSuccess: () => toast.success(`${d.user?.name} approved.`), onError: () => toast.error('Could not approve.') });

    const doSuspend = () => {
        if (!suspend) return;
        router.post(`/hr/compliance/drivers/${suspend.id}/suspend`, { suspension_reason: reason }, {
            preserveScroll: true,
            onSuccess: () => toast.success('Driving privileges suspended.'),
            onError: () => toast.error('Could not suspend.'),
        });
        setSuspend(null);
        setReason('');
    };

    const rowMenu = (d: DriverRecord): CtxItem[] => [
        { icon: FileText, label: 'Open', onClick: () => router.visit(`/hr/compliance/drivers/${d.id}`) },
        ...(can.manage
            ? [
                  { icon: Pencil, label: 'Edit', onClick: () => router.visit(`/hr/compliance/drivers/${d.id}`) },
                  { icon: CheckCircle2, label: 'Approve', tone: 'success' as const, onClick: () => approve(d) },
                  { icon: Ban, label: 'Suspend', tone: 'critical' as const, onClick: () => setSuspend(d) },
              ]
            : []),
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Driver register" />
            <div className="space-y-4 px-4 py-4 lg:px-6">
                <ComplianceHubHeader hero={hero} active="drivers" can={{ manage: can.compliance_manage, vetting: can.vetting_manage, driver: can.manage }} onWizard={(type) => setWz({ type })} />

                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    <StatTile label="Total" value={summary.total} icon={Car} tone="info" />
                    <StatTile label="Eligible" value={summary.eligible} icon={CheckCircle2} tone="success" />
                    <StatTile label="Pending" value={summary.pending} icon={Clock} tone="warning" />
                    <StatTile label="Suspended" value={summary.suspended} icon={Ban} tone="critical" />
                    <StatTile label="Expiring" value={summary.expiring} icon={Clock} tone="warning" />
                </div>

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="text-[12.5px] text-muted-foreground">NZTA licence classes &amp; endorsements · shift-eligibility hard-stop</p>
                    <div className="flex gap-2">
                        <Button variant="outline" onClick={() => (window.location.href = '/hr/compliance/export?dataset=drivers')}>
                            <Download className="h-4 w-4" /> Export
                        </Button>
                        {can.manage && (
                            <Button onClick={() => setWz({ type: 'driver' })}>
                                <Plus className="h-4 w-4" /> Add driver
                            </Button>
                        )}
                    </div>
                </div>

                <GuardrailCard unstyled className="flex flex-wrap items-center gap-2.5 rounded-xl border border-border bg-card p-2.5">
                    <div className="relative min-w-[220px] flex-1">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <input
                            ref={searchRef}
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search drivers…"
                            className="h-9 w-full rounded-lg border border-border bg-background pl-9 pr-3 text-sm outline-none focus:ring-2 focus:ring-primary"
                        />
                    </div>
                    <Select value={filters.status || 'all'} onValueChange={(v) => router.get('/hr/compliance/drivers', { status: v === 'all' ? undefined : v, q: filters.q || undefined }, { preserveScroll: true, preserveState: true })}>
                        <SelectTrigger className="h-9 w-[160px]" aria-label="Status filter">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All statuses</SelectItem>
                            <SelectItem value="eligible">Eligible</SelectItem>
                            <SelectItem value="pending_review">Pending</SelectItem>
                            <SelectItem value="suspended">Suspended</SelectItem>
                            <SelectItem value="expiring">Expiring</SelectItem>
                        </SelectContent>
                    </Select>
                </GuardrailCard>

                <GuardrailCard unstyled className="overflow-hidden rounded-xl border border-border bg-card">
                    <table className="w-full text-[13px]">
                        <thead>
                            <tr className="border-b border-border bg-muted text-left text-muted-foreground">
                                <th className="px-3 py-3 font-semibold">Driver</th>
                                <th className="px-3 py-3 font-semibold">Licence</th>
                                <th className="px-3 py-3 font-semibold">Endorsements</th>
                                <th className="px-3 py-3 font-semibold">Status</th>
                                <th className="px-3 py-3 font-semibold">Expires</th>
                                <th className="w-10" />
                            </tr>
                        </thead>
                        <tbody>
                            {records.data.length === 0 ? (
                                <tr>
                                    <td colSpan={6} className="px-4 py-12 text-center text-muted-foreground">
                                        <Car className="mx-auto mb-2 h-8 w-8 opacity-40" />
                                        No driver records.
                                    </td>
                                </tr>
                            ) : (
                                records.data.map((d) => {
                                    const expired = isExpired(d.licence_expires_at);
                                    const statusKey = expired ? 'expired' : d.status;
                                    const badge = DRIVER_BADGE[statusKey] ?? DRIVER_BADGE.none;
                                    return (
                                        <tr key={d.id} onContextMenu={(e) => openCtx(e, rowMenu(d))} className="border-b border-border last:border-0 hover:bg-muted/60">
                                            <td className="px-3 py-2.5">
                                                <div className="flex items-center gap-2.5">
                                                    <AvatarBubble name={d.user?.name ?? '?'} size={30} />
                                                    <Link href={`/hr/compliance/drivers/${d.id}`} className="font-semibold text-primary hover:underline">
                                                        {d.user?.name ?? 'Unknown'}
                                                    </Link>
                                                </div>
                                            </td>
                                            <td className="px-3 py-2.5">
                                                <div className="font-semibold">Class {d.licence_class ?? '—'}</div>
                                                <div className="font-mono text-[11px] text-muted-foreground">{d.licence_number ?? '—'}</div>
                                            </td>
                                            <td className="px-3 py-2.5">
                                                {d.licence_endorsements && d.licence_endorsements.length > 0 ? (
                                                    <span className="flex flex-wrap gap-1">
                                                        {d.licence_endorsements.map((e) => (
                                                            <span key={e} className="inline-flex h-[22px] min-w-[22px] items-center justify-center rounded-md bg-accent px-1.5 text-[11px] font-bold text-primary">
                                                                {e}
                                                            </span>
                                                        ))}
                                                    </span>
                                                ) : (
                                                    <span className="text-muted-foreground">—</span>
                                                )}
                                            </td>
                                            <td className="px-3 py-2.5">
                                                <StatusBadge variant={badge.variant}>{badge.label}</StatusBadge>
                                            </td>
                                            <td className="px-3 py-2.5">
                                                <span className={expired ? 'font-semibold text-status-critical' : 'text-muted-foreground'}>{fmtDate(d.licence_expires_at)}</span>
                                            </td>
                                            <td className="px-3 py-2.5 text-right">
                                                <Button unstyled onClick={(e) => openCtx(e, rowMenu(d))} aria-label="Driver actions" className="grid h-7 w-7 place-items-center rounded-md text-muted-foreground hover:bg-accent">
                                                    <MoreVertical className="h-4 w-4" />
                                                </Button>
                                            </td>
                                        </tr>
                                    );
                                })
                            )}
                        </tbody>
                    </table>
                    <div className="flex items-center justify-between border-t border-border px-4 py-3 text-[12.5px] text-muted-foreground">
                        <span>Showing {records.from ?? 0}–{records.to ?? 0} of {records.total}</span>
                        {records.last_page > 1 && <LaravelPagination links={records.links} />}
                    </div>
                </GuardrailCard>
            </div>

            <AlertDialog open={!!suspend} onOpenChange={(o) => !o && setSuspend(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Suspend {suspend?.user?.name}?</AlertDialogTitle>
                        <AlertDialogDescription>Record a reason. This removes the driver's client-transport eligibility.</AlertDialogDescription>
                    </AlertDialogHeader>
                    <Textarea value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Reason for suspension…" className="min-h-[88px]" />
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <Button variant="destructive" disabled={!reason.trim()} onClick={doSuspend}>
                            Suspend
                        </Button>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <ComplianceContextMenu ctx={ctx} onClose={closeCtx} />
            <ComplianceWizards state={wz} onClose={() => setWz(null)} people={wizard.people} requirements={wizard.requirements} roles={wizard.roles} siteTypes={wizard.siteTypes} />
        </AppLayout>
    );
}
