/* eslint-disable no-restricted-syntax -- The Recruitment Hub is a bespoke command
 * surface: the hero band, drag-and-drop board, candidate dossier sheet and the
 * stage-hue pills are custom on-brand layout (raw <button>/<div> + the stage hue
 * scale), not shadcn Card/Button cases. Every semantic colour is a design token;
 * the stage colours reuse the shared OKLCH hue scale from ./stage. */
import { Head, router, usePage } from '@inertiajs/react';
import {
    BarChart3,
    Briefcase,
    CalendarDays,
    CalendarPlus,
    ChevronDown,
    Clock,
    Download,
    FilePlus2,
    FileText,
    LayoutGrid,
    ListChecks,
    MoreHorizontal,
    Search,
    Send,
    Sparkles,
    Tags,
    UserCheck,
    Users,
    XCircle,
} from 'lucide-react';
import { useMemo, useState, type MouseEvent } from 'react';
import { toast } from 'sonner';

import { HrTabs, useHrTab, type HrTabItem } from '@/components/hr/hr-tabs';
import { KitDialog, type KitDraft } from '@/components/hr/recruitment/kit-dialog';
import { RecruitmentHero } from '@/components/hr/recruitment/recruitment-hero';
import { BulkEmailDialog } from '@/components/hr/recruitment/bulk-email-dialog';
import { BulkRejectDialog } from '@/components/hr/recruitment/bulk-reject-dialog';
import { TextPromptDialog } from '@/components/hr/text-prompt-dialog';
import { ScoreDialog, type ScoreTarget } from '@/components/hr/recruitment/score-dialog';
import { TagManagerDialog } from '@/components/hr/recruitment/tag-manager-dialog';
import {
    RecruitmentWizards,
    type RecruitmentSupport,
    type WizardContext,
    type WizardKind,
    type WizardState,
} from '@/components/hr/recruitment/recruitment-wizards';
import {
    BOARD_STAGES,
    avatarStyle,
    daysLabel,
    initials,
    nextStage,
    stageBadgeStyle,
    stageColors,
    stageDotStyle,
    stageLabel,
} from '@/components/hr/recruitment/stage';
import PageShell from '@/components/page-shell';
import {
    ShiftContextMenu,
    type ShiftCtxItem,
    type ShiftCtxState,
} from '@/components/rostering/shift-context-menu';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';

/* ------------------------------------------------------------------ */
/*  Prop types                                                        */
/* ------------------------------------------------------------------ */

type HubCandidate = {
    id: number;
    application_id?: number | null;
    first_name: string;
    last_name: string;
    full_name: string;
    email: string;
    source: string;
    tags: string[];
    stage: string;
    days: number;
    stale: boolean;
    score?: number | null;
    score_count?: number;
    possible_duplicate?: 'email' | 'name' | null;
    requisition?: { id: number; title: string } | null;
};

type Requisition = {
    id: number;
    title: string;
    site: string;
    status: string;
    openings: number;
    applicants: number;
    hiring_manager?: string | null;
    employment_type: string;
    position: string;
    position_id?: number | null;
    requires_approval?: boolean;
    pay?: string | null;
};

type WeekInterview = {
    id: number;
    application_id?: number | null;
    candidate: string;
    type: string;
    status: string;
    scheduled_at?: string | null;
    scored?: boolean;
    kit_name?: string | null;
    criteria?: { label: string; weight: number }[];
};

type Consensus = {
    name: string;
    role: string;
    count: number;
    criteria: { label: string; avg: number; dots: number[] }[];
    rec: string;
    rec_sub: string;
} | null;

type OfferRow = {
    id: number;
    application_id?: number | null;
    candidate: string;
    role: string;
    status: string;
    pay: string;
    meta: string;
    response?: string | null;
    approval_status: string;
    sent: boolean;
};

type AnalyticsData = {
    kpis: { key: string; label: string; value: string; trend: string }[];
    funnel: { stage: string; label: string; count: number; rate: string; width: number }[];
    sources: { name: string; total: number; hired: number; detail: string; width: number }[];
    open_positions: { requisition_id: number | null; title: string; applications: number; days_open: number }[];
    range?: { from: string | null; to: string | null };
};

type Kit = { id: number; name: string; role: string | null; is_active: boolean; criteria: { label: string; weight: number }[] };
type PoolItem = { id: number; name: string; last_role: string; tags: string[]; reason: string };
export type EmailTemplate = { id: number; name: string; subject: string; body: string };

type Props = {
    hero: React.ComponentProps<typeof RecruitmentHero>['hero'];
    needs: { key: string; label: string; tab: string }[];
    candidates: HubCandidate[];
    requisitions: Requisition[];
    interviews: { week: WeekInterview[]; consensus: Consensus };
    offers: { summary: { key: string; label: string; count: number; color: string }[]; list: OfferRow[] };
    analytics: AnalyticsData;
    kits: Kit[];
    pool: PoolItem[];
    email_templates: EmailTemplate[];
    support: RecruitmentSupport;
    can: { manage: boolean; manage_employees: boolean };
};

const PRIMARY_TABS: HrTabItem[] = [
    { id: 'pipeline', label: 'Pipeline', icon: Users, tone: 'primary' },
    { id: 'board', label: 'Board', icon: LayoutGrid, tone: 'info' },
    { id: 'requisitions', label: 'Requisitions', icon: Briefcase, tone: 'violet' },
    { id: 'interviews', label: 'Interviews', icon: CalendarDays, tone: 'warning' },
    { id: 'offers', label: 'Offers', icon: Send, tone: 'success' },
    { id: 'analytics', label: 'Analytics', icon: BarChart3, tone: 'primary' },
];

const MORE_TABS: HrTabItem[] = [
    { id: 'kits', label: 'Interview kits', icon: ListChecks, tone: 'warning' },
    { id: 'pool', label: 'Talent pool', icon: Sparkles, tone: 'success' },
];

const TAB_LABEL: Record<string, string> = {
    pipeline: 'Pipeline',
    board: 'Board',
    requisitions: 'Requisitions',
    interviews: 'Interviews',
    offers: 'Offers',
    analytics: 'Analytics',
    kits: 'Interview kits',
    pool: 'Talent pool',
};

