import { EmarHubRail } from '@/components/emar/emar-hub-rail';
import AttentionBar from '@/components/emar/mar/attention-bar';
import ClinicalRail, {
    type InrRecord,
} from '@/components/emar/mar/clinical-rail';
import DoseContextMenu, {
    type DoseCtxTarget,
} from '@/components/emar/mar/dose-context-menu';
import MarGrid, { type MarGridMed } from '@/components/emar/mar/mar-grid';
import PrnCard from '@/components/emar/mar/prn-card';
import {
    EmarMeters,
    EmarRecordTabs,
} from '@/components/emar/workspace-navigation';
import {
    addDays,
    DayPickerChip,
    toYmd,
} from '@/components/meds/day-picker-chip';
import { type PageHeroBadge, type PageHeroMetaItem } from '@/components/page';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageHeaderStatusChip,
} from '@/components/page/page-header';
import { EntityFilter, type RosterTabItem } from '@/components/rostering';
import { Button } from '@/components/ui/button';
import { useEmarBreadcrumbs } from '@/hooks/use-emar-breadcrumbs';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly } from '@/lib/datetime';
import MarGovernanceDialogs, {
    type ChartMedicationOption,
    type MarModal,
    type PendingCorrection,
} from '@/pages/emar/components/mar-governance-dialogs';
import { PrnWizard } from '@/pages/meds/today/components/prn-wizard';
import { RecordDoseWizard } from '@/pages/meds/today/components/record-dose-wizard';
import {
    awaitsOrderCheck,
    type ClientInfo,
    type NotGivenReasonOption,
    type PrnMedication,
    type ScheduleRow,
    type WitnessOption,
} from '@/pages/meds/today/types';
import { Head, router } from '@inertiajs/react';
import {
    CalendarDays,
    FileDown,
    HeartPulse,
    Home,
    Pill,
    Plus,
    Shield,
    User,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

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
    prn?: Array<{
        id: number;
        name: string;
        dosage: string;
        controlled_drug: boolean;
        witness_required: boolean;
    }>;
    attention_alerts?: Array<{
        id: number;
        type: string;
        title: string;
        detail?: string | null;
        prompt_on_open: boolean;
    }>;
    inr_records?: InrRecord[];
    syringe_drivers?: Array<{
        id: number;
        status: string;
        rate?: string | null;
        rate_unit?: string | null;
        site_of_insertion?: string | null;
    }>;
    awaiting_verification?: Array<{ id: number; name: string; dosage: string }>;
    settings?: {
        care_level?: string | null;
        next_chart_review_date?: string | null;
        suppress_med_admin_alerts?: boolean;
        med_alerts_suppressed_reason?: string | null;
    };
};

