import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertTriangle,
    CalendarClock,
    CheckCircle2,
    ClipboardList,
    Map,
    RefreshCw,
    ShieldCheck,
    Wallet,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';

type RoadmapSummary = {
    published_plan: null | {
        id: number;
        fiscal_year: number;
        quarter: number;
        revision_no: number;
        published_at?: string | null;
    };
    initiatives: {
        total: number;
        in_progress: number;
        blocked: number;
        deferred: number;
        completed: number;
        top: Array<{
            id: number;
            code?: string | null;
            title: string;
            score: number;
            status: string;
            owner?: string | null;
        }>;
    };
    budget: {
        forecast_total: number;
    };
    governance_budget: null | {
        id: number;
        fiscal_year: string;
        title: string | null;
        total_budget: number;
        total_allocated: number;
        total_actual: number;
        variance_pct: number;
        remaining: number;
        approved_at: string | null;
        resolution: { id: number; reference: string; title: string } | null;
    };
    assurance: {
        overdue: number;
        verified: number;
    };
    decisions_required: number;
    house_rollout: {
        not_started: number;
        in_progress: number;
        blocked: number;
        completed: number;
    };
    status?: string;
    reason?: string;
};

type TriageSummary = {
    pending: number;
    overload: boolean;
};

type RoadmapCan = {
    viewDashboard: boolean;
    viewRoadmap: boolean;
    manageRoadmap: boolean;
    approveRoadmap: boolean;
    manageBudget: boolean;
    viewDecisions: boolean;
    manageDecisions: boolean;
    exportReports: boolean;
};

type ManagerOption = {
    id: number;
    name: string;
    email: string;
    role_label?: string | null;
};

type InitiativeRow = {
    id: number;
    title: string;
    stream?: string | null;
    status: string;
    priority_score?: number | null;
    owner_name?: string | null;
    sponsor_name?: string | null;
    next_decision?: string | null;
    decision_due_at?: string | null;
};

type PlanRow = {
    id: number;
    fiscal_year: number;
    quarter: number;
    status: string;
    revision_no: number;
    preset_profile?: string | null;
    items_count?: number;
};

type SuggestionRow = {
    id: number;
    title: string;
    source: string;
    status: string;
    hit_count?: number;
    last_seen_at?: string | null;
    first_seen_at?: string | null;
    summary?: string | null;
    dedupe_key?: string | null;
    source_key?: string | null;
    score_hint?: number | null;
    triage_owner_id?: number | null;
    triage_owner_name?: string | null;
    triage_owner_email?: string | null;
    triage_notes?: string | null;
    raw_payload?: Record<string, unknown> | null;
};

type DecisionRow = {
    id: number;
    request_type: string;
    required_role?: string | null;
    amount?: number | null;
    due_date?: string | null;
    status: string;
};

type InitiativeFormState = {
    title: string;
    stream: string;
    ownerUserId: number | null;
    sponsorUserId: number | null;
    targetFiscalYear: number;
    targetQuarter: number;
    costEstimateLow: string;
    costEstimateHigh: string;
    nextDecision: string;
};

type InitiativeApiRow = {
    id: number;
    title: string;
    stream?: string | null;
    status: string;
    priority_score?: number | null;
    owner?: { name?: string | null } | null;
    sponsor?: { name?: string | null } | null;
    next_decision?: string | null;
    decision_due_at?: string | null;
};

type SuggestionApiRow = {
    id: number;
    title: string;
    source: string;
    status: string;
    hit_count?: number;
    first_seen_at?: string | null;
    last_seen_at?: string | null;
    summary?: string | null;
    dedupe_key?: string | null;
    source_key?: string | null;
    score_hint?: number | null;
    triage_owner_id?: number | null;
    triage_owner?: { name?: string | null; email?: string | null } | null;
    triage_notes?: string | null;
    raw_payload?: Record<string, unknown> | null;
};

type Props = {
    summary: RoadmapSummary;
    triage: TriageSummary;
    generatedAt: string;
    managers: ManagerOption[];
    can: RoadmapCan;
};

type PlanWorkflowAction =
    | 'submit-manager'
    | 'submit-executive'
    | 'approve'
    | 'publish'
    | 'revise';

type PlanDetail = {
    id: number;
    fiscal_year: number;
    quarter: number;
    status: string;
    revision_no: number;
    preset_profile: string;
    items: Array<{
        id: number;
        rank?: number | null;
        score_at_snapshot?: number | null;
        planned_capex?: number | null;
        planned_opex?: number | null;
        initiative?: {
            id: number;
            code?: string | null;
            title: string;
            status: string;
        } | null;
    }>;
};

type PlanDetailTableProps = {
    plan: PlanDetail;
};

const PRESET_OPTIONS = [
    { key: 'board_ceo', label: 'Board / CEO' },
    { key: 'budget_first', label: 'Budget First' },
    { key: 'security_compliance', label: 'Security & Compliance' },
    { key: 'house_rollout', label: 'House Rollout' },
] as const;

const STREAM_OPTIONS = [
    { key: 'it', label: 'IT' },
    { key: 'maintenance', label: 'Maintenance' },
    { key: 'facilities', label: 'Facilities' },
    { key: 'operations', label: 'Operations' },
    { key: 'overheads', label: 'Overheads' },
    { key: 'continuous_improvement', label: 'Continuous Improvement' },
] as const;

function extractErrorMessage(error: unknown, fallback: string): string {
    if (axios.isAxiosError(error)) {
        const responseMessage = error.response?.data?.message;
        if (typeof responseMessage === 'string' && responseMessage.length > 0) {
            return responseMessage;
        }
    }

    return fallback;
}

function actionLabel(action: PlanWorkflowAction): string {
    return {
        'submit-manager': 'Submit Manager',
        'submit-executive': 'Submit Executive',
        approve: 'Approve',
        publish: 'Publish',
        revise: 'Revise',
    }[action];
}

function currency(value: number): string {
    return new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency: 'NZD',
        maximumFractionDigits: 0,
    }).format(value ?? 0);
}

function statusLabel(value: string): string {
    return value.replaceAll('_', ' ');
}