export default function RecruitmentHub(props: Props) {
    const { hero, needs, candidates, requisitions, interviews, offers, analytics, kits, pool, email_templates, support, can } = props;
    const page = usePage();
    const [tab, setTab] = useHrTab('pipeline', { param: 'tab', syncUrl: true });
    const [search, setSearch] = useState('');
    const [stageFilter, setStageFilter] = useState<string>('all');
    const [tagFilter, setTagFilter] = useState<string | null>(null);
    const [dupOnly, setDupOnly] = useState(false);
    const [selected, setSelected] = useState<number[]>([]);
    const [bulkEmailOpen, setBulkEmailOpen] = useState(false);
    const [bulkRejectOpen, setBulkRejectOpen] = useState(false);
    const [manageTagsOpen, setManageTagsOpen] = useState(false);
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [moreOpen, setMoreOpen] = useState(false);
    const [sheetId, setSheetId] = useState<number | null>(null);
    const [wizard, setWizard] = useState<WizardState | null>(null);
    const [kitDialog, setKitDialog] = useState<{ kit: KitDraft | null } | null>(null);
    const [scoreTarget, setScoreTarget] = useState<ScoreTarget | null>(null);
    const [dragId, setDragId] = useState<number | null>(null);
    const [dragOver, setDragOver] = useState<string | null>(null);
    const [scorecardOverride, setScorecardOverride] = useState<{
        candidate: HubCandidate;
        targetStage: string;
    } | null>(null);

    const flash = (page.props as { flash?: { error?: string; success?: string } }).flash;

    const openWizard = (kind: WizardKind, context?: WizardContext) => {
        setSheetId(null);
        setCtx(null);
        setWizard({ kind, context: { ...context, canManageEmployees: can.manage_employees } });
    };

    const sheetCandidate = candidates.find((c) => c.id === sheetId) ?? null;

    /* ---- pipeline filtering ---- */
    const stageCounts = useMemo(() => {
        const counts: Record<string, number> = { all: candidates.length };
        for (const c of candidates) counts[c.stage] = (counts[c.stage] ?? 0) + 1;
        return counts;
    }, [candidates]);

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        const tagQ = tagFilter?.toLowerCase() ?? null;
        return candidates.filter((c) => {
            if (stageFilter !== 'all' && c.stage !== stageFilter) return false;
            if (dupOnly && !c.possible_duplicate) return false;
            if (tagQ && !c.tags.some((t) => t.toLowerCase() === tagQ)) return false;
            if (q) {
                const hay = `${c.full_name} ${c.email} ${c.requisition?.title ?? ''} ${c.tags.join(' ')}`.toLowerCase();
                if (!hay.includes(q)) return false;
            }
            return true;
        });
    }, [candidates, search, stageFilter, tagFilter, dupOnly]);

    const [tagPromptOpen, setTagPromptOpen] = useState(false);
    const [declineOfferId, setDeclineOfferId] = useState<number | null>(null);
    const [expireOffer, setExpireOffer] = useState<OfferRow | null>(null);

    /* ---- actions ---- */
    const advance = (c: HubCandidate) => {
        if (!c.application_id) {
            toast.error('No application to advance');
            return;
        }
        const next = nextStage(c.stage);
        router.post(
            `/hr/recruitment/applications/${c.application_id}/advance`,
            next ? { target_stage: next } : {},
            {
                preserveScroll: true,
                onSuccess: (pg) => {
                    const f = (pg.props as { flash?: { error?: string } }).flash;
                    if (f?.error) {
                        toast.error(f.error);
                        if (next && f.error.toLowerCase().includes('scorecard quorum')) {
                            setScorecardOverride({ candidate: c, targetStage: next });
                        }
                    } else toast.success(`${c.first_name} → ${next ? stageLabel(next) : 'advanced'}`);
                },
            },
        );
    };

    const boardDrop = (c: HubCandidate, targetStage: string) => {
        if (!c.application_id || c.stage === targetStage) return;
        router.post(
            `/hr/recruitment/applications/${c.application_id}/advance`,
            { target_stage: targetStage },
            {
                preserveScroll: true,
                onSuccess: (pg) => {
                    const f = (pg.props as { flash?: { error?: string } }).flash;
                    if (f?.error) {
                        toast.error(f.error, { description: 'The card stayed where it was.' });
                        if (f.error.toLowerCase().includes('scorecard quorum')) {
                            setScorecardOverride({ candidate: c, targetStage });
                        }
                    } else toast.success(`${c.first_name} moved to ${stageLabel(targetStage)}`);
                },
            },
        );
    };

    const bulkAction = (action: 'advance' | 'pool') => {
        if (selected.length === 0) return;
        router.post(
            '/hr/recruitment/applications/bulk',
            { action, candidate_ids: selected },
            {
                preserveScroll: true,
                onSuccess: (pg) => {
                    const f = (pg.props as { flash?: { error?: string; success?: string } }).flash;
                    if (f?.error) toast.error(f.error);
                    else toast.success(f?.success ?? `${selected.length} candidates updated`);
                    setSelected([]);
                },
            },
        );
    };

    const bulkTag = () => {
        if (selected.length === 0) return;
        setTagPromptOpen(true);
    };

    const submitBulkTag = (tag: string) => {
        router.post(
            '/hr/recruitment/applications/bulk',
            { action: 'tag', candidate_ids: selected, tag },
            {
                preserveScroll: true,
                onSuccess: (pg) => {
                    const f = (pg.props as { flash?: { error?: string; success?: string } }).flash;
                    if (f?.error) toast.error(f.error);
                    else toast.success(f?.success ?? `${selected.length} candidates tagged`);
                    setSelected([]);
                },
            },
        );
    };

    const toggleSelect = (id: number) =>
        setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]));
    const toggleAll = () => {
        const ids = filtered.map((c) => c.id);
        const all = ids.every((id) => selected.includes(id));
        setSelected(all ? [] : ids);
    };

    // Server-side, uncapped CSV export (the in-browser export was silently
    // truncated at the 300-row index cap). Streams the chosen dataset.
    const EXPORT_DATASETS = new Set(['pipeline', 'requisitions', 'offers', 'analytics']);
    const exportData = (dataset?: string) => {
        const ds = dataset && EXPORT_DATASETS.has(dataset) ? dataset : 'pipeline';
        window.location.href = `/hr/recruitment/export?dataset=${ds}&format=csv`;
        toast.success(`Exporting ${ds}…`);
    };

    /* ---- context menus ---- */
    const candidateCtxItems = (c: HubCandidate): ShiftCtxItem[] => {
        const items: ShiftCtxItem[] = [
            { icon: <Users className="h-4 w-4" />, label: 'Open dossier', tone: 'primary', onClick: () => setSheetId(c.id) },
        ];
        if (can.manage) {
            items.push(
                { icon: <ChevronDown className="h-4 w-4" />, label: 'Advance stage', onClick: () => advance(c) },
                { icon: <CalendarPlus className="h-4 w-4" />, label: 'Schedule interview', onClick: () => openWizard('interview', candidateCtx(c)) },
                { icon: <Send className="h-4 w-4" />, label: 'Create offer', onClick: () => openWizard('offer', candidateCtx(c)) },
                { icon: <UserCheck className="h-4 w-4" />, label: 'Request reference', onClick: () => openWizard('reference', candidateCtx(c)) },
                { icon: <FileText className="h-4 w-4" />, label: 'Upload document', onClick: () => openWizard('document', candidateCtx(c)) },
                { sep: true },
                { icon: <XCircle className="h-4 w-4" />, label: 'Reject…', tone: 'critical', onClick: () => openWizard('reject', candidateCtx(c)) },
            );
        }
        return items;
    };

    const openCandidateCtx = (e: MouseEvent, c: HubCandidate) => {
        e.preventDefault();
        setCtx({
            x: e.clientX,
            y: e.clientY,
            tag: stageLabel(c.stage),
            tagBg: stageColors(c.stage).bg,
            tagColor: stageColors(c.stage).text,
            meta: `${c.full_name} · ${c.requisition?.title ?? 'No requisition'}`,
            items: candidateCtxItems(c),
        });
    };

    const openTabCtx = (id: string, e: MouseEvent) => {
        e.preventDefault();
        setCtx({
            x: e.clientX,
            y: e.clientY,
            tag: 'View',
            meta: TAB_LABEL[id] ?? id,
            items: [
                {
                    icon: <Sparkles className="h-4 w-4" />,
                    label: 'Set as default view',
                    onClick: () => {
                        window.localStorage.setItem('hrRecruit.defaultTab', id);
                        toast.success(`Default view set to ${TAB_LABEL[id] ?? id}`);
                    },
                },
            ],
        });
    };

    const heroHandlers = {
        onAddCandidate: () => openWizard('add'),
        onNewRequisition: () => openWizard('requisition'),
        onSchedule: () => setTab('interviews'),
        onReviewOffers: () => setTab('offers'),
        onExport: () => exportData(EXPORT_DATASETS.has(tab) ? tab : 'pipeline'),
        onStat: (t: string) => setTab(t),
        onNeed: (chip: { tab: string }) => setTab(chip.tab),
    };

    const tabItems: HrTabItem[] = [
        ...PRIMARY_TABS.map((t) => ({
            ...t,
            badge: tabBadge(t.id, { candidates, requisitions, interviews, offers }),
        })),
        ...MORE_TABS.filter((t) => t.id === tab),
    ];

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'HR', href: '/hr' },
                { title: 'Recruitment', href: '/hr/recruitment' },
            ]}
        >
            <Head title="Recruitment" />
            <PageShell>
                <div className="flex flex-col gap-5">
                    <RecruitmentHero hero={hero} needs={needs} canManage={can.manage} handlers={heroHandlers} />

                    <HrTabs
                        value={tab}
                        onChange={(t) => {
                            setTab(t);
                            setMoreOpen(false);
                        }}
                        items={tabItems}
                        onItemContextMenu={openTabCtx}
                        trailing={
                            <div className="relative ml-auto">
                                <button
                                    type="button"
                                    onClick={() => setMoreOpen((o) => !o)}
                                    className="inline-flex items-center gap-1.5 rounded-[9px] px-3 py-2 text-[13px] font-semibold text-muted-foreground hover:bg-accent hover:text-foreground"
                                >
                                    <MoreHorizontal className="h-4 w-4" /> More
                                </button>
                                {moreOpen ? (
                                    <>
                                        <div className="fixed inset-0 z-40" onClick={() => setMoreOpen(false)} />
                                        <div className="absolute right-0 z-50 mt-1 w-48 rounded-xl border border-border bg-popover p-1.5 shadow-lg">
                                            {MORE_TABS.map((t) => {
                                                const Icon = t.icon;
                                                return (
                                                    <button
                                                        key={t.id}
                                                        type="button"
                                                        onClick={() => {
                                                            setTab(t.id);
                                                            setMoreOpen(false);
                                                        }}
                                                        className="flex w-full items-center gap-2.5 rounded-md px-2 py-1.5 text-[13px] hover:bg-accent"
                                                    >
                                                        <Icon className="h-4 w-4 text-muted-foreground" /> {t.label}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    </>
                                ) : null}
                            </div>
                        }
                    />

                    {flash?.error ? (
                        <div className="rounded-xl border border-status-critical/30 bg-status-critical-bg px-4 py-2.5 text-[13px] text-status-critical">
                            {flash.error}
                        </div>
                    ) : null}

                    <div className="motion-safe:animate-in motion-safe:fade-in-0">
                        {tab === 'pipeline' ? (
                            <PipelineTab
                                rows={filtered}
                                total={candidates.length}
                                stageCounts={stageCounts}
                                search={search}
                                setSearch={setSearch}
                                stageFilter={stageFilter}
                                setStageFilter={setStageFilter}
                                tagFilter={tagFilter}
                                setTagFilter={setTagFilter}
                                dupOnly={dupOnly}
                                setDupOnly={setDupOnly}
                                selected={selected}
                                toggleSelect={toggleSelect}
                                toggleAll={toggleAll}
                                clearSelection={() => setSelected([])}
                                onExport={() => exportData('pipeline')}
                                onManageTags={() => setManageTagsOpen(true)}
                                onOpen={(c) => setSheetId(c.id)}
                                onCtx={openCandidateCtx}
                                onBulkAdvance={() => bulkAction('advance')}
                                onBulkReject={() => setBulkRejectOpen(true)}
                                onBulkPool={() => bulkAction('pool')}
                                onBulkEmail={() => setBulkEmailOpen(true)}
                                onBulkTag={bulkTag}
                                canManage={can.manage}
                            />
                        ) : null}

                        {tab === 'board' ? (
                            <BoardTab
                                candidates={candidates}
                                dragId={dragId}
                                dragOver={dragOver}
                                setDragId={setDragId}
                                setDragOver={setDragOver}
                                onDrop={boardDrop}
                                onOpen={(c) => setSheetId(c.id)}
                                onCtx={openCandidateCtx}
                                canManage={can.manage}
                            />
                        ) : null}

                        {tab === 'requisitions' ? (
                            <RequisitionsTab
                                requisitions={requisitions}
                                canManage={can.manage}
                                onNew={() => openWizard('requisition')}
                                onAction={(jobId, action) => {
                                    const urls: Record<string, string> = {
                                        submit: `/hr/recruitment/jobs/${jobId}/submit-approval`,
                                        approve: `/hr/recruitment/jobs/${jobId}/approve`,
                                        reject: `/hr/recruitment/jobs/${jobId}/reject-approval`,
                                        publish: `/hr/recruitment/jobs/${jobId}/publish`,
                                    };
                                    router.post(urls[action], {}, {
                                        preserveScroll: true,
                                        onSuccess: (pg) => {
                                            const f = (pg.props as { flash?: { error?: string; success?: string } }).flash;
                                            if (f?.error) toast.error(f.error);
                                            else toast.success(f?.success ?? 'Done');
                                        },
                                    });
                                }}
                            />
                        ) : null}

                        {tab === 'interviews' ? (
                            <InterviewsTab
                                data={interviews}
                                canManage={can.manage}
                                onNew={() => setTab('pipeline')}
                                onScore={(iv) => setScoreTarget({ id: iv.id, candidate: iv.candidate, kit_name: iv.kit_name, criteria: iv.criteria ?? [] })}
                            />
                        ) : null}

                        {tab === 'offers' ? (
                            <OffersTab
                                offers={offers}
                                canManage={can.manage}
                                onSend={(o) => sendOffer(o, false)}
                                onResend={(o) => sendOffer(o, true)}
                                onExpire={setExpireOffer}
                                onConvert={(o) => openWizard('convert', { offerId: o.id, candidateName: o.candidate, role: o.role })}
                                onAction={offerAction}
                            />
                        ) : null}

                        {tab === 'analytics' ? <AnalyticsTab data={analytics} onDrill={(stage) => { setStageFilter(stage); setTab('pipeline'); }} /> : null}
                        {tab === 'kits' ? (
                            <KitsTab
                                kits={kits}
                                canManage={can.manage}
                                onNew={() => setKitDialog({ kit: null })}
                                onEdit={(k) => setKitDialog({ kit: k })}
                                onToggle={(id) => {
                                    router.post(`/hr/recruitment/kits/${id}/toggle-active`, {}, {
                                        preserveScroll: true,
                                        onSuccess: (pg) => {
                                            const f = (pg.props as { flash?: { error?: string; success?: string } }).flash;
                                            if (f?.error) toast.error(f.error);
                                            else toast.success(f?.success ?? 'Kit updated');
                                        },
                                    });
                                }}
                            />
                        ) : null}
                        {tab === 'pool' ? (
                            <PoolTab
                                pool={pool}
                                requisitions={requisitions.filter((r) => r.status !== 'closed')}
                                canManage={can.manage}
                                onReactivate={(candidateId, requisitionId) => {
                                    router.post(
                                        `/hr/recruitment/candidates/${candidateId}/reactivate`,
                                        { requisition_id: requisitionId },
                                        {
                                            preserveScroll: true,
                                            onSuccess: (pg) => {
                                                const f = (pg.props as { flash?: { error?: string; success?: string } }).flash;
                                                if (f?.error) toast.error(f.error);
                                                else toast.success(f?.success ?? 'Candidate re-activated');
                                            },
                                        },
                                    );
                                }}
                            />
                        ) : null}
                    </div>
                </div>
            </PageShell>

            {sheetCandidate ? (
                <CandidateSheet
                    candidate={sheetCandidate}
                    canManage={can.manage}
                    onClose={() => setSheetId(null)}
                    onAdvance={() => advance(sheetCandidate)}
                    onWizard={(kind) => openWizard(kind, candidateCtx(sheetCandidate))}
                />
            ) : null}

            {wizard ? <RecruitmentWizards state={wizard} onClose={() => setWizard(null)} support={support} /> : null}
            {kitDialog ? <KitDialog open onClose={() => setKitDialog(null)} kit={kitDialog.kit} /> : null}
            {scoreTarget ? <ScoreDialog open onClose={() => setScoreTarget(null)} interview={scoreTarget} /> : null}
            <BulkEmailDialog open={bulkEmailOpen} onClose={() => setBulkEmailOpen(false)} candidateIds={selected} templates={email_templates} canManage={can.manage} />
            <BulkRejectDialog open={bulkRejectOpen} onClose={() => setBulkRejectOpen(false)} candidateIds={selected} onDone={() => setSelected([])} />
            <TagManagerDialog open={manageTagsOpen} onClose={() => setManageTagsOpen(false)} tags={support.tags} canManage={can.manage} />
            <TextPromptDialog
                open={tagPromptOpen}
                onClose={() => setTagPromptOpen(false)}
                onSubmit={submitBulkTag}
                title={`Tag ${selected.length} candidate${selected.length === 1 ? '' : 's'}`}
                description="Adds the tag to every selected candidate — existing tags are kept."
                label="Tag"
                placeholder="e.g. second-round"
                submitLabel="Apply tag"
            />
            <TextPromptDialog
                open={declineOfferId !== null}
                onClose={() => setDeclineOfferId(null)}
                onSubmit={submitDeclineReason}
                title="Request changes to this offer?"
                description="Sends the offer back to its author with your note — it leaves the approval queue until resubmitted."
                label="Reason"
                placeholder="e.g. salary sits above the band — needs GM sign-off"
                submitLabel="Request changes"
                required={false}
            />
            <TextPromptDialog
                open={expireOffer !== null}
                onClose={() => setExpireOffer(null)}
                onSubmit={submitOfferExpiry}
                title={`Expire ${expireOffer?.candidate ?? 'this candidate'}'s offer?`}
                description="This immediately invalidates the current portal link. The offer history is kept, and Resend link is the intentional way to revive it with a new token."
                label="Reason"
                placeholder="e.g. package is being revised at the candidate's request"
                submitLabel="Expire offer"
                required
            />
            <TextPromptDialog
                open={scorecardOverride !== null}
                onClose={() => setScorecardOverride(null)}
                onSubmit={submitScorecardOverride}
                title="Advance without every scorecard?"
                description="Use only when the missing panel score cannot reasonably be obtained. The reason and missing interviewer IDs are added to the audit trail."
                label="Override reason"
                placeholder="e.g. panel member left the organisation before submitting"
                submitLabel="Advance with override"
                required
            />
            {ctx ? <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} /> : null}
        </AppLayout>
    );

    function sendOffer(o: OfferRow, resend: boolean) {
        const url = resend ? `/hr/recruitment/offers/${o.id}/resend` : `/hr/recruitment/offers/${o.id}/send`;
        router.post(url, {}, {
            preserveScroll: true,
            onSuccess: (pg) => {
                const f = (pg.props as { flash?: { error?: string } }).flash;
                if (f?.error) toast.error(f.error);
                else toast.success(resend ? `Offer link resent to ${o.candidate}` : `Offer emailed to ${o.candidate}`);
            },
        });
    }

    function submitOfferExpiry(reason: string) {
        if (!expireOffer) return;
        router.post(
            `/hr/recruitment/offers/${expireOffer.id}/expire`,
            { reason },
            {
                preserveScroll: true,
                onSuccess: (pg) => {
                    const f = (pg.props as { flash?: { error?: string; success?: string } }).flash;
                    if (f?.error) toast.error(f.error);
                    else {
                        toast.success(f?.success ?? 'Offer expired');
                        setExpireOffer(null);
                    }
                },
            },
        );
    }

    function submitScorecardOverride(reason: string) {
        if (!scorecardOverride?.candidate.application_id) return;
        router.post(
            `/hr/recruitment/applications/${scorecardOverride.candidate.application_id}/advance`,
            {
                target_stage: scorecardOverride.targetStage,
                scorecard_override_reason: reason,
            },
            {
                preserveScroll: true,
                onSuccess: (pg) => {
                    const f = (pg.props as { flash?: { error?: string; success?: string } }).flash;
                    if (f?.error) toast.error(f.error);
                    else {
                        toast.success(f?.success ?? 'Candidate advanced with audited override');
                        setScorecardOverride(null);
                    }
                },
            },
        );
    }

    function offerAction(offerId: number, action: 'submit' | 'approve' | 'decline') {
        const urls: Record<typeof action, string> = {
            submit: `/hr/recruitment/offers/${offerId}/submit-approval`,
            approve: `/hr/recruitment/offers/${offerId}/approve`,
            decline: `/hr/recruitment/offers/${offerId}/decline-approval`,
        };
        const onSuccess = (pg: { props: object }) => {
            const f = (pg.props as { flash?: { error?: string; success?: string } }).flash;
            if (f?.error) toast.error(f.error);
            else toast.success(f?.success ?? 'Done');
        };
        if (action === 'decline') {
            setDeclineOfferId(offerId);
            return;
        }
        router.post(urls[action], {}, { preserveScroll: true, onSuccess });
    }

    function submitDeclineReason(reason: string) {
        if (declineOfferId === null) return;
        router.post(
            `/hr/recruitment/offers/${declineOfferId}/decline-approval`,
            { reason },
            {
                preserveScroll: true,
                onSuccess: (pg) => {
                    const f = (pg.props as { flash?: { error?: string; success?: string } }).flash;
                    if (f?.error) toast.error(f.error);
                    else toast.success(f?.success ?? 'Changes requested');
                },
            },
        );
    }
}

function candidateCtx(c: HubCandidate): WizardContext {
    return {
        candidateId: c.id,
        applicationId: c.application_id ?? undefined,
        candidateName: c.full_name,
        role: c.requisition?.title,
    };
}

function tabBadge(
    id: string,
    d: { candidates: HubCandidate[]; requisitions: Requisition[]; interviews: { week: WeekInterview[] }; offers: { list: OfferRow[] } },
): number | undefined {
    switch (id) {
        case 'pipeline':
        case 'board':
            return d.candidates.length || undefined;
        case 'requisitions':
            return d.requisitions.filter((r) => r.status !== 'closed').length || undefined;
        case 'interviews':
            return d.interviews.week.length || undefined;
        case 'offers':
            return d.offers.list.filter((o) => o.status === 'sent' || o.status === 'accepted').length || undefined;
        default:
            return undefined;
    }
}

/* ================================================================== */
/*  Pipeline                                                          */
/* ================================================================== */

function PipelineTab({
    rows,
    total,
    stageCounts,
    search,
    setSearch,
    stageFilter,
    setStageFilter,
    tagFilter,
    setTagFilter,
    dupOnly,
    setDupOnly,
    selected,
    toggleSelect,
    toggleAll,
    clearSelection,
    onExport,
    onManageTags,
    onOpen,
    onCtx,
    onBulkAdvance,
    onBulkReject,
    onBulkPool,
    onBulkEmail,
    onBulkTag,
    canManage,
}: {
    rows: HubCandidate[];
    total: number;
    stageCounts: Record<string, number>;
    search: string;
    setSearch: (v: string) => void;
    stageFilter: string;
    setStageFilter: (v: string) => void;
    tagFilter: string | null;
    setTagFilter: (v: string | null) => void;
    dupOnly: boolean;
    setDupOnly: (v: boolean) => void;
    selected: number[];
    toggleSelect: (id: number) => void;
    toggleAll: () => void;
    clearSelection: () => void;
    onExport: () => void;
    onManageTags: () => void;
    onOpen: (c: HubCandidate) => void;
    onCtx: (e: MouseEvent, c: HubCandidate) => void;
    onBulkAdvance: () => void;
    onBulkReject: () => void;
    onBulkPool: () => void;
    onBulkEmail: () => void;
    onBulkTag: () => void;
    canManage: boolean;
}) {
    const chips = [
        { key: 'all', label: 'All' },
        ...BOARD_STAGES.map((s) => ({ key: s, label: stageLabel(s) })),
    ];
    const allChecked = rows.length > 0 && rows.every((c) => selected.includes(c.id));
    const [sortByScore, setSortByScore] = useState(false);
    const displayRows = sortByScore
        ? [...rows].sort((a, b) => (b.score ?? -1) - (a.score ?? -1))
        : rows;

    return (
        <div>
            <div className="mb-4 flex flex-wrap items-center gap-2.5">
                <div className="relative min-w-[220px] max-w-[340px] flex-1">
                    <Search className="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
                    <input
                        type="text"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Search candidates…"
                        className="h-[38px] w-full rounded-[10px] border border-border bg-card pl-9 pr-3 text-[13px] outline-none focus:border-primary"
                    />
                </div>
                <div className="flex flex-wrap gap-2">
                    {chips.map((c) => {
                        const on = stageFilter === c.key;
                        return (
                            <button
                                key={c.key}
                                type="button"
                                onClick={() => setStageFilter(c.key)}
                                className={`rounded-full border px-3 py-1.5 text-[12.5px] font-semibold transition-colors ${on ? 'border-primary bg-primary/10 text-primary' : 'border-border bg-card text-foreground hover:border-primary/40'}`}
                            >
                                {c.label}
                                <span className="ml-1.5 tabular-nums opacity-60">{stageCounts[c.key] ?? 0}</span>
                            </button>
                        );
                    })}
                </div>
                {tagFilter ? (
                    <button
                        type="button"
                        onClick={() => setTagFilter(null)}
                        title="Clear tag filter"
                        className="inline-flex h-[30px] items-center gap-1.5 rounded-full border border-primary bg-primary/10 px-3 text-[12.5px] font-semibold text-primary hover:bg-primary/15"
                    >
                        Tag: {tagFilter} <span aria-hidden className="text-[13px] leading-none">✕</span>
                    </button>
                ) : null}
                {dupOnly ? (
                    <button
                        type="button"
                        onClick={() => setDupOnly(false)}
                        title="Clear duplicate filter"
                        className="inline-flex h-[30px] items-center gap-1.5 rounded-full border border-status-warning/40 bg-status-warning-bg px-3 text-[12.5px] font-semibold text-status-warning"
                    >
                        Possible duplicates <span aria-hidden className="text-[13px] leading-none">✕</span>
                    </button>
                ) : null}
                <button
                    type="button"
                    onClick={() => setSortByScore((s) => !s)}
                    aria-pressed={sortByScore}
                    className={`ml-auto inline-flex h-[38px] items-center gap-2 rounded-[10px] border px-3.5 text-[13px] font-semibold transition-colors ${sortByScore ? 'border-primary bg-primary/10 text-primary' : 'border-border bg-card hover:bg-muted'}`}
                >
                    <BarChart3 className="h-3.5 w-3.5" /> {sortByScore ? 'Top scored' : 'Sort by score'}
                </button>
                {canManage ? (
                    <button
                        type="button"
                        onClick={onManageTags}
                        className="inline-flex h-[38px] items-center gap-2 rounded-[10px] border border-border bg-card px-3.5 text-[13px] font-semibold hover:bg-muted"
                    >
                        <Tags className="h-3.5 w-3.5" /> Tags
                    </button>
                ) : null}
                <button
                    type="button"
                    onClick={onExport}
                    className="inline-flex h-[38px] items-center gap-2 rounded-[10px] border border-border bg-card px-3.5 text-[13px] font-semibold hover:bg-muted"
                >
                    <Download className="h-3.5 w-3.5" /> Export
                </button>
            </div>

            {canManage && selected.length > 0 ? (
                <div className="mb-3.5 flex items-center gap-3 rounded-xl border border-primary/30 bg-primary/5 px-3.5 py-2.5 motion-safe:animate-in motion-safe:fade-in-0">
                    <span className="text-[13px] font-bold text-primary">{selected.length} selected</span>
                    <div className="h-4 w-px bg-border" />
                    <button type="button" onClick={onBulkAdvance} className="rounded-lg border border-border bg-card px-2.5 py-1 text-[12.5px] font-semibold hover:bg-muted">
                        Advance stage
                    </button>
                    <button type="button" onClick={onBulkEmail} className="rounded-lg border border-border bg-card px-2.5 py-1 text-[12.5px] font-semibold hover:bg-muted">
                        Email
                    </button>
                    <button type="button" onClick={onBulkPool} className="rounded-lg border border-border bg-card px-2.5 py-1 text-[12.5px] font-semibold hover:bg-muted">
                        Add to pool
                    </button>
                    <button type="button" onClick={onBulkTag} className="rounded-lg border border-border bg-card px-2.5 py-1 text-[12.5px] font-semibold hover:bg-muted">
                        Tag
                    </button>
                    <button type="button" onClick={onBulkReject} className="rounded-lg border border-status-critical/30 bg-status-critical-bg px-2.5 py-1 text-[12.5px] font-semibold text-status-critical">
                        Reject
                    </button>
                    <button type="button" onClick={clearSelection} className="ml-auto text-[12.5px] font-semibold text-muted-foreground">
                        Clear
                    </button>
                </div>
            ) : null}

            <div className="overflow-hidden rounded-[14px] border border-border bg-card">
                <div className="grid grid-cols-[36px_2.4fr_1.2fr_1.4fr_1fr_0.7fr_0.7fr_32px] items-center gap-2.5 border-b border-border bg-muted px-4 py-2.5 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">
                    {canManage ? (
                        <button type="button" onClick={toggleAll} aria-label="Select all" className={`grid h-[18px] w-[18px] place-items-center rounded border ${allChecked ? 'border-primary bg-primary text-primary-foreground' : 'border-border'}`}>
                            {allChecked ? '✓' : ''}
                        </button>
                    ) : (
                        <span />
                    )}
                    <span>Candidate</span>
                    <span>Stage</span>
                    <span>Requisition</span>
                    <span>Source</span>
                    <span title="Average interview scorecard rating">Score</span>
                    <span>Days</span>
                    <span />
                </div>

                {rows.length === 0 ? (
                    <div className="flex flex-col items-center justify-center px-5 py-16 text-center">
                        <div className="mb-3.5 grid h-12 w-12 place-items-center rounded-[14px] bg-muted text-muted-foreground">
                            <Search className="h-6 w-6" />
                        </div>
                        <h3 className="text-[15px] font-bold">No candidates match your filters</h3>
                        <p className="text-[13px] text-muted-foreground">Try clearing the stage filter or search term.</p>
                    </div>
                ) : (
                    displayRows.map((c) => (
                        <div
                            key={c.id}
                            onContextMenu={(e) => onCtx(e, c)}
                            className="grid grid-cols-[36px_2.4fr_1.2fr_1.4fr_1fr_0.7fr_0.7fr_32px] items-center gap-2.5 border-b border-border px-4 py-2.5 last:border-0 hover:bg-muted/40"
                        >
                            {canManage ? (
                                <button type="button" onClick={() => toggleSelect(c.id)} aria-label={`Select ${c.full_name}`} className={`grid h-[18px] w-[18px] place-items-center rounded border ${selected.includes(c.id) ? 'border-primary bg-primary text-primary-foreground' : 'border-border'}`}>
                                    {selected.includes(c.id) ? '✓' : ''}
                                </button>
                            ) : (
                                <span />
                            )}
                            <button type="button" onClick={() => onOpen(c)} className="flex min-w-0 items-center gap-2.5 text-left">
                                <span style={avatarStyle(c.full_name)}>{initials(c.full_name)}</span>
                                <span className="min-w-0">
                                    <span className="block truncate text-[13.5px] font-semibold">{c.full_name}</span>
                                    <span className="block truncate text-[11.5px] text-muted-foreground">{c.email}</span>
                                    {c.possible_duplicate ? (
                                        <span
                                            role="button"
                                            tabIndex={0}
                                            onClick={(e) => { e.stopPropagation(); setDupOnly(true); }}
                                            onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); e.stopPropagation(); setDupOnly(true); } }}
                                            title={c.possible_duplicate === 'email' ? 'Possible duplicate — shares an email with another candidate' : 'Possible duplicate — shares a name and phone with another candidate'}
                                            className="mt-0.5 inline-flex w-fit cursor-pointer items-center gap-1 rounded bg-status-warning-bg px-1.5 py-0.5 text-[10px] font-semibold text-status-warning hover:brightness-95"
                                        >
                                            <span aria-hidden>⚠</span> Possible duplicate
                                        </span>
                                    ) : null}
                                    {c.tags.length > 0 ? (
                                        <span className="mt-0.5 flex flex-wrap items-center gap-1">
                                            {c.tags.slice(0, 3).map((t) => (
                                                <span
                                                    key={t}
                                                    role="button"
                                                    tabIndex={0}
                                                    onClick={(e) => { e.stopPropagation(); setTagFilter(t); }}
                                                    onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); e.stopPropagation(); setTagFilter(t); } }}
                                                    title={`Filter by “${t}”`}
                                                    className={`cursor-pointer rounded px-1.5 py-0.5 text-[10px] font-semibold transition-colors ${tagFilter?.toLowerCase() === t.toLowerCase() ? 'bg-primary/15 text-primary' : 'bg-muted text-muted-foreground hover:bg-primary/10 hover:text-primary'}`}
                                                >{t}</span>
                                            ))}
                                            {c.tags.length > 3 ? <span className="text-[10px] text-muted-foreground">+{c.tags.length - 3}</span> : null}
                                        </span>
                                    ) : null}
                                </span>
                            </button>
                            <span>
                                <span style={stageBadgeStyle(c.stage)}>
                                    <span style={stageDotStyle(c.stage)} />
                                    {stageLabel(c.stage)}
                                </span>
                            </span>
                            <span className="truncate text-[12.5px] text-muted-foreground">{c.requisition?.title ?? '—'}</span>
                            <span className="truncate text-[12.5px] capitalize text-muted-foreground">{c.source?.replace(/_/g, ' ')}</span>
                            <span>
                                {c.score != null ? (
                                    <span
                                        title={`${c.score_count ?? 0} scorecard${(c.score_count ?? 0) === 1 ? '' : 's'}`}
                                        className={`rounded-md px-2 py-0.5 text-[11px] font-bold tabular-nums ${c.score >= 70 ? 'bg-status-success-bg text-status-success' : c.score >= 50 ? 'bg-muted text-foreground' : 'bg-status-warning-bg text-status-warning'}`}
                                    >
                                        {c.score}
                                    </span>
                                ) : (
                                    <span className="text-[12px] text-muted-foreground">—</span>
                                )}
                            </span>
                            <span>
                                <span className={`rounded-md px-2 py-0.5 text-[11px] font-bold tabular-nums ${c.stale ? 'bg-status-warning-bg text-status-warning' : 'bg-muted text-muted-foreground'}`}>
                                    {daysLabel(c.days)}
                                </span>
                            </span>
                            <button type="button" onClick={(e) => onCtx(e, c)} aria-label="Row menu" className="grid h-7 w-7 place-items-center rounded-md text-muted-foreground hover:bg-muted">
                                <MoreHorizontal className="h-4 w-4" />
                            </button>
                        </div>
                    ))
                )}
            </div>
            <div className="mt-3 text-[12.5px] text-muted-foreground">
                Showing {rows.length} of {total} candidates
            </div>
        </div>
    );
}

