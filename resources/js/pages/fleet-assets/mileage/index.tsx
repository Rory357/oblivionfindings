import { FleetEmptyState } from '@/components/fleet-empty-state';
import { FleetStatCard } from '@/components/fleet-stat-card';
import { HorizontalBarChart } from '@/components/fleet-charts';
import {
    FleetHeroAction,
    fmt,
    HeroClusterTile,
    HeroMedallion,
    HeroShell,
    HeroStatusPill,
} from '@/pages/fleet-assets/components/fleet-hero-kit';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/wizard/shell';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import {
    Car,
    Check,
    CheckCircle,
    ClipboardList,
    DollarSign,
    Download,
    FileCheck2,
    MapPin,
    Plus,
    Receipt,
    Route,
    Search,
    X,
} from 'lucide-react';
import { useState } from 'react';
import { formatCurrency, formatDate, formatDistance, statusColor } from '@/lib/fleet-utils';

type PersonalTrip = {
    id: number;
    user: { id: number; name: string } | null;
    date: string | null;
    start_location: string;
    end_location: string;
    distance_km: number;
    purpose: string;
    client: { id: number; name: string } | null;
    rate_per_km: number;
    total_amount: number;
    status: string;
    approved_by: { id: number; name: string } | null;
    approved_at: string | null;
    notes: string | null;
    created_at: string | null;
};

type StaffSummary = {
    label: string;
    value: number;
    amount: number;
    trips: number;
};

type Props = {
    trips: {
        data: PersonalTrip[];
        links: any[];
        meta: { current_page: number; last_page: number; total: number };
    };
    filters: {
        date_from?: string;
        date_to?: string;
        status?: string;
        user_id?: string;
    };
    staff: Array<{ id: number; name: string }>;
    stats: {
        trips_this_month: number;
        total_distance: number;
        total_reimbursement: number;
        pending_approval: number;
        approved_unpaid?: number;
        paid_this_month?: number;
        claims_30d?: number;
    };
    staff_summary: StaffSummary[];
    is_manager?: boolean;
    clients?: Array<{ id: number; name: string }>;
    ird_rate?: number;
    can?: {
        approve: boolean;
    };
};

const _PURPOSE_LABELS: Record<string, string> = {
    client_visit: 'Client Visit',
    meeting: 'Meeting',
    training: 'Training',
    admin: 'Admin',
    other: 'Other',
};

function statusBadge(status: string) {
    switch (status) {
        case 'pending':
            return <Badge className="bg-status-warning text-white">Pending</Badge>;
        case 'approved':
            return <Badge className="bg-primary text-white">Approved</Badge>;
        case 'rejected':
            return <Badge className="bg-status-critical text-white">Rejected</Badge>;
        case 'paid':
            return <Badge className="bg-status-success text-white">Paid</Badge>;
        default:
            return <Badge variant="secondary">{status}</Badge>;
    }
}

