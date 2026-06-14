import AttentionBar from '@/components/emar/mar/attention-bar';
import ClinicalRail from '@/components/emar/mar/clinical-rail';
import MarGrid, { type MarGridMed } from '@/components/emar/mar/mar-grid';
import PrnCard from '@/components/emar/mar/prn-card';
import { PageHero, type PageHeroBadge, type PageHeroMetaItem, type PageHeroStat } from '@/components/page';
import { EntityFilter, TabStrip, type RosterTabItem } from '@/components/rostering';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { addDays, DayPickerChip, toYmd } from '@/components/meds/day-picker-chip';
import { PrnWizard } from '@/pages/meds/today/components/prn-wizard';
import { RecordDoseWizard } from '@/pages/meds/today/components/record-dose-wizard';
import type { ClientInfo, NotGivenReasonOption, PrnMedication, ScheduleRow, WitnessOption } from '@/pages/meds/today/types';
import MarGovernanceDialogs, { type MarModal } from '@/pages/emar/components/mar-governance-dialogs';
import { Head, router } from '@inertiajs/react';
import { CalendarDays, FileDown, HeartPulse, Home, Pill, Plus, Shield, User } from 'lucide-react';
import { useMemo, useState } from 'react';

type Client = { id: number; first_name: string; last_name: string };

type MarData = {
    scheduled: Array<{
        id: number;
        name: string;
        dosage: string;
        frequency: string;
        route: string | null;
        instructions: string | null;
        controlled_drug: boolean;
        high_risk: boolean;
        witness_required: boolean;
        admin_rules?: { required_observations?: string[] | null } | null;
        dose_times: string[];
    }>;
    attention_alerts?: Array<{ id: number; type: string; title: string; detail?: string | null; prompt_on_open: boolean }>;
    inr_records?: Array<{
        id: number;
        medication_name?: string | null;
        inr_value: string | number;
        tested_on?: string | null;
        next_test_date?: string | null;
        target_range_min?: string | number | null;
        target_range_max?: string | number | null;
        medication_dose?: string | null;
        disabled_at?: string | null;
    }>;
    syringe_drivers?: Array<{ id: number; status: string; rate?: string | null; rate_unit?: string | null; site_of_insertion?: string | null }>;
    awaiting_verification?: Array<{ id: number; name: string; dosage: string }>;
    settings?: { care_level?: string | null; next_chart_review_date?: string | null };
};

type Props = {
    clients: Client[];
    selectedClient: { id: number; first_name: string; last_name: string } | null;
    selected_client_info: ClientInfo | null;
    marData: MarData;
    date: string;
    schedule: ScheduleRow[];
    prn_medications: PrnMedication[];
    witnesses: WitnessOption[];
    not_given_reasons: NotGivenReasonOption[];
    board_user: { name: string; role_label: string | null; med_competent: boolean; cd_witness: boolean };
    site_brand_colour: string | null;
    allergies: Array<{ id: number; allergen: string; severity?: string | null }>;
    clientContext: {
        profile: { gp_name?: string | null } | null;
        conditions: Array<{ id: number; label: string; severity?: string | null }>;
        emergency_contacts: Array<{ id: number; name: string; relationship?: string | null; phone?: string | null }>;
    } | null;
    pendingCorrections: Array<{ id: number }>;
    can: {
        record: boolean;
        verify_orders?: boolean;
        manage_inr?: boolean;
        manage_syringe_drivers?: boolean;
        manage_settings?: boolean;
        export_reports: boolean;
    };
};

const TABS: RosterTabItem[] = [
    { id: 'schedule', label: 'Schedule', icon: Pill, tone: 'primary' },
    { id: 'due', label: 'Due / overdue', icon: CalendarDays, tone: 'critical' },
    { id: 'prn', label: 'PRN', icon: Plus, tone: 'warning' },
    { id: 'history', label: 'History', icon: Shield, tone: 'info' },
];

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]!.toUpperCase())
        .join('');
}