/* ================================================================== */
/*  Board                                                             */
/* ================================================================== */

function BoardTab({
    candidates,
    dragId,
    dragOver,
    setDragId,
    setDragOver,
    onDrop,
    onOpen,
    onCtx,
    canManage,
}: {
    candidates: HubCandidate[];
    dragId: number | null;
    dragOver: string | null;
    setDragId: (id: number | null) => void;
    setDragOver: (s: string | null) => void;
    onDrop: (c: HubCandidate, stage: string) => void;
    onOpen: (c: HubCandidate) => void;
    onCtx: (e: MouseEvent, c: HubCandidate) => void;
    canManage: boolean;
}) {
    return (
        <div>
            <p className="mb-3.5 text-[13px] text-muted-foreground">
                {canManage ? 'Drag a candidate between stages to advance the pipeline. Aging cards are flagged.' : 'Candidates by stage. Aging cards are flagged.'}
            </p>
            <div className="flex gap-3.5 overflow-x-auto pb-3.5">
                {BOARD_STAGES.map((stage) => {
                    const cards = candidates.filter((c) => c.stage === stage);
                    const aging = cards.some((c) => c.stale);
                    const isOver = dragOver === stage;
                    return (
                        <div
                            key={stage}
                            onDragOver={(e) => {
                                if (!canManage) return;
                                e.preventDefault();
                                if (dragOver !== stage) setDragOver(stage);
                            }}
                            onDrop={() => {
                                if (!canManage) return;
                                const c = candidates.find((x) => x.id === dragId);
                                setDragOver(null);
                                setDragId(null);
                                if (c) onDrop(c, stage);
                            }}
                            className={`w-[244px] flex-none rounded-[14px] border p-2.5 transition-colors ${isOver ? 'border-primary bg-primary/5' : 'border-border bg-muted/30'}`}
                        >
                            <div className="mb-2.5 flex items-center gap-2 px-0.5">
                                <span style={stageDotStyle(stage)} />
                                <span className="text-[12.5px] font-bold">{stageLabel(stage)}</span>
                                <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] font-bold tabular-nums text-muted-foreground">{cards.length}</span>
                                {aging ? <Clock aria-label="Cards aging in this stage" className="ml-auto h-3.5 w-3.5 text-status-warning" /> : null}
                            </div>
                            <div className="flex min-h-[60px] flex-col gap-2.5">
                                {cards.map((c) => (
                                    <div
                                        key={c.id}
                                        draggable={canManage}
                                        onDragStart={() => setDragId(c.id)}
                                        onDragEnd={() => {
                                            setDragId(null);
                                            setDragOver(null);
                                        }}
                                        onContextMenu={(e) => onCtx(e, c)}
                                        onClick={() => onOpen(c)}
                                        className={`cursor-pointer rounded-[10px] border border-border bg-card p-2.5 shadow-sm transition-shadow hover:shadow-md ${dragId === c.id ? 'opacity-50' : ''}`}
                                    >
                                        <div className="flex items-center gap-2.5">
                                            <span style={avatarStyle(c.full_name, 30)}>{initials(c.full_name)}</span>
                                            <div className="min-w-0 flex-1">
                                                <div className="truncate text-[13px] font-semibold">{c.full_name}</div>
                                                <div className="truncate text-[11px] text-muted-foreground">{c.requisition?.title ?? '—'}</div>
                                            </div>
                                        </div>
                                        <div className="mt-2.5 flex items-center justify-between">
                                            <span className="rounded-md bg-muted px-1.5 py-0.5 text-[10.5px] font-semibold capitalize text-muted-foreground">{c.source?.replace(/_/g, ' ')}</span>
                                            <span className={`rounded-md px-1.5 py-0.5 text-[10.5px] font-bold tabular-nums ${c.stale ? 'bg-status-warning-bg text-status-warning' : 'bg-muted text-muted-foreground'}`}>{daysLabel(c.days)}</span>
                                        </div>
                                    </div>
                                ))}
                                {cards.length === 0 ? (
                                    <div className="rounded-[10px] border border-dashed border-border px-2.5 py-4 text-center text-[11.5px] text-muted-foreground">Drop here</div>
                                ) : null}
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

/* ================================================================== */
/*  Requisitions                                                      */
/* ================================================================== */

const REQ_STATUS_VARIANT: Record<string, 'success' | 'warning' | 'critical' | 'info' | 'neutral'> = {
    published: 'success',
    draft: 'neutral',
    paused: 'warning',
    closed: 'critical',
    pending_approval: 'warning',
};

function RequisitionsTab({
    requisitions,
    canManage,
    onNew,
    onAction,
}: {
    requisitions: Requisition[];
    canManage: boolean;
    onNew: () => void;
    onAction: (jobId: number, action: 'submit' | 'approve' | 'reject' | 'publish') => void;
}) {
    return (
        <div>
            <div className="mb-4 flex items-center gap-3">
                <p className="text-[13px] text-muted-foreground">Open roles, status and applicant load. Each requisition fills an establishment seat.</p>
                {canManage ? (
                    <button type="button" onClick={onNew} className="ml-auto inline-flex h-[38px] items-center gap-2 rounded-[10px] bg-primary px-4 text-[13px] font-bold text-primary-foreground">
                        <FilePlus2 className="h-3.5 w-3.5" /> New requisition
                    </button>
                ) : null}
            </div>
            {requisitions.length === 0 ? (
                <EmptyCard icon={Briefcase} title="No requisitions yet" sub="Open a role to start a pipeline against an establishment seat." />
            ) : (
                <div className="grid gap-3.5 [grid-template-columns:repeat(auto-fill,minmax(330px,1fr))]">
                    {requisitions.map((r) => (
                        <div key={r.id} className="rounded-[14px] border border-border bg-card p-4 shadow-sm">
                            <div className="flex items-start gap-2.5">
                                <div className="min-w-0 flex-1">
                                    <div className="text-[15px] font-bold">{r.title}</div>
                                    <div className="mt-0.5 text-[12px] text-muted-foreground">{r.site}</div>
                                </div>
                                <StatusBadge variant={REQ_STATUS_VARIANT[r.status] ?? 'neutral'} size="sm" label={stageLabel(r.status)} />
                            </div>
                            <div className="mt-2.5 inline-flex items-center gap-1.5 rounded-md bg-primary/10 px-2 py-1 text-[11px] font-semibold text-primary">
                                <Briefcase className="h-3 w-3" />
                                {r.position}
                            </div>
                            <div className="mt-3.5 flex gap-5 border-t border-border pt-3">
                                <Stat value={r.openings} label="Openings" />
                                <Stat value={r.applicants} label="Applicants" />
                                <div className="flex-1 text-right">
                                    <div className="text-[13px] font-bold capitalize">{r.pay ?? r.employment_type?.replace(/_/g, ' ')}</div>
                                    <div className="text-[10.5px] text-muted-foreground">{r.hiring_manager ?? 'Unassigned'}</div>
                                </div>
                            </div>
                            {canManage ? (
                                <div className="mt-3 flex flex-wrap gap-2">
                                    {r.status === 'pending_approval' ? (
                                        <>
                                            <button type="button" onClick={() => onAction(r.id, 'approve')} className="h-8 rounded-md bg-status-success px-3 text-[12px] font-bold text-white">Approve</button>
                                            <button type="button" onClick={() => onAction(r.id, 'reject')} className="h-8 rounded-md border border-status-critical/30 bg-status-critical-bg px-3 text-[12px] font-semibold text-status-critical">Reject</button>
                                        </>
                                    ) : r.status === 'draft' && r.requires_approval ? (
                                        <button type="button" onClick={() => onAction(r.id, 'submit')} className="h-8 rounded-md border border-primary bg-primary/10 px-3 text-[12px] font-bold text-primary">Submit for approval</button>
                                    ) : r.status === 'draft' || r.status === 'paused' ? (
                                        <button type="button" onClick={() => onAction(r.id, 'publish')} className="h-8 rounded-md border border-primary bg-primary/10 px-3 text-[12px] font-bold text-primary">Publish</button>
                                    ) : null}
                                </div>
                            ) : null}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

function Stat({ value, label }: { value: number; label: string }) {
    return (
        <div>
            <div className="text-[18px] font-extrabold tabular-nums">{value}</div>
            <div className="text-[10.5px] font-semibold uppercase tracking-wide text-muted-foreground">{label}</div>
        </div>
    );
}

/* ================================================================== */
/*  Interviews                                                        */
/* ================================================================== */

function InterviewsTab({ data, canManage, onNew, onScore }: { data: { week: WeekInterview[]; consensus: Consensus }; canManage: boolean; onNew: () => void; onScore: (iv: WeekInterview) => void }) {
    return (
        <div>
            <div className="mb-4 flex items-center gap-3">
                <p className="text-[13px] text-muted-foreground">This week's panels and structured scorecards.</p>
                {canManage ? (
                    <button type="button" onClick={onNew} className="ml-auto inline-flex h-[38px] items-center gap-2 rounded-[10px] bg-primary px-4 text-[13px] font-bold text-primary-foreground">
                        <CalendarPlus className="h-3.5 w-3.5" /> Schedule (from a candidate)
                    </button>
                ) : null}
            </div>
            <div className="grid gap-4 lg:grid-cols-[1.5fr_1fr]">
                <div className="rounded-[14px] border border-border bg-card p-4">
                    <div className="mb-3 text-[12px] font-bold">This week</div>
                    {data.week.length === 0 ? (
                        <p className="py-8 text-center text-[13px] text-muted-foreground">No interviews scheduled this week.</p>
                    ) : (
                        <div className="flex flex-col gap-2">
                            {data.week.map((iv) => (
                                <div key={iv.id} className="flex items-center gap-3 rounded-[10px] border border-border px-3 py-2.5">
                                    <span style={avatarStyle(iv.candidate, 32)}>{initials(iv.candidate)}</span>
                                    <div className="min-w-0 flex-1">
                                        <div className="truncate text-[13px] font-semibold">{iv.candidate}</div>
                                        <div className="text-[11.5px] capitalize text-muted-foreground">{iv.type?.replace(/_/g, ' ')}</div>
                                    </div>
                                    <div className="text-right">
                                        <div className="text-[12px] font-semibold">{iv.scheduled_at ? new Date(iv.scheduled_at).toLocaleDateString('en-NZ', { weekday: 'short', day: 'numeric', month: 'short' }) : '—'}</div>
                                        <div className="text-[11px] text-muted-foreground">{iv.scheduled_at ? new Date(iv.scheduled_at).toLocaleTimeString('en-NZ', { hour: 'numeric', minute: '2-digit' }) : ''}</div>
                                    </div>
                                    {canManage ? (
                                        <button
                                            type="button"
                                            onClick={() => onScore(iv)}
                                            className={`h-8 shrink-0 rounded-md border px-2.5 text-[12px] font-bold ${iv.scored ? 'border-border bg-card text-muted-foreground hover:bg-muted' : 'border-primary bg-primary/10 text-primary'}`}
                                        >
                                            {iv.scored ? 'Re-score' : 'Score'}
                                        </button>
                                    ) : null}
                                </div>
                            ))}
                        </div>
                    )}
                </div>
                <div className="rounded-[14px] border border-border bg-card p-4">
                    {data.consensus ? (
                        <>
                            <div className="text-[12px] font-bold">Panel consensus · {data.consensus.name}</div>
                            <div className="mb-3.5 text-[11.5px] text-muted-foreground">{data.consensus.role} · {data.consensus.rec_sub}</div>
                            {data.consensus.criteria.map((cr) => (
                                <div key={cr.label} className="mb-3">
                                    <div className="mb-1.5 flex justify-between gap-2 text-[12px]">
                                        <span className="truncate font-semibold">{cr.label}</span>
                                        <span className="font-bold tabular-nums text-primary">{cr.avg}</span>
                                    </div>
                                    <div className="relative h-2 rounded-full bg-muted">
                                        <div className="absolute inset-y-0 left-0 rounded-full bg-primary" style={{ width: `${(cr.avg / 5) * 100}%` }} />
                                    </div>
                                </div>
                            ))}
                            <div className="mt-4 flex items-center gap-2.5 rounded-xl border border-status-success/25 bg-status-success-bg px-3 py-3">
                                <UserCheck className="h-4.5 w-4.5 text-status-success" />
                                <div>
                                    <div className="text-[12.5px] font-bold text-status-success">{data.consensus.rec}</div>
                                    <div className="text-[11px] text-status-success/80">{data.consensus.rec_sub}</div>
                                </div>
                            </div>
                        </>
                    ) : (
                        <p className="py-8 text-center text-[13px] text-muted-foreground">No scorecards submitted yet.</p>
                    )}
                </div>
            </div>
        </div>
    );
}

/* ================================================================== */
/*  Offers                                                            */
/* ================================================================== */

const OFFER_VARIANT: Record<string, 'success' | 'warning' | 'critical' | 'info' | 'neutral'> = {
    accepted: 'success',
    sent: 'info',
    approved: 'info',
    pending_approval: 'warning',
    changes_requested: 'warning',
    declined: 'critical',
    withdrawn: 'critical',
    expired: 'critical',
    draft: 'neutral',
};

function OffersTab({
    offers,
    canManage,
    onSend,
    onResend,
    onExpire,
    onConvert,
    onAction,
}: {
    offers: { summary: { key: string; label: string; count: number; color: string }[]; list: OfferRow[] };
    canManage: boolean;
    onSend: (o: OfferRow) => void;
    onResend: (o: OfferRow) => void;
    onExpire: (o: OfferRow) => void;
    onConvert: (o: OfferRow) => void;
    onAction: (offerId: number, action: 'submit' | 'approve' | 'decline') => void;
}) {
    return (
        <div>
            <p className="mb-4 text-[13px] text-muted-foreground">Offers from draft to accepted. Sending emails the candidate their portal link; accepted offers convert to staff.</p>
            <div className="mb-4 flex flex-wrap gap-2.5">
                {offers.summary.map((s) => (
                    <div key={s.key} className="min-w-[130px] flex-1 rounded-[12px] border border-border bg-card px-3.5 py-3">
                        <div className="text-[22px] font-extrabold tabular-nums" style={{ color: s.color }}>{s.count}</div>
                        <div className="text-[11.5px] font-semibold text-muted-foreground">{s.label}</div>
                    </div>
                ))}
            </div>
            {offers.list.length === 0 ? (
                <EmptyCard icon={Send} title="No offers yet" sub="Create an offer from a candidate to start the hire." />
            ) : (
                <div className="flex flex-col gap-2.5">
                    {offers.list.map((o) => (
                        <div key={o.id} className="flex items-center gap-3.5 rounded-[13px] border border-border bg-card px-4 py-3">
                            <span style={avatarStyle(o.candidate)}>{initials(o.candidate)}</span>
                            <div className="min-w-0 flex-1">
                                <div className="text-[14px] font-bold">{o.candidate}</div>
                                <div className="text-[12px] text-muted-foreground">{o.role} · {o.pay}</div>
                            </div>
                            <div className="mr-1.5 text-right">
                                <StatusBadge variant={OFFER_VARIANT[o.status] ?? 'neutral'} size="sm" label={stageLabel(o.status)} />
                                <div className="mt-1 text-[11px] text-muted-foreground">{o.meta}</div>
                            </div>
                            {canManage ? (
                                o.status === 'accepted' ? (
                                    <button type="button" onClick={() => onConvert(o)} className="h-[34px] rounded-[9px] bg-primary px-3.5 text-[12.5px] font-bold text-primary-foreground">Convert</button>
                                ) : o.status === 'approved' ? (
                                    <button type="button" onClick={() => onSend(o)} className="h-[34px] rounded-[9px] border border-primary bg-primary/10 px-3.5 text-[12.5px] font-bold text-primary">Send</button>
                                ) : o.status === 'pending_approval' ? (
                                    <div className="flex gap-2">
                                        <button type="button" onClick={() => onAction(o.id, 'approve')} className="h-[34px] rounded-[9px] border border-primary bg-primary/10 px-3.5 text-[12.5px] font-bold text-primary">Approve</button>
                                        <button type="button" onClick={() => onAction(o.id, 'decline')} className="h-[34px] rounded-[9px] border border-border bg-card px-3.5 text-[12.5px] font-semibold">Decline</button>
                                    </div>
                                ) : o.status === 'draft' || o.status === 'changes_requested' ? (
                                    <button type="button" onClick={() => onAction(o.id, 'submit')} className="h-[34px] rounded-[9px] border border-primary bg-primary/10 px-3.5 text-[12.5px] font-bold text-primary">Submit</button>
                                ) : o.status === 'sent' ? (
                                    <div className="flex gap-2">
                                        <button type="button" onClick={() => onResend(o)} className="h-[34px] rounded-[9px] border border-border bg-card px-3.5 text-[12.5px] font-semibold">Resend link</button>
                                        <button type="button" onClick={() => onExpire(o)} className="h-[34px] rounded-[9px] border border-status-critical/40 bg-status-critical/10 px-3.5 text-[12.5px] font-semibold text-status-critical">Expire</button>
                                    </div>
                                ) : o.status === 'expired' ? (
                                    <button type="button" onClick={() => onResend(o)} className="h-[34px] rounded-[9px] border border-border bg-card px-3.5 text-[12.5px] font-semibold">Resend link</button>
                                ) : (
                                    <span className="w-[60px]" />
                                )
                            ) : null}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

/* ================================================================== */
/*  Analytics                                                         */
/* ================================================================== */

// Terminal stages are excluded from the pipeline list, so drilling into them
// from the funnel would land on an empty view — keep them non-clickable.
const NON_DRILLABLE_STAGES = ['withdrawn', 'rejected', 'hired'];

function AnalyticsTab({ data, onDrill }: { data: AnalyticsData; onDrill: (stage: string) => void }) {
    const [from, setFrom] = useState(data.range?.from ?? '');
    const [to, setTo] = useState(data.range?.to ?? '');
    const hasFilter = Boolean(data.range?.from || data.range?.to);

    const apply = (next: { from?: string; to?: string }) => {
        const params: Record<string, string> = { tab: 'analytics' };
        const f = next.from ?? from;
        const t = next.to ?? to;
        if (f) params.from = f;
        if (t) params.to = t;
        router.get(window.location.pathname, params, { preserveState: true, preserveScroll: true });
    };
    const clear = () => {
        setFrom('');
        setTo('');
        router.get(window.location.pathname, { tab: 'analytics' }, { preserveState: true, preserveScroll: true });
    };

    return (
        <div>
            <div className="mb-4 flex flex-wrap items-end gap-3 rounded-[14px] border border-border bg-card px-4 py-3">
                <div>
                    <label className="mb-1 block text-[11px] font-bold uppercase tracking-wide text-muted-foreground">From</label>
                    <input type="date" value={from} max={to || undefined} onChange={(e) => setFrom(e.target.value)} className="h-9 rounded-md border border-border bg-background px-2.5 text-[13px] outline-none focus:border-primary" />
                </div>
                <div>
                    <label className="mb-1 block text-[11px] font-bold uppercase tracking-wide text-muted-foreground">To</label>
                    <input type="date" value={to} min={from || undefined} onChange={(e) => setTo(e.target.value)} className="h-9 rounded-md border border-border bg-background px-2.5 text-[13px] outline-none focus:border-primary" />
                </div>
                <button type="button" onClick={() => apply({})} className="h-9 rounded-md bg-primary px-4 text-[13px] font-bold text-primary-foreground">Apply</button>
                {hasFilter ? (
                    <button type="button" onClick={clear} className="h-9 rounded-md border border-border bg-card px-3 text-[13px] font-semibold hover:bg-muted">Clear</button>
                ) : null}
                <span className="ml-auto self-center text-[11.5px] text-muted-foreground">Scopes pipeline, sources &amp; open roles by candidate date.</span>
            </div>
            <div className="mb-4 flex flex-wrap gap-3">
                {data.kpis.map((k) => (
                    <div key={k.key} className="min-w-[170px] flex-1 rounded-[14px] border border-border bg-card px-4 py-3.5">
                        <div className="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">{k.label}</div>
                        <div className="mt-1 text-[28px] font-extrabold tabular-nums">{k.value}</div>
                    </div>
                ))}
            </div>
            <div className="grid gap-4 lg:grid-cols-[1.4fr_1fr]">
                <div className="rounded-[14px] border border-border bg-card p-4">
                    <div className="mb-3.5 text-[13px] font-bold">Conversion funnel</div>
                    {data.funnel.length === 0 ? (
                        <p className="py-6 text-center text-[13px] text-muted-foreground">Not enough data yet.</p>
                    ) : (
                        data.funnel.map((f) => {
                            const drillable = f.count > 0 && !NON_DRILLABLE_STAGES.includes(f.stage);
                            return (
                            <button
                                key={f.label}
                                type="button"
                                onClick={() => drillable && onDrill(f.stage)}
                                title={drillable ? `View ${f.count} in the pipeline` : undefined}
                                className={`mb-2.5 flex w-full items-center gap-3 rounded-lg text-left ${drillable ? 'cursor-pointer hover:bg-muted/50' : 'cursor-default'}`}
                            >
                                <span className="w-24 flex-none text-[12px] font-semibold">{f.label}</span>
                                <div className="relative h-[30px] flex-1 overflow-hidden rounded-lg bg-muted">
                                    <div className="flex h-full items-center rounded-lg bg-primary px-2.5 text-[12px] font-bold text-primary-foreground" style={{ width: `${Math.max(8, f.width)}%` }}>{f.count}</div>
                                </div>
                                <span className="w-14 flex-none text-right text-[11.5px] font-semibold text-muted-foreground">{f.rate}</span>
                            </button>
                            );
                        })
                    )}
                </div>
                <div className="rounded-[14px] border border-border bg-card p-4">
                    <div className="mb-3.5 text-[13px] font-bold">Source effectiveness</div>
                    {data.sources.length === 0 ? (
                        <p className="py-6 text-center text-[13px] text-muted-foreground">No source data yet.</p>
                    ) : (
                        data.sources.map((s) => (
                            <div key={s.name} className="mb-3">
                                <div className="mb-1.5 flex justify-between text-[12px]">
                                    <span className="font-semibold capitalize">{s.name?.replace(/_/g, ' ')}</span>
                                    <span className="text-muted-foreground">{s.detail}</span>
                                </div>
                                <div className="h-[7px] overflow-hidden rounded-full bg-muted">
                                    <div className="h-full rounded-full bg-primary" style={{ width: `${Math.max(4, s.width)}%` }} />
                                </div>
                            </div>
                        ))
                    )}
                </div>
            </div>

            {data.open_positions.length > 0 ? (
                <div className="mt-4 rounded-[14px] border border-border bg-card p-4">
                    <div className="mb-3.5 text-[13px] font-bold">Open positions <span className="font-normal text-muted-foreground">· by requisition</span></div>
                    <div className="overflow-hidden rounded-[10px] border border-border">
                        <div className="grid grid-cols-[2.5fr_1fr_1fr] gap-2 border-b border-border bg-muted px-3 py-2 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">
                            <span>Requisition</span>
                            <span className="text-right">Applicants</span>
                            <span className="text-right">Days open</span>
                        </div>
                        {data.open_positions.map((p, i) => (
                            <div key={`${p.requisition_id ?? 'none'}-${i}`} className="grid grid-cols-[2.5fr_1fr_1fr] gap-2 border-b border-border px-3 py-2 text-[12.5px] last:border-0">
                                <span className="truncate font-semibold">{p.title}</span>
                                <span className="text-right tabular-nums">{p.applications}</span>
                                <span className="text-right tabular-nums text-muted-foreground">{p.days_open}d</span>
                            </div>
                        ))}
                    </div>
                </div>
            ) : null}
        </div>
    );
}

/* ================================================================== */
/*  Kits + Pool                                                       */
/* ================================================================== */

function KitsTab({
    kits,
    canManage,
    onNew,
    onEdit,
    onToggle,
}: {
    kits: Kit[];
    canManage: boolean;
    onNew: () => void;
    onEdit: (k: KitDraft) => void;
    onToggle: (id: number) => void;
}) {
    const [active, setActive] = useState<number | null>(kits[0]?.id ?? null);
    const current = kits.find((k) => k.id === active) ?? kits[0];
    const newKitBtn = canManage ? (
        <button type="button" onClick={onNew} className="inline-flex h-[38px] items-center gap-2 rounded-[10px] bg-primary px-4 text-[13px] font-bold text-primary-foreground">
            <ListChecks className="h-3.5 w-3.5" /> New kit
        </button>
    ) : null;
    if (kits.length === 0) {
        return (
            <div>
                <div className="mb-4 flex items-center gap-3">
                    <p className="text-[13px] text-muted-foreground">Interview kits hold the weighted scorecard criteria your panel scores against.</p>
                    {newKitBtn ? <div className="ml-auto">{newKitBtn}</div> : null}
                </div>
                <EmptyCard icon={ListChecks} title="No interview kits yet" sub="Create a kit to give your panel a consistent, weighted rubric." />
            </div>
        );
    }
    const toDraft = (k: Kit): KitDraft => ({ id: k.id, name: k.name, role: k.role, is_active: k.is_active, criteria: k.criteria });
    return (
        <div>
            <div className="mb-4 flex items-center gap-3">
                <p className="text-[13px] text-muted-foreground">Reusable scorecards with weighted criteria — interviewers score candidates against this rubric.</p>
                {newKitBtn ? <div className="ml-auto">{newKitBtn}</div> : null}
            </div>
            <div className="grid gap-4 lg:grid-cols-[300px_1fr]">
                <div className="flex flex-col gap-2">
                    {kits.map((k) => {
                        const on = k.id === current?.id;
                        return (
                            <button
                                key={k.id}
                                type="button"
                                onClick={() => setActive(k.id)}
                                className={`rounded-[12px] border px-3.5 py-3 text-left transition-colors ${on ? 'border-primary bg-primary/10' : 'border-border bg-card hover:border-primary/40'}`}
                            >
                                <div className="flex items-center gap-2 text-[13.5px] font-bold">
                                    {k.name}
                                    {!k.is_active ? <span className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-semibold text-muted-foreground">Inactive</span> : null}
                                </div>
                                <div className="mt-0.5 text-[11.5px] text-muted-foreground">{k.role ?? 'All roles'} · {k.criteria.length} criteria</div>
                            </button>
                        );
                    })}
                </div>
                {current ? (
                    <div className="rounded-[14px] border border-border bg-card p-5">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <div className="text-[14px] font-bold">{current.name}</div>
                                <div className="mb-4 text-[12px] text-muted-foreground">Weighted criteria · total {current.criteria.reduce((a, c) => a + c.weight, 0)}%</div>
                            </div>
                            {canManage ? (
                                <div className="flex items-center gap-2">
                                    <button type="button" onClick={() => onToggle(current.id)} className="h-8 rounded-md border border-border bg-card px-2.5 text-[12px] font-semibold hover:bg-muted">
                                        {current.is_active ? 'Deactivate' : 'Activate'}
                                    </button>
                                    <button type="button" onClick={() => onEdit(toDraft(current))} className="h-8 rounded-md border border-primary bg-primary/10 px-2.5 text-[12px] font-bold text-primary">Edit</button>
                                </div>
                            ) : null}
                        </div>
                        <div className="flex flex-col gap-2">
                            {current.criteria.map((c) => (
                                <div key={c.label} className="flex items-center gap-2.5 rounded-[10px] border border-border px-3 py-2">
                                    <span className="flex-1 text-[13px] font-semibold">{c.label}</span>
                                    <div className="flex w-[160px] items-center gap-2">
                                        <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted">
                                            <div className="h-full rounded-full bg-primary" style={{ width: `${Math.min(100, c.weight)}%` }} />
                                        </div>
                                        <span className="w-[38px] text-right text-[12px] font-bold tabular-nums text-primary">{c.weight}%</span>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                ) : null}
            </div>
        </div>
    );
}

function PoolTab({
    pool,
    requisitions,
    canManage,
    onReactivate,
}: {
    pool: PoolItem[];
    requisitions: Requisition[];
    canManage: boolean;
    onReactivate: (candidateId: number, requisitionId: number) => void;
}) {
    if (pool.length === 0) return <EmptyCard icon={Sparkles} title="Talent pool is empty" sub="Strong candidates you keep warm appear here. Use a reject wizard's 'Add to talent pool' toggle to add them." />;
    return (
        <div>
            <p className="mb-4 text-[13px] text-muted-foreground">Strong candidates kept warm, safe from data-retention purges. Re-activate into a requisition to put them back in the pipeline.</p>
            <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(300px,1fr))]">
                {pool.map((p) => (
                    <PoolCard key={p.id} item={p} requisitions={requisitions} canManage={canManage} onReactivate={onReactivate} />
                ))}
            </div>
        </div>
    );
}

function PoolCard({
    item,
    requisitions,
    canManage,
    onReactivate,
}: {
    item: PoolItem;
    requisitions: Requisition[];
    canManage: boolean;
    onReactivate: (candidateId: number, requisitionId: number) => void;
}) {
    const [reqId, setReqId] = useState<string>('');
    return (
        <div className="rounded-[13px] border border-border bg-card p-4">
            <div className="flex items-center gap-2.5">
                <span style={avatarStyle(item.name)}>{initials(item.name)}</span>
                <div className="min-w-0 flex-1">
                    <div className="text-[13.5px] font-bold">{item.name}</div>
                    <div className="text-[11.5px] text-muted-foreground">{item.last_role}</div>
                </div>
            </div>
            {item.tags.length > 0 ? (
                <div className="mt-2.5 flex flex-wrap gap-1.5">
                    {item.tags.map((t) => (
                        <span key={t} className="rounded-md bg-primary/10 px-2 py-0.5 text-[10.5px] font-semibold text-primary">{t}</span>
                    ))}
                </div>
            ) : null}
            <div className="mt-3 border-t border-border pt-2.5 text-[11px] text-muted-foreground">{item.reason}</div>
            {canManage ? (
                <div className="mt-3 flex items-center gap-2">
                    <select
                        value={reqId}
                        onChange={(e) => setReqId(e.target.value)}
                        aria-label="Re-activate into requisition"
                        className="h-8 min-w-0 flex-1 rounded-md border border-border bg-card px-2 text-[12px] outline-none focus:border-primary"
                    >
                        <option value="">Into requisition…</option>
                        {requisitions.map((r) => (
                            <option key={r.id} value={r.id}>{r.title}</option>
                        ))}
                    </select>
                    <button
                        type="button"
                        disabled={reqId === ''}
                        onClick={() => onReactivate(item.id, Number(reqId))}
                        className="h-8 rounded-md border border-primary bg-primary/10 px-2.5 text-[12px] font-bold text-primary disabled:opacity-40"
                    >
                        Re-activate
                    </button>
                </div>
            ) : null}
        </div>
    );
}

/* ================================================================== */
/*  Candidate sheet                                                   */
/* ================================================================== */

function CandidateSheet({
    candidate,
    canManage,
    onClose,
    onAdvance,
    onWizard,
}: {
    candidate: HubCandidate;
    canManage: boolean;
    onClose: () => void;
    onAdvance: () => void;
    onWizard: (kind: WizardKind) => void;
}) {
    return (
        <>
            <div className="fixed inset-0 z-50 bg-[oklch(0.2_0.04_277/0.5)] backdrop-blur-[2px]" onClick={onClose} />
            <div className="pointer-events-none fixed inset-0 z-[51] grid place-items-center p-5">
                <div className="pointer-events-auto flex h-[min(88vh,640px)] w-[min(95vw,860px)] overflow-hidden rounded-[18px] bg-card shadow-2xl motion-safe:animate-in motion-safe:zoom-in-95">
                    <aside className="flex w-[252px] flex-none flex-col border-r border-sidebar-border bg-sidebar p-5">
                        <span style={avatarStyle(candidate.full_name, 56)}>{initials(candidate.full_name)}</span>
                        <div className="mt-3.5 text-[18px] font-bold leading-tight">{candidate.full_name}</div>
                        <div className="mt-1 text-[12.5px] text-muted-foreground">{candidate.requisition?.title ?? 'No requisition'}</div>
                        <div className="mt-3">
                            <span style={stageBadgeStyle(candidate.stage)}>
                                <span style={stageDotStyle(candidate.stage)} />
                                {stageLabel(candidate.stage)}
                            </span>
                        </div>
                        {canManage ? (
                            <div className="mt-5 flex flex-col gap-2">
                                <button type="button" onClick={onAdvance} className="h-9 rounded-[9px] bg-primary text-[12.5px] font-bold text-primary-foreground">Advance stage</button>
                                <button type="button" onClick={() => onWizard('interview')} className="h-9 rounded-[9px] border border-border bg-card text-[12.5px] font-semibold hover:bg-muted">Schedule interview</button>
                                <button type="button" onClick={() => onWizard('offer')} className="h-9 rounded-[9px] border border-border bg-card text-[12.5px] font-semibold hover:bg-muted">Create offer</button>
                                <button type="button" onClick={() => onWizard('reference')} className="h-9 rounded-[9px] border border-border bg-card text-[12.5px] font-semibold hover:bg-muted">Request reference</button>
                            </div>
                        ) : null}
                        <div className="mt-auto pt-4">
                            <a href={`/hr/recruitment/candidates/${candidate.id}`} className="block text-center text-[12px] font-semibold text-primary hover:underline">
                                Open full profile →
                            </a>
                        </div>
                    </aside>
                    <div className="flex min-w-0 flex-1 flex-col">
                        <header className="flex items-center justify-between border-b border-border px-5 py-3.5">
                            <span className="inline-flex items-center gap-2 text-[12.5px] font-semibold text-muted-foreground">
                                <span style={stageDotStyle(candidate.stage)} /> Candidate dossier · <span className="text-foreground">{stageLabel(candidate.stage)}</span>
                            </span>
                            <button type="button" onClick={onClose} aria-label="Close" className="grid h-8 w-8 place-items-center rounded-md text-muted-foreground hover:bg-muted">✕</button>
                        </header>
                        <div className="flex-1 overflow-y-auto p-5">
                            <div className="grid grid-cols-2 gap-2.5">
                                <Detail label="Email" value={candidate.email} />
                                <Detail label="Source" value={candidate.source?.replace(/_/g, ' ')} />
                                <Detail label="Requisition" value={candidate.requisition?.title ?? '—'} />
                                <Detail label="Days in stage" value={daysLabel(candidate.days)} />
                            </div>
                            {canManage ? (
                                <div className="mt-5">
                                    <div className="mb-2 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Quick actions</div>
                                    <div className="flex flex-wrap gap-2">
                                        <SheetAction icon={FileText} label="Upload document" onClick={() => onWizard('document')} />
                                        <SheetAction icon={XCircle} label="Reject" tone="crit" onClick={() => onWizard('reject')} />
                                    </div>
                                </div>
                            ) : null}
                            <p className="mt-5 rounded-xl border border-border bg-muted/40 px-3.5 py-3 text-[12.5px] text-muted-foreground">
                                Full activity timeline, pre-employment safety checks and documents live on the candidate's profile — open it from the rail.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}

function Detail({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-[11px] border border-border px-3 py-2.5">
            <div className="text-[10.5px] font-bold uppercase tracking-wide text-muted-foreground">{label}</div>
            <div className="mt-0.5 break-words text-[12.5px] font-semibold capitalize">{value}</div>
        </div>
    );
}

function SheetAction({ icon: Icon, label, onClick, tone }: { icon: typeof FileText; label: string; onClick: () => void; tone?: 'crit' }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`inline-flex h-9 items-center gap-2 rounded-[9px] border px-3 text-[12.5px] font-semibold ${tone === 'crit' ? 'border-status-critical/30 bg-status-critical-bg text-status-critical' : 'border-border bg-card hover:bg-muted'}`}
        >
            <Icon className="h-3.5 w-3.5" /> {label}
        </button>
    );
}

function EmptyCard({ icon: Icon, title, sub }: { icon: typeof Briefcase; title: string; sub: string }) {
    return (
        <div className="flex flex-col items-center justify-center rounded-[14px] border border-dashed border-border bg-card px-5 py-16 text-center">
            <div className="mb-3.5 grid h-12 w-12 place-items-center rounded-[14px] bg-muted text-muted-foreground">
                <Icon className="h-6 w-6" />
            </div>
            <h3 className="text-[15px] font-bold">{title}</h3>
            <p className="max-w-sm text-[13px] text-muted-foreground">{sub}</p>
        </div>
    );
}
