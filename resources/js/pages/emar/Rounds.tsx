/* eslint-disable no-restricted-syntax -- the templates/activity surfaces and the
   filter/segmented chips are custom-layout bordered panels (not Card components);
   all colours are semantic tokens. */
import RoundActivity from '@/components/emar/rounds/round-activity';
import RoundActivityDialog from '@/components/emar/rounds/round-activity-dialog';
import RoundAuditDialog from '@/components/emar/rounds/round-audit-dialog';
import RoundBoard from '@/components/emar/rounds/round-board';
import RoundChart from '@/components/emar/rounds/round-chart';
import RoundTimeline from '@/components/emar/rounds/round-timeline';
import {
    roundCounts,
    roundStatusMeta,
    type ActivityItem,
    type GuidedRound,
    type Resident,
    type RoundCell,
    type RoundStatus,
    type RoundSummary,
} from '@/components/emar/rounds/types';
import {
    addDays,
    DayPickerChip,
    toYmd,
} from '@/components/meds/day-picker-chip';
import {
    PageHero,
    type PageHeroBadge,
    type PageHeroStat,
} from '@/components/page';
import {
    EntityFilter,
    TabStrip,
    type RosterTabItem,
} from '@/components/rostering';
import {
    ShiftContextMenu,
    type ShiftCtxItem,
    type ShiftCtxState,
} from '@/components/rostering/shift-context-menu';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import {
    OWN_WITNESS_PIN_PROMPT,
    OWN_WITNESS_PIN_SETTINGS_URL,
    type WitnessPinStatus,
} from '@/lib/witness-pin';
import GenerateRoundsModal from '@/pages/emar/components/generate-rounds-modal';
import GuidedRoundDialog from '@/pages/emar/components/guided-round-dialog';
import type {
    NotGivenReasonOption,
    WitnessOption,
} from '@/pages/meds/today/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    Activity,
    ArrowUpRight,
    CalendarCheck,
    CalendarDays,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    KeyRound,
    LayoutGrid,
    LayoutList,
    List,
    Pill,
    Printer,
    Zap,
} from 'lucide-react';
import type { ComponentType, MouseEvent } from 'react';
import { useMemo, useState } from 'react';

type Props = {
    rounds: RoundSummary[];
    date: string;
    now_label: string;
    lastGenerated: string | null;
    guidedRound: GuidedRound | null;
    activity: ActivityItem[];
    residents: Resident[];
    sites: { id: number; name: string }[];
    site_brand_colour: string | null;
    witnesses: WitnessOption[];
    not_given_reasons: NotGivenReasonOption[];
    board_user: {
        first_name: string;
        name: string;
        role_label: string | null;
        med_competent: boolean;
        controlled_record: boolean;
        cd_witness: boolean;
        witness_pin?: WitnessPinStatus;
    };
    can_manage: boolean;
    /** Can open Settings › Rounds & timing › Round templates (read or change). */
    can_read_templates: boolean;
    can_export: boolean;
};

type StatusChip = 'all' | 'due' | 'flagged';