type Props = {
    clients: Client[];
    selectedClient: {
        id: number;
        first_name: string;
        last_name: string;
    } | null;
    selected_client_info: ClientInfo | null;
    marData: MarData;
    date: string;
    schedule: ScheduleRow[];
    prn_medications: PrnMedication[];
    witnesses: WitnessOption[];
    not_given_reasons: NotGivenReasonOption[];
    board_user: {
        name: string;
        role_label: string | null;
        med_competent: boolean;
        controlled_record: boolean;
        cd_witness: boolean;
    };
    site_brand_colour: string | null;
    allergies: Array<{
        id: number;
        allergen: string;
        severity?: string | null;
    }>;
    clientContext: {
        profile: { gp_name?: string | null } | null;
        conditions: Array<{
            id: number;
            label: string;
            severity?: string | null;
        }>;
        emergency_contacts: Array<{
            id: number;
            name: string;
            relationship?: string | null;
            phone?: string | null;
        }>;
    } | null;
    pendingCorrections: PendingCorrection[];
    can: {
        record: boolean;
        record_controlled: boolean;
        correct?: boolean;
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
    const breadcrumbs = useEmarBreadcrumbs();
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
    const [recordTarget, setRecordTarget] = useState<{
        row: ScheduleRow;
        outcome: 'given' | 'refused' | 'withheld';
    } | null>(null);
    const [ctxTarget, setCtxTarget] = useState<DoseCtxTarget | null>(null);
    const [prnMedId, setPrnMedId] = useState<number | null>(null);
    const [modal, setModal] = useState<MarModal>(null);

    const isToday = date === toYmd(new Date());
    const signedAs = { name: signer.name, role_label: signer.role_label };

    const goDate = (next: string) => {
        if (!info) return;
        router.get(
            '/emar/mar',
            { client_id: info.id, date: next },
            { preserveState: true, preserveScroll: true },
        );
    };

    const switchClient = (clientId: number | null) => {
        if (!clientId) return;
        router.get(
            '/emar/mar',
            { client_id: clientId, date },
            { preserveState: true },
        );
    };

    // Chart-warnings prompt-on-open (1CHART "prompt when viewing patient"): when
    // the resident has enabled attention alerts flagged prompt_on_open, surface
    // the warnings dialog automatically — once per chart open per session (keyed
    // by client + date so revisiting the same chart doesn't nag).
    const infoId = info?.id ?? null;
    const promptOnOpenCount = (marData.attention_alerts ?? []).filter(
        (a) => a.prompt_on_open,
    ).length;
    useEffect(() => {
        if (infoId === null || promptOnOpenCount === 0) return;
        const key = `emar.mar.warned.${infoId}.${date}`;
        if (sessionStorage.getItem(key)) return;
        sessionStorage.setItem(key, '1');
        setModal('warnings');
    }, [infoId, date, promptOnOpenCount]);

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
                requires_observation:
                    (med.admin_rules?.required_observations?.length ?? 0) > 0,
                dose_times: med.dose_times ?? [],
            })),
        [marData.scheduled],
    );

    // Active charted medicines (scheduled + PRN) a syringe driver may contain.
    const chartMedications: ChartMedicationOption[] = useMemo(
        () =>
            [...(marData.scheduled ?? []), ...(marData.prn ?? [])]
                .map((med) => ({
                    id: med.id,
                    name: med.name,
                    dosage: med.dosage,
                    controlled_drug: med.controlled_drug,
                    witness_required: med.witness_required,
                }))
                .sort((a, b) => a.name.localeCompare(b.name)),
        [marData.scheduled, marData.prn],
    );

    const searched = useMemo(
        () =>
            search
                ? gridMeds.filter((m) =>
                      m.name.toLowerCase().includes(search.toLowerCase()),
                  )
                : gridMeds,
        [gridMeds, search],
    );

    const dueMedIds = useMemo(() => {
        const ids = new Set<number>();
        for (const row of schedule) {
            if (row.status === 'due' || row.status === 'overdue')
                ids.add(row.medication_id);
        }
        return ids;
    }, [schedule]);

    const visibleMeds =
        activeTab === 'due'
            ? searched.filter((m) => dueMedIds.has(m.id))
            : searched;

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
            if (
                (row.status === 'due' || row.status === 'overdue') &&
                row.is_controlled
            )
                cdDue += 1;
        }
        // Doses waiting for the order check, and doses the person is away
        // for, are shown but not counted.
        const total = schedule.filter(
            (row) => !awaitsOrderCheck(row) && row.status !== 'away',
        ).length;
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

    const latestInr =
        (marData.inr_records ?? []).find((r) => !r.disabled_at) ??
        (marData.inr_records ?? [])[0] ??
        null;
    const awaitingCount = marData.awaiting_verification?.length ?? 0;

    const canRecordMedication = (isControlled: boolean) =>
        can.record && (!isControlled || can.record_controlled);
    const onRecord = (row: ScheduleRow) => {
        if (canRecordMedication(row.is_controlled)) {
            setRecordTarget({ row, outcome: 'given' });
        }
    };
    const onGivePrn = (med: PrnMedication) => {
        if (canRecordMedication(med.is_controlled)) {
            setPrnMedId(med.id);
        }
    };

    // ── No viewable resident with active meds: the server defaults onto a chart
    // whenever one exists, so this is the genuinely-empty state (not a picker).
    if (!info) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="MAR Charts" />
                <div className="flex flex-col gap-5">
                    <PageHeader
                        wrapTitle
                        rail={<EmarHubRail />}
                        icon={Pill}
                        title="MAR Charts"
                        subline="No people with active medications to chart yet."
                    />
                    <div className="rounded-2xl border bg-card p-10 text-center text-sm text-muted-foreground">
                        Once a resident has active medication orders, their
                        medication administration record opens here
                        automatically.
                    </div>
                </div>
            </AppLayout>
        );
    }

    const heroMeta: PageHeroMetaItem[] = [
        info.nhi ? { icon: User, label: `NHI ${info.nhi}` } : null,
        info.dob
            ? {
                  icon: CalendarDays,
                  label: `${info.dob}${info.age != null ? ` (${info.age})` : ''}`,
              }
            : null,
        clientContext?.profile?.gp_name
            ? { icon: HeartPulse, label: clientContext.profile.gp_name }
            : null,
        info.site_name ? { icon: Home, label: info.site_name } : null,
        marData.settings?.care_level
            ? { icon: Shield, label: marData.settings.care_level }
            : null,
    ].filter(Boolean) as PageHeroMetaItem[];

    const heroBadges: PageHeroBadge[] = [
        counts.overdue > 0
            ? { tone: 'critical' as const, label: `${counts.overdue} overdue` }
            : null,
        counts.cdDue > 0
            ? {
                  tone: 'warning' as const,
                  label: `${counts.cdDue} controlled · witness`,
              }
            : null,
        latestInr
            ? {
                  tone: 'info' as const,
                  label: `Warfarin · INR ${latestInr.inr_value}`,
              }
            : null,
        (marData.attention_alerts ?? []).some(
            (a) => a.type === 'paper_prescription',
        )
            ? { label: 'Paper prescription on file' }
            : null,
    ].filter(Boolean) as PageHeroBadge[];

    const heroFooter = (
        <div className="flex w-full flex-wrap items-center justify-between gap-2">
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
                <EntityFilter
                    label="Resident"
                    allLabel="All residents"
                    items={clients.map((c) => ({
                        id: c.id,
                        name: `${c.first_name} ${c.last_name}`,
                    }))}
                    value={info.id}
                    onChange={switchClient}
                    onDark
                />
            </div>
        </div>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`MAR · ${info.name}`} />
            <div className="flex flex-col gap-5">
                <PageHeader
                    wrapTitle
                    rail={<EmarHubRail />}
                    variant="profile"
                    brandColour={brandColour}
                    mark={
                        <span className="eh-mark-ring text-sm font-semibold">
                            {initials(info.name)}
                        </span>
                    }
                    title={info.name}
                    titleChip={
                        <PageHeaderStatusChip variant="neutral">
                            MAR chart
                        </PageHeaderStatusChip>
                    }
                    subline={[
                        formatDateOnly(date),
                        ...heroMeta.map((item) => item.label),
                    ].join(' · ')}
                    meters={
                        <EmarMeters
                            items={[
                                {
                                    label: 'Recorded',
                                    value: counts.total
                                        ? `${counts.pct}%`
                                        : '—',
                                    caption: `${counts.recorded} of ${counts.total} doses`,
                                    onClick: () => setActiveTab('history'),
                                },
                                {
                                    label: 'Due now',
                                    value: counts.due,
                                    caption: 'Scheduled doses due',
                                    tone: counts.due ? 'warning' : 'brand',
                                    onClick: () => setActiveTab('due'),
                                },
                                {
                                    label: 'Overdue',
                                    value: counts.overdue,
                                    caption: 'Review before recording',
                                    tone: counts.overdue ? 'critical' : 'brand',
                                    onClick: () => setActiveTab('due'),
                                },
                                {
                                    label: 'As needed',
                                    value: counts.prnGiven,
                                    caption: 'Doses given in the last 24 hours',
                                    onClick: () => setActiveTab('prn'),
                                },
                            ]}
                        />
                    }
                    actions={
                        <>
                            <PageHeaderSearch
                                value={search}
                                onChange={setSearch}
                                placeholder="Search medication…"
                                ariaLabel="Search this medication chart"
                            />
                            <PageHeaderPrimaryButton asChild>
                                <a href="/emar/rounds">
                                    Start medication round
                                </a>
                            </PageHeaderPrimaryButton>
                            {can.record && (
                                <PageHeaderGlassButton
                                    icon={Plus}
                                    onClick={() => setModal('addMed')}
                                >
                                    Add medication
                                </PageHeaderGlassButton>
                            )}
                            {can.export_reports && (
                                <PageHeaderGlassButton asChild>
                                    <a
                                        href={`/emar/pdf/mar-chart?client_id=${info.id}&date_from=${date}&date_to=${date}`}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        <FileDown className="h-4 w-4" />
                                        PDF
                                    </a>
                                </PageHeaderGlassButton>
                            )}
                        </>
                    }
                    filters={heroFooter}
                />
                {heroBadges.length > 0 && (
                    <p className="text-sm text-muted-foreground">
                        {heroBadges.map((item) => item.label).join(' · ')}
                    </p>
                )}

                <AttentionBar
                    alerts={marData.attention_alerts ?? []}
                    onReview={() => setModal('warnings')}
                    onManage={() => setModal('alerts')}
                    canManage={!!can.manage_settings}
                />

                <EmarRecordTabs
                    value={activeTab}
                    onChange={setActiveTab}
                    items={TABS}
                    ariaLabel="MAR chart views"
                />

                <div className="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_372px]">
                    <div className="flex min-w-0 flex-col gap-6">
                        {activeTab === 'history' ? (
                            <HistoryList schedule={schedule} />
                        ) : activeTab === 'prn' ? (
                            <PrnCard
                                prn={prn}
                                canRecord={can.record}
                                canRecordControlled={can.record_controlled}
                                onGive={onGivePrn}
                            />
                        ) : (
                            <>
                                <MarGrid
                                    meds={visibleMeds}
                                    schedule={schedule}
                                    canRecord={can.record}
                                    canRecordControlled={can.record_controlled}
                                    onRecord={onRecord}
                                    onContext={(e, row) => {
                                        e.preventDefault();
                                        setCtxTarget({
                                            x: e.clientX,
                                            y: e.clientY,
                                            row,
                                            requiresObservation:
                                                gridMeds.find(
                                                    (m) =>
                                                        m.id ===
                                                        row.medication_id,
                                                )?.requires_observation ??
                                                false,
                                        });
                                    }}
                                />
                                <PrnCard
                                    prn={prn}
                                    canRecord={can.record}
                                    canRecordControlled={can.record_controlled}
                                    onGive={onGivePrn}
                                />
                            </>
                        )}
                    </div>

                    <ClinicalRail
                        inrRecords={marData.inr_records ?? []}
                        syringeDrivers={marData.syringe_drivers ?? []}
                        awaitingVerification={awaitingCount}
                        pendingCorrections={pendingCorrections.length}
                        chartReviewDate={
                            marData.settings?.next_chart_review_date ?? null
                        }
                        allergies={allergies}
                        conditions={clientContext?.conditions ?? []}
                        emergencyContacts={
                            clientContext?.emergency_contacts ?? []
                        }
                        can={{
                            manageInr: !!can.manage_inr,
                            manageSyringeDrivers: !!can.manage_syringe_drivers,
                            verifyOrders: !!can.verify_orders,
                            reviewCorrections: !!can.correct,
                        }}
                        onRecordInr={() => setModal('inr')}
                        onStartDriver={() => setModal('syringe')}
                        onVerifyOrders={() => setModal('verify')}
                        onReviewCorrections={() => setModal('corrections')}
                    />
                </div>
            </div>

            {recordTarget &&
            canRecordMedication(recordTarget.row.is_controlled) ? (
                <RecordDoseWizard
                    row={recordTarget.row}
                    client={info}
                    date={date}
                    witnesses={witnesses}
                    notGivenReasons={notGivenReasons}
                    signedAs={signedAs}
                    initialOutcome={recordTarget.outcome}
                    onClose={() => setRecordTarget(null)}
                />
            ) : null}

            <DoseContextMenu
                target={ctxTarget}
                date={date}
                isToday={isToday}
                canRecord={
                    !!ctxTarget &&
                    canRecordMedication(ctxTarget.row.is_controlled)
                }
                onRecordFull={(row) => {
                    setCtxTarget(null);
                    setRecordTarget({ row, outcome: 'given' });
                }}
                onOutcome={(row, outcome) => {
                    setCtxTarget(null);
                    setRecordTarget({ row, outcome });
                }}
                onViewHistory={() => {
                    setCtxTarget(null);
                    setActiveTab('history');
                }}
                onClose={() => setCtxTarget(null)}
            />

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
                corrections={pendingCorrections}
                witnesses={witnesses}
                medications={chartMedications}
                suppression={{
                    suppressed:
                        marData.settings?.suppress_med_admin_alerts ?? false,
                    reason:
                        marData.settings?.med_alerts_suppressed_reason ?? null,
                }}
            />
        </AppLayout>
    );
}

function HistoryList({ schedule }: { schedule: ScheduleRow[] }) {
    const recorded = schedule.filter((r) => r.recorded);
    return (
        <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
            <div className="border-b px-5 py-4 text-[15px] font-bold">
                Today&apos;s recorded administrations
            </div>
            {recorded.length === 0 ? (
                <div className="px-5 py-10 text-center text-sm text-muted-foreground">
                    Nothing recorded yet.
                </div>
            ) : (
                <ul className="divide-y">
                    {recorded.map((row) => (
                        <li
                            key={row.key}
                            className="flex items-center justify-between px-5 py-3 text-sm"
                        >
                            <div>
                                <span className="font-medium">
                                    {row.medication_name}
                                </span>
                                <span className="ml-2 text-xs text-muted-foreground">
                                    {row.time}
                                </span>
                            </div>
                            <div className="flex items-center gap-3 text-xs">
                                <span className="font-medium capitalize">
                                    {row.recorded?.status}
                                </span>
                                {row.recorded?.by && (
                                    <span className="text-muted-foreground">
                                        {row.recorded.by}
                                    </span>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
