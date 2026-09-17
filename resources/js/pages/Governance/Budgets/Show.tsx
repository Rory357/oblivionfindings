import { ConfirmDialog } from '@/components/confirm-dialog';
import { GovernanceExplainer } from '@/components/governance/GovernanceTermHint';
import {
    pageHasFlashError,
    useDialogDeepLink,
} from '@/components/governance/governance-dialog-deep-link';
import InputError from '@/components/input-error';
import {
    EmptyValue,
    EntityChip,
    EntityContextMenu,
    EntityStatusChip,
    EntityTable,
    ListCaption,
    ProgressValue,
    compactMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import {
    TierTwoTabs,
    type GroupedProfileNavTab,
} from '@/components/page/grouped-profile-nav';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    InfoCard,
    SelectInput,
    TilePicker,
} from '@/components/wizard/primitives';
import AppLayout from '@/layouts/app-layout';
import { formatDateLong, formatMonthYear } from '@/lib/datetime';
import {
    budgetCategoryLabel,
    formatNzd,
    governanceStatus,
    refSuffix,
} from '@/lib/governance-labels';
import { PageProps } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    CalendarRange,
    CheckCircle,
    ExternalLink,
    Layers,
    ListChecks,
    Pencil,
    Plus,
    Receipt,
    Send,
    Trash2,
    Undo2,
    Wallet,
    XCircle,
} from 'lucide-react';
import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { BudgetWizardDialog } from './_dialogs';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

interface LineItem {
    id: number;
    category: string;
    description: string;
    account_code: string | null;
    budget_amount: number | string;
    forecast_amount: number | string | null;
    actual_amount: number | string;
    notes: string | null;
}

interface LinkedResolution {
    id: number;
    title: string;
    reference: string | null;
}

interface BudgetChange {
    id: number;
    direction: string;
    amount: number | string;
    reason: string;
    status: string;
    needs_board: boolean;
    line: {
        id: number;
        description: string;
        budget_amount: number | string;
    } | null;
    requested_by: string | null;
    requested_at: string | null;
    decided_by: string | null;
    decided_at: string | null;
    review_notes: string | null;
    resolution: (LinkedResolution & {
        status: string;
        outcome: string | null;
    }) | null;
    ready_resolution: (LinkedResolution & { amount: number | null }) | null;
}

interface MonthlyAmount {
    id: number;
    budget_line_item_id: number | null;
    line_description: string | null;
    site_id: number | null;
    site_name?: string | null;
    period_year_month: string;
    category: string | null;
    allocated_amount: number | string;
    forecast_amount: number | string | null;
    actual_amount: number | string | null;
    notes: string | null;
}

interface BudgetRecord {
    id: number;
    fiscal_year: string;
    financial_year_label: string;
    title: string | null;
    display_name: string;
    description: string | null;
    total_budget: number | string;
    currency: string;
    status: string;
    version_number: number;
    supersedes: { id: number; version_number: number } | null;
    created_by: string | null;
    proposed_by: string | null;
    proposed_at: string | null;
    approved_by_board_at: string | null;
    external_approval_reference: string | null;
    actuals_recorded: boolean;
    actuals_recorded_at: string | null;
    line_items: LineItem[];
    changes: BudgetChange[];
    allocations: MonthlyAmount[];
}

interface ApprovalSummary {
    key: string;
    label: string;
    detail: string;
    tone: 'success' | 'warning' | 'info' | 'critical' | 'neutral';
    stale: boolean;
    resolution: LinkedResolution | null;
}

interface Props extends PageProps {
    budget: BudgetRecord;
    categories: Record<string, string>;
    approval: ApprovalSummary;
    changeThreshold: { percent: number; amount: number; sentence: string };
    canEdit: boolean;
    canPropose: boolean;
    canReturnToDrafting: boolean;
    canApprove: boolean;
    canRequestChange: boolean;
    canDecideChanges: boolean;
    canRecordActuals: boolean;
    canManageAllocations: boolean;
    allocationOptions: {
        sites: Array<{ id: number; name: string }>;
        can_leave_site_empty: boolean;
    } | null;
}

type TabKey = 'lines' | 'changes' | 'categories' | 'monthly';

const TAB_KEYS: TabKey[] = ['lines', 'changes', 'categories', 'monthly'];
const NONE = '__none';

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

const amount = (value: number | string | null | undefined) =>
    Number(value) || 0;

/** "Over budget by $X" / "Under budget by $X" / "On budget" — actual − budget. */
export function spendPosition(
    budgeted: number,
    actual: number,
): { text: string; over: boolean } {
    const difference = Math.round((actual - budgeted) * 100) / 100;
    if (difference > 0) {
        return { text: `Over budget by ${formatNzd(difference)}`, over: true };
    }
    if (difference < 0) {
        return {
            text: `Under budget by ${formatNzd(Math.abs(difference))}`,
            over: false,
        };
    }
    return { text: 'On budget', over: false };
}

/** `?tab=adjustments` (older links) opens Budget changes. */
export function budgetTabFromUrl(url: string): TabKey {
    const query = url.split('#')[0]?.split('?')[1] ?? '';
    const tab = new URLSearchParams(query).get('tab');
    if (tab === 'adjustments') return 'changes';
    return TAB_KEYS.includes(tab as TabKey) ? (tab as TabKey) : 'lines';
}

function urlWithTab(url: string, tab: TabKey): string {
    const [pathAndQuery, hash] = url.split('#');
    const [path, query = ''] = (pathAndQuery ?? '').split('?');
    const params = new URLSearchParams(query);
    if (tab === 'lines') params.delete('tab');
    else params.set('tab', tab);
    const qs = params.toString();
    return `${path}${qs ? `?${qs}` : ''}${hash ? `#${hash}` : ''}`;
}

const TONE_VARIANT: Record<ApprovalSummary['tone'], StatusVariant> = {
    success: 'success',
    warning: 'warning',
    info: 'info',
    critical: 'critical',
    neutral: 'neutral',
};

/** Short words for the header meter; the full sentence is on the page. */
const APPROVAL_METER: Record<string, string> = {
    approved: 'Approved',
    draft: 'Draft',
    drafted: 'Not on an agenda',
    on_agenda: 'On an agenda',
    voting_open: 'Voting open',
    passed: 'Passed',
    not_passed: 'Not passed',
    stale: 'Out of date',
    missing: 'No resolution',
    unlinked: "Can't approve",
    used: 'Already used',
};

/** "Increase Support worker wages by $5,000". */
function changeTitle(change: BudgetChange): string {
    const verb = change.direction === 'decrease' ? 'Decrease' : 'Increase';
    return `${verb} ${change.line?.description ?? 'a budget line'} by ${formatNzd(change.amount)}`;
}

function lineAfterChange(change: BudgetChange): number | null {
    if (!change.line) return null;
    const current = amount(change.line.budget_amount);
    return change.direction === 'decrease'
        ? current - amount(change.amount)
        : current + amount(change.amount);
}

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