function PlanDetailTable({ plan }: PlanDetailTableProps) {
    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>Rank</TableHead>
                    <TableHead>Initiative</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Score</TableHead>
                    <TableHead>CAPEX</TableHead>
                    <TableHead>OPEX</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {plan.items.length === 0 && (
                    <TableRow>
                        <TableCell
                            colSpan={6}
                            className="text-muted-foreground"
                        >
                            This plan has no ranked items yet. Convert accepted
                            triage suggestions or quick-add initiatives, then
                            regenerate the quarterly draft.
                        </TableCell>
                    </TableRow>
                )}
                {plan.items.map((item) => (
                    <TableRow key={item.id}>
                        <TableCell>{item.rank ?? '-'}</TableCell>
                        <TableCell className="max-w-[320px] truncate">
                            {item.initiative?.title ??
                                `Initiative ${item.initiative?.id ?? ''}`}
                        </TableCell>
                        <TableCell>
                            {item.initiative?.status
                                ? statusLabel(item.initiative.status)
                                : '-'}
                        </TableCell>
                        <TableCell>{item.score_at_snapshot ?? '-'}</TableCell>
                        <TableCell>
                            {item.planned_capex
                                ? currency(item.planned_capex)
                                : '-'}
                        </TableCell>
                        <TableCell>
                            {item.planned_opex
                                ? currency(item.planned_opex)
                                : '-'}
                        </TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}

function managerLabel(manager: ManagerOption): string {
    if (manager.role_label && manager.role_label.trim() !== '') {
        return `${manager.name} (${manager.role_label})`;
    }

    return manager.name;
}

function formatDateValue(value?: string | null): string {
    if (!value) {
        return '-';
    }

    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) {
        return value;
    }

    return parsed.toLocaleString();
}

function parseOptionalNumber(value: string): number | undefined {
    if (value.trim() === '') {
        return undefined;
    }

    const parsed = Number(value);
    if (Number.isNaN(parsed)) {
        return undefined;
    }

    return parsed;
}

function asString(value: unknown): string | null {
    if (typeof value === 'string') {
        const trimmed = value.trim();
        return trimmed.length > 0 ? trimmed : null;
    }

    if (typeof value === 'number' || typeof value === 'boolean') {
        return String(value);
    }

    return null;
}

function asStringArray(value: unknown): string[] {
    if (!Array.isArray(value)) {
        return [];
    }

    return value
        .map((item) => asString(item))
        .filter((item): item is string => Boolean(item));
}

function asObjectArray(value: unknown): Array<Record<string, unknown>> {
    if (!Array.isArray(value)) {
        return [];
    }

    return value.filter(
        (item): item is Record<string, unknown> =>
            typeof item === 'object' && item !== null && !Array.isArray(item),
    );
}

function buildIssueDetails(
    suggestion: SuggestionRow | null,
): Array<{ label: string; value: string }> {
    if (!suggestion) {
        return [];
    }

    const payload = suggestion.raw_payload ?? {};
    const details: Array<{ label: string; value: string }> = [];

    const add = (label: string, value: unknown) => {
        const parsed = asString(value);
        if (parsed) {
            details.push({ label, value: parsed });
        }
    };

    if (suggestion.source === 'incidents') {
        add('Incident type', payload.type);
        add('Severity', payload.severity);
        add('Incidents in window', payload.count);
        add('Window (days)', payload.window_days);
        return details;
    }

    if (suggestion.source === 'assets') {
        add('Site ID', payload.site_id);
        add('Asset category', payload.category);
        add('Assets in scope', payload.count);
        add('Maintenance overdue', payload.maintenance_overdue_count);
        add('Warranty expiring <= 6 months', payload.warranty_expiring_count);
        return details;
    }

    add('Source key', payload.source_key ?? suggestion.source_key);
    add('Count', payload.count);
    add('Provider', payload.provider);
    add('Event type', payload.event_type);
    add('Signal type', payload.signal_type);
    add('Site ID', payload.site_id);

    return details;
}

function mapSuggestionRow(row: SuggestionApiRow): SuggestionRow {
    return {
        id: row.id,
        title: row.title,
        source: row.source,
        status: row.status,
        hit_count: row.hit_count,
        first_seen_at: row.first_seen_at,
        last_seen_at: row.last_seen_at,
        summary: row.summary,
        dedupe_key: row.dedupe_key,
        source_key: row.source_key,
        score_hint: row.score_hint,
        triage_owner_id: row.triage_owner_id ?? null,
        triage_owner_name: row.triage_owner?.name ?? null,
        triage_owner_email: row.triage_owner?.email ?? null,
        triage_notes: row.triage_notes ?? null,
        raw_payload: row.raw_payload ?? null,
    };
}

export default function RoadmapDashboard({
    summary: initialSummary,
    triage: initialTriage,
    generatedAt: initialGeneratedAt,
    managers,
    can,
}: Props) {
    const [summary, setSummary] = useState<RoadmapSummary>(initialSummary);
    const [triage, setTriage] = useState<TriageSummary>(initialTriage);
    const [generatedAt, setGeneratedAt] = useState(initialGeneratedAt);
    const [initiatives, setInitiatives] = useState<InitiativeRow[]>([]);
    const [plans, setPlans] = useState<PlanRow[]>([]);
    const [suggestions, setSuggestions] = useState<SuggestionRow[]>([]);
    const [decisions, setDecisions] = useState<DecisionRow[]>([]);
    const [loading, setLoading] = useState(false);
    const [planDetailLoading, setPlanDetailLoading] = useState(false);
    const [selectedPlanId, setSelectedPlanId] = useState<number | null>(null);
    const [selectedPlan, setSelectedPlan] = useState<PlanDetail | null>(null);
    const [selectedSuggestion, setSelectedSuggestion] =
        useState<SuggestionRow | null>(null);
    const [isPlanDialogOpen, setIsPlanDialogOpen] = useState(false);
    const [isSuggestionDialogOpen, setIsSuggestionDialogOpen] = useState(false);
    const [suggestionNotesDraft, setSuggestionNotesDraft] = useState('');
    const [showTechnicalPayload, setShowTechnicalPayload] = useState(false);
    const planDetailSectionRef = useRef<HTMLDivElement | null>(null);
    const [actionLoadingKey, setActionLoadingKey] = useState<string | null>(
        null,
    );
    const [planForm, setPlanForm] = useState({
        fiscalYear: new Date().getFullYear(),
        quarter: Math.ceil((new Date().getMonth() + 1) / 3),
        preset: 'board_ceo',
    });
    const [initiativeForm, setInitiativeForm] = useState<InitiativeFormState>({
        title: '',
        stream: 'operations',
        ownerUserId: managers[0]?.id ?? null,
        sponsorUserId: null,
        targetFiscalYear: new Date().getFullYear(),
        targetQuarter: Math.ceil((new Date().getMonth() + 1) / 3),
        costEstimateLow: '',
        costEstimateHigh: '',
        nextDecision: 'Define scope and approve',
    });

    const publishedLabel = useMemo(() => {
        if (!summary.published_plan) {
            return 'No published quarter';
        }

        return `FY${summary.published_plan.fiscal_year} Q${summary.published_plan.quarter} r${summary.published_plan.revision_no}`;
    }, [summary.published_plan]);

    const suggestionIssueDetails = useMemo(
        () => buildIssueDetails(selectedSuggestion),
        [selectedSuggestion],
    );
    const suggestionIncidentNotes = useMemo(() => {
        return asStringArray(selectedSuggestion?.raw_payload?.incident_notes);
    }, [selectedSuggestion]);
    const suggestionAssetNotes = useMemo(() => {
        return asStringArray(selectedSuggestion?.raw_payload?.asset_notes);
    }, [selectedSuggestion]);
    const suggestionIncidentExamples = useMemo(() => {
        return asObjectArray(
            selectedSuggestion?.raw_payload?.incident_examples,
        );
    }, [selectedSuggestion]);
    const suggestionAssetExamples = useMemo(() => {
        return asObjectArray(selectedSuggestion?.raw_payload?.asset_examples);
    }, [selectedSuggestion]);

    useEffect(() => {
        setSuggestionNotesDraft(selectedSuggestion?.triage_notes ?? '');
    }, [selectedSuggestion]);

    const availablePlanActions = useCallback(
        (plan: PlanRow): PlanWorkflowAction[] => {
            const actions: PlanWorkflowAction[] = [];

            if (plan.status === 'draft' && can.manageRoadmap) {
                actions.push('submit-manager');
            }

            if (plan.status === 'manager_review' && can.manageRoadmap) {
                actions.push('submit-executive');
            }

            if (
                ['draft', 'manager_review', 'exec_review'].includes(
                    plan.status,
                ) &&
                can.approveRoadmap
            ) {
                actions.push('approve');
            }

            if (plan.status === 'approved' && can.approveRoadmap) {
                actions.push('publish');
            }

            if (plan.status === 'published' && can.approveRoadmap) {
                actions.push('revise');
            }

            return actions;
        },
        [can.approveRoadmap, can.manageRoadmap],
    );

    const loadDashboardSummary = useCallback(async () => {
        const response = await axios.get('/roadmap/dashboard', {
            headers: { Accept: 'application/json' },
        });

        setSummary(response.data.summary);
        setTriage(response.data.triage);
        setGeneratedAt(response.data.generated_at);
    }, []);

    const loadRoadmapLists = useCallback(async () => {
        if (!can.viewRoadmap) {
            setInitiatives([]);
            setPlans([]);
            setSuggestions([]);
            setSelectedPlanId(null);
            setSelectedPlan(null);

            return;
        }

        const [initiativeResponse, planResponse, suggestionResponse] =
            await Promise.all([
                axios.get('/roadmap/initiatives?per_page=5', {
                    headers: { Accept: 'application/json' },
                }),
                axios.get('/roadmap/quarterly-plans?per_page=5', {
                    headers: { Accept: 'application/json' },
                }),
                axios.get(
                    '/roadmap/suggestions?status=triage_pending&per_page=5',
                    { headers: { Accept: 'application/json' } },
                ),
            ]);

        const initiativeRows = (initiativeResponse.data?.items?.data ?? []).map(
            (row: InitiativeApiRow): InitiativeRow => ({
                id: row.id,
                title: row.title,
                stream: row.stream,
                status: row.status,
                priority_score: row.priority_score,
                owner_name: row.owner?.name ?? null,
                sponsor_name: row.sponsor?.name ?? null,
                next_decision: row.next_decision ?? null,
                decision_due_at: row.decision_due_at ?? null,
            }),
        );
        setInitiatives(initiativeRows);
        const planRows = planResponse.data?.items?.data ?? [];
        setPlans(planRows);
        const suggestionRows = (suggestionResponse.data?.items?.data ?? []).map(
            (row: SuggestionApiRow): SuggestionRow => mapSuggestionRow(row),
        );
        setSuggestions(suggestionRows);

        if (planRows.length === 0) {
            setSelectedPlanId(null);
            setSelectedPlan(null);
            return;
        }

        setSelectedPlanId((current) =>
            current !== null &&
            planRows.some((plan: PlanRow) => plan.id === current)
                ? current
                : planRows[0].id,
        );
    }, [can.viewRoadmap]);

    useEffect(() => {
        if (initiativeForm.ownerUserId === null && managers.length > 0) {
            setInitiativeForm((current) => ({
                ...current,
                ownerUserId: managers[0].id,
            }));
        }
    }, [initiativeForm.ownerUserId, managers]);

    const loadDecisions = useCallback(async () => {
        if (!can.viewDecisions) {
            setDecisions([]);

            return;
        }

        const response = await axios.get(
            '/roadmap/decisions?status=pending&per_page=8',
            {
                headers: { Accept: 'application/json' },
            },
        );
        setDecisions(response.data?.items?.data ?? []);
    }, [can.viewDecisions]);

    const loadPlanDetail = useCallback(async (planId: number) => {
        setPlanDetailLoading(true);
        try {
            const response = await axios.get(
                `/roadmap/quarterly-plans/${planId}`,
                {
                    headers: { Accept: 'application/json' },
                },
            );
            setSelectedPlan(response.data?.item ?? null);
        } catch (error) {
            setSelectedPlan(null);
            toast.error(
                extractErrorMessage(
                    error,
                    'Unable to load quarterly plan detail.',
                ),
            );
        } finally {
            setPlanDetailLoading(false);
        }
    }, []);

    const openPlanDetail = useCallback(
        async (planId: number) => {
            setSelectedPlanId(planId);
            setIsPlanDialogOpen(true);
            await loadPlanDetail(planId);
            window.requestAnimationFrame(() => {
                planDetailSectionRef.current?.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start',
                });
            });
        },
        [loadPlanDetail],
    );

    const refreshAll = useCallback(async () => {
        setLoading(true);

        try {
            await Promise.all([
                loadDashboardSummary(),
                loadRoadmapLists(),
                loadDecisions(),
            ]);
        } catch (error) {
            console.error('Failed to refresh roadmap dashboard', error);
        } finally {
            setLoading(false);
        }
    }, [loadDashboardSummary, loadRoadmapLists, loadDecisions]);

    const createInitiative = useCallback(async () => {
        if (!can.manageRoadmap) {
            return;
        }

        const title = initiativeForm.title.trim();
        if (title.length === 0) {
            toast.error('Initiative title is required.');
            return;
        }

        const ownerUserId =
            initiativeForm.ownerUserId ?? managers[0]?.id ?? null;
        if (!ownerUserId) {
            toast.error(
                'Assign an owner manager before creating an initiative.',
            );
            return;
        }

        setActionLoadingKey('initiative:create');
        try {
            await axios.post(
                '/roadmap/initiatives',
                {
                    title,
                    stream: initiativeForm.stream,
                    owner_user_id: ownerUserId,
                    sponsor_user_id: initiativeForm.sponsorUserId ?? undefined,
                    target_fiscal_year: initiativeForm.targetFiscalYear,
                    target_quarter: initiativeForm.targetQuarter,
                    next_decision: initiativeForm.nextDecision,
                    cost_estimate_low: parseOptionalNumber(
                        initiativeForm.costEstimateLow,
                    ),
                    cost_estimate_high: parseOptionalNumber(
                        initiativeForm.costEstimateHigh,
                    ),
                },
                { headers: { Accept: 'application/json' } },
            );

            setInitiativeForm((current) => ({
                ...current,
                title: '',
                costEstimateLow: '',
                costEstimateHigh: '',
            }));
            await refreshAll();
            toast.success('Initiative created and assigned.');
        } catch (error) {
            toast.error(
                extractErrorMessage(error, 'Failed to create initiative.'),
            );
        } finally {
            setActionLoadingKey(null);
        }
    }, [can.manageRoadmap, initiativeForm, managers, refreshAll]);

    const triageSuggestion = useCallback(
        async (
            suggestionId: number,
            status: 'accepted' | 'rejected' | 'snoozed',
            triageNotes?: string,
        ) => {
            if (!can.manageRoadmap) {
                return;
            }

            const loadingKey = `suggestion:${suggestionId}:${status}`;
            setActionLoadingKey(loadingKey);
            try {
                const payload: Record<string, string | null> = { status };
                if (status === 'snoozed') {
                    payload.snoozed_until = new Date(
                        Date.now() + 7 * 24 * 60 * 60 * 1000,
                    )
                        .toISOString()
                        .slice(0, 10);
                }
                if (typeof triageNotes === 'string') {
                    payload.triage_notes =
                        triageNotes.trim() === '' ? null : triageNotes.trim();
                }

                await axios.post(
                    `/roadmap/suggestions/${suggestionId}/triage`,
                    payload,
                    { headers: { Accept: 'application/json' } },
                );
                await refreshAll();
                toast.success(`Suggestion ${status}.`);
            } catch (error) {
                toast.error(
                    extractErrorMessage(error, 'Failed to update suggestion.'),
                );
            } finally {
                setActionLoadingKey(null);
            }
        },
        [can.manageRoadmap, refreshAll],
    );

    const saveSuggestionNotes = useCallback(
        async (suggestionId: number, triageNotes: string) => {
            if (!can.manageRoadmap) {
                return;
            }

            const loadingKey = `suggestion:${suggestionId}:notes`;
            setActionLoadingKey(loadingKey);
            try {
                await axios.post(
                    `/roadmap/suggestions/${suggestionId}/triage`,
                    {
                        status: 'triage_pending',
                        triage_notes:
                            triageNotes.trim() === ''
                                ? null
                                : triageNotes.trim(),
                    },
                    { headers: { Accept: 'application/json' } },
                );
                await refreshAll();
                setSelectedSuggestion((current) =>
                    current
                        ? {
                              ...current,
                              triage_notes:
                                  triageNotes.trim() === ''
                                      ? null
                                      : triageNotes.trim(),
                          }
                        : current,
                );
                toast.success('Triage notes saved.');
            } catch (error) {
                toast.error(
                    extractErrorMessage(error, 'Failed to save triage notes.'),
                );
            } finally {
                setActionLoadingKey(null);
            }
        },
        [can.manageRoadmap, refreshAll],
    );

    const assignSuggestionOwner = useCallback(
        async (suggestionId: number, triageOwnerId: number | null) => {
            if (!can.manageRoadmap) {
                return;
            }

            const loadingKey = `suggestion:${suggestionId}:assign`;
            setActionLoadingKey(loadingKey);
            try {
                await axios.post(
                    `/roadmap/suggestions/${suggestionId}/triage`,
                    {
                        status: 'triage_pending',
                        triage_owner_id: triageOwnerId ?? undefined,
                    },
                    { headers: { Accept: 'application/json' } },
                );
                await refreshAll();
                toast.success('Triage owner updated.');
            } catch (error) {
                toast.error(
                    extractErrorMessage(
                        error,
                        'Failed to assign triage owner.',
                    ),
                );
            } finally {
                setActionLoadingKey(null);
            }
        },
        [can.manageRoadmap, refreshAll],
    );

    const convertSuggestion = useCallback(
        async (suggestionId: number, triageNotes?: string) => {
            if (!can.manageRoadmap) {
                return;
            }

            const ownerUserId =
                initiativeForm.ownerUserId ?? managers[0]?.id ?? null;
            if (!ownerUserId) {
                toast.error(
                    'Assign an owner manager first, then convert the suggestion.',
                );
                return;
            }

            const loadingKey = `suggestion:${suggestionId}:convert`;
            setActionLoadingKey(loadingKey);
            try {
                await axios.post(
                    `/roadmap/suggestions/${suggestionId}/convert`,
                    {
                        owner_user_id: ownerUserId,
                        sponsor_user_id:
                            initiativeForm.sponsorUserId ?? undefined,
                        target_fiscal_year: initiativeForm.targetFiscalYear,
                        target_quarter: initiativeForm.targetQuarter,
                        next_decision: initiativeForm.nextDecision,
                        triage_notes:
                            typeof triageNotes === 'string'
                                ? triageNotes.trim() === ''
                                    ? null
                                    : triageNotes.trim()
                                : undefined,
                    },
                    { headers: { Accept: 'application/json' } },
                );
                await refreshAll();
                toast.success('Suggestion converted to initiative.');
            } catch (error) {
                toast.error(
                    extractErrorMessage(
                        error,
                        'Failed to convert suggestion to initiative.',
                    ),
                );
            } finally {
                setActionLoadingKey(null);
            }
        },
        [can.manageRoadmap, initiativeForm, managers, refreshAll],
    );

    const resolveDecision = useCallback(
        async (decisionId: number, status: 'approved' | 'rejected') => {
            if (!can.manageDecisions) {
                return;
            }

            const loadingKey = `decision:${decisionId}:${status}`;
            setActionLoadingKey(loadingKey);
            try {
                await axios.post(
                    `/roadmap/decisions/${decisionId}/resolve`,
                    {
                        status,
                        notes:
                            status === 'approved'
                                ? 'Approved via roadmap dashboard.'
                                : 'Rejected via roadmap dashboard.',
                    },
                    { headers: { Accept: 'application/json' } },
                );
                await refreshAll();
                toast.success(`Decision request ${status}.`);
            } catch (error) {
                toast.error(
                    extractErrorMessage(
                        error,
                        'Failed to resolve decision request.',
                    ),
                );
            } finally {
                setActionLoadingKey(null);
            }
        },
        [can.manageDecisions, refreshAll],
    );

    const generatePlan = useCallback(async () => {
        if (!can.manageRoadmap) {
            return;
        }

        setActionLoadingKey('generate');
        try {
            const response = await axios.post(
                '/roadmap/quarterly-plans/generate',
                {
                    fiscal_year: planForm.fiscalYear,
                    quarter: planForm.quarter,
                    preset: planForm.preset,
                },
                { headers: { Accept: 'application/json' } },
            );

            const newPlanId = response.data?.item?.id as number | undefined;
            await refreshAll();
            if (newPlanId) {
                setSelectedPlanId(newPlanId);
            }
            toast.success('Quarterly draft plan generated.');
        } catch (error) {
            toast.error(
                extractErrorMessage(
                    error,
                    'Failed to generate quarterly plan.',
                ),
            );
        } finally {
            setActionLoadingKey(null);
        }
    }, [can.manageRoadmap, planForm, refreshAll]);

    const runIngestNow = useCallback(async () => {
        if (!can.manageRoadmap) {
            return;
        }

        setActionLoadingKey('ingest');
        try {
            await axios.post(
                '/roadmap/suggestions/ingest',
                {},
                { headers: { Accept: 'application/json' } },
            );
            await refreshAll();
            toast.success('Suggestion intake completed.');
        } catch (error) {
            toast.error(
                extractErrorMessage(error, 'Failed to run suggestion intake.'),
            );
        } finally {
            setActionLoadingKey(null);
        }
    }, [can.manageRoadmap, refreshAll]);

    const runPlanAction = useCallback(
        async (planId: number, action: PlanWorkflowAction) => {
            const endpoint = {
                'submit-manager': `/roadmap/quarterly-plans/${planId}/submit-manager`,
                'submit-executive': `/roadmap/quarterly-plans/${planId}/submit-executive`,
                approve: `/roadmap/quarterly-plans/${planId}/approve`,
                publish: `/roadmap/quarterly-plans/${planId}/publish`,
                revise: `/roadmap/quarterly-plans/${planId}/revise`,
            }[action];

            setActionLoadingKey(`${planId}:${action}`);

            try {
                const payload =
                    action === 'revise'
                        ? {
                              change_summary:
                                  'Revision requested from roadmap dashboard.',
                          }
                        : {};

                const response = await axios.post(endpoint, payload, {
                    headers: { Accept: 'application/json' },
                });

                const returnedPlanId = response.data?.item?.id as
                    | number
                    | undefined;

                await refreshAll();
                if (returnedPlanId) {
                    setSelectedPlanId(returnedPlanId);
                } else {
                    setSelectedPlanId(planId);
                }

                toast.success(`Plan action completed: ${actionLabel(action)}.`);
            } catch (error) {
                toast.error(
                    extractErrorMessage(
                        error,
                        `Failed plan action: ${actionLabel(action)}.`,
                    ),
                );
            } finally {
                setActionLoadingKey(null);
            }
        },
        [refreshAll],
    );

    useEffect(() => {
        void refreshAll();
    }, [refreshAll]);

    useEffect(() => {
        if (!can.viewRoadmap || selectedPlanId === null) {
            setSelectedPlan(null);
            return;
        }

        void loadPlanDetail(selectedPlanId);
    }, [can.viewRoadmap, loadPlanDetail, selectedPlanId]);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Roadmap', href: '/roadmap/dashboard' },
            ]}
        >
            <Head title="Roadmap Dashboard" />

            <PageLayout
                hero={
                    <PageHero
                        icon={Map}
                        title="Roadmap"
                        description="Quarterly initiatives, budgets, rollout, and board decisions in one place."
                        stats={[
                            {
                                label: 'Initiatives',
                                value: summary.initiatives.total,
                            },
                            {
                                label: 'In progress',
                                value: summary.initiatives.in_progress,
                            },
                            {
                                label: 'Blocked',
                                value: summary.initiatives.blocked,
                            },
                            {
                                label: 'Decisions required',
                                value: summary.decisions_required,
                            },
                        ]}
                        actions={
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    variant="outline"
                                    onClick={() => void refreshAll()}
                                    disabled={loading}
                                    className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                >
                                    <RefreshCw
                                        className={`mr-2 h-4 w-4 ${loading ? 'animate-spin' : ''}`}
                                    />
                                    Refresh
                                </Button>
                                <Link href="/governance/dashboard">
                                    <Button
                                        variant="outline"
                                        className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                    >
                                        Back to Governance
                                    </Button>
                                </Link>
                            </div>
                        }
                    >
                        <p className="text-xs text-primary-foreground/70">
                            Last refreshed{' '}
                            {new Date(generatedAt).toLocaleString()}
                        </p>
                    </PageHero>
                }
            >
                {summary.status === 'unavailable' && (
                    <Card className="border-status-warning/30 bg-status-warning-bg">
                        <CardContent className="space-y-2 py-4 text-sm">
                            <div className="font-medium text-status-warning">
                                Roadmap module is not ready in this environment.
                            </div>
                            <div className="text-status-warning">
                                Reason:{' '}
                                {summary.reason ??
                                    'Missing roadmap tables or seed data.'}
                            </div>
                            <div className="font-mono text-xs text-status-warning">
                                php artisan migrate
                            </div>
                            <div className="font-mono text-xs text-status-warning">
                                php artisan db:seed
                                --class=Database\\Seeders\\RoadmapPermissionsSeeder
                            </div>
                            <div className="font-mono text-xs text-status-warning">
                                php artisan db:seed
                                --class=Database\\Seeders\\RoadmapSeeder
                            </div>
                        </CardContent>
                    </Card>
                )}

                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-sm text-muted-foreground">
                                <ClipboardList className="h-4 w-4" />
                                Initiatives
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-semibold">
                                {summary.initiatives.total}
                            </div>
                            <div className="mt-1 text-xs text-muted-foreground">
                                {summary.initiatives.in_progress} in progress,{' '}
                                {summary.initiatives.blocked} blocked,{' '}
                                {summary.initiatives.deferred} deferred
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-sm text-muted-foreground">
                                <Wallet className="h-4 w-4" />
                                Budget
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {summary.governance_budget ? (
                                <>
                                    <div className="text-2xl font-semibold">
                                        {currency(
                                            summary.governance_budget
                                                .total_budget,
                                        )}
                                    </div>
                                    <div className="mt-1 space-y-0.5 text-xs text-muted-foreground">
                                        <div>
                                            Approved FY
                                            {
                                                summary.governance_budget
                                                    .fiscal_year
                                            }
                                            {summary.governance_budget
                                                .approved_at && (
                                                <>
                                                    {' '}
                                                    on{' '}
                                                    {
                                                        summary
                                                            .governance_budget
                                                            .approved_at
                                                    }
                                                </>
                                            )}
                                        </div>
                                        <div>
                                            Actual:{' '}
                                            {currency(
                                                summary.governance_budget
                                                    .total_actual,
                                            )}
                                            {' / '}
                                            Remaining:{' '}
                                            {currency(
                                                summary.governance_budget
                                                    .remaining,
                                            )}
                                        </div>
                                        {summary.governance_budget
                                            .variance_pct !== 0 && (
                                            <div
                                                className={
                                                    summary.governance_budget
                                                        .variance_pct > 5
                                                        ? 'text-destructive'
                                                        : ''
                                                }
                                            >
                                                Variance:{' '}
                                                {summary.governance_budget
                                                    .variance_pct > 0
                                                    ? '+'
                                                    : ''}
                                                {
                                                    summary.governance_budget
                                                        .variance_pct
                                                }
                                                %
                                            </div>
                                        )}
                                        <div className="mt-1 border-t pt-1">
                                            Initiative Forecast:{' '}
                                            {currency(
                                                summary.budget.forecast_total,
                                            )}
                                        </div>
                                    </div>
                                </>
                            ) : (
                                <>
                                    <div className="text-2xl font-semibold">
                                        {currency(
                                            summary.budget.forecast_total,
                                        )}
                                    </div>
                                    <div className="mt-1 text-xs text-muted-foreground">
                                        Initiative forecast only &mdash; no
                                        approved governance budget
                                    </div>
                                </>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-sm text-muted-foreground">
                                <CalendarClock className="h-4 w-4" />
                                Decisions Required
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-semibold">
                                {summary.decisions_required}
                            </div>
                            <div className="mt-1 text-xs text-muted-foreground">
                                {can.viewDecisions
                                    ? 'Pending DoA approvals and deferrals'
                                    : 'No decision access'}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-sm text-muted-foreground">
                                <ShieldCheck className="h-4 w-4" />
                                Assurance
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-semibold">
                                {summary.assurance.verified}
                            </div>
                            <div className="mt-1 text-xs text-muted-foreground">
                                {summary.assurance.overdue} overdue evidence
                                checks
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            How To Run The Quarterly Workflow
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ol className="list-decimal space-y-1 pl-5 text-sm text-muted-foreground">
                            <li>
                                Triage new suggestions in{' '}
                                <strong>Triage Inbox</strong> and either reject
                                noise or convert accepted items.
                            </li>
                            <li>
                                Use <strong>Initiative Register</strong> quick
                                add for direct manager-owned initiatives.
                            </li>
                            <li>
                                Generate a draft in{' '}
                                <strong>
                                    Quarterly Planning Control Center
                                </strong>{' '}
                                (year, quarter, scoring preset).
                            </li>
                            <li>
                                In <strong>Quarterly Plans</strong>, click{' '}
                                <strong>Open</strong> to load and jump to plan
                                detail.
                            </li>
                            <li>
                                Run approvals in order: Approve, then Publish.
                            </li>
                            <li>
                                Resolve funding/risk approvals in{' '}
                                <strong>Decisions Required</strong> and export
                                board reports.
                            </li>
                        </ol>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Quarterly Planning Control Center
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid gap-3 md:grid-cols-4">
                            <div className="space-y-1">
                                <Label htmlFor="roadmap-fiscal-year">
                                    Fiscal year
                                </Label>
                                <Input
                                    id="roadmap-fiscal-year"
                                    type="number"
                                    min={2000}
                                    max={3000}
                                    value={planForm.fiscalYear}
                                    onChange={(event) =>
                                        setPlanForm((current) => ({
                                            ...current,
                                            fiscalYear:
                                                Number(event.target.value) ||
                                                new Date().getFullYear(),
                                        }))
                                    }
                                />
                            </div>

                            <div className="space-y-1">
                                <Label>Quarter</Label>
                                <Select
                                    value={String(planForm.quarter)}
                                    onValueChange={(value) =>
                                        setPlanForm((current) => ({
                                            ...current,
                                            quarter: Number(value),
                                        }))
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Quarter" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="1">Q1</SelectItem>
                                        <SelectItem value="2">Q2</SelectItem>
                                        <SelectItem value="3">Q3</SelectItem>
                                        <SelectItem value="4">Q4</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="space-y-1">
                                <Label>Scoring preset</Label>
                                <Select
                                    value={planForm.preset}
                                    onValueChange={(value) =>
                                        setPlanForm((current) => ({
                                            ...current,
                                            preset: value,
                                        }))
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Preset" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {PRESET_OPTIONS.map((preset) => (
                                            <SelectItem
                                                key={preset.key}
                                                value={preset.key}
                                            >
                                                {preset.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="flex items-end gap-2">
                                <Button
                                    onClick={() => void generatePlan()}
                                    disabled={
                                        !can.manageRoadmap ||
                                        actionLoadingKey === 'generate'
                                    }
                                >
                                    Generate Draft Plan
                                </Button>
                                <Button
                                    variant="outline"
                                    onClick={() => void runIngestNow()}
                                    disabled={
                                        !can.manageRoadmap ||
                                        actionLoadingKey === 'ingest'
                                    }
                                >
                                    Run Intake
                                </Button>
                            </div>
                        </div>

                        <div className="text-xs text-muted-foreground">
                            Workflow: draft to manager review to executive
                            review to approved to published (immutable) to
                            revise for a new draft revision.
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Initiative Quick Add (Manager Assigned)
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {!can.manageRoadmap && (
                            <p className="text-sm text-muted-foreground">
                                You can view roadmap data but cannot create or
                                assign initiatives.
                            </p>
                        )}

                        {can.manageRoadmap && (
                            <>
                                {managers.length === 0 && (
                                    <div className="rounded-md border border-status-warning/30 bg-status-warning-bg p-3 text-sm text-status-warning">
                                        No manager users are available to assign
                                        as initiative owners. Add manager roles
                                        in Access Control first.
                                    </div>
                                )}

                                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                                    <div className="space-y-1 md:col-span-2">
                                        <Label htmlFor="initiative-title">
                                            Initiative title
                                        </Label>
                                        <Input
                                            id="initiative-title"
                                            placeholder="e.g. CCTV resilience uplift for high-risk homes"
                                            value={initiativeForm.title}
                                            onChange={(event) =>
                                                setInitiativeForm(
                                                    (current) => ({
                                                        ...current,
                                                        title: event.target
                                                            .value,
                                                    }),
                                                )
                                            }
                                        />
                                    </div>

                                    <div className="space-y-1">
                                        <Label>Stream</Label>
                                        <Select
                                            value={initiativeForm.stream}
                                            onValueChange={(value) =>
                                                setInitiativeForm(
                                                    (current) => ({
                                                        ...current,
                                                        stream: value,
                                                    }),
                                                )
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Stream" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {STREAM_OPTIONS.map(
                                                    (stream) => (
                                                        <SelectItem
                                                            key={stream.key}
                                                            value={stream.key}
                                                        >
                                                            {stream.label}
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div className="space-y-1">
                                        <Label>Owner manager</Label>
                                        <Select
                                            value={
                                                initiativeForm.ownerUserId
                                                    ? String(
                                                          initiativeForm.ownerUserId,
                                                      )
                                                    : 'none'
                                            }
                                            onValueChange={(value) =>
                                                setInitiativeForm(
                                                    (current) => ({
                                                        ...current,
                                                        ownerUserId:
                                                            value === 'none'
                                                                ? null
                                                                : Number(value),
                                                    }),
                                                )
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select owner" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="none">
                                                    Unassigned
                                                </SelectItem>
                                                {managers.map((manager) => (
                                                    <SelectItem
                                                        key={manager.id}
                                                        value={String(
                                                            manager.id,
                                                        )}
                                                    >
                                                        {managerLabel(manager)}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div className="space-y-1">
                                        <Label>Sponsor (optional)</Label>
                                        <Select
                                            value={
                                                initiativeForm.sponsorUserId
                                                    ? String(
                                                          initiativeForm.sponsorUserId,
                                                      )
                                                    : 'none'
                                            }
                                            onValueChange={(value) =>
                                                setInitiativeForm(
                                                    (current) => ({
                                                        ...current,
                                                        sponsorUserId:
                                                            value === 'none'
                                                                ? null
                                                                : Number(value),
                                                    }),
                                                )
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select sponsor" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="none">
                                                    None
                                                </SelectItem>
                                                {managers.map((manager) => (
                                                    <SelectItem
                                                        key={manager.id}
                                                        value={String(
                                                            manager.id,
                                                        )}
                                                    >
                                                        {managerLabel(manager)}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div className="space-y-1">
                                        <Label htmlFor="initiative-fy">
                                            Target fiscal year
                                        </Label>
                                        <Input
                                            id="initiative-fy"
                                            type="number"
                                            min={2000}
                                            max={3000}
                                            value={
                                                initiativeForm.targetFiscalYear
                                            }
                                            onChange={(event) =>
                                                setInitiativeForm(
                                                    (current) => ({
                                                        ...current,
                                                        targetFiscalYear:
                                                            Number(
                                                                event.target
                                                                    .value,
                                                            ) ||
                                                            new Date().getFullYear(),
                                                    }),
                                                )
                                            }
                                        />
                                    </div>

                                    <div className="space-y-1">
                                        <Label>Target quarter</Label>
                                        <Select
                                            value={String(
                                                initiativeForm.targetQuarter,
                                            )}
                                            onValueChange={(value) =>
                                                setInitiativeForm(
                                                    (current) => ({
                                                        ...current,
                                                        targetQuarter:
                                                            Number(value),
                                                    }),
                                                )
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Quarter" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="1">
                                                    Q1
                                                </SelectItem>
                                                <SelectItem value="2">
                                                    Q2
                                                </SelectItem>
                                                <SelectItem value="3">
                                                    Q3
                                                </SelectItem>
                                                <SelectItem value="4">
                                                    Q4
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div className="space-y-1">
                                        <Label htmlFor="initiative-cost-low">
                                            Cost low (NZD)
                                        </Label>
                                        <Input
                                            id="initiative-cost-low"
                                            inputMode="decimal"
                                            placeholder="0"
                                            value={
                                                initiativeForm.costEstimateLow
                                            }
                                            onChange={(event) =>
                                                setInitiativeForm(
                                                    (current) => ({
                                                        ...current,
                                                        costEstimateLow:
                                                            event.target.value,
                                                    }),
                                                )
                                            }
                                        />
                                    </div>

                                    <div className="space-y-1">
                                        <Label htmlFor="initiative-cost-high">
                                            Cost high (NZD)
                                        </Label>
                                        <Input
                                            id="initiative-cost-high"
                                            inputMode="decimal"
                                            placeholder="0"
                                            value={
                                                initiativeForm.costEstimateHigh
                                            }
                                            onChange={(event) =>
                                                setInitiativeForm(
                                                    (current) => ({
                                                        ...current,
                                                        costEstimateHigh:
                                                            event.target.value,
                                                    }),
                                                )
                                            }
                                        />
                                    </div>

                                    <div className="space-y-1 md:col-span-2">
                                        <Label htmlFor="initiative-next-decision">
                                            Next decision required
                                        </Label>
                                        <Input
                                            id="initiative-next-decision"
                                            value={initiativeForm.nextDecision}
                                            onChange={(event) =>
                                                setInitiativeForm(
                                                    (current) => ({
                                                        ...current,
                                                        nextDecision:
                                                            event.target.value,
                                                    }),
                                                )
                                            }
                                        />
                                    </div>
                                </div>

                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        onClick={() => void createInitiative()}
                                        disabled={
                                            actionLoadingKey ===
                                                'initiative:create' ||
                                            managers.length === 0
                                        }
                                    >
                                        Create Initiative
                                    </Button>
                                </div>
                            </>
                        )}
                    </CardContent>
                </Card>

                {triage.overload && (
                    <Card className="border-status-warning/30 bg-status-warning-bg">
                        <CardContent className="flex items-center gap-3 py-4">
                            <AlertTriangle className="h-5 w-5 text-status-warning" />
                            <div className="text-sm">
                                Triage inbox is overloaded with {triage.pending}{' '}
                                pending suggestions. Review and convert or
                                reject to prevent roadmap noise.
                            </div>
                        </CardContent>
                    </Card>
                )}

                <div className="grid gap-4 xl:grid-cols-2">
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between gap-3">
                            <CardTitle className="text-base">
                                Top Ranked Initiatives
                            </CardTitle>
                            <Link
                                href="/roadmap/initiatives"
                                className="text-sm font-medium text-primary hover:underline"
                            >
                                View all initiatives
                            </Link>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {summary.initiatives.top.length === 0 && (
                                <p className="text-sm text-muted-foreground">
                                    No ranked initiatives available.
                                </p>
                            )}
                            {summary.initiatives.top.map((item) => (
                                <div
                                    key={item.id}
                                    className="rounded-md border p-3"
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <div className="font-medium">
                                            {item.title}
                                        </div>
                                        <Badge variant="outline">
                                            Score {item.score.toFixed(1)}
                                        </Badge>
                                    </div>
                                    <div className="mt-1 flex flex-wrap gap-2 text-xs text-muted-foreground">
                                        <span>
                                            {item.code ?? `INIT-${item.id}`}
                                        </span>
                                        <span>{statusLabel(item.status)}</span>
                                        <span>
                                            {item.owner ?? 'Owner unassigned'}
                                        </span>
                                    </div>
                                </div>
                            ))}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                House Rollout Status
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                                <div className="rounded-md border p-3 text-center">
                                    <div className="text-xl font-semibold">
                                        {summary.house_rollout.not_started}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        Not started
                                    </div>
                                </div>
                                <div className="rounded-md border p-3 text-center">
                                    <div className="text-xl font-semibold">
                                        {summary.house_rollout.in_progress}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        In progress
                                    </div>
                                </div>
                                <div className="rounded-md border p-3 text-center">
                                    <div className="text-xl font-semibold">
                                        {summary.house_rollout.blocked}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        Blocked
                                    </div>
                                </div>
                                <div className="rounded-md border p-3 text-center">
                                    <div className="text-xl font-semibold">
                                        {summary.house_rollout.completed}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        Completed
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <div ref={planDetailSectionRef}>
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Selected Quarterly Plan Detail
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {!can.viewRoadmap && (
                                <p className="text-sm text-muted-foreground">
                                    No plan detail access for this role.
                                </p>
                            )}

                            {can.viewRoadmap && planDetailLoading && (
                                <p className="text-sm text-muted-foreground">
                                    Loading selected quarterly plan detail...
                                </p>
                            )}

                            {can.viewRoadmap &&
                                !planDetailLoading &&
                                selectedPlan === null && (
                                    <p className="text-sm text-muted-foreground">
                                        Choose a plan from the Quarterly Plans
                                        table to inspect ranked items and costs.
                                    </p>
                                )}

                            {can.viewRoadmap &&
                                !planDetailLoading &&
                                selectedPlan !== null && (
                                    <div className="space-y-3">
                                        <div className="flex flex-wrap items-center gap-2 text-sm">
                                            <Badge variant="outline">{`FY${selectedPlan.fiscal_year} Q${selectedPlan.quarter} r${selectedPlan.revision_no}`}</Badge>
                                            <Badge variant="outline">
                                                {statusLabel(
                                                    selectedPlan.status,
                                                )}
                                            </Badge>
                                            <Badge variant="outline">
                                                {statusLabel(
                                                    selectedPlan.preset_profile,
                                                )}
                                            </Badge>
                                        </div>

                                        <PlanDetailTable plan={selectedPlan} />
                                    </div>
                                )}
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between gap-3">
                        <CardTitle className="text-base">
                            Initiative Register (Recent)
                        </CardTitle>
                        <Link
                            href="/roadmap/initiatives"
                            className="text-sm font-medium text-primary hover:underline"
                        >
                            View all initiatives
                        </Link>
                    </CardHeader>
                    <CardContent>
                        {can.viewRoadmap ? (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Title</TableHead>
                                        <TableHead>Stream</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Owner</TableHead>
                                        <TableHead>Sponsor</TableHead>
                                        <TableHead>Next Decision</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {initiatives.length === 0 && (
                                        <TableRow>
                                            <TableCell
                                                colSpan={6}
                                                className="text-muted-foreground"
                                            >
                                                No initiatives found.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {initiatives
                                        .slice(0, 8)
                                        .map((initiative) => (
                                            <TableRow key={initiative.id}>
                                                <TableCell className="max-w-[420px] truncate">
                                                    {initiative.title}
                                                </TableCell>
                                                <TableCell>
                                                    {initiative.stream
                                                        ? statusLabel(
                                                              initiative.stream,
                                                          )
                                                        : '-'}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant="outline">
                                                        {statusLabel(
                                                            initiative.status,
                                                        )}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell>
                                                    {initiative.owner_name ??
                                                        '-'}
                                                </TableCell>
                                                <TableCell>
                                                    {initiative.sponsor_name ??
                                                        '-'}
                                                </TableCell>
                                                <TableCell className="max-w-[280px] truncate">
                                                    {initiative.next_decision ??
                                                        '-'}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                </TableBody>
                            </Table>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                No initiative list access for this role.
                            </p>
                        )}
                    </CardContent>
                </Card>

                <div className="grid gap-4 xl:grid-cols-2">
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between gap-3">
                            <CardTitle className="text-base">
                                Quarterly Plans
                            </CardTitle>
                            <Link
                                href="/roadmap/quarterly-plans"
                                className="text-sm font-medium text-primary hover:underline"
                            >
                                Quarterly plan history
                            </Link>
                        </CardHeader>
                        <CardContent>
                            {can.viewRoadmap ? (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Quarter</TableHead>
                                            <TableHead>Preset</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Items</TableHead>
                                            <TableHead>Actions</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {plans.length === 0 && (
                                            <TableRow>
                                                <TableCell
                                                    colSpan={5}
                                                    className="text-muted-foreground"
                                                >
                                                    No quarterly plans found.
                                                </TableCell>
                                            </TableRow>
                                        )}
                                        {plans.map((plan) => (
                                            <TableRow
                                                key={plan.id}
                                                className={
                                                    selectedPlanId === plan.id
                                                        ? 'bg-muted/50'
                                                        : undefined
                                                }
                                            >
                                                <TableCell>{`FY${plan.fiscal_year} Q${plan.quarter} r${plan.revision_no}`}</TableCell>
                                                <TableCell>
                                                    {statusLabel(
                                                        plan.preset_profile ??
                                                            'board_ceo',
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant="outline">
                                                        {statusLabel(
                                                            plan.status,
                                                        )}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell>
                                                    {plan.items_count ?? 0}
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex flex-wrap gap-1">
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() =>
                                                                void openPlanDetail(
                                                                    plan.id,
                                                                )
                                                            }
                                                        >
                                                            Open
                                                        </Button>
                                                        {availablePlanActions(
                                                            plan,
                                                        ).map((action) => {
                                                            const key = `${plan.id}:${action}`;

                                                            return (
                                                                <Button
                                                                    key={key}
                                                                    size="sm"
                                                                    onClick={() =>
                                                                        void runPlanAction(
                                                                            plan.id,
                                                                            action,
                                                                        )
                                                                    }
                                                                    disabled={
                                                                        actionLoadingKey ===
                                                                        key
                                                                    }
                                                                >
                                                                    {actionLabel(
                                                                        action,
                                                                    )}
                                                                </Button>
                                                            );
                                                        })}
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    You can view summary metrics but not
                                    detailed roadmap plans.
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between gap-3">
                            <CardTitle className="text-base">
                                Triage Inbox
                            </CardTitle>
                            <Link
                                href="/roadmap/suggestions"
                                className="text-sm font-medium text-primary hover:underline"
                            >
                                Open triage backlog
                            </Link>
                        </CardHeader>
                        <CardContent>
                            {can.viewRoadmap ? (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Title</TableHead>
                                            <TableHead>Source</TableHead>
                                            <TableHead>Owner</TableHead>
                                            <TableHead>Hits</TableHead>
                                            <TableHead>Last Seen</TableHead>
                                            <TableHead>View</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {suggestions.length === 0 && (
                                            <TableRow>
                                                <TableCell
                                                    colSpan={6}
                                                    className="text-muted-foreground"
                                                >
                                                    No pending suggestions.
                                                </TableCell>
                                            </TableRow>
                                        )}
                                        {suggestions.map((suggestion) => (
                                            <TableRow key={suggestion.id}>
                                                <TableCell className="max-w-[280px] truncate">
                                                    {suggestion.title}
                                                </TableCell>
                                                <TableCell>
                                                    {statusLabel(
                                                        suggestion.source,
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {suggestion.triage_owner_name ??
                                                        'Unassigned'}
                                                </TableCell>
                                                <TableCell>
                                                    {suggestion.hit_count ?? 0}
                                                </TableCell>
                                                <TableCell>
                                                    {suggestion.last_seen_at
                                                        ? new Date(
                                                              suggestion.last_seen_at,
                                                          ).toLocaleDateString()
                                                        : '-'}
                                                </TableCell>
                                                <TableCell>
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() => {
                                                            setSelectedSuggestion(
                                                                suggestion,
                                                            );
                                                            setSuggestionNotesDraft(
                                                                suggestion.triage_notes ??
                                                                    '',
                                                            );
                                                            setShowTechnicalPayload(
                                                                false,
                                                            );
                                                            setIsSuggestionDialogOpen(
                                                                true,
                                                            );
                                                        }}
                                                    >
                                                        View
                                                    </Button>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    No triage permission on this account.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between gap-3">
                        <CardTitle className="text-base">
                            Decision Requests
                        </CardTitle>
                        <Link
                            href="/roadmap/decisions"
                            className="text-sm font-medium text-primary hover:underline"
                        >
                            All pending decisions
                        </Link>
                    </CardHeader>
                    <CardContent>
                        {can.viewDecisions ? (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Type</TableHead>
                                        <TableHead>Required Role</TableHead>
                                        <TableHead>Amount</TableHead>
                                        <TableHead>Due</TableHead>
                                        <TableHead>Status</TableHead>
                                        {can.manageDecisions && (
                                            <TableHead>Actions</TableHead>
                                        )}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {decisions.length === 0 && (
                                        <TableRow>
                                            <TableCell
                                                colSpan={
                                                    can.manageDecisions ? 6 : 5
                                                }
                                                className="text-muted-foreground"
                                            >
                                                No pending decisions.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {decisions.map((decision) => (
                                        <TableRow key={decision.id}>
                                            <TableCell>
                                                {statusLabel(
                                                    decision.request_type,
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {decision.required_role ?? '-'}
                                            </TableCell>
                                            <TableCell>
                                                {decision.amount
                                                    ? currency(decision.amount)
                                                    : '-'}
                                            </TableCell>
                                            <TableCell>
                                                {decision.due_date ?? '-'}
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant="outline">
                                                    {statusLabel(
                                                        decision.status,
                                                    )}
                                                </Badge>
                                            </TableCell>
                                            {can.manageDecisions && (
                                                <TableCell>
                                                    <div className="flex flex-wrap gap-1">
                                                        <Button
                                                            size="sm"
                                                            onClick={() =>
                                                                void resolveDecision(
                                                                    decision.id,
                                                                    'approved',
                                                                )
                                                            }
                                                            disabled={
                                                                actionLoadingKey ===
                                                                `decision:${decision.id}:approved`
                                                            }
                                                        >
                                                            Approve
                                                        </Button>
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() =>
                                                                void resolveDecision(
                                                                    decision.id,
                                                                    'rejected',
                                                                )
                                                            }
                                                            disabled={
                                                                actionLoadingKey ===
                                                                `decision:${decision.id}:rejected`
                                                            }
                                                        >
                                                            Reject
                                                        </Button>
                                                    </div>
                                                </TableCell>
                                            )}
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        ) : (
                            <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                <CheckCircle2 className="h-4 w-4" />
                                Decision request list is hidden for your role.
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Dialog
                    open={isPlanDialogOpen}
                    onOpenChange={setIsPlanDialogOpen}
                >
                    <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-5xl">
                        <DialogHeader>
                            <DialogTitle>Quarterly Plan Detail</DialogTitle>
                            <DialogDescription>
                                Ranked initiatives and planned costs for this
                                quarter.
                            </DialogDescription>
                        </DialogHeader>

                        {planDetailLoading && (
                            <p className="text-sm text-muted-foreground">
                                Loading selected quarterly plan detail...
                            </p>
                        )}

                        {!planDetailLoading && selectedPlan === null && (
                            <p className="text-sm text-muted-foreground">
                                No plan detail loaded. Choose a plan from the
                                Quarterly Plans table.
                            </p>
                        )}

                        {!planDetailLoading && selectedPlan !== null && (
                            <div className="space-y-3 text-sm">
                                <div className="flex flex-wrap items-center gap-2">
                                    <Badge variant="outline">{`FY${selectedPlan.fiscal_year} Q${selectedPlan.quarter} r${selectedPlan.revision_no}`}</Badge>
                                    <Badge variant="outline">
                                        {statusLabel(selectedPlan.status)}
                                    </Badge>
                                    <Badge variant="outline">
                                        {statusLabel(
                                            selectedPlan.preset_profile,
                                        )}
                                    </Badge>
                                </div>

                                <PlanDetailTable plan={selectedPlan} />
                            </div>
                        )}
                    </DialogContent>
                </Dialog>

                <Dialog
                    open={isSuggestionDialogOpen}
                    onOpenChange={(open) => {
                        setIsSuggestionDialogOpen(open);
                        if (!open) {
                            setSuggestionNotesDraft('');
                            setShowTechnicalPayload(false);
                        }
                    }}
                >
                    <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-3xl">
                        <DialogHeader>
                            <DialogTitle>Triage Suggestion Details</DialogTitle>
                            <DialogDescription>
                                Review source evidence before converting or
                                triaging.
                            </DialogDescription>
                        </DialogHeader>

                        {selectedSuggestion && (
                            <div className="space-y-4 text-sm">
                                <div className="grid gap-3 md:grid-cols-2">
                                    <div>
                                        <div className="text-xs text-muted-foreground">
                                            Title
                                        </div>
                                        <div className="font-medium">
                                            {selectedSuggestion.title}
                                        </div>
                                    </div>
                                    <div>
                                        <div className="text-xs text-muted-foreground">
                                            Source
                                        </div>
                                        <div>
                                            {statusLabel(
                                                selectedSuggestion.source,
                                            )}
                                        </div>
                                    </div>
                                    <div>
                                        <div className="text-xs text-muted-foreground">
                                            Current owner
                                        </div>
                                        <div>
                                            {selectedSuggestion.triage_owner_name ??
                                                'Unassigned'}
                                        </div>
                                        {selectedSuggestion.triage_owner_email && (
                                            <div className="text-xs text-muted-foreground">
                                                {
                                                    selectedSuggestion.triage_owner_email
                                                }
                                            </div>
                                        )}
                                        {can.manageRoadmap && (
                                            <div className="mt-2 max-w-[260px]">
                                                <Select
                                                    value={
                                                        selectedSuggestion.triage_owner_id
                                                            ? String(
                                                                  selectedSuggestion.triage_owner_id,
                                                              )
                                                            : 'none'
                                                    }
                                                    onValueChange={(value) =>
                                                        void assignSuggestionOwner(
                                                            selectedSuggestion.id,
                                                            value === 'none'
                                                                ? null
                                                                : Number(value),
                                                        )
                                                    }
                                                >
                                                    <SelectTrigger className="h-8">
                                                        <SelectValue placeholder="Assign owner" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="none">
                                                            Unassigned
                                                        </SelectItem>
                                                        {managers.map(
                                                            (manager) => (
                                                                <SelectItem
                                                                    key={
                                                                        manager.id
                                                                    }
                                                                    value={String(
                                                                        manager.id,
                                                                    )}
                                                                >
                                                                    {managerLabel(
                                                                        manager,
                                                                    )}
                                                                </SelectItem>
                                                            ),
                                                        )}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                        )}
                                    </div>
                                    <div>
                                        <div className="text-xs text-muted-foreground">
                                            Status
                                        </div>
                                        <div>
                                            {statusLabel(
                                                selectedSuggestion.status,
                                            )}
                                        </div>
                                    </div>
                                    <div>
                                        <div className="text-xs text-muted-foreground">
                                            Hits
                                        </div>
                                        <div>
                                            {selectedSuggestion.hit_count ?? 0}
                                        </div>
                                    </div>
                                    <div>
                                        <div className="text-xs text-muted-foreground">
                                            Score hint
                                        </div>
                                        <div>
                                            {selectedSuggestion.score_hint ??
                                                '-'}
                                        </div>
                                    </div>
                                    <div>
                                        <div className="text-xs text-muted-foreground">
                                            First seen
                                        </div>
                                        <div>
                                            {formatDateValue(
                                                selectedSuggestion.first_seen_at,
                                            )}
                                        </div>
                                    </div>
                                    <div>
                                        <div className="text-xs text-muted-foreground">
                                            Last seen
                                        </div>
                                        <div>
                                            {formatDateValue(
                                                selectedSuggestion.last_seen_at,
                                            )}
                                        </div>
                                    </div>
                                    <div>
                                        <div className="text-xs text-muted-foreground">
                                            Source key
                                        </div>
                                        <div>
                                            {selectedSuggestion.source_key ??
                                                '-'}
                                        </div>
                                    </div>
                                    <div>
                                        <div className="text-xs text-muted-foreground">
                                            Dedupe key
                                        </div>
                                        <div className="break-all">
                                            {selectedSuggestion.dedupe_key ??
                                                '-'}
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <div className="mb-1 text-xs text-muted-foreground">
                                        Summary
                                    </div>
                                    <div className="rounded-md border p-3">
                                        {selectedSuggestion.summary ??
                                            'No summary provided.'}
                                    </div>
                                </div>

                                <div>
                                    <Label
                                        htmlFor="triage-notes"
                                        className="text-xs text-muted-foreground"
                                    >
                                        Triage notes
                                    </Label>
                                    <Textarea
                                        id="triage-notes"
                                        className="mt-1 min-h-[100px]"
                                        value={suggestionNotesDraft}
                                        onChange={(event) =>
                                            setSuggestionNotesDraft(
                                                event.target.value,
                                            )
                                        }
                                        placeholder="Add context for why this suggestion should be accepted, rejected, or converted."
                                    />
                                </div>

                                {suggestionIssueDetails.length > 0 && (
                                    <div>
                                        <div className="mb-1 text-xs text-muted-foreground">
                                            Issue details
                                        </div>
                                        <div className="rounded-md border p-3">
                                            <div className="grid gap-2 md:grid-cols-2">
                                                {suggestionIssueDetails.map(
                                                    (detail) => (
                                                        <div
                                                            key={`${detail.label}:${detail.value}`}
                                                        >
                                                            <div className="text-xs text-muted-foreground">
                                                                {detail.label}
                                                            </div>
                                                            <div>
                                                                {detail.value}
                                                            </div>
                                                        </div>
                                                    ),
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                )}

                                {suggestionIncidentNotes.length > 0 && (
                                    <div>
                                        <div className="mb-1 text-xs text-muted-foreground">
                                            Incident notes
                                        </div>
                                        <div className="rounded-md border p-3">
                                            <ul className="list-disc space-y-1 pl-4">
                                                {suggestionIncidentNotes.map(
                                                    (note, index) => (
                                                        <li
                                                            key={`incident-note-${index}`}
                                                        >
                                                            {note}
                                                        </li>
                                                    ),
                                                )}
                                            </ul>
                                        </div>
                                    </div>
                                )}

                                {suggestionIncidentExamples.length > 0 && (
                                    <div>
                                        <div className="mb-1 text-xs text-muted-foreground">
                                            Recent incident examples
                                        </div>
                                        <div className="space-y-2 rounded-md border p-3">
                                            {suggestionIncidentExamples.map(
                                                (example, index) => (
                                                    <div
                                                        key={`incident-example-${index}`}
                                                        className="rounded border bg-muted/20 p-2"
                                                    >
                                                        <div className="font-medium">
                                                            {asString(
                                                                example.title,
                                                            ) ?? 'Incident'}
                                                        </div>
                                                        <div className="text-xs text-muted-foreground">
                                                            ID{' '}
                                                            {asString(
                                                                example.id,
                                                            ) ?? '-'}{' '}
                                                            |{' '}
                                                            {formatDateValue(
                                                                asString(
                                                                    example.occurred_at,
                                                                ),
                                                            )}{' '}
                                                            |{' '}
                                                            {asString(
                                                                example.location,
                                                            ) ?? 'No location'}
                                                        </div>
                                                    </div>
                                                ),
                                            )}
                                        </div>
                                    </div>
                                )}

                                {suggestionAssetNotes.length > 0 && (
                                    <div>
                                        <div className="mb-1 text-xs text-muted-foreground">
                                            Asset notes
                                        </div>
                                        <div className="rounded-md border p-3">
                                            <ul className="list-disc space-y-1 pl-4">
                                                {suggestionAssetNotes.map(
                                                    (note, index) => (
                                                        <li
                                                            key={`asset-note-${index}`}
                                                        >
                                                            {note}
                                                        </li>
                                                    ),
                                                )}
                                            </ul>
                                        </div>
                                    </div>
                                )}

                                {suggestionAssetExamples.length > 0 && (
                                    <div>
                                        <div className="mb-1 text-xs text-muted-foreground">
                                            Asset examples
                                        </div>
                                        <div className="space-y-2 rounded-md border p-3">
                                            {suggestionAssetExamples.map(
                                                (example, index) => (
                                                    <div
                                                        key={`asset-example-${index}`}
                                                        className="rounded border bg-muted/20 p-2"
                                                    >
                                                        <div className="font-medium">
                                                            {asString(
                                                                example.name,
                                                            ) ?? 'Asset'}
                                                            {asString(
                                                                example.asset_tag,
                                                            )
                                                                ? ` (${asString(example.asset_tag)})`
                                                                : ''}
                                                        </div>
                                                        <div className="text-xs text-muted-foreground">
                                                            ID{' '}
                                                            {asString(
                                                                example.id,
                                                            ) ?? '-'}{' '}
                                                            | Maintenance due:{' '}
                                                            {asString(
                                                                example.maintenance_due_at,
                                                            ) ?? '-'}{' '}
                                                            | Warranty:{' '}
                                                            {asString(
                                                                example.warranty_expires_at,
                                                            ) ?? '-'}{' '}
                                                            | Risk:{' '}
                                                            {asString(
                                                                example.risk_level,
                                                            ) ?? '-'}
                                                        </div>
                                                    </div>
                                                ),
                                            )}
                                        </div>
                                    </div>
                                )}

                                <div>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            setShowTechnicalPayload(
                                                (current) => !current,
                                            )
                                        }
                                    >
                                        {showTechnicalPayload
                                            ? 'Hide technical payload'
                                            : 'Show technical payload'}
                                    </Button>
                                    {showTechnicalPayload && (
                                        <pre className="mt-2 max-h-[280px] overflow-auto rounded-md border bg-muted/30 p-3 text-xs">
                                            {selectedSuggestion.raw_payload
                                                ? JSON.stringify(
                                                      selectedSuggestion.raw_payload,
                                                      null,
                                                      2,
                                                  )
                                                : 'No raw payload captured.'}
                                        </pre>
                                    )}
                                </div>

                                <div className="flex flex-wrap gap-2">
                                    {can.manageRoadmap && (
                                        <>
                                            <Button
                                                variant="outline"
                                                onClick={() =>
                                                    void saveSuggestionNotes(
                                                        selectedSuggestion.id,
                                                        suggestionNotesDraft,
                                                    )
                                                }
                                                disabled={
                                                    actionLoadingKey ===
                                                        `suggestion:${selectedSuggestion.id}:notes` ||
                                                    actionLoadingKey ===
                                                        `suggestion:${selectedSuggestion.id}:assign`
                                                }
                                            >
                                                Save notes
                                            </Button>
                                            <Button
                                                onClick={() =>
                                                    void convertSuggestion(
                                                        selectedSuggestion.id,
                                                        suggestionNotesDraft,
                                                    )
                                                }
                                                disabled={
                                                    actionLoadingKey ===
                                                        `suggestion:${selectedSuggestion.id}:convert` ||
                                                    actionLoadingKey ===
                                                        `suggestion:${selectedSuggestion.id}:assign`
                                                }
                                            >
                                                Convert to Initiative
                                            </Button>
                                            <Button
                                                variant="outline"
                                                onClick={() =>
                                                    void triageSuggestion(
                                                        selectedSuggestion.id,
                                                        'accepted',
                                                        suggestionNotesDraft,
                                                    )
                                                }
                                                disabled={
                                                    actionLoadingKey ===
                                                        `suggestion:${selectedSuggestion.id}:accepted` ||
                                                    actionLoadingKey ===
                                                        `suggestion:${selectedSuggestion.id}:assign`
                                                }
                                            >
                                                Accept
                                            </Button>
                                            <Button
                                                variant="outline"
                                                onClick={() =>
                                                    void triageSuggestion(
                                                        selectedSuggestion.id,
                                                        'rejected',
                                                        suggestionNotesDraft,
                                                    )
                                                }
                                                disabled={
                                                    actionLoadingKey ===
                                                        `suggestion:${selectedSuggestion.id}:rejected` ||
                                                    actionLoadingKey ===
                                                        `suggestion:${selectedSuggestion.id}:assign`
                                                }
                                            >
                                                Reject
                                            </Button>
                                        </>
                                    )}
                                    <Button
                                        variant="outline"
                                        onClick={() => {
                                            setSuggestionNotesDraft('');
                                            setShowTechnicalPayload(false);
                                            setIsSuggestionDialogOpen(false);
                                        }}
                                    >
                                        Close
                                    </Button>
                                </div>
                            </div>
                        )}
                    </DialogContent>
                </Dialog>
            </PageLayout>
        </AppLayout>
    );
}