export default function Rounds(props: Props) {
    const {
        rounds,
        date,
        now_label: nowLabel,
        guidedRound,
        activity,
        residents,
        sites,
        site_brand_colour: brandColour,
        witnesses,
        not_given_reasons: notGivenReasons,
        board_user: signer,
        can_manage: canManage,
        can_read_templates: canReadTemplates,
        can_export: canExport,
    } = props;

    const [activeTab, setActiveTab] = useState('board');
    const [boardView, setBoardView] = useState<'cards' | 'list'>('cards');
    const [siteFilter, setSiteFilter] = useState<number | null>(null);
    const [residentFilter, setResidentFilter] = useState<number | null>(null);
    const [statusChip, setStatusChip] = useState<StatusChip>('all');
    const [expanded, setExpanded] = useState<Record<number, boolean>>({});
    const [generateOpen, setGenerateOpen] = useState(false);
    const [auditRoundId, setAuditRoundId] = useState<number | null>(null);
    const [activityView, setActivityView] = useState<ActivityItem | null>(null);
    const [contextMenu, setContextMenu] = useState<ShiftCtxState | null>(null);

    const isToday = date === toYmd(new Date());

    // ── Navigation (date + guided modal are server-driven; site/resident filters are client-side) ──
    const goDate = (next: string) =>
        router.get('/emar/rounds', { date: next }, { preserveScroll: true });
    const openGuided = (roundId: number) =>
        router.get(
            '/emar/rounds',
            { date, guided: roundId },
            { preserveState: true, preserveScroll: true },
        );
    const closeGuided = () =>
        router.get(
            '/emar/rounds',
            { date },
            { preserveState: true, preserveScroll: true },
        );

    const toggleExpand = (id: number) =>
        setExpanded((prev) => ({ ...prev, [id]: !prev[id] }));
    const printRoundSheet = () =>
        window.open(
            `/emar/pdf/round-sheet?date=${encodeURIComponent(date)}`,
            '_blank',
            'noopener',
        );
    const markComplete = (id: number) => {
        router.post(
            `/emar/rounds/${id}/complete`,
            {},
            { preserveScroll: true, onSuccess: () => undefined },
        );
        setContextMenu(null);
    };

    // ── Filtering (client-side, applies to timeline / board / chart) ──
    const residentSite = useMemo(() => {
        const m = new Map<number, number | null>();
        residents.forEach((r) => m.set(r.id, r.site_id));
        return m;
    }, [residents]);

    const cellVisible = (c: RoundCell): boolean =>
        (siteFilter == null ||
            residentSite.get(c.resident_id) === siteFilter) &&
        (residentFilter == null || c.resident_id === residentFilter);

    const hasFilter = siteFilter != null || residentFilter != null;

    const filteredRounds = useMemo(() => {
        return rounds
            .map((r) =>
                hasFilter ? { ...r, cells: r.cells.filter(cellVisible) } : r,
            )
            .filter((r) => {
                if (hasFilter && r.cells.length === 0) return false;
                const counts = roundCounts(r.cells);
                if (statusChip === 'due') return counts.due > 0;
                if (statusChip === 'flagged')
                    return counts.refused + counts.held + counts.missed > 0;
                return true;
            });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [rounds, siteFilter, residentFilter, statusChip]);

    const filteredResidents = useMemo(
        () =>
            residents.filter(
                (res) =>
                    (siteFilter == null || res.site_id === siteFilter) &&
                    (residentFilter == null || res.id === residentFilter),
            ),
        [residents, siteFilter, residentFilter],
    );

    // ── Hero counts (global day overview — not filtered) ──
    const counts = useMemo(() => {
        let totalDoses = 0,
            given = 0,
            due = 0,
            flags = 0,
            doneRounds = 0;
        rounds.forEach((r) => {
            const c = roundCounts(r.cells);
            totalDoses += c.total;
            given += c.given;
            due += c.due;
            flags += c.refused + c.held + c.missed;
            if (
                r.status === 'completed' ||
                (c.total > 0 && c.due === 0 && c.recorded > 0)
            )
                doneRounds++;
        });
        return {
            totalRounds: rounds.length,
            doneRounds,
            totalDoses,
            given,
            due,
            flags,
        };
    }, [rounds]);

    const activeRound = rounds.find((r) => r.status === 'in_progress');
    const auditRound =
        auditRoundId != null
            ? (rounds.find((r) => r.id === auditRoundId) ?? null)
            : null;
    const auditRoundCanOpen = auditRound
        ? (() => {
              return signer.med_competent || auditRound.status === 'completed';
          })()
        : false;

    // ── Right-click context menu ──
    const openContext = (e: MouseEvent, round: RoundSummary) => {
        e.preventDefault();
        const original = rounds.find((r) => r.id === round.id) ?? round;
        const c = roundCounts(original.cells);
        const completed = original.status === 'completed';
        const inProgress =
            original.status === 'in_progress' || original.status === 'partial';
        const tag = statusTag(original.status);

        const items: ShiftCtxItem[] = [
            ...(completed || signer.med_competent
                ? [
                      {
                          icon: <LayoutList className="h-3.5 w-3.5" />,
                          label: completed
                              ? 'Review round'
                              : inProgress
                                ? 'Resume guided round'
                                : 'Start guided round',
                          sub: `${original.scheduled_time} · ${c.recorded}/${c.total} recorded`,
                          tone: 'primary',
                          onClick: () => openGuided(original.id),
                      } satisfies ShiftCtxItem,
                  ]
                : []),
            {
                icon: <Activity className="h-3.5 w-3.5" />,
                label: 'Audit & timeline',
                sub: 'Every action — who & when',
                onClick: () => setAuditRoundId(original.id),
            },
            {
                icon:
                    boardView === 'list' ? (
                        <LayoutGrid className="h-3.5 w-3.5" />
                    ) : (
                        <List className="h-3.5 w-3.5" />
                    ),
                label:
                    boardView === 'list'
                        ? 'Switch to card view'
                        : 'Switch to list view',
                onClick: () =>
                    setBoardView(boardView === 'list' ? 'cards' : 'list'),
            },
            { sep: true },
        ];
        if (
            canManage &&
            original.status === 'in_progress' &&
            original.can_complete
        ) {
            items.push({
                icon: <CheckCircle2 className="h-3.5 w-3.5" />,
                label: 'Mark round complete',
                sub: c.due > 0 ? `${c.due} still due` : 'Close the round',
                tone: 'critical',
                onClick: () => markComplete(original.id),
            });
        }
        if (canExport)
            items.push({
                icon: <Printer className="h-3.5 w-3.5" />,
                label: 'Print round sheet',
                onClick: printRoundSheet,
            });
        if (canManage)
            items.push({
                icon: <Zap className="h-3.5 w-3.5" />,
                label: 'Generate rounds',
                onClick: () => setGenerateOpen(true),
            });

        setContextMenu({
            x: e.clientX,
            y: e.clientY,
            tag: tag.label,
            tagBg: tag.bg,
            tagColor: tag.color,
            meta: original.name,
            items,
        });
    };

    const TABS: RosterTabItem[] = [
        {
            id: 'board',
            label: 'Board',
            icon: CalendarCheck,
            tone: 'primary',
            badge: rounds.length || undefined,
        },
        {
            id: 'chart',
            label: 'Chart',
            icon: LayoutGrid,
            tone: 'info',
            badge: counts.totalDoses || undefined,
        },
        ...(canReadTemplates
            ? [
                  {
                      id: 'templates',
                      label: 'Templates',
                      icon: LayoutList,
                      tone: 'violet' as const,
                  },
              ]
            : []),
        {
            id: 'activity',
            label: 'Activity',
            icon: Activity,
            tone: 'success',
            badge: activity.length || undefined,
        },
    ];

    const heroBadges: PageHeroBadge[] = [
        {
            label: `${sites.length} site${sites.length === 1 ? '' : 's'} · ${residents.length} resident${residents.length === 1 ? '' : 's'}`,
        },
        signer.med_competent
            ? {
                  tone: 'success' as const,
                  label: signer.cd_witness
                      ? 'Med-competent · CD witness authorised'
                      : 'Med-competent',
              }
            : null,
        signer.witness_pin && signer.witness_pin !== 'set'
            ? {
                  tone: 'warning' as const,
                  icon: KeyRound,
                  label: OWN_WITNESS_PIN_PROMPT[signer.witness_pin],
                  href: OWN_WITNESS_PIN_SETTINGS_URL,
              }
            : null,
    ].filter(Boolean) as PageHeroBadge[];

    const heroStats: PageHeroStat[] = [
        {
            label: 'Rounds',
            value: `${counts.doneRounds}/${counts.totalRounds}`,
        },
        { label: 'Given', value: `${counts.given}/${counts.totalDoses}` },
        {
            label: 'Due',
            value: counts.due,
            tone: counts.due > 0 ? 'warning' : 'neutral',
        },
        {
            label: 'Flags',
            value: counts.flags,
            tone: counts.flags > 0 ? 'critical' : 'neutral',
        },
    ];

    const dayTitle = useMemo(() => {
        const d = new Date(`${date}T00:00:00`);
        return Number.isNaN(d.getTime())
            ? date
            : d
                  .toLocaleDateString('en-NZ', {
                      weekday: 'long',
                      day: 'numeric',
                      month: 'long',
                  })
                  .replace(',', '');
    }, [date]);

    // Short weekday+day label for the Prev/Next day-stepper chips (mirrors /meds/today).
    const stepLabel = (ymd: string) => {
        const d = new Date(`${ymd}T00:00:00`);
        return Number.isNaN(d.getTime())
            ? ymd
            : d.toLocaleDateString('en-NZ', {
                  weekday: 'short',
                  day: 'numeric',
              });
    };

    const description = `${counts.totalDoses} scheduled dose${counts.totalDoses === 1 ? '' : 's'} across ${sites.length} site${
        sites.length === 1 ? '' : 's'
    } today. ${counts.given} given, ${counts.due} still to give${activeRound ? `, and the ${activeRound.name} is in progress.` : '.'}`;

    const onDarkChip =
        'border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20';

    const heroFooter = (
        <div className="flex flex-col items-stretch gap-2 py-3 md:flex-row md:items-center md:justify-between">
            <div className="flex flex-wrap items-center gap-1.5">
                <Button
                    variant="outline"
                    size="sm"
                    className={onDarkChip}
                    onClick={() => goDate(addDays(date, -1))}
                >
                    <ChevronLeft className="h-3.5 w-3.5" />
                    {stepLabel(addDays(date, -1))}
                </Button>
                <DayPickerChip date={date} isToday={isToday} onPick={goDate} />
                <Button
                    variant="outline"
                    size="sm"
                    className={onDarkChip}
                    onClick={() => goDate(addDays(date, 1))}
                >
                    {stepLabel(addDays(date, 1))}
                    <ChevronRight className="h-3.5 w-3.5" />
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
                <StatusChips value={statusChip} onChange={setStatusChip} />
                <EntityFilter
                    label="Site"
                    allLabel="All sites"
                    items={sites}
                    value={siteFilter}
                    onChange={setSiteFilter}
                    onDark
                    className="rounded-lg"
                />
                <EntityFilter
                    label="Resident"
                    allLabel="All"
                    pluralLabel="residents"
                    items={residents.map((r) => ({
                        id: r.id,
                        name: r.name,
                        description: r.site_name,
                    }))}
                    value={residentFilter}
                    onChange={setResidentFilter}
                    onDark
                    className="rounded-lg"
                />
            </div>
        </div>
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'eMAR', href: '/emar' },
                { title: 'Medication Rounds', href: '/emar/rounds' },
            ]}
        >
            <Head title="Medication Rounds" />
            <div className="flex flex-col gap-6 p-6">
                <PageHero
                    variant="hero"
                    category="ops"
                    brandColour={brandColour}
                    icon={Pill}
                    title={
                        <span>
                            <span className="flex items-center gap-2 text-[10.5px] font-semibold tracking-wider text-primary-foreground/80 uppercase">
                                {isToday ? (
                                    <span
                                        aria-hidden
                                        className="relative inline-flex h-2 w-2"
                                    >
                                        <span className="absolute inset-0 animate-ping rounded-full bg-status-success/70" />
                                        <span className="relative inline-flex h-2 w-2 rounded-full bg-status-success" />
                                    </span>
                                ) : (
                                    <CalendarDays className="h-3 w-3" />
                                )}
                                {isToday
                                    ? `Live medication board · refreshed ${nowLabel}`
                                    : 'Medication board · day view'}
                            </span>
                            <span className="mt-1 block text-[26px] leading-tight font-bold">
                                <span className="font-normal text-primary-foreground/80">
                                    Kia ora {signer.first_name},{' '}
                                    {isToday
                                        ? "today's rounds —"
                                        : 'the rounds for —'}
                                </span>{' '}
                                <span className="border-b-2 border-primary-foreground/40 pb-0.5 whitespace-nowrap">
                                    {dayTitle}
                                </span>
                            </span>
                        </span>
                    }
                    description={description}
                    badges={heroBadges}
                    stats={heroStats}
                    actions={
                        <>
                            {canManage && (
                                <Button
                                    className="bg-primary-foreground text-primary hover:bg-primary-foreground/90"
                                    onClick={() => setGenerateOpen(true)}
                                >
                                    <Zap className="h-4 w-4" />
                                    Generate rounds
                                </Button>
                            )}
                        </>
                    }
                    footer={heroFooter}
                />

                <RoundTimeline
                    rounds={filteredRounds}
                    dateTitle={dayTitle}
                    canRecord={signer.med_competent}
                    onOpen={openGuided}
                    onContext={openContext}
                />

                <TabStrip
                    value={activeTab}
                    onChange={setActiveTab}
                    items={TABS}
                    ariaLabel="Medication rounds views"
                />

                {activeTab === 'board' && (
                    <div className="flex flex-col gap-4">
                        <div className="flex items-center justify-between gap-3">
                            <p className="text-sm text-muted-foreground">
                                Right-click any round for quick actions, audit
                                &amp; list view.
                            </p>
                            <SegmentedToggle
                                value={boardView}
                                onChange={setBoardView}
                            />
                        </div>
                        <RoundBoard
                            rounds={filteredRounds}
                            view={boardView}
                            expanded={expanded}
                            canRecord={signer.med_competent}
                            onToggleExpand={toggleExpand}
                            onOpen={openGuided}
                            onAudit={(r) => setAuditRoundId(r.id)}
                            onContext={openContext}
                        />
                    </div>
                )}

                {activeTab === 'chart' && (
                    <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                        <div className="border-b px-4 py-3.5">
                            <div className="text-sm font-semibold">
                                Resident × round chart
                            </div>
                            <div className="mt-0.5 text-xs text-muted-foreground">
                                Every scheduled dose, gridded by resident and
                                round. Click any cell to open that round.
                            </div>
                        </div>
                        <RoundChart
                            residents={filteredResidents}
                            rounds={filteredRounds}
                            canRecord={signer.med_competent}
                            onOpen={openGuided}
                            onContext={openContext}
                        />
                    </div>
                )}

                {activeTab === 'templates' && canReadTemplates && (
                    <div className="rounded-2xl border bg-card p-5 shadow-sm">
                        <div className="text-sm font-semibold">
                            Round templates are in Settings
                        </div>
                        <p className="mt-1 text-sm text-muted-foreground">
                            When each round happens at a house, who does it, and
                            pausing or retiring a template are now in Medication
                            › Settings › Rounds &amp; timing.
                            {canManage
                                ? ''
                                : ' You can read them there; people who manage orders at a house change them.'}
                        </p>
                        <Button asChild size="sm" className="mt-3">
                            <Link href="/emar/settings#rounds/templates">
                                Open round templates
                                <ArrowUpRight className="h-4 w-4" />
                            </Link>
                        </Button>
                    </div>
                )}

                {activeTab === 'activity' && (
                    <RoundActivity
                        activity={activity}
                        rounds={rounds}
                        siteFilter={siteFilter}
                        residentFilter={residentFilter}
                        onView={setActivityView}
                    />
                )}
            </div>

            {guidedRound && (
                <GuidedRoundDialog
                    guided={guidedRound}
                    witnesses={witnesses}
                    notGivenReasons={notGivenReasons}
                    signer={{
                        med_competent: signer.med_competent,
                        controlled_record: signer.controlled_record,
                        cd_witness: signer.cd_witness,
                    }}
                    canExport={canExport}
                    onPrint={printRoundSheet}
                    onClose={closeGuided}
                />
            )}

            {auditRound && (
                <RoundAuditDialog
                    round={auditRound}
                    canExport={canExport}
                    onClose={() => setAuditRoundId(null)}
                    onOpenGuided={
                        auditRoundCanOpen
                            ? (id) => {
                                  setAuditRoundId(null);
                                  openGuided(id);
                              }
                            : undefined
                    }
                    onPrint={printRoundSheet}
                />
            )}

            {activityView && (
                <RoundActivityDialog
                    item={activityView}
                    onClose={() => setActivityView(null)}
                />
            )}

            {generateOpen && (
                <GenerateRoundsModal
                    open
                    onClose={() => setGenerateOpen(false)}
                    defaultDate={date}
                />
            )}

            {contextMenu && (
                <ShiftContextMenu
                    ctx={contextMenu}
                    onClose={() => setContextMenu(null)}
                />
            )}
        </AppLayout>
    );
}