export default function BudgetShow({
    budget,
    categories,
    approval,
    changeThreshold,
    canEdit,
    canPropose,
    canReturnToDrafting,
    canApprove,
    canRequestChange,
    canDecideChanges,
    canRecordActuals,
    canManageAllocations,
    allocationOptions,
}: Props) {
    const page = usePage<{ errors?: Record<string, string> }>();
    const errors = page.props.errors ?? {};
    const isApproved = budget.status === 'approved';
    const isProposed = budget.status === 'proposed';

    const [tab, setTab] = useState<TabKey>(() => budgetTabFromUrl(page.url));
    const [overOnly, setOverOnly] = useState(false);
    const [categoryFilter, setCategoryFilter] = useState<string | null>(null);
    const [editOpen, setEditOpen] = useDialogDeepLink(
        'edit',
        canEdit,
        isApproved
            ? 'This budget is approved and can no longer be edited. Request a budget change instead.'
            : "You can't edit this budget.",
    );

    const [lineDialog, setLineDialog] = useState<
        { mode: 'add' } | { mode: 'edit'; line: LineItem } | null
    >(null);
    const [removeLine, setRemoveLine] = useState<LineItem | null>(null);
    const [actualsFor, setActualsFor] = useState<LineItem[] | null>(null);
    const [changeFor, setChangeFor] = useState<{ lineId?: number } | null>(
        null,
    );
    const [approveChange, setApproveChange] = useState<BudgetChange | null>(
        null,
    );
    const [declineChange, setDeclineChange] = useState<BudgetChange | null>(
        null,
    );
    const [monthlyOpen, setMonthlyOpen] = useState(false);
    const [removeMonthly, setRemoveMonthly] = useState<MonthlyAmount | null>(
        null,
    );
    const [confirm, setConfirm] = useState<
        'propose' | 'approve' | 'return' | null
    >(null);

    const lineCtx = useEntityContextMenu<LineItem>();
    const changeCtx = useEntityContextMenu<BudgetChange>();
    const categoryCtx = useEntityContextMenu<CategoryRow>();
    const monthlyCtx = useEntityContextMenu<MonthlyAmount>();

    useEffect(() => {
        setTab(budgetTabFromUrl(page.url));
    }, [page.url]);

    const selectTab = (next: TabKey) => {
        setTab(next);
        router.replace({
            url: urlWithTab(page.url, next),
            preserveScroll: true,
            preserveState: true,
        });
    };

    /* ── figures ─────────────────────────────────────────────────── */

    const lines = useMemo(() => budget.line_items ?? [], [budget.line_items]);
    const total = amount(budget.total_budget);
    const totalActual = lines.reduce(
        (sum, line) => sum + amount(line.actual_amount),
        0,
    );
    const linesTotal = lines.reduce(
        (sum, line) => sum + amount(line.budget_amount),
        0,
    );
    const position = spendPosition(total, totalActual);
    const overLines = budget.actuals_recorded
        ? lines.filter(
              (line) => amount(line.actual_amount) > amount(line.budget_amount),
          )
        : [];
    const waitingChanges = budget.changes.filter(
        (change) => change.status === 'submitted',
    );
    const decidedChanges = budget.changes.filter(
        (change) => change.status !== 'submitted',
    );

    const visibleLines = lines.filter((line) => {
        if (overOnly && !overLines.includes(line)) return false;
        if (categoryFilter && line.category !== categoryFilter) return false;
        return true;
    });

    const categoryRows = useMemo<CategoryRow[]>(() => {
        const grouped = new Map<string, LineItem[]>();
        for (const line of lines) {
            grouped.set(line.category, [
                ...(grouped.get(line.category) ?? []),
                line,
            ]);
        }
        return Array.from(grouped.entries()).map(([key, items]) => ({
            key,
            label: categories[key] ?? budgetCategoryLabel(key),
            count: items.length,
            budgeted: items.reduce(
                (sum, line) => sum + amount(line.budget_amount),
                0,
            ),
            actual: items.reduce(
                (sum, line) => sum + amount(line.actual_amount),
                0,
            ),
        }));
    }, [lines, categories]);

    const categoryName = (key: string | null | undefined) =>
        key ? (categories[key] ?? budgetCategoryLabel(key)) : null;

    const statusChip = governanceStatus('budget_status', budget.status);
    const versionLine = budget.supersedes
        ? `Version ${budget.version_number} (replaces version ${budget.supersedes.version_number})`
        : `Version ${budget.version_number}`;

    /* ── actions ─────────────────────────────────────────────────── */

    const post = (url: string) =>
        router.post(url, {}, { preserveScroll: true });

    const primary = canApprove
        ? 'approve'
        : canPropose
          ? 'propose'
          : canRecordActuals
            ? 'actuals'
            : canRequestChange
              ? 'change'
              : null;

    const scrollToApproval = () =>
        document
            .getElementById('board-approval')
            ?.scrollIntoView({ behavior: 'smooth', block: 'start' });

    const lineActions = (line: LineItem): MenuItem[] =>
        compactMenu([
            canEdit
                ? {
                      label: 'Edit line',
                      icon: Pencil,
                      onClick: () => setLineDialog({ mode: 'edit', line }),
                  }
                : null,
            canRecordActuals
                ? {
                      label: 'Record actual spend',
                      icon: Receipt,
                      onClick: () => setActualsFor([line]),
                  }
                : null,
            canRequestChange
                ? {
                      label: 'Request a change to this line',
                      icon: ArrowUpDown,
                      onClick: () => setChangeFor({ lineId: line.id }),
                  }
                : null,
            canEdit ? { separator: true } : null,
            canEdit
                ? {
                      label: 'Remove line',
                      icon: Trash2,
                      danger: true,
                      onClick: () => setRemoveLine(line),
                  }
                : null,
        ]);

    const changeActions = (change: BudgetChange): MenuItem[] => {
        const waiting = change.status === 'submitted';
        const decidable = waiting && canDecideChanges && !isProposed;
        return compactMenu([
            decidable && !change.needs_board
                ? {
                      label: 'Approve change',
                      icon: CheckCircle,
                      onClick: () => setApproveChange(change),
                  }
                : null,
            decidable && change.needs_board && change.ready_resolution
                ? {
                      label: "Record the board's approval",
                      icon: CheckCircle,
                      onClick: () => setApproveChange(change),
                  }
                : null,
            waiting && canDecideChanges
                ? {
                      label: 'Decline change',
                      icon: XCircle,
                      danger: true,
                      onClick: () => setDeclineChange(change),
                  }
                : null,
            change.resolution
                ? {
                      label: 'Open resolution',
                      icon: ExternalLink,
                      onClick: () =>
                          router.visit(
                              `/governance/resolutions/${change.resolution?.id}`,
                          ),
                  }
                : null,
        ]);
    };

    const monthlyActions = (row: MonthlyAmount): MenuItem[] =>
        compactMenu([
            canManageAllocations
                ? {
                      label: 'Remove monthly amount',
                      icon: Trash2,
                      danger: true,
                      onClick: () => setRemoveMonthly(row),
                  }
                : null,
        ]);

    const categoryActions = (row: CategoryRow): MenuItem[] => [
        {
            label: 'Show these lines',
            icon: ListChecks,
            onClick: () => {
                setCategoryFilter(row.key);
                setOverOnly(false);
                selectTab('lines');
            },
        },
    ];

    const tabs: GroupedProfileNavTab[] = [
        { key: 'lines', label: 'Lines', icon: ListChecks, count: lines.length },
        {
            key: 'changes',
            label: 'Budget changes',
            icon: ArrowUpDown,
            count: waitingChanges.length || undefined,
        },
        { key: 'categories', label: 'By category', icon: Layers },
        {
            key: 'monthly',
            label: 'Monthly split',
            icon: CalendarRange,
            count: budget.allocations.length || undefined,
        },
    ];

    const siteName = (row: MonthlyAmount) =>
        row.site_name ??
        allocationOptions?.sites.find((site) => site.id === row.site_id)
            ?.name ??
        (row.site_id ? 'A site' : 'Whole organisation');

    const actualSummary = budget.actuals_recorded
        ? `${formatNzd(totalActual)} spent · ${position.text}`
        : 'Actual spend not recorded yet';

    /* ── header ──────────────────────────────────────────────────── */

    const header = (
        <PageHeader
            variant="profile"
            backHref="/governance/budgets"
            icon={Wallet}
            title={budget.display_name}
            titleDusk="budget-heading"
            titleChip={
                <PageHeaderStatusChip variant={statusChip.variant}>
                    {statusChip.label}
                </PageHeaderStatusChip>
            }
            subline={[
                `Financial year ${budget.financial_year_label}`,
                versionLine,
                budget.created_by ? `Created by ${budget.created_by}` : null,
            ]
                .filter(Boolean)
                .join(' · ')}
            actions={
                <>
                    {canEdit ? (
                        <PageHeaderGlassButton
                            icon={Pencil}
                            onClick={() => setEditOpen(true)}
                        >
                            Edit budget
                        </PageHeaderGlassButton>
                    ) : null}
                    {canReturnToDrafting ? (
                        <PageHeaderGlassButton
                            icon={Undo2}
                            onClick={() => setConfirm('return')}
                        >
                            Return to drafting
                        </PageHeaderGlassButton>
                    ) : null}
                    {canRecordActuals && primary !== 'actuals' ? (
                        <PageHeaderGlassButton
                            icon={Receipt}
                            onClick={() => setActualsFor(lines)}
                        >
                            Record actual spend
                        </PageHeaderGlassButton>
                    ) : null}
                    {canRequestChange && primary !== 'change' ? (
                        <PageHeaderGlassButton
                            icon={ArrowUpDown}
                            onClick={() => setChangeFor({})}
                        >
                            Request a budget change
                        </PageHeaderGlassButton>
                    ) : null}
                    {primary === 'approve' ? (
                        <PageHeaderPrimaryButton
                            icon={CheckCircle}
                            onClick={() => setConfirm('approve')}
                        >
                            Record the board&apos;s approval
                        </PageHeaderPrimaryButton>
                    ) : primary === 'propose' ? (
                        <PageHeaderPrimaryButton
                            icon={Send}
                            onClick={() => setConfirm('propose')}
                            disabled={lines.length === 0}
                            title={
                                lines.length === 0
                                    ? 'Add at least one line first'
                                    : undefined
                            }
                        >
                            {isProposed
                                ? 'Send the updated budget to the board'
                                : 'Send to the board'}
                        </PageHeaderPrimaryButton>
                    ) : primary === 'actuals' ? (
                        <PageHeaderPrimaryButton
                            icon={Receipt}
                            onClick={() => setActualsFor(lines)}
                            disabled={lines.length === 0}
                        >
                            Record actual spend
                        </PageHeaderPrimaryButton>
                    ) : primary === 'change' ? (
                        <PageHeaderPrimaryButton
                            icon={ArrowUpDown}
                            onClick={() => setChangeFor({})}
                        >
                            Request a budget change
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Budget"
                        value={
                            budget.actuals_recorded && total > 0
                                ? `${Math.round((totalActual / total) * 100)}% spent`
                                : undefined
                        }
                        tone={
                            budget.actuals_recorded && position.over
                                ? 'warning'
                                : 'brand'
                        }
                        onClick={() => {
                            setOverOnly(false);
                            setCategoryFilter(null);
                            selectTab('lines');
                        }}
                        ariaLabel="View budget lines"
                    >
                        <PageHeaderMeterBig>{formatNzd(total)}</PageHeaderMeterBig>
                        {budget.actuals_recorded && total > 0 ? (
                            <PageHeaderMeterBar
                                percent={(totalActual / total) * 100}
                            />
                        ) : null}
                        <PageHeaderMeterCaption>
                            {!budget.actuals_recorded
                                ? 'Actual spend not recorded yet'
                                : position.over
                                  ? position.text
                                  : `${formatNzd(total - totalActual)} left of ${formatNzd(total)}`}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Over-budget lines"
                        tone={overLines.length > 0 ? 'critical' : 'brand'}
                        onClick={() => {
                            setOverOnly(budget.actuals_recorded);
                            setCategoryFilter(null);
                            selectTab('lines');
                        }}
                        ariaLabel="View lines that are over budget"
                    >
                        <PageHeaderMeterBig>
                            {budget.actuals_recorded ? overLines.length : '—'}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {budget.actuals_recorded
                                ? 'Lines spending more than budgeted'
                                : 'Actual spend not recorded yet'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Budget changes"
                        tone={waitingChanges.length > 0 ? 'warning' : 'brand'}
                        onClick={() => selectTab('changes')}
                        ariaLabel="View budget changes"
                    >
                        <PageHeaderMeterBig>
                            {waitingChanges.length}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Waiting for a decision
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Board decision"
                        tone={
                            approval.tone === 'success'
                                ? 'success'
                                : approval.tone === 'critical'
                                  ? 'critical'
                                  : approval.tone === 'warning'
                                    ? 'warning'
                                    : 'brand'
                        }
                        href={
                            approval.resolution
                                ? `/governance/resolutions/${approval.resolution.id}`
                                : undefined
                        }
                        onClick={
                            approval.resolution ? undefined : scrollToApproval
                        }
                        ariaLabel={
                            approval.resolution
                                ? `Open resolution: ${approval.resolution.title}`
                                : 'View where the board approval stands'
                        }
                    >
                        <PageHeaderMeterBig>
                            {APPROVAL_METER[approval.key] ?? approval.label}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {approval.resolution
                                ? approval.resolution.title
                                : isApproved && budget.external_approval_reference
                                  ? 'Approved outside this system'
                                  : 'No resolution linked'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
        />
    );

    /* ── body ────────────────────────────────────────────────────── */

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Budgets', href: '/governance/budgets' },
                {
                    title: budget.display_name,
                    href: `/governance/budgets/${budget.id}`,
                },
            ]}
        >
            <Head title={`${budget.display_name} — Budgets`} />

            <PageLayout
                hero={header}
                tabs={
                    <TierTwoTabs
                        tabs={tabs}
                        activeTab={tab}
                        onTab={(key) => selectTab(key as TabKey)}
                        testIdPrefix="budget"
                        ariaLabel="Budget sections"
                        panelId="budget-panel"
                        renderLink={() => null}
                    />
                }
            >
                <div id="budget-panel" className="flex flex-col gap-5">
                    <Card id="board-approval" className="scroll-mt-5">
                        <CardHeader>
                            <CardTitle className="text-section-title">
                                Board approval
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-3">
                            <div className="flex flex-wrap items-center gap-2">
                                <StatusBadge
                                    variant={TONE_VARIANT[approval.tone]}
                                >
                                    {approval.label}
                                </StatusBadge>
                                {approval.resolution ? (
                                    <Link
                                        href={`/governance/resolutions/${approval.resolution.id}`}
                                        className="text-sm font-medium text-primary hover:underline"
                                    >
                                        {approval.resolution.title}
                                    </Link>
                                ) : null}
                                {approval.resolution?.reference ? (
                                    <span className="text-caption">
                                        {refSuffix(
                                            approval.resolution.reference,
                                        )}
                                    </span>
                                ) : null}
                            </div>
                            <p className="text-subtle">{approval.detail}</p>
                            {budget.description ? (
                                <p className="text-sm whitespace-pre-wrap">
                                    {budget.description}
                                </p>
                            ) : null}
                            {errors.budget || errors.resolution_id ? (
                                <InfoCard icon={AlertTriangle} tone="crit">
                                    {errors.budget ?? errors.resolution_id}
                                </InfoCard>
                            ) : null}
                        </CardContent>
                    </Card>

                    {tab === 'lines' ? (
                        <section className="flex flex-col gap-5">
                            <ListCaption
                                title={
                                    overOnly
                                        ? 'Lines over budget'
                                        : categoryFilter
                                          ? `${categoryName(categoryFilter)} lines`
                                          : 'Budget lines'
                                }
                                caption={`${visibleLines.length} of ${lines.length} · totalling ${formatNzd(linesTotal)} · ${actualSummary}`}
                                right={
                                    <>
                                        {overOnly || categoryFilter ? (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() => {
                                                    setOverOnly(false);
                                                    setCategoryFilter(null);
                                                }}
                                            >
                                                Show all lines
                                            </Button>
                                        ) : null}
                                        {canEdit ? (
                                            <Button
                                                size="sm"
                                                onClick={() =>
                                                    setLineDialog({
                                                        mode: 'add',
                                                    })
                                                }
                                            >
                                                <Plus className="h-4 w-4" />
                                                Add line
                                            </Button>
                                        ) : null}
                                    </>
                                }
                            />
                            {isProposed && canEdit ? (
                                <InfoCard icon={AlertTriangle} tone="warn">
                                    This budget is waiting for the board.
                                    Adding, changing or removing a line means
                                    the board must see the updated budget
                                    before it can be approved.
                                </InfoCard>
                            ) : null}
                            {isApproved && !budget.actuals_recorded ? (
                                <InfoCard icon={Receipt}>
                                    Actual spend not recorded yet
                                    {canRecordActuals
                                        ? ' — use Record actual spend to enter what has been spent on each line.'
                                        : '.'}
                                </InfoCard>
                            ) : null}
                            {budget.actuals_recorded_at ? (
                                <p className="text-caption">
                                    Actuals last recorded{' '}
                                    {formatDateLong(budget.actuals_recorded_at)}
                                </p>
                            ) : null}
                            {visibleLines.length === 0 ? (
                                <EmptyState
                                    icon={Wallet}
                                    title={
                                        lines.length === 0
                                            ? 'No budget lines yet'
                                            : 'No lines to show'
                                    }
                                    description={
                                        lines.length === 0
                                            ? 'Lines say what the money is for, such as support worker wages or vehicle leases.'
                                            : 'No line matches this view.'
                                    }
                                    action={
                                        lines.length === 0 && canEdit ? (
                                            <Button
                                                size="sm"
                                                onClick={() =>
                                                    setLineDialog({
                                                        mode: 'add',
                                                    })
                                                }
                                            >
                                                <Plus className="h-4 w-4" />
                                                Add line
                                            </Button>
                                        ) : undefined
                                    }
                                />
                            ) : (
                                <EntityTable
                                    rows={visibleLines}
                                    rowKey={(line) => line.id}
                                    identityLabel="Line"
                                    identity={(line) => ({
                                        icon: Wallet,
                                        name: line.description,
                                        subline: [
                                            categoryName(line.category),
                                            line.account_code
                                                ? `Account ${line.account_code}`
                                                : null,
                                        ]
                                            .filter(Boolean)
                                            .join(' · '),
                                    })}
                                    onRowContextMenu={lineCtx.open}
                                    actionsFor={lineActions}
                                    columns={[
                                        {
                                            key: 'budgeted',
                                            label: 'Budgeted',
                                            width: '0.9fr',
                                            align: 'right',
                                            cell: (line) => (
                                                <span className="font-semibold tabular-nums">
                                                    {formatNzd(
                                                        line.budget_amount,
                                                    )}
                                                </span>
                                            ),
                                        },
                                        {
                                            key: 'forecast',
                                            label: 'Forecast',
                                            width: '0.9fr',
                                            align: 'right',
                                            cell: (line) =>
                                                line.forecast_amount == null ? (
                                                    <EmptyValue />
                                                ) : (
                                                    <span className="tabular-nums">
                                                        {formatNzd(
                                                            line.forecast_amount,
                                                        )}
                                                    </span>
                                                ),
                                        },
                                        {
                                            key: 'actual',
                                            label: 'Spent',
                                            width: '0.9fr',
                                            align: 'right',
                                            cell: (line) =>
                                                budget.actuals_recorded ? (
                                                    <span className="tabular-nums">
                                                        {formatNzd(
                                                            line.actual_amount,
                                                        )}
                                                    </span>
                                                ) : (
                                                    <span className="text-caption">
                                                        Not recorded yet
                                                    </span>
                                                ),
                                        },
                                        {
                                            key: 'position',
                                            label: 'Position',
                                            width: '1.3fr',
                                            cell: (line) => {
                                                if (!budget.actuals_recorded) {
                                                    return <EmptyValue />;
                                                }
                                                const linePosition =
                                                    spendPosition(
                                                        amount(
                                                            line.budget_amount,
                                                        ),
                                                        amount(
                                                            line.actual_amount,
                                                        ),
                                                    );
                                                return (
                                                    <EntityStatusChip
                                                        variant={
                                                            linePosition.over
                                                                ? 'critical'
                                                                : 'neutral'
                                                        }
                                                    >
                                                        {linePosition.text}
                                                    </EntityStatusChip>
                                                );
                                            },
                                        },
                                    ]}
                                />
                            )}
                        </section>
                    ) : null}

                    {tab === 'changes' ? (
                        <section className="flex flex-col gap-5">
                            <GovernanceExplainer
                                title="How budget changes work"
                                body={
                                    <>
                                        A budget change moves an approved
                                        amount up or down on one line.{' '}
                                        {changeThreshold.sentence} After a
                                        change that needs a board decision is
                                        sent, the secretary adds it to a
                                        resolution. When the board passes it,
                                        an approver records the board&apos;s
                                        approval here.
                                    </>
                                }
                            />
                            {!isApproved ? (
                                <InfoCard icon={Pencil}>
                                    Budget changes are for approved budgets.
                                    While this budget is a draft or waiting for
                                    the board, edit its lines instead.
                                </InfoCard>
                            ) : null}
                            {isProposed && waitingChanges.length > 0 ? (
                                <InfoCard icon={AlertTriangle} tone="warn">
                                    This budget is waiting for the
                                    board&apos;s vote, so its figures
                                    can&apos;t change now. Decide these changes
                                    after the vote.
                                </InfoCard>
                            ) : null}
                            {errors.adjustment ||
                            errors.approval_resolution_id ? (
                                <InfoCard icon={AlertTriangle} tone="crit">
                                    {errors.adjustment ??
                                        errors.approval_resolution_id}
                                </InfoCard>
                            ) : null}

                            <ListCaption
                                title="Waiting for a decision"
                                caption={`${waitingChanges.length} change${waitingChanges.length === 1 ? '' : 's'}`}
                                right={
                                    canRequestChange ? (
                                        <Button
                                            size="sm"
                                            onClick={() => setChangeFor({})}
                                        >
                                            <ArrowUpDown className="h-4 w-4" />
                                            Request a budget change
                                        </Button>
                                    ) : null
                                }
                            />
                            {waitingChanges.length === 0 ? (
                                <EmptyState
                                    variant="compact"
                                    icon={ArrowUpDown}
                                    title="No budget changes waiting"
                                    description="Requests to move an approved amount up or down appear here."
                                />
                            ) : (
                                <EntityTable
                                    rows={waitingChanges}
                                    rowKey={(change) => change.id}
                                    identityLabel="Change"
                                    identity={(change) => ({
                                        icon:
                                            change.direction === 'decrease'
                                                ? ArrowDown
                                                : ArrowUp,
                                        name: changeTitle(change),
                                        subline: [
                                            change.requested_by
                                                ? `Requested by ${change.requested_by}`
                                                : null,
                                            change.requested_at
                                                ? formatDateLong(
                                                      change.requested_at,
                                                  )
                                                : null,
                                            change.reason,
                                        ]
                                            .filter(Boolean)
                                            .join(' · '),
                                    })}
                                    onRowContextMenu={changeCtx.open}
                                    actionsFor={changeActions}
                                    columns={[
                                        {
                                            key: 'decider',
                                            label: 'Who decides',
                                            width: '1fr',
                                            cell: (change) => (
                                                <EntityChip>
                                                    {change.needs_board
                                                        ? 'The board'
                                                        : 'An approver'}
                                                </EntityChip>
                                            ),
                                        },
                                        {
                                            key: 'resolution',
                                            label: 'Board decision',
                                            width: '1.4fr',
                                            cell: (change) =>
                                                !change.needs_board ? (
                                                    <EmptyValue />
                                                ) : change.ready_resolution ? (
                                                    <EntityStatusChip variant="success">
                                                        Passed — ready to record
                                                    </EntityStatusChip>
                                                ) : change.resolution ? (
                                                    <span className="truncate">
                                                        {
                                                            change.resolution
                                                                .title
                                                        }
                                                    </span>
                                                ) : (
                                                    <span className="text-caption">
                                                        Waiting for a resolution
                                                    </span>
                                                ),
                                        },
                                        {
                                            key: 'after',
                                            label: 'Line becomes',
                                            width: '0.9fr',
                                            align: 'right',
                                            cell: (change) => {
                                                const after =
                                                    lineAfterChange(change);
                                                return after === null ? (
                                                    <EmptyValue />
                                                ) : (
                                                    <span className="tabular-nums">
                                                        {formatNzd(after)}
                                                    </span>
                                                );
                                            },
                                        },
                                    ]}
                                />
                            )}
                            {!canDecideChanges && waitingChanges.length > 0 ? (
                                <p className="text-caption">
                                    An approver decides budget changes. You can
                                    see them here, but you can&apos;t approve or
                                    decline them.
                                </p>
                            ) : null}

                            {decidedChanges.length > 0 ? (
                                <>
                                    <ListCaption
                                        title="Decided changes"
                                        caption={`${decidedChanges.length} change${decidedChanges.length === 1 ? '' : 's'}`}
                                    />
                                    <EntityTable
                                        rows={decidedChanges}
                                        rowKey={(change) => change.id}
                                        identityLabel="Change"
                                        identity={(change) => ({
                                            icon:
                                                change.direction === 'decrease'
                                                    ? ArrowDown
                                                    : ArrowUp,
                                            name: changeTitle(change),
                                            subline: change.review_notes
                                                ? `Reason: ${change.review_notes}`
                                                : change.reason,
                                        })}
                                        onRowContextMenu={changeCtx.open}
                                        actionsFor={changeActions}
                                        mutedFor={(change) =>
                                            change.status !== 'approved'
                                        }
                                        columns={[
                                            {
                                                key: 'status',
                                                label: 'Status',
                                                width: '1fr',
                                                cell: (change) => {
                                                    const chip =
                                                        governanceStatus(
                                                            'budget_change_status',
                                                            change.status,
                                                        );
                                                    return (
                                                        <EntityStatusChip
                                                            variant={
                                                                chip.variant
                                                            }
                                                        >
                                                            {chip.label}
                                                        </EntityStatusChip>
                                                    );
                                                },
                                            },
                                            {
                                                key: 'decided',
                                                label: 'Decided',
                                                width: '1.4fr',
                                                cell: (change) => (
                                                    <span className="truncate">
                                                        {change.decided_at
                                                            ? formatDateLong(
                                                                  change.decided_at,
                                                              )
                                                            : 'Date not recorded'}
                                                        {' · '}
                                                        {change.decided_by ??
                                                            'a former user'}
                                                    </span>
                                                ),
                                            },
                                            {
                                                key: 'resolution',
                                                label: 'Resolution',
                                                width: '1.2fr',
                                                cell: (change) =>
                                                    change.resolution ? (
                                                        <span className="truncate">
                                                            {
                                                                change
                                                                    .resolution
                                                                    .title
                                                            }
                                                        </span>
                                                    ) : (
                                                        <EmptyValue />
                                                    ),
                                            },
                                        ]}
                                    />
                                </>
                            ) : null}
                        </section>
                    ) : null}

                    {tab === 'categories' ? (
                        <section className="flex flex-col gap-5">
                            <ListCaption
                                title="By category"
                                caption={`${categoryRows.length} categor${categoryRows.length === 1 ? 'y' : 'ies'} · ${actualSummary}`}
                            />
                            {categoryRows.length === 0 ? (
                                <EmptyState
                                    icon={Layers}
                                    title="Nothing to summarise yet"
                                    description="Categories appear once the budget has lines."
                                />
                            ) : (
                                <EntityTable
                                    rows={categoryRows}
                                    rowKey={(row) => row.key}
                                    identityLabel="Category"
                                    identity={(row) => ({
                                        icon: Layers,
                                        name: row.label,
                                        subline: `${row.count} line${row.count === 1 ? '' : 's'}`,
                                    })}
                                    onOpen={(row) => categoryActions(row)[0]?.onClick?.()}
                                    onRowContextMenu={categoryCtx.open}
                                    actionsFor={categoryActions}
                                    columns={[
                                        {
                                            key: 'budgeted',
                                            label: 'Budgeted',
                                            width: '0.9fr',
                                            align: 'right',
                                            cell: (row) => (
                                                <span className="font-semibold tabular-nums">
                                                    {formatNzd(row.budgeted)}
                                                </span>
                                            ),
                                        },
                                        {
                                            key: 'share',
                                            label: 'Share of budget',
                                            width: '1.1fr',
                                            cell: (row) => (
                                                <ProgressValue
                                                    percent={
                                                        linesTotal > 0
                                                            ? (row.budgeted /
                                                                  linesTotal) *
                                                              100
                                                            : null
                                                    }
                                                >
                                                    {linesTotal > 0
                                                        ? `${Math.round((row.budgeted / linesTotal) * 100)}%`
                                                        : '—'}
                                                </ProgressValue>
                                            ),
                                        },
                                        {
                                            key: 'actual',
                                            label: 'Spent',
                                            width: '0.9fr',
                                            align: 'right',
                                            cell: (row) =>
                                                budget.actuals_recorded ? (
                                                    <span className="tabular-nums">
                                                        {formatNzd(row.actual)}
                                                    </span>
                                                ) : (
                                                    <span className="text-caption">
                                                        Not recorded yet
                                                    </span>
                                                ),
                                        },
                                        {
                                            key: 'position',
                                            label: 'Position',
                                            width: '1.3fr',
                                            cell: (row) => {
                                                if (!budget.actuals_recorded) {
                                                    return <EmptyValue />;
                                                }
                                                const rowPosition =
                                                    spendPosition(
                                                        row.budgeted,
                                                        row.actual,
                                                    );
                                                return (
                                                    <EntityStatusChip
                                                        variant={
                                                            rowPosition.over
                                                                ? 'critical'
                                                                : 'neutral'
                                                        }
                                                    >
                                                        {rowPosition.text}
                                                    </EntityStatusChip>
                                                );
                                            },
                                        },
                                    ]}
                                />
                            )}
                        </section>
                    ) : null}

                    {tab === 'monthly' ? (
                        <section className="flex flex-col gap-5">
                            <GovernanceExplainer
                                title="Monthly split"
                                body="Split this year's budget into monthly amounts for each site, so spending can be followed month by month."
                            />
                            <ListCaption
                                title="Monthly amounts"
                                caption={`${budget.allocations.length} amount${budget.allocations.length === 1 ? '' : 's'} totalling ${formatNzd(
                                    budget.allocations.reduce(
                                        (sum, row) =>
                                            sum + amount(row.allocated_amount),
                                        0,
                                    ),
                                )}`}
                                right={
                                    canManageAllocations &&
                                    allocationOptions ? (
                                        <Button
                                            size="sm"
                                            onClick={() => setMonthlyOpen(true)}
                                        >
                                            <Plus className="h-4 w-4" />
                                            Add monthly amount
                                        </Button>
                                    ) : null
                                }
                            />
                            {budget.allocations.length === 0 ? (
                                <EmptyState
                                    icon={CalendarRange}
                                    title="No monthly amounts yet"
                                    description="Add an amount for a month and site to follow spending through the year."
                                />
                            ) : (
                                <EntityTable
                                    rows={budget.allocations}
                                    rowKey={(row) => row.id}
                                    identityLabel="Month"
                                    identity={(row) => ({
                                        icon: CalendarRange,
                                        name: formatMonthYear(
                                            `${row.period_year_month}-15T00:00:00Z`,
                                        ),
                                        subline: siteName(row),
                                    })}
                                    onRowContextMenu={monthlyCtx.open}
                                    actionsFor={monthlyActions}
                                    columns={[
                                        {
                                            key: 'line',
                                            label: 'Budget line',
                                            width: '1.2fr',
                                            cell: (row) =>
                                                row.line_description ? (
                                                    <span className="truncate">
                                                        {row.line_description}
                                                    </span>
                                                ) : (
                                                    <EmptyValue />
                                                ),
                                        },
                                        {
                                            key: 'category',
                                            label: 'Category',
                                            width: '0.9fr',
                                            cell: (row) =>
                                                row.category ? (
                                                    <EntityChip>
                                                        {categoryName(
                                                            row.category,
                                                        )}
                                                    </EntityChip>
                                                ) : (
                                                    <EmptyValue />
                                                ),
                                        },
                                        {
                                            key: 'amount',
                                            label: 'Amount',
                                            width: '0.8fr',
                                            align: 'right',
                                            cell: (row) => (
                                                <span className="font-semibold tabular-nums">
                                                    {formatNzd(
                                                        row.allocated_amount,
                                                    )}
                                                </span>
                                            ),
                                        },
                                        {
                                            key: 'actual',
                                            label: 'Spent',
                                            width: '1.3fr',
                                            cell: (row) =>
                                                row.actual_amount == null ? (
                                                    <span className="text-caption">
                                                        Not recorded yet
                                                    </span>
                                                ) : (
                                                    <span className="truncate">
                                                        {formatNzd(
                                                            row.actual_amount,
                                                        )}{' '}
                                                        ·{' '}
                                                        {
                                                            spendPosition(
                                                                amount(
                                                                    row.allocated_amount,
                                                                ),
                                                                amount(
                                                                    row.actual_amount,
                                                                ),
                                                            ).text
                                                        }
                                                    </span>
                                                ),
                                        },
                                    ]}
                                />
                            )}
                        </section>
                    ) : null}
                </div>
            </PageLayout>

            {/* ── context menus ─────────────────────────────────────── */}
            {lineCtx.ctx ? (
                <EntityContextMenu
                    x={lineCtx.ctx.x}
                    y={lineCtx.ctx.y}
                    icon={Wallet}
                    title={lineCtx.ctx.record.description}
                    items={lineActions(lineCtx.ctx.record)}
                    onClose={lineCtx.close}
                />
            ) : null}
            {changeCtx.ctx ? (
                <EntityContextMenu
                    x={changeCtx.ctx.x}
                    y={changeCtx.ctx.y}
                    icon={ArrowUpDown}
                    title={changeTitle(changeCtx.ctx.record)}
                    items={changeActions(changeCtx.ctx.record)}
                    onClose={changeCtx.close}
                />
            ) : null}
            {categoryCtx.ctx ? (
                <EntityContextMenu
                    x={categoryCtx.ctx.x}
                    y={categoryCtx.ctx.y}
                    icon={Layers}
                    title={categoryCtx.ctx.record.label}
                    items={categoryActions(categoryCtx.ctx.record)}
                    onClose={categoryCtx.close}
                />
            ) : null}
            {monthlyCtx.ctx ? (
                <EntityContextMenu
                    x={monthlyCtx.ctx.x}
                    y={monthlyCtx.ctx.y}
                    icon={CalendarRange}
                    title={formatMonthYear(
                        `${monthlyCtx.ctx.record.period_year_month}-15T00:00:00Z`,
                    )}
                    items={monthlyActions(monthlyCtx.ctx.record)}
                    onClose={monthlyCtx.close}
                />
            ) : null}

            {/* ── confirmations ─────────────────────────────────────── */}
            <ConfirmDialog
                open={confirm === 'propose'}
                onClose={() => setConfirm(null)}
                onConfirm={() => post(`/governance/budgets/${budget.id}/propose`)}
                title={
                    isProposed
                        ? 'Send the updated budget to the board?'
                        : 'Send this budget to the board?'
                }
                description={
                    isProposed
                        ? `This updates the budget's resolution to match the current figures: ${formatNzd(total)} across ${lines.length} line${lines.length === 1 ? '' : 's'}. The board needs to vote on this version.`
                        : `This prepares a resolution asking the board to approve ${formatNzd(total)} across ${lines.length} line${lines.length === 1 ? '' : 's'}. The secretary adds it to a meeting agenda. Changing the budget after this means the board must see the updated budget.`
                }
                confirmText="Send to the board"
                variant="default"
            />
            <ConfirmDialog
                open={confirm === 'approve'}
                onClose={() => setConfirm(null)}
                onConfirm={() => post(`/governance/budgets/${budget.id}/approve`)}
                title="Record the board's approval?"
                description={`The board passed ${approval.resolution ? `the resolution "${approval.resolution.title}"` : "this budget's resolution"}. Recording it marks the budget of ${formatNzd(total)} as approved. After that its lines can only change through budget changes.`}
                confirmText="Record approval"
                variant="default"
            />
            <ConfirmDialog
                open={confirm === 'return'}
                onClose={() => setConfirm(null)}
                onConfirm={() =>
                    post(`/governance/budgets/${budget.id}/return-to-drafting`)
                }
                title="Return this budget to drafting?"
                description="The budget stops waiting for the board so it can be reworked. Send it to the board again when it's ready."
                confirmText="Return to drafting"
                variant="default"
            />
            <ConfirmDialog
                open={removeLine !== null}
                onClose={() => setRemoveLine(null)}
                onConfirm={() => {
                    if (!removeLine) return;
                    router.delete(
                        `/governance/budgets/${budget.id}/line-items/${removeLine.id}`,
                        { preserveScroll: true },
                    );
                }}
                title="Remove this budget line?"
                description={
                    removeLine
                        ? `"${removeLine.description}" (${formatNzd(removeLine.budget_amount)}) will be removed and the budget total becomes ${formatNzd(total - amount(removeLine.budget_amount))}.${isProposed ? ' The board will need to see the updated budget.' : ''} This can't be undone.`
                        : ''
                }
                confirmText="Remove line"
            />
            <ConfirmDialog
                open={approveChange !== null}
                onClose={() => setApproveChange(null)}
                onConfirm={() => {
                    if (!approveChange) return;
                    router.post(
                        `/governance/budgets/${budget.id}/adjustments/${approveChange.id}/approve`,
                        approveChange.ready_resolution
                            ? {
                                  approval_resolution_id:
                                      approveChange.ready_resolution.id,
                              }
                            : {},
                        { preserveScroll: true },
                    );
                }}
                title={
                    approveChange?.needs_board
                        ? "Record the board's approval?"
                        : 'Approve this budget change?'
                }
                description={
                    approveChange
                        ? approvalSentence(approveChange, total)
                        : ''
                }
                confirmText={
                    approveChange?.needs_board
                        ? 'Record approval'
                        : 'Approve change'
                }
                variant="default"
            />
            <ConfirmDialog
                open={removeMonthly !== null}
                onClose={() => setRemoveMonthly(null)}
                onConfirm={() => {
                    if (!removeMonthly) return;
                    router.delete(
                        `/governance/budgets/${budget.id}/allocations/${removeMonthly.id}`,
                        { preserveScroll: true },
                    );
                }}
                title="Remove this monthly amount?"
                description={
                    removeMonthly
                        ? `${formatNzd(removeMonthly.allocated_amount)} for ${formatMonthYear(`${removeMonthly.period_year_month}-15T00:00:00Z`)} (${siteName(removeMonthly)}) will be removed. This can't be undone.`
                        : ''
                }
                confirmText="Remove amount"
            />

            {/* ── dialogs ───────────────────────────────────────────── */}
            {lineDialog ? (
                <LineDialog
                    budgetId={budget.id}
                    categories={categories}
                    line={lineDialog.mode === 'edit' ? lineDialog.line : null}
                    proposed={isProposed}
                    onClose={() => setLineDialog(null)}
                />
            ) : null}
            {actualsFor ? (
                <ActualsDialog
                    budgetId={budget.id}
                    lines={actualsFor}
                    onClose={() => setActualsFor(null)}
                />
            ) : null}
            {changeFor ? (
                <ChangeRequestDialog
                    budgetId={budget.id}
                    lines={lines}
                    initialLineId={changeFor.lineId}
                    threshold={changeThreshold}
                    onClose={() => setChangeFor(null)}
                />
            ) : null}
            {declineChange ? (
                <DeclineChangeDialog
                    budgetId={budget.id}
                    change={declineChange}
                    onClose={() => setDeclineChange(null)}
                />
            ) : null}
            {monthlyOpen && allocationOptions ? (
                <MonthlyAmountDialog
                    budgetId={budget.id}
                    lines={lines}
                    categories={categories}
                    options={allocationOptions}
                    onClose={() => setMonthlyOpen(false)}
                />
            ) : null}

            {canEdit ? (
                <BudgetWizardDialog
                    isOpen={editOpen}
                    onClose={() => setEditOpen(false)}
                    options={{ categories }}
                    budget={{
                        id: budget.id,
                        fiscal_year: budget.fiscal_year,
                        title: budget.title,
                        description: budget.description,
                        total_budget: budget.total_budget,
                        status: budget.status,
                        version_number: budget.version_number,
                        line_items: lines,
                    }}
                />
            ) : null}
        </AppLayout>
    );
}

interface CategoryRow {
    key: string;
    label: string;
    count: number;
    budgeted: number;
    actual: number;
}

/** What approving a change does, in one plain sentence. */
export function approvalSentence(change: BudgetChange, budgetTotal: number) {
    const line = change.line?.description ?? 'The budget line';
    const verb = change.direction === 'decrease' ? 'decreasing' : 'increasing';
    const after = lineAfterChange(change);
    const delta =
        change.direction === 'decrease'
            ? -amount(change.amount)
            : amount(change.amount);
    const totalAfter = formatNzd(budgetTotal + delta);
    const becomes =
        after === null
            ? ''
            : ` The line becomes ${formatNzd(after)} and the budget total becomes ${totalAfter}.`;

    if (change.needs_board && change.ready_resolution) {
        return `Resolution "${change.ready_resolution.title}"${change.ready_resolution.reference ? ` (${refSuffix(change.ready_resolution.reference)})` : ''} approved ${verb} ${line} by ${formatNzd(change.amount)}.${becomes}`;
    }

    return `${line} goes ${change.direction === 'decrease' ? 'down' : 'up'} by ${formatNzd(change.amount)}.${becomes} This can't be undone.`;
}

/* ------------------------------------------------------------------ */
/*  Dialogs                                                            */
/* ------------------------------------------------------------------ */

function DialogErrors({ errors }: { errors: Record<string, string> }) {
    const general = errors.budget ?? errors.adjustment ?? errors.actuals;
    return general ? (
        <InfoCard icon={AlertTriangle} tone="crit">
            {general}
        </InfoCard>
    ) : null;
}

function LineDialog({
    budgetId,
    categories,
    line,
    proposed,
    onClose,
}: {
    budgetId: number;
    categories: Record<string, string>;
    line: LineItem | null;
    proposed: boolean;
    onClose: () => void;
}) {
    const form = useForm({
        category: line?.category ?? 'operations',
        description: line?.description ?? '',
        account_code: line?.account_code ?? '',
        budget_amount: line ? String(amount(line.budget_amount)) : '',
        forecast_amount:
            line?.forecast_amount != null
                ? String(amount(line.forecast_amount))
                : '',
        notes: line?.notes ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: (page: unknown) => {
                if (!pageHasFlashError(page)) onClose();
            },
        };
        form.transform((data) => ({
            ...data,
            account_code: data.account_code.trim() || null,
            forecast_amount: data.forecast_amount.trim() || null,
            notes: data.notes.trim() || null,
        }));
        if (line) {
            form.put(
                `/governance/budgets/${budgetId}/line-items/${line.id}`,
                options,
            );
        } else {
            form.post(`/governance/budgets/${budgetId}/line-items`, options);
        }
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent style={{ maxWidth: 'min(92vw, 560px)' }}>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>
                            {line ? 'Edit budget line' : 'Add a budget line'}
                        </DialogTitle>
                        <DialogDescription>
                            {line
                                ? 'Change what this line is for or how much is budgeted.'
                                : 'Add something this budget pays for, with the amount budgeted.'}
                        </DialogDescription>
                    </DialogHeader>
                    {proposed ? (
                        <InfoCard icon={AlertTriangle} tone="warn">
                            This budget is waiting for the board. Changing its
                            lines means the board must see the updated budget
                            before it can be approved.
                        </InfoCard>
                    ) : null}
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Category"
                            required
                            error={form.errors.category}
                        >
                            <SelectInput
                                value={form.data.category}
                                onChange={(value) =>
                                    form.setData('category', value)
                                }
                                placeholder="Choose a category"
                                ariaLabel="Category"
                                options={Object.entries(categories).map(
                                    ([value, label]) => ({ value, label }),
                                )}
                            />
                        </Field>
                        <Field
                            label="Account code"
                            hint="if known"
                            error={form.errors.account_code}
                        >
                            <Input
                                id="line-account-code"
                                value={form.data.account_code}
                                onChange={(e) =>
                                    form.setData('account_code', e.target.value)
                                }
                                placeholder="From your accounting system"
                            />
                        </Field>
                        <Field
                            label="Description"
                            required
                            span
                            error={form.errors.description}
                        >
                            <Input
                                id="line-description"
                                value={form.data.description}
                                onChange={(e) =>
                                    form.setData('description', e.target.value)
                                }
                                placeholder="e.g. Support worker wages"
                            />
                        </Field>
                        <Field
                            label="Amount budgeted (NZD)"
                            required
                            error={form.errors.budget_amount}
                        >
                            <Input
                                id="line-budget-amount"
                                type="number"
                                step="0.01"
                                min="0"
                                inputMode="decimal"
                                value={form.data.budget_amount}
                                onChange={(e) =>
                                    form.setData('budget_amount', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Forecast (NZD)"
                            hint="what you now expect to spend"
                            error={form.errors.forecast_amount}
                        >
                            <Input
                                id="line-forecast-amount"
                                type="number"
                                step="0.01"
                                min="0"
                                inputMode="decimal"
                                value={form.data.forecast_amount}
                                onChange={(e) =>
                                    form.setData(
                                        'forecast_amount',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="Notes"
                            hint="optional"
                            span
                            error={form.errors.notes}
                        >
                            <Textarea
                                id="line-notes"
                                rows={2}
                                value={form.data.notes}
                                onChange={(e) =>
                                    form.setData('notes', e.target.value)
                                }
                            />
                        </Field>
                    </div>
                    <DialogErrors errors={form.errors as Record<string, string>} />
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {line ? 'Save line' : 'Add line'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function ActualsDialog({
    budgetId,
    lines,
    onClose,
}: {
    budgetId: number;
    lines: LineItem[];
    onClose: () => void;
}) {
    const form = useForm({
        actuals: lines.map((line) => ({
            id: line.id,
            actual_amount: String(amount(line.actual_amount)),
        })),
    });
    const errors = form.errors as Record<string, string>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/governance/budgets/${budgetId}/record-actuals`, {
            preserveScroll: true,
            onSuccess: (page: unknown) => {
                if (!pageHasFlashError(page)) onClose();
            },
        });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent
                className="max-h-[88vh] overflow-y-auto"
                style={{ maxWidth: 'min(92vw, 560px)' }}
            >
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>Record actual spend</DialogTitle>
                        <DialogDescription>
                            Enter the total spent so far on{' '}
                            {lines.length === 1 ? 'this line' : 'each line'}{' '}
                            (0 if nothing yet). The page then shows whether
                            the budget is over or under.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-3">
                        {form.data.actuals.map((row, index) => {
                            const line = lines.find(
                                (candidate) => candidate.id === row.id,
                            );
                            return (
                                <Field
                                    key={row.id}
                                    label={`${line?.description ?? 'Budget line'} — spent so far (NZD)`}
                                    hint={
                                        line
                                            ? `budgeted ${formatNzd(line.budget_amount)}`
                                            : undefined
                                    }
                                    required
                                    error={
                                        errors[
                                            `actuals.${index}.actual_amount`
                                        ] ?? errors[`actuals.${index}.id`]
                                    }
                                >
                                    <Input
                                        id={`actual-${row.id}`}
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        inputMode="decimal"
                                        value={row.actual_amount}
                                        onChange={(e) =>
                                            form.setData(
                                                'actuals',
                                                form.data.actuals.map(
                                                    (current, i) =>
                                                        i === index
                                                            ? {
                                                                  ...current,
                                                                  actual_amount:
                                                                      e.target
                                                                          .value,
                                                              }
                                                            : current,
                                                ),
                                            )
                                        }
                                    />
                                </Field>
                            );
                        })}
                    </div>
                    <DialogErrors errors={errors} />
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Save actual spend
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function ChangeRequestDialog({
    budgetId,
    lines,
    initialLineId,
    threshold,
    onClose,
}: {
    budgetId: number;
    lines: LineItem[];
    initialLineId?: number;
    threshold: Props['changeThreshold'];
    onClose: () => void;
}) {
    const form = useForm({
        budget_line_item_id: initialLineId ? String(initialLineId) : '',
        adjustment_type: 'increase',
        amount: '',
        reason: '',
    });
    const errors = form.errors as Record<string, string>;
    const line = lines.find(
        (candidate) => String(candidate.id) === form.data.budget_line_item_id,
    );
    const value = Number(form.data.amount) || 0;
    const needsBoard = value > 0 && value >= threshold.amount;
    const after = line
        ? form.data.adjustment_type === 'decrease'
            ? amount(line.budget_amount) - value
            : amount(line.budget_amount) + value
        : null;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/governance/budgets/${budgetId}/adjust`, {
            preserveScroll: true,
            onSuccess: (page: unknown) => {
                if (!pageHasFlashError(page)) onClose();
            },
        });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent
                className="max-h-[88vh] overflow-y-auto"
                style={{ maxWidth: 'min(92vw, 600px)' }}
            >
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>Request a budget change</DialogTitle>
                        <DialogDescription>
                            Move an approved amount up or down on one line.
                            The change only happens once it is approved.
                        </DialogDescription>
                    </DialogHeader>
                    <Field
                        label="Budget line"
                        required
                        error={errors.budget_line_item_id}
                    >
                        <SelectInput
                            value={form.data.budget_line_item_id}
                            onChange={(next) =>
                                form.setData('budget_line_item_id', next)
                            }
                            placeholder="Choose the line to change"
                            ariaLabel="Budget line"
                            options={lines.map((candidate) => ({
                                value: String(candidate.id),
                                label: `${candidate.description} (${formatNzd(candidate.budget_amount)})`,
                            }))}
                        />
                    </Field>
                    <Field
                        label="Change"
                        required
                        error={errors.adjustment_type}
                    >
                        <TilePicker
                            value={form.data.adjustment_type}
                            onChange={(next) =>
                                form.setData('adjustment_type', next)
                            }
                            options={[
                                {
                                    key: 'increase',
                                    label: 'Increase',
                                    description: 'Add money to this line.',
                                    icon: ArrowUp,
                                },
                                {
                                    key: 'decrease',
                                    label: 'Decrease',
                                    description: 'Take money off this line.',
                                    icon: ArrowDown,
                                },
                            ]}
                        />
                    </Field>
                    <p className="text-caption">
                        Moving money between two lines? Request a decrease on
                        one line and an increase on the other.
                    </p>
                    <Field label="Amount (NZD)" required error={errors.amount}>
                        <Input
                            id="change-amount"
                            type="number"
                            step="0.01"
                            min="0.01"
                            inputMode="decimal"
                            value={form.data.amount}
                            onChange={(e) =>
                                form.setData('amount', e.target.value)
                            }
                        />
                    </Field>
                    <Field
                        label="Why is this change needed?"
                        required
                        error={errors.reason}
                    >
                        <Textarea
                            id="change-reason"
                            rows={3}
                            value={form.data.reason}
                            onChange={(e) =>
                                form.setData('reason', e.target.value)
                            }
                        />
                    </Field>
                    {line && value > 0 && after !== null ? (
                        <p className="text-subtle">
                            {line.description} would become{' '}
                            <strong className="text-foreground">
                                {formatNzd(after)}
                            </strong>
                            .
                        </p>
                    ) : null}
                    <InfoCard
                        icon={needsBoard ? AlertTriangle : Wallet}
                        tone={needsBoard ? 'warn' : 'info'}
                    >
                        {needsBoard
                            ? `This change needs a board decision. ${threshold.sentence} After you send it, the secretary adds it to a resolution. When the board passes it, an approver records the approval here.`
                            : `${threshold.sentence} Smaller changes are approved by an approver.`}
                    </InfoCard>
                    <DialogErrors errors={errors} />
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Send request
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function DeclineChangeDialog({
    budgetId,
    change,
    onClose,
}: {
    budgetId: number;
    change: BudgetChange;
    onClose: () => void;
}) {
    const form = useForm({ review_notes: '' });
    const errors = form.errors as Record<string, string>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(
            `/governance/budgets/${budgetId}/adjustments/${change.id}/reject`,
            {
                preserveScroll: true,
                onSuccess: (page: unknown) => {
                    if (!pageHasFlashError(page)) onClose();
                },
            },
        );
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent style={{ maxWidth: 'min(92vw, 520px)' }}>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>Decline this budget change?</DialogTitle>
                        <DialogDescription>
                            {changeTitle(change)}. The budget stays as it is,
                            and the person who asked for the change will see
                            your reason. This can&apos;t be undone.
                        </DialogDescription>
                    </DialogHeader>
                    <Field
                        label="Reason for declining"
                        required
                        error={errors.review_notes}
                    >
                        <Textarea
                            id="decline-reason"
                            rows={3}
                            value={form.data.review_notes}
                            onChange={(e) =>
                                form.setData('review_notes', e.target.value)
                            }
                        />
                    </Field>
                    <DialogErrors errors={errors} />
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={
                                form.processing ||
                                form.data.review_notes.trim() === ''
                            }
                        >
                            Decline change
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function MonthlyAmountDialog({
    budgetId,
    lines,
    categories,
    options,
    onClose,
}: {
    budgetId: number;
    lines: LineItem[];
    categories: Record<string, string>;
    options: NonNullable<Props['allocationOptions']>;
    onClose: () => void;
}) {
    const form = useForm({
        period_year_month: '',
        site_id: options.sites.length === 1 ? String(options.sites[0].id) : '',
        budget_line_item_id: NONE,
        category: NONE,
        allocated_amount: '',
        forecast_amount: '',
        notes: '',
    });
    const errors = form.errors as Record<string, string>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            period_year_month: data.period_year_month,
            site_id:
                data.site_id && data.site_id !== NONE
                    ? Number(data.site_id)
                    : null,
            budget_line_item_id:
                data.budget_line_item_id !== NONE
                    ? Number(data.budget_line_item_id)
                    : null,
            category: data.category !== NONE ? data.category : null,
            allocated_amount: data.allocated_amount,
            forecast_amount: data.forecast_amount.trim() || null,
            notes: data.notes.trim() || null,
        }));
        form.post(`/governance/budgets/${budgetId}/allocations`, {
            preserveScroll: true,
            onSuccess: (page: unknown) => {
                if (!pageHasFlashError(page)) onClose();
            },
        });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent
                className="max-h-[88vh] overflow-y-auto"
                style={{ maxWidth: 'min(92vw, 560px)' }}
            >
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>Add a monthly amount</DialogTitle>
                        <DialogDescription>
                            Set how much of this budget is planned for one
                            month at one site.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Month"
                            required
                            error={errors.period_year_month}
                        >
                            <Input
                                id="monthly-month"
                                type="month"
                                value={form.data.period_year_month}
                                onChange={(e) =>
                                    form.setData(
                                        'period_year_month',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="Site"
                            required={!options.can_leave_site_empty}
                            error={errors.site_id}
                        >
                            <SelectInput
                                value={form.data.site_id}
                                onChange={(value) =>
                                    form.setData('site_id', value)
                                }
                                placeholder="Choose a site"
                                ariaLabel="Site"
                                options={[
                                    ...(options.can_leave_site_empty
                                        ? [
                                              {
                                                  value: NONE,
                                                  label: 'Whole organisation',
                                              },
                                          ]
                                        : []),
                                    ...options.sites.map((site) => ({
                                        value: String(site.id),
                                        label: site.name,
                                    })),
                                ]}
                            />
                        </Field>
                        <Field
                            label="Budget line"
                            hint="optional"
                            error={errors.budget_line_item_id}
                        >
                            <SelectInput
                                value={form.data.budget_line_item_id}
                                onChange={(value) =>
                                    form.setData('budget_line_item_id', value)
                                }
                                placeholder="No particular line"
                                ariaLabel="Budget line"
                                options={[
                                    { value: NONE, label: 'No particular line' },
                                    ...lines.map((line) => ({
                                        value: String(line.id),
                                        label: line.description,
                                    })),
                                ]}
                            />
                        </Field>
                        <Field
                            label="Category"
                            hint="optional"
                            error={errors.category}
                        >
                            <SelectInput
                                value={form.data.category}
                                onChange={(value) =>
                                    form.setData('category', value)
                                }
                                placeholder="No category"
                                ariaLabel="Category"
                                options={[
                                    { value: NONE, label: 'No category' },
                                    ...Object.entries(categories).map(
                                        ([value, label]) => ({ value, label }),
                                    ),
                                ]}
                            />
                        </Field>
                        <Field
                            label="Amount (NZD)"
                            required
                            error={errors.allocated_amount}
                        >
                            <Input
                                id="monthly-amount"
                                type="number"
                                step="0.01"
                                min="0"
                                inputMode="decimal"
                                value={form.data.allocated_amount}
                                onChange={(e) =>
                                    form.setData(
                                        'allocated_amount',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="Forecast (NZD)"
                            hint="optional"
                            error={errors.forecast_amount}
                        >
                            <Input
                                id="monthly-forecast"
                                type="number"
                                step="0.01"
                                min="0"
                                inputMode="decimal"
                                value={form.data.forecast_amount}
                                onChange={(e) =>
                                    form.setData(
                                        'forecast_amount',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="Notes"
                            hint="optional"
                            span
                            error={errors.notes}
                        >
                            <Textarea
                                id="monthly-notes"
                                rows={2}
                                value={form.data.notes}
                                onChange={(e) =>
                                    form.setData('notes', e.target.value)
                                }
                            />
                        </Field>
                    </div>
                    <DialogErrors errors={errors} />
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Add monthly amount
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