export default function MarCharts(props: Props) {
    const {
        clients,
        selected_client_info: info,
        marData,
        date,
        schedule,
        prn_medications: prn,
        witnesses,
        not_given_reasons: notGivenReasons,
        board_user: signer,
        site_brand_colour: brandColour,
        allergies,
        clientContext,
        pendingCorrections,
        can,
    } = props;

    const [activeTab, setActiveTab] = useState('schedule');
    const [search, setSearch] = useState('');
    const [recordRow, setRecordRow] = useState<ScheduleRow | null>(null);
    const [prnMedId, setPrnMedId] = useState<number | null>(null);
    const [modal, setModal] = useState<MarModal>(null);

    const isToday = date === toYmd(new Date());
    const signedAs = { name: signer.name, role_label: signer.role_label };

    const goDate = (next: string) => {
        if (!info) return;
        router.get('/emar/mar', { client_id: info.id, date: next }, { preserveState: true, preserveScroll: true });
    };

    const switchClient = (clientId: number | null) => {
        if (!clientId) return;
        router.get('/emar/mar', { client_id: clientId, date }, { preserveState: true });
    };

    // Build the grid medication rows (rich metadata) keyed to the flat schedule.
    // `marData` is an empty array (not an object) when no resident is selected,
    // so guard the access — these hooks run before the no-client early return.
    const gridMeds: MarGridMed[] = useMemo(
        () =>
            (marData.scheduled ?? []).map((med) => ({
                id: med.id,
                name: med.name,
                dosage: med.dosage,
                route: med.route,
                frequency: med.frequency,
                instructions: med.instructions,
                controlled_drug: med.controlled_drug,
                high_risk: med.high_risk,
                witness_required: med.witness_required,
                is_inr: /warfarin/i.test(med.name),
                requires_observation: (med.admin_rules?.required_observations?.length ?? 0) > 0,
                dose_times: med.dose_times ?? [],
            })),
        [marData.scheduled],
    );

    const searched = useMemo(
        () => (search ? gridMeds.filter((m) => m.name.toLowerCase().includes(search.toLowerCase())) : gridMeds),
        [gridMeds, search],
    );

    const dueMedIds = useMemo(() => {
        const ids = new Set<number>();
        for (const row of schedule) {
            if (row.status === 'due' || row.status === 'overdue') ids.add(row.medication_id);
        }
        return ids;
    }, [schedule]);

    const visibleMeds = activeTab === 'due' ? searched.filter((m) => dueMedIds.has(m.id)) : searched;

    // Live counts from the flat schedule.
    const counts = useMemo(() => {
        let recorded = 0;
        let due = 0;
        let overdue = 0;
        let cdDue = 0;
        for (const row of schedule) {
            if (row.recorded) recorded += 1;
            if (row.status === 'due') due += 1;
            if (row.status === 'overdue') overdue += 1;
            if ((row.status === 'due' || row.status === 'overdue') && row.is_controlled) cdDue += 1;
        }
        const total = schedule.length;
        return {
            recorded,
            total,
            due,
            overdue,
            cdDue,
            pct: total ? Math.round((recorded / total) * 100) : 0,
            prnGiven: prn.reduce((sum, p) => sum + p.given_last_24h, 0),
        };
    }, [schedule, prn]);

    const latestInr = (marData.inr_records ?? []).find((r) => !r.disabled_at) ?? (marData.inr_records ?? [])[0] ?? null;
    const awaitingCount = marData.awaiting_verification?.length ?? 0;

    const onRecord = (row: ScheduleRow) => setRecordRow(row);
    const onGivePrn = (med: PrnMedication) => setPrnMedId(med.id);

    // ── No resident selected: prompt to pick one ───────────────────────────
    if (!info) {
        return (
            <AppLayout breadcrumbs={[{ title: 'eMAR', href: '/emar' }, { title: 'MAR Charts', href: '/emar/mar' }]}>
                <Head title="MAR Charts" />
                <div className="flex flex-col gap-6 p-6">
                    <PageHero
                        variant="hero"
                        category="ops"
                        icon={Pill}
                        title="MAR Charts"
                        description="Select a resident to open their medication administration record."
                    />
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {clients.map((client) => (
                            // eslint-disable-next-line no-restricted-syntax -- resident picker card (custom layout, not a <Button>)
                            <button
                                key={client.id}
                                type="button"
                                onClick={() => switchClient(client.id)}
                                className="flex items-center gap-3 rounded-xl border bg-card p-4 text-left transition hover:border-primary/40 hover:shadow-sm"
                            >
                                <span className="flex h-10 w-10 items-center justify-center rounded-full bg-primary/10 text-sm font-bold text-primary">
                                    {initials(`${client.first_name} ${client.last_name}`)}
                                </span>
                                <span className="font-medium">
                                    {client.first_name} {client.last_name}
                                </span>
                            </button>
                        ))}
                    </div>
                </div>
            </AppLayout>
        );
    }

    const heroMeta: PageHeroMetaItem[] = [
        info.nhi ? { icon: User, label: `NHI ${info.nhi}` } : null,
        info.dob ? { icon: CalendarDays, label: `${info.dob}${info.age != null ? ` (${info.age})` : ''}` } : null,
        clientContext?.profile?.gp_name ? { icon: HeartPulse, label: clientContext.profile.gp_name } : null,
        info.site_name ? { icon: Home, label: info.site_name } : null,
        marData.settings?.care_level ? { icon: Shield, label: marData.settings.care_level } : null,
    ].filter(Boolean) as PageHeroMetaItem[];

    const heroBadges: PageHeroBadge[] = [
        counts.overdue > 0 ? { tone: 'critical' as const, label: `${counts.overdue} overdue` } : null,
        counts.cdDue > 0 ? { tone: 'warning' as const, label: `${counts.cdDue} controlled · witness` } : null,
        latestInr ? { tone: 'info' as const, label: `Warfarin · INR ${latestInr.inr_value}` } : null,
        (marData.attention_alerts ?? []).some((a) => a.type === 'paper_prescription')
            ? { label: 'Paper prescription on file' }
            : null,
    ].filter(Boolean) as PageHeroBadge[];

    const heroStats: PageHeroStat[] = [
        { label: 'Recorded', value: `${counts.pct}%` },
        { label: 'Due now', value: counts.due, tone: counts.due > 0 ? 'warning' : 'neutral' },
        { label: 'Overdue', value: counts.overdue, tone: counts.overdue > 0 ? 'critical' : 'neutral' },
        { label: 'PRN today', value: counts.prnGiven },
    ];

    const heroFooter = (
        <div className="flex flex-col items-stretch gap-2 py-3 md:flex-row md:items-center md:justify-between">
            <div className="flex flex-wrap items-center gap-1.5">
                <Button
                    variant="outline"
                    size="sm"
                    className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20"
                    onClick={() => goDate(addDays(date, -1))}
                >
                    Prev
                </Button>
                <DayPickerChip date={date} isToday={isToday} onPick={goDate} />
                <Button
                    variant="outline"
                    size="sm"
                    className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20"
                    onClick={() => goDate(addDays(date, 1))}
                >
                    Next
                </Button>
                {!isToday && (
                    <Button
                        variant="ghost"
                        size="sm"
                        className="text-primary-foreground hover:bg-primary-foreground/10"
                        onClick={() => goDate(toYmd(new Date()))}
                    >
                        Back to today
                    </Button>
                )}
            </div>
            <div className="flex flex-wrap items-center gap-2 md:ml-auto">
                <input
                    type="text"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder="Search medication…"
                    className="h-9 w-44 rounded-full border border-primary-foreground/30 bg-primary-foreground/10 px-3 text-sm text-primary-foreground placeholder:text-primary-foreground/60 focus:outline-none focus:ring-2 focus:ring-primary-foreground/40"
                />
                <EntityFilter
                    label="Resident"
                    allLabel="All residents"
                    items={clients.map((c) => ({ id: c.id, name: `${c.first_name} ${c.last_name}` }))}
                    value={info.id}
                    onChange={switchClient}
                    onDark
                />
            </div>
        </div>
    );

    return (
        <AppLayout breadcrumbs={[{ title: 'eMAR', href: '/emar' }, { title: 'MAR Charts', href: '/emar/mar' }]}>
            <Head title={`MAR · ${info.name}`} />
            <div className="flex flex-col gap-6 p-6">
                <PageHero
                    variant="hero"
                    category="ops"
                    brandColour={brandColour}
                    avatar={{ fallback: initials(info.name) }}
                    title={
                        <span>
                            <span className="flex items-center gap-2 text-[10.5px] font-semibold uppercase tracking-wide text-primary-foreground/80">
                                {isToday ? (
                                    <span aria-hidden className="relative inline-flex h-2 w-2">
                                        <span className="absolute inset-0 animate-ping rounded-full bg-status-success/70" />
                                        <span className="relative inline-flex h-2 w-2 rounded-full bg-status-success" />
                                    </span>
                                ) : (
                                    <CalendarDays className="h-3 w-3" />
                                )}
                                {isToday ? 'Live medication chart' : 'Medication chart'}
                            </span>
                            <span className="mt-1 block text-[28px] font-bold leading-tight">{info.name}</span>
                        </span>
                    }
                    description={
                        <span>
                            Medication administration record for{' '}
                            <span className="border-b-2 border-primary-foreground/40 font-medium">{date}</span>
                        </span>
                    }
                    meta={heroMeta}
                    badges={heroBadges}
                    stats={heroStats}
                    actions={
                        <>
                            <Button asChild className="bg-primary-foreground text-primary hover:bg-primary-foreground/90">
                                <a href="/emar/rounds">Start medication round</a>
                            </Button>
                            {can.record && (
                                <Button
                                    variant="outline"
                                    className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20"
                                    onClick={() => setModal('addMed')}
                                >
                                    <Plus className="h-4 w-4" />
                                    Add medication
                                </Button>
                            )}
                            {can.export_reports && (
                                <Button
                                    asChild
                                    variant="outline"
                                    className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20"
                                >
                                    <a href={`/emar/pdf/mar-chart?client_id=${info.id}&date_from=${date}&date_to=${date}`} target="_blank" rel="noreferrer">
                                        <FileDown className="h-4 w-4" />
                                        PDF
                                    </a>
                                </Button>
                            )}
                        </>
                    }
                    footer={heroFooter}
                />

                <AttentionBar
                    alerts={marData.attention_alerts ?? []}
                    onReview={() => setModal('warnings')}
                    onManage={() => setModal('alerts')}
                    canManage={!!can.manage_settings}
                />

                <TabStrip value={activeTab} onChange={setActiveTab} items={TABS} ariaLabel="MAR chart views" />

                <div className="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_372px]">
                    <div className="flex min-w-0 flex-col gap-6">
                        {activeTab === 'history' ? (
                            <HistoryList schedule={schedule} />
                        ) : activeTab === 'prn' ? (
                            <PrnCard prn={prn} canRecord={can.record} onGive={onGivePrn} />
                        ) : (
                            <>
                                <MarGrid
                                    meds={visibleMeds}
                                    schedule={schedule}
                                    onRecord={onRecord}
                                    onContext={(e, row) => {
                                        // Right-click opens the full record wizard (safe default —
                                        // CD witness is never skipped). One-click quick-actions are
                                        // a documented follow-up enhancement.
                                        e.preventDefault();
                                        onRecord(row);
                                    }}
                                />
                                <PrnCard prn={prn} canRecord={can.record} onGive={onGivePrn} />
                            </>
                        )}
                    </div>

                    <ClinicalRail
                        inrRecords={marData.inr_records ?? []}
                        syringeDrivers={marData.syringe_drivers ?? []}
                        awaitingVerification={awaitingCount}
                        pendingCorrections={pendingCorrections.length}
                        chartReviewDate={marData.settings?.next_chart_review_date ?? null}
                        allergies={allergies}
                        conditions={clientContext?.conditions ?? []}
                        emergencyContacts={clientContext?.emergency_contacts ?? []}
                        can={{
                            manageInr: !!can.manage_inr,
                            manageSyringeDrivers: !!can.manage_syringe_drivers,
                            verifyOrders: !!can.verify_orders,
                        }}
                        onRecordInr={() => setModal('inr')}
                        onStartDriver={() => setModal('syringe')}
                        onVerifyOrders={() => setModal('verify')}
                    />
                </div>
            </div>

            {recordRow && (
                <RecordDoseWizard
                    row={recordRow}
                    client={info}
                    date={date}
                    witnesses={witnesses}
                    notGivenReasons={notGivenReasons}
                    signedAs={signedAs}
                    onClose={() => setRecordRow(null)}
                />
            )}

            {prnMedId !== null && (
                <PrnWizard
                    medications={prn}
                    clients={new Map([[info.id, info]])}
                    date={date}
                    witnesses={witnesses}
                    signedAs={signedAs}
                    initialMedId={prnMedId}
                    onClose={() => setPrnMedId(null)}
                />
            )}

            <MarGovernanceDialogs
                modal={modal}
                onClose={() => setModal(null)}
                clientId={info.id}
                attentionAlerts={marData.attention_alerts ?? []}
                awaitingVerification={marData.awaiting_verification ?? []}
                witnesses={witnesses}
            />
        </AppLayout>
    );
}

function HistoryList({ schedule }: { schedule: ScheduleRow[] }) {
    const recorded = schedule.filter((r) => r.recorded);
    return (
        <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
            <div className="border-b px-5 py-4 text-[15px] font-bold">Today&apos;s recorded administrations</div>
            {recorded.length === 0 ? (
                <div className="px-5 py-10 text-center text-sm text-muted-foreground">Nothing recorded yet.</div>
            ) : (
                <ul className="divide-y">
                    {recorded.map((row) => (
                        <li key={row.key} className="flex items-center justify-between px-5 py-3 text-sm">
                            <div>
                                <span className="font-medium">{row.medication_name}</span>
                                <span className="ml-2 text-xs text-muted-foreground">{row.time}</span>
                            </div>
                            <div className="flex items-center gap-3 text-xs">
                                <span className="font-medium capitalize">{row.recorded?.status}</span>
                                {row.recorded?.by && <span className="text-muted-foreground">{row.recorded.by}</span>}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