function StatusChips({
    value,
    onChange,
}: {
    value: StatusChip;
    onChange: (v: StatusChip) => void;
}) {
    const chips: { id: StatusChip; label: string }[] = [
        { id: 'all', label: 'All' },
        { id: 'due', label: 'Due' },
        { id: 'flagged', label: 'Flagged' },
    ];
    return (
        <div className="inline-flex gap-0.5 rounded-lg border border-primary-foreground/20 bg-primary-foreground/10 p-0.5">
            {chips.map((c) => (
                <Button
                    key={c.id}
                    size="sm"
                    variant="ghost"
                    className={
                        value === c.id
                            ? 'h-7 bg-primary-foreground text-primary hover:bg-primary-foreground'
                            : 'h-7 text-primary-foreground hover:bg-primary-foreground/15'
                    }
                    onClick={() => onChange(c.id)}
                >
                    {c.label}
                </Button>
            ))}
        </div>
    );
}

function SegmentedToggle({
    value,
    onChange,
}: {
    value: 'cards' | 'list';
    onChange: (v: 'cards' | 'list') => void;
}) {
    const opts: {
        id: 'cards' | 'list';
        label: string;
        icon: ComponentType<{ className?: string }>;
    }[] = [
        { id: 'cards', label: 'Cards', icon: LayoutGrid },
        { id: 'list', label: 'List', icon: List },
    ];
    return (
        <div className="inline-flex gap-0.5 rounded-xl border bg-card p-1">
            {opts.map((o) => {
                const active = value === o.id;
                const Icon = o.icon;
                return (
                    <Button
                        key={o.id}
                        size="sm"
                        variant="ghost"
                        className={
                            active
                                ? 'bg-accent text-primary hover:bg-accent'
                                : 'text-muted-foreground'
                        }
                        onClick={() => onChange(o.id)}
                    >
                        <Icon className="h-4 w-4" />
                        {o.label}
                    </Button>
                );
            })}
        </div>
    );
}

function statusTag(status: RoundStatus): {
    label: string;
    bg: string;
    color: string;
} {
    const meta = roundStatusMeta(status);
    const map: Record<string, { bg: string; color: string }> = {
        success: {
            bg: 'var(--status-success-bg)',
            color: 'var(--status-success)',
        },
        info: { bg: 'var(--accent)', color: 'var(--primary)' },
        warning: {
            bg: 'var(--status-warning-bg)',
            color: 'var(--status-warning)',
        },
        neutral: { bg: 'var(--muted)', color: 'var(--muted-foreground)' },
    };
    const tone = map[meta.tone] ?? map.neutral;
    return { label: meta.label, ...tone };
}

const DAY_LABELS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
function daysLabel(days: number[]): string {
    if (!days || days.length === 0 || days.length >= 7) return 'Every day';
    return days
        .slice()
        .sort((a, b) => a - b)
        .map((d) => DAY_LABELS[d - 1] ?? '')
        .filter(Boolean)
        .join(', ');
}
