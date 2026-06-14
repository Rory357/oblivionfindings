/* eslint-disable no-restricted-syntax -- the board/templates/activity surfaces are
   custom-layout bordered panels (not Card components); all colours are semantic tokens. */
import RoundBoard from '@/components/emar/rounds/round-board';
import type { ActivityItem, GuidedRound, RoundSummary, RoundTemplate, StaffOption } from '@/components/emar/rounds/types';
import { PageHero, type PageHeroBadge, type PageHeroStat } from '@/components/page';
import { EntityFilter, TabStrip, type RosterTabItem } from '@/components/rostering';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/app-layout';
import GuidedRoundDialog from '@/pages/emar/components/guided-round-dialog';
import GenerateRoundsModal from '@/pages/emar/components/generate-rounds-modal';
import RoundTemplateDialog from '@/pages/emar/components/round-template-dialog';
import { addDays, DayPickerChip, toYmd } from '@/components/meds/day-picker-chip';
import type { NotGivenReasonOption, WitnessOption } from '@/pages/meds/today/types';
import { Head, router } from '@inertiajs/react';
import { Activity, CalendarCheck, CalendarDays, LayoutList, Pencil, Pill, Plus, RefreshCw, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';

type Props = {
    rounds: RoundSummary[];
    templates: RoundTemplate[];
    staff: StaffOption[];
    date: string;
    lastGenerated: string | null;
    guidedRound: GuidedRound | null;
    activity: ActivityItem[];
    sites: { id: number; name: string }[];
    site_brand_colour: string | null;
    witnesses: WitnessOption[];
    not_given_reasons: NotGivenReasonOption[];
    board_user: { first_name: string; name: string; role_label: string | null; med_competent: boolean; cd_witness: boolean };
};

export default function Rounds(props: Props) {
    const {
        rounds,
        templates,
        staff,
        date,
        guidedRound,
        activity,
        sites,
        site_brand_colour: brandColour,
        witnesses,
        not_given_reasons: notGivenReasons,
        board_user: signer,
    } = props;

    const [activeTab, setActiveTab] = useState('board');
    const [boardView, setBoardView] = useState<'cards' | 'list'>('cards');
    const [siteFilter, setSiteFilter] = useState<number | null>(null);
    const [generateOpen, setGenerateOpen] = useState(false);
    const [templateEditing, setTemplateEditing] = useState<RoundTemplate | 'new' | null>(null);

    const isToday = date === toYmd(new Date());

    const navigate = (params: Record<string, string | number | undefined>) => {
        router.get('/emar/rounds', { date, ...(siteFilter ? { site_id: siteFilter } : {}), ...params }, { preserveState: true, preserveScroll: true });
    };
    const goDate = (next: string) => navigate({ date: next, guided: undefined });
    const openGuided = (roundId: number) => navigate({ guided: roundId });
    const closeGuided = () => navigate({ guided: undefined });
    const changeSite = (id: number | null) => {
        setSiteFilter(id);
        router.get('/emar/rounds', { date, ...(id ? { site_id: id } : {}) }, { preserveState: true });
    };
    const assign = (roundId: number, userId: number | null) => {
        router.put(`/emar/rounds/${roundId}/assign`, { assigned_to: userId }, { preserveScroll: true });
    };
    const deleteTemplate = (id: number) => router.delete(`/emar/rounds/templates/${id}`, { preserveScroll: true });
    const toggleTemplateActive = (t: RoundTemplate) =>
        router.put(`/emar/rounds/templates/${t.id}`, { active: !t.active }, { preserveScroll: true });

    // Hero counts
    const counts = useMemo(() => {
        const totalRounds = rounds.length;
        const doneRounds = rounds.filter((r) => r.status === 'completed').length;
        const totalDoses = rounds.reduce((s, r) => s + r.total_medications, 0);
        const given = rounds.reduce((s, r) => s + r.given, 0);
        const recorded = rounds.reduce((s, r) => s + r.given + r.refused + r.withheld + r.missed, 0);
        const due = Math.max(0, totalDoses - recorded);
        const flags = rounds.reduce((s, r) => s + r.refused + r.withheld + r.missed, 0);
        return { totalRounds, doneRounds, totalDoses, given, due, flags };
    }, [rounds]);

    const TABS: RosterTabItem[] = [
        { id: 'board', label: 'Board', icon: CalendarCheck, tone: 'primary', badge: rounds.length || undefined },
        { id: 'templates', label: 'Templates', icon: LayoutList, tone: 'violet', badge: templates.length || undefined },
        { id: 'activity', label: 'Activity', icon: Activity, tone: 'success', badge: activity.length || undefined },
    ];

    const heroBadges: PageHeroBadge[] = [
        { label: `${sites.length} site${sites.length === 1 ? '' : 's'}` },
        signer.med_competent ? { tone: 'success' as const, label: signer.cd_witness ? 'Med-competent · CD witness' : 'Med-competent' } : null,
        counts.flags > 0 ? { tone: 'warning' as const, label: `${counts.flags} flag${counts.flags === 1 ? '' : 's'}` } : null,
    ].filter(Boolean) as PageHeroBadge[];

    const heroStats: PageHeroStat[] = [
        { label: 'Rounds', value: `${counts.doneRounds}/${counts.totalRounds}` },
        { label: 'Given', value: `${counts.given}/${counts.totalDoses}` },
        { label: 'Due', value: counts.due, tone: counts.due > 0 ? 'warning' : 'neutral' },
        { label: 'Flags', value: counts.flags, tone: counts.flags > 0 ? 'critical' : 'neutral' },
    ];

    const heroFooter = (
        <div className="flex flex-col items-stretch gap-2 py-3 md:flex-row md:items-center md:justify-between">
            <div className="flex flex-wrap items-center gap-1.5">
                <Button variant="outline" size="sm" className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20" onClick={() => goDate(addDays(date, -1))}>
                    Prev
                </Button>
                <DayPickerChip date={date} isToday={isToday} onPick={goDate} />
                <Button variant="outline" size="sm" className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20" onClick={() => goDate(addDays(date, 1))}>
                    Next
                </Button>
                {!isToday && (
                    <Button variant="ghost" size="sm" className="text-primary-foreground hover:bg-primary-foreground/10" onClick={() => goDate(toYmd(new Date()))}>
                        Back to today
                    </Button>
                )}
            </div>
            <div className="flex flex-wrap items-center gap-2 md:ml-auto">
                <EntityFilter label="Site" allLabel="All sites" items={sites} value={siteFilter} onChange={changeSite} onDark />
            </div>
        </div>
    );

    return (
        <AppLayout breadcrumbs={[{ title: 'eMAR', href: '/emar' }, { title: 'Medication Rounds', href: '/emar/rounds' }]}>
            <Head title="Medication Rounds" />
            <div className="flex flex-col gap-6 p-6">
                <PageHero
                    variant="hero"
                    category="ops"
                    brandColour={brandColour}
                    icon={Pill}
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
                                {isToday ? 'Live medication board' : 'Medication board · day view'}
                            </span>
                            <span className="mt-1 block text-[26px] font-bold leading-tight">
                                Kia ora {signer.first_name}, today&apos;s rounds —{' '}
                                <span className="border-b-2 border-primary-foreground/40">{date}</span>
                            </span>
                        </span>
                    }
                    description={`${counts.totalDoses} scheduled doses across ${sites.length} site${sites.length === 1 ? '' : 's'}. ${counts.given} given, ${counts.due} still to give.`}
                    badges={heroBadges}
                    stats={heroStats}
                    actions={
                        <>
                            <Button className="bg-primary-foreground text-primary hover:bg-primary-foreground/90" onClick={() => setGenerateOpen(true)}>
                                <RefreshCw className="h-4 w-4" />
                                Generate rounds
                            </Button>
                            <Button variant="outline" className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20" onClick={() => setTemplateEditing('new')}>
                                <Plus className="h-4 w-4" />
                                New template
                            </Button>
                        </>
                    }
                    footer={heroFooter}
                />

                <TabStrip value={activeTab} onChange={setActiveTab} items={TABS} ariaLabel="Medication rounds views" />

                {activeTab === 'board' && (
                    <div className="flex flex-col gap-4">
                        <div className="flex items-center justify-between">
                            <p className="text-sm text-muted-foreground">Open a round to step through its doses with the safety gate.</p>
                            <div className="inline-flex rounded-lg border bg-card p-0.5">
                                {(['cards', 'list'] as const).map((v) => (
                                    <Button key={v} size="sm" variant={boardView === v ? 'secondary' : 'ghost'} className="capitalize" onClick={() => setBoardView(v)}>
                                        {v}
                                    </Button>
                                ))}
                            </div>
                        </div>
                        <RoundBoard rounds={rounds} view={boardView} staff={staff} canManage={signer.med_competent} onOpenGuided={openGuided} onAssign={assign} />
                    </div>
                )}

                {activeTab === 'templates' && (
                    <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                                        <th className="px-4 py-2.5">Name</th>
                                        <th className="px-4 py-2.5">Time</th>
                                        <th className="px-4 py-2.5">Days</th>
                                        <th className="px-4 py-2.5">Default staff</th>
                                        <th className="px-4 py-2.5">Auto-gen</th>
                                        <th className="px-4 py-2.5"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {templates.length === 0 ? (
                                        <tr>
                                            <td colSpan={6} className="px-4 py-10 text-center text-muted-foreground">No templates yet.</td>
                                        </tr>
                                    ) : (
                                        templates.map((t) => (
                                            <tr key={t.id} className="border-b last:border-b-0">
                                                <td className="px-4 py-3 font-medium">{t.name}</td>
                                                <td className="px-4 py-3 text-muted-foreground">{t.scheduled_time} · ±{t.window_minutes}m</td>
                                                <td className="px-4 py-3 text-muted-foreground">{daysLabel(t.days_of_week)}</td>
                                                <td className="px-4 py-3 text-muted-foreground">{t.default_staff ?? '—'}</td>
                                                <td className="px-4 py-3">
                                                    {signer.med_competent ? (
                                                        <Switch checked={t.active} onCheckedChange={() => toggleTemplateActive(t)} />
                                                    ) : (
                                                        <span className="text-muted-foreground">{t.active ? 'On' : 'Off'}</span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-right">
                                                    {signer.med_competent && (
                                                        <div className="flex items-center justify-end gap-1">
                                                            <Button size="icon" variant="ghost" onClick={() => setTemplateEditing(t)} aria-label="Edit template">
                                                                <Pencil className="h-4 w-4" />
                                                            </Button>
                                                            <Button size="icon" variant="ghost" onClick={() => deleteTemplate(t.id)} aria-label="Delete template">
                                                                <Trash2 className="h-4 w-4 text-status-critical" />
                                                            </Button>
                                                        </div>
                                                    )}
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                {activeTab === 'activity' && (
                    <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                        {activity.length === 0 ? (
                            <div className="px-5 py-10 text-center text-sm text-muted-foreground">No round activity yet today.</div>
                        ) : (
                            <ul className="divide-y">
                                {activity.map((a) => (
                                    <li key={a.id} className="flex items-center justify-between px-5 py-3 text-sm">
                                        <span>
                                            <span className="font-medium capitalize">{a.status}</span> · {a.medication_name}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {a.time}
                                            {a.staff ? ` · ${a.staff}` : ''}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                )}
            </div>

            {guidedRound && (
                <GuidedRoundDialog
                    guided={guidedRound}
                    witnesses={witnesses}
                    notGivenReasons={notGivenReasons}
                    signer={{ med_competent: signer.med_competent, cd_witness: signer.cd_witness }}
                    onClose={closeGuided}
                />
            )}

            {generateOpen && <GenerateRoundsModal open onClose={() => setGenerateOpen(false)} defaultDate={date} />}

            {templateEditing !== null && (
                <RoundTemplateDialog
                    template={templateEditing === 'new' ? null : templateEditing}
                    staff={staff}
                    sites={sites}
                    onClose={() => setTemplateEditing(null)}
                />
            )}
        </AppLayout>
    );
}

const DAY_LABELS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
function daysLabel(days: number[]): string {
    if (!days || days.length === 0) return 'Every day';
    return days
        .slice()
        .sort((a, b) => a - b)
        .map((d) => DAY_LABELS[d - 1] ?? '')
        .filter(Boolean)
        .join(', ');
}