export default function MileageIndex({ trips, filters, staff, stats, staff_summary, is_manager, clients, ird_rate, can }: Props) {
    const { labels } = usePage().props as any;
    const clientSingular = labels?.['client.singular'] ?? 'Client';
    const canApprove = can?.approve ?? false;
    const PURPOSE_LABELS: Record<string, string> = {
        ..._PURPOSE_LABELS,
        client_visit: `${clientSingular} Visit`,
    };
    const safeData = trips?.data ?? [];
    const safeMeta = trips?.meta ?? { current_page: 1, last_page: 1, total: 0 };
    const safeStats = stats ?? { trips_this_month: 0, total_distance: 0, total_reimbursement: 0, pending_approval: 0 };
    const safeStaff = staff ?? [];
    const safeStaffSummary = staff_summary ?? [];
    const irdRate = ird_rate ?? 0.95;

    // /fleet-assets/mileage/create now redirects here with ?new=1 — open the wizard on mount.
    const [wizardOpen, setWizardOpen] = useState(
        () =>
            typeof window !== 'undefined' &&
            new URLSearchParams(window.location.search).has('new'),
    );

    const localDay = (offset = 0) => {
        const d = new Date();
        d.setDate(d.getDate() - offset);
        return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    };
    const last30Href = `/fleet-assets/mileage?date_from=${localDay(30)}`;

    const [localFilters, setLocalFilters] = useState({
        date_from: filters?.date_from ?? '',
        date_to: filters?.date_to ?? '',
        status: filters?.status ?? '',
        user_id: filters?.user_id ?? '',
    });

    const applyFilters = () => {
        const params: Record<string, string> = {};
        if (localFilters.date_from) params.date_from = localFilters.date_from;
        if (localFilters.date_to) params.date_to = localFilters.date_to;
        if (localFilters.status) params.status = localFilters.status;
        if (localFilters.user_id) params.user_id = localFilters.user_id;
        router.get('/fleet-assets/mileage', params, { preserveState: true });
    };

    const clearFilters = () => {
        setLocalFilters({ date_from: '', date_to: '', status: '', user_id: '' });
        router.get('/fleet-assets/mileage', {}, { preserveState: true });
    };

    const handleApprove = (tripId: number) => {
        router.post(`/fleet-assets/mileage/${tripId}/approve`, {}, { preserveScroll: true });
    };

    const handleReject = (tripId: number) => {
        router.post(`/fleet-assets/mileage/${tripId}/reject`, {}, { preserveScroll: true });
    };

    const handleMarkPaid = (tripId: number) => {
        router.post(`/fleet-assets/mileage/${tripId}/mark-paid`, {}, { preserveScroll: true });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Mileage Claims', href: '#' },
            ]}
        >
            <Head title="Mileage Claims" />
            <PageShell>
                <HeroShell>
                    <div className="flex flex-wrap items-center gap-4">
                        <HeroMedallion icon={Receipt} />
                        <div className="min-w-0">
                            <HeroStatusPill>Mileage claims · IRD ${irdRate.toFixed(2)}/km</HeroStatusPill>
                            <h1 className="mt-1.5 text-2xl font-bold tracking-tight">Staff Mileage Claims</h1>
                            <p className="mt-0.5 text-[13px] text-primary-foreground/75">
                                Personal vehicle mileage reimbursement claims. NZ IRD rate: ${irdRate.toFixed(2)}/km.
                            </p>
                        </div>
                        <div className="grid flex-1 grid-cols-2 gap-2 sm:grid-cols-4 lg:ml-auto lg:max-w-2xl">
                            <HeroClusterTile
                                href="/fleet-assets/mileage?status=pending"
                                label="Pending approval"
                                value={fmt(safeStats.pending_approval)}
                                caption="awaiting review"
                                tone={safeStats.pending_approval > 0 ? 'warning' : 'success'}
                            />
                            <HeroClusterTile
                                href="/fleet-assets/mileage?status=approved"
                                label="Approved unpaid"
                                value={fmt(safeStats.approved_unpaid ?? 0)}
                                caption="ready for payroll"
                                tone={(safeStats.approved_unpaid ?? 0) > 0 ? 'warning' : 'neutral'}
                            />
                            <HeroClusterTile
                                href="/fleet-assets/mileage?status=paid"
                                label="Paid this month"
                                value={formatCurrency(safeStats.paid_this_month ?? 0)}
                                caption="reimbursed"
                                tone="neutral"
                            />
                            <HeroClusterTile
                                href={last30Href}
                                label="Claims 30d"
                                value={fmt(safeStats.claims_30d ?? 0)}
                                caption="trips claimed"
                                tone="neutral"
                            />
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <FleetHeroAction icon={Plus} emphasis onClick={() => setWizardOpen(true)}>
                            New claim
                        </FleetHeroAction>
                        <FleetHeroAction
                            href={`/fleet-assets/mileage/export?${new URLSearchParams(localFilters as Record<string, string>).toString()}`}
                            icon={Download}
                            external
                        >
                            Export CSV
                        </FleetHeroAction>
                    </div>
                </HeroShell>

                {/* KPI Cards */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 mb-6">
                    <FleetStatCard
                        label="Trips This Month"
                        value={safeStats.trips_this_month}
                        icon={Car}
                        color="purple"
                        subtitle="Personal vehicle trips"
                    />
                    <FleetStatCard
                        label="Total Distance"
                        value={formatDistance(safeStats.total_distance)}
                        icon={Route}
                        color="blue"
                        subtitle="This month"
                    />
                    <FleetStatCard
                        label="Total Reimbursement"
                        value={formatCurrency(safeStats.total_reimbursement)}
                        icon={DollarSign}
                        color="purple"
                        subtitle="This month"
                    />
                </div>

                {/* Filters */}
                <Card className="mb-6">
                    <CardContent className="p-4">
                        <div className="flex flex-wrap items-end gap-3">
                            <div className="min-w-[140px]">
                                <label className="mb-1 block text-xs font-medium text-muted-foreground">From</label>
                                <Input
                                    type="date"
                                    value={localFilters.date_from}
                                    onChange={(e) => setLocalFilters((f) => ({ ...f, date_from: e.target.value }))}
                                />
                            </div>
                            <div className="min-w-[140px]">
                                <label className="mb-1 block text-xs font-medium text-muted-foreground">To</label>
                                <Input
                                    type="date"
                                    value={localFilters.date_to}
                                    onChange={(e) => setLocalFilters((f) => ({ ...f, date_to: e.target.value }))}
                                />
                            </div>
                            <div className="min-w-[140px]">
                                <label className="mb-1 block text-xs font-medium text-muted-foreground">Status</label>
                                <Select
                                    value={localFilters.status}
                                    onValueChange={(v) => setLocalFilters((f) => ({ ...f, status: v === 'all' ? '' : v }))}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="All Statuses" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All Statuses</SelectItem>
                                        <SelectItem value="pending">Pending</SelectItem>
                                        <SelectItem value="approved">Approved</SelectItem>
                                        <SelectItem value="rejected">Rejected</SelectItem>
                                        <SelectItem value="paid">Paid</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            {is_manager && safeStaff.length > 0 && (
                                <div className="min-w-[180px]">
                                    <label className="mb-1 block text-xs font-medium text-muted-foreground">Staff Member</label>
                                    <Select
                                        value={localFilters.user_id}
                                        onValueChange={(v) => setLocalFilters((f) => ({ ...f, user_id: v === 'all' ? '' : v }))}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="All Staff" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">All Staff</SelectItem>
                                            {safeStaff.map((s) => (
                                                <SelectItem key={s.id} value={String(s.id)}>
                                                    {s.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            )}
                            <Button size="sm" onClick={applyFilters}>
                                <Search className="mr-1.5 h-4 w-4" />
                                Filter
                            </Button>
                            <Button size="sm" variant="outline" onClick={clearFilters}>
                                Clear
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                <div className="grid gap-6 lg:grid-cols-3">
                    {/* Table */}
                    <div className="lg:col-span-2">
                        <Card>
                            <CardContent className="p-0">
                                {safeData.length === 0 ? (
                                    <FleetEmptyState
                                        icon={Car}
                                        title="No mileage claims"
                                        description="Log your first personal vehicle trip to get started."
                                        actionLabel="Log Personal Trip"
                                        onAction={() => setWizardOpen(true)}
                                    />
                                ) : (
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-sm">
                                            <thead>
                                                <tr className="border-b bg-muted/30">
                                                    <th className="px-4 py-3 text-left font-medium text-muted-foreground">Date</th>
                                                    {is_manager && <th className="px-4 py-3 text-left font-medium text-muted-foreground">Staff</th>}
                                                    <th className="px-4 py-3 text-left font-medium text-muted-foreground">Route</th>
                                                    <th className="px-4 py-3 text-right font-medium text-muted-foreground">Distance</th>
                                                    <th className="px-4 py-3 text-left font-medium text-muted-foreground">{clientSingular}</th>
                                                    <th className="px-4 py-3 text-right font-medium text-muted-foreground">Amount</th>
                                                    <th className="px-4 py-3 text-left font-medium text-muted-foreground">Status</th>
                                                    {canApprove && <th className="px-4 py-3 text-right font-medium text-muted-foreground">Actions</th>}
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y">
                                                {safeData.map((trip) => (
                                                    <tr key={trip.id} className="hover:bg-muted/20 transition-colors">
                                                        <td className="px-4 py-3 whitespace-nowrap text-xs">
                                                            {formatDate(trip.date)}
                                                        </td>
                                                        {is_manager && (
                                                            <td className="px-4 py-3">
                                                                <span className="font-medium text-xs">{trip.user?.name ?? '---'}</span>
                                                            </td>
                                                        )}
                                                        <td className="px-4 py-3">
                                                            <div className="flex items-center gap-1 text-xs">
                                                                <MapPin className="h-3 w-3 text-muted-foreground shrink-0" />
                                                                <span className="truncate max-w-[120px]">{trip.start_location}</span>
                                                                <span className="text-muted-foreground mx-1">&rarr;</span>
                                                                <span className="truncate max-w-[120px]">{trip.end_location}</span>
                                                            </div>
                                                            <span className="text-[10px] text-muted-foreground">
                                                                {PURPOSE_LABELS[trip.purpose] ?? trip.purpose}
                                                            </span>
                                                        </td>
                                                        <td className="px-4 py-3 text-right tabular-nums text-xs">
                                                            {formatDistance(trip.distance_km)}
                                                        </td>
                                                        <td className="px-4 py-3 text-xs">
                                                            {trip.client?.name ?? '---'}
                                                        </td>
                                                        <td className="px-4 py-3 text-right tabular-nums text-xs font-medium">
                                                            {formatCurrency(trip.total_amount)}
                                                        </td>
                                                        <td className="px-4 py-3">
                                                            {statusBadge(trip.status)}
                                                        </td>
                                                        {canApprove && (
                                                            <td className="px-4 py-3 text-right">
                                                                {trip.status === 'pending' && (
                                                                    <div className="flex items-center justify-end gap-1">
                                                                        <Button
                                                                            size="sm"
                                                                            variant="outline"
                                                                            onClick={() => handleApprove(trip.id)}
                                                                            className="text-xs h-7"
                                                                        >
                                                                            <Check className="mr-1 h-3 w-3" />
                                                                            Approve
                                                                        </Button>
                                                                        <Button
                                                                            size="sm"
                                                                            variant="outline"
                                                                            onClick={() => handleReject(trip.id)}
                                                                            className="text-xs h-7 text-status-critical hover:text-status-critical"
                                                                        >
                                                                            <X className="mr-1 h-3 w-3" />
                                                                            Reject
                                                                        </Button>
                                                                    </div>
                                                                )}
                                                                {trip.status === 'approved' && (
                                                                    <Button
                                                                        size="sm"
                                                                        variant="outline"
                                                                        onClick={() => handleMarkPaid(trip.id)}
                                                                        className="text-xs h-7 text-status-success hover:text-status-success"
                                                                    >
                                                                        <CheckCircle className="mr-1 h-3 w-3" />
                                                                        Mark Paid
                                                                    </Button>
                                                                )}
                                                            </td>
                                                        )}
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {/* Pagination */}
                        {safeMeta.last_page > 1 && (
                            <div className="mt-4 flex items-center justify-between text-sm text-muted-foreground">
                                <span>
                                    Page {safeMeta.current_page} of {safeMeta.last_page} ({safeMeta.total} total)
                                </span>
                                <div className="flex gap-1">
                                    {(trips?.links ?? []).map((link: any, i: number) => (
                                        <Button
                                            key={i}
                                            size="sm"
                                            variant={link.active ? 'default' : 'outline'}
                                            disabled={!link.url}
                                            onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                                            className="text-xs"
                                            dangerouslySetInnerHTML={{ __html: link.label }}
                                        />
                                    ))}
                                </div>
                            </div>
                        )}
                    </div>

                    {/* Staff Summary Sidebar */}
                    {is_manager && safeStaffSummary.length > 0 && (
                        <div>
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-sm">Staff Distance This Month</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <HorizontalBarChart
                                        items={safeStaffSummary.map((s) => ({
                                            label: s.label,
                                            value: s.value,
                                        }))}
                                    />
                                    <div className="mt-4 space-y-2">
                                        {safeStaffSummary.map((s, i) => (
                                            <div key={i} className="flex items-center justify-between text-xs">
                                                <span className="text-muted-foreground truncate max-w-[60%]">{s.label}</span>
                                                <span className="font-medium tabular-nums">{formatCurrency(s.amount)}</span>
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        </div>
                    )}
                </div>

                <MileageClaimWizard
                    open={wizardOpen}
                    onClose={() => setWizardOpen(false)}
                    clients={clients ?? []}
                    irdRate={irdRate}
                    purposeLabels={PURPOSE_LABELS}
                />
            </PageShell>
        </AppLayout>
    );
}

/* ------------------------------------------------------------------ */
/*  New-claim wizard (WizardShell modal — replaces the old create page) */
/* ------------------------------------------------------------------ */

const WIZARD_STEPS: WizardStep[] = [
    { key: 'trip', label: 'Trip details', blurb: 'Where and how far', icon: Route },
    { key: 'purpose', label: 'Purpose & links', blurb: 'Why you drove', icon: ClipboardList },
    { key: 'review', label: 'Review & submit', blurb: 'Check, then claim', icon: FileCheck2 },
];

function MileageClaimWizard({
    open,
    onClose,
    clients,
    irdRate,
    purposeLabels,
}: {
    open: boolean;
    onClose: () => void;
    clients: Array<{ id: number; name: string }>;
    irdRate: number;
    purposeLabels: Record<string, string>;
}) {
    const [stepIndex, setStepIndex] = useState(0);
    const [submitted, setSubmitted] = useState(false);

    const form = useForm({
        date: new Date().toISOString().slice(0, 10),
        start_location: '',
        end_location: '',
        distance_km: '',
        purpose: '',
        client_id: '',
        notes: '',
    });

    const distance = parseFloat(form.data.distance_km) || 0;
    const calculatedTotal = distance * irdRate;

    const tripValid =
        !!form.data.date &&
        form.data.start_location.trim() !== '' &&
        form.data.end_location.trim() !== '' &&
        distance > 0;
    const purposeValid = form.data.purpose !== '';
    const canContinue = stepIndex === 0 ? tripValid : stepIndex === 1 ? purposeValid : true;

    const resetAll = () => {
        form.reset();
        form.clearErrors();
        setStepIndex(0);
        setSubmitted(false);
    };

    const close = () => {
        onClose();
        resetAll();
    };

    const submit = () => {
        form.post('/fleet-assets/mileage', {
            preserveScroll: true,
            onSuccess: (page) => {
                // flash.error arrives via onSuccess in Inertia — only celebrate a clean redirect.
                const flash = (page.props as { flash?: { error?: string | null } }).flash;
                if (!flash?.error) setSubmitted(true);
            },
        });
    };

    const clientName = form.data.client_id
        ? (clients.find((c) => String(c.id) === form.data.client_id)?.name ?? '—')
        : null;

    const fieldError = (key: keyof typeof form.errors) =>
        form.errors[key] ? <p className="mt-1 text-xs text-destructive">{form.errors[key]}</p> : null;

    return (
        <WizardShell
            open={open}
            onClose={close}
            title="Log personal vehicle trip"
            description="Record a trip using your personal vehicle for mileage reimbursement."
            railIcon={Receipt}
            railTitle="Mileage claim"
            railSub={`NZ IRD rate $${irdRate.toFixed(2)}/km`}
            steps={WIZARD_STEPS}
            stepIndex={stepIndex}
            onStepClick={(i) => i < stepIndex && setStepIndex(i)}
            footerStart={
                <span className="text-xs text-muted-foreground">
                    {distance > 0 ? (
                        <>
                            {distance} km × ${irdRate.toFixed(2)} ={' '}
                            <span className="font-semibold text-foreground">{formatCurrency(calculatedTotal)}</span>
                        </>
                    ) : (
                        'Reimbursement is calculated from distance.'
                    )}
                </span>
            }
            footerEnd={
                submitted ? null : (
                    <>
                        {stepIndex > 0 && (
                            <Button variant="outline" onClick={() => setStepIndex(stepIndex - 1)}>
                                Back
                            </Button>
                        )}
                        {stepIndex < WIZARD_STEPS.length - 1 ? (
                            <Button onClick={() => setStepIndex(stepIndex + 1)} disabled={!canContinue}>
                                Continue
                            </Button>
                        ) : (
                            <Button onClick={submit} disabled={form.processing}>
                                {form.processing ? 'Submitting…' : 'Submit claim'}
                            </Button>
                        )}
                    </>
                )
            }
            success={
                submitted ? (
                    <WizardSuccessPane
                        title="Mileage claim submitted"
                        blurb="Your claim is now pending approval. You can track its status in the claims list."
                        actions={
                            <>
                                <Button variant="outline" onClick={resetAll}>
                                    Log another trip
                                </Button>
                                <Button onClick={close}>Done</Button>
                            </>
                        }
                    />
                ) : undefined
            }
        >
            {stepIndex === 0 && (
                <WizardStepPane>
                    <div className="grid gap-4 sm:max-w-lg">
                        <div className="grid gap-1.5">
                            <Label htmlFor="claim-date">Date *</Label>
                            <Input
                                id="claim-date"
                                type="date"
                                value={form.data.date}
                                onChange={(e) => form.setData('date', e.target.value)}
                            />
                            {fieldError('date')}
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="claim-start">Start location *</Label>
                            <Input
                                id="claim-start"
                                value={form.data.start_location}
                                onChange={(e) => form.setData('start_location', e.target.value)}
                                placeholder="e.g. Office, Home"
                            />
                            {fieldError('start_location')}
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="claim-end">End location *</Label>
                            <Input
                                id="claim-end"
                                value={form.data.end_location}
                                onChange={(e) => form.setData('end_location', e.target.value)}
                                placeholder="e.g. Client home, Meeting venue"
                            />
                            {fieldError('end_location')}
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="claim-distance">Distance (km) *</Label>
                            <Input
                                id="claim-distance"
                                type="number"
                                step="0.1"
                                min="0.1"
                                max="9999"
                                value={form.data.distance_km}
                                onChange={(e) => form.setData('distance_km', e.target.value)}
                                placeholder="0.0"
                            />
                            {fieldError('distance_km')}
                        </div>
                    </div>
                </WizardStepPane>
            )}

            {stepIndex === 1 && (
                <WizardStepPane>
                    <div className="grid gap-4 sm:max-w-lg">
                        <div className="grid gap-1.5">
                            <Label>Purpose *</Label>
                            <Select value={form.data.purpose} onValueChange={(v) => form.setData('purpose', v)}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Select purpose" />
                                </SelectTrigger>
                                <SelectContent>
                                    {Object.entries(purposeLabels).map(([value, label]) => (
                                        <SelectItem key={value} value={value}>
                                            {label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {fieldError('purpose')}
                        </div>
                        {clients.length > 0 && (
                            <div className="grid gap-1.5">
                                <Label>Link to client</Label>
                                <Select
                                    value={form.data.client_id || 'none'}
                                    onValueChange={(v) => form.setData('client_id', v === 'none' ? '' : v)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select client (optional)" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">No client</SelectItem>
                                        {clients.map((c) => (
                                            <SelectItem key={c.id} value={String(c.id)}>
                                                {c.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {fieldError('client_id')}
                            </div>
                        )}
                        <div className="grid gap-1.5">
                            <Label htmlFor="claim-notes">Notes</Label>
                            <textarea
                                id="claim-notes"
                                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                rows={3}
                                value={form.data.notes}
                                onChange={(e) => form.setData('notes', e.target.value)}
                                placeholder="Any additional details about this trip..."
                            />
                            {fieldError('notes')}
                        </div>
                    </div>
                </WizardStepPane>
            )}

            {stepIndex === 2 && (
                <WizardStepPane>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <ReviewCard icon={Route} title="Trip details" onEdit={() => setStepIndex(0)}>
                            <ReviewRow label="Date" value={form.data.date} />
                            <ReviewRow label="From" value={form.data.start_location} />
                            <ReviewRow label="To" value={form.data.end_location} />
                            <ReviewRow label="Distance" value={distance > 0 ? `${distance} km` : undefined} />
                        </ReviewCard>
                        <ReviewCard icon={ClipboardList} title="Purpose & links" onEdit={() => setStepIndex(1)}>
                            <ReviewRow label="Purpose" value={purposeLabels[form.data.purpose] ?? form.data.purpose} />
                            <ReviewRow label="Client" value={clientName ?? undefined} />
                            <ReviewRow label="Notes" value={form.data.notes || undefined} />
                        </ReviewCard>
                        <ReviewCard icon={DollarSign} title="Reimbursement" span>
                            <ReviewRow label="IRD rate" value={`$${irdRate.toFixed(2)}/km`} />
                            <ReviewRow label="Distance" value={`${distance} km`} />
                            <ReviewRow
                                label="Total"
                                value={<span className="font-bold text-primary">{formatCurrency(calculatedTotal)}</span>}
                            />
                        </ReviewCard>
                    </div>
                    {Object.keys(form.errors).length > 0 && (
                        <p className="mt-4 text-sm text-destructive">
                            Please fix the highlighted fields on the earlier steps before submitting.
                        </p>
                    )}
                </WizardStepPane>
            )}
        </WizardShell>
    );
}
