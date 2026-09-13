import {
    SpecialistRecordList,
    type SpecialistListItem,
} from '@/components/it/specialist-record-list';
import {
    GroupPillRail,
    TabSearchPalette,
    TierTwoTabs,
    type GroupedProfileNavGroup,
} from '@/components/page/grouped-profile-nav';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderSearch,
    PageHeaderStatusChip,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import type { StatusVariant } from '@/components/ui/status-badge';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import { Link, router } from '@inertiajs/react';
import {
    Activity,
    ClipboardList,
    FileText,
    GitBranch,
    Package,
    RefreshCw,
    ShieldCheck,
    type LucideIcon,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';

export interface ProvisioningEvent {
    id: number;
    type: string;
    actor: string | null;
    at: string | null;
    details: Record<string, unknown> | null;
}
export interface ProvisioningTask {
    id: number;
    version: number;
    reference: string;
    title: string;
    href: string;
    status: string;
    employee: { id: number; name: string };
    workflow: { id: number; lifecycle_type: string; href: string } | null;
    type: string;
    category: string;
    action: string;
    stage: number;
    due_date: string | null;
    priority: string;
    approval_required: boolean;
    approval_status: string;
    evidence_required: boolean;
    reversal_of_request_id: number | null;
    assignee: { id: number; name: string } | null;
    team: string | null;
    readiness: {
        storage_ready: boolean;
        actions: string[];
        blockers: string[];
        next_action: string;
        worker: { id: number; name: string } | null;
        approver: { id: number; name: string } | null;
        manual_evidence: boolean;
    };
}
export interface ProvisioningWorkflow {
    id: number;
    version: number;
    href: string;
    status: string;
    lifecycle_type: string;
    employee: { id: number; name: string };
    effective_at: string | null;
    original_effective_at: string | null;
    owner: { id: number; name: string } | null;
    cover: { id: number; name: string } | null;
    template: { name: string; version: number | null };
    source_type: string;
    source_id: number;
    source_href?: string | null;
    progress: {
        total: number;
        done: number;
        failed: number;
        cancelled: number;
    };
    cancelled_at: string | null;
    cancellation_reason: string | null;
    can_manage: boolean;
}
export const provisioningLabel = (value: string) =>
    value.replaceAll('_', ' ').replace(/^\w/, (letter) => letter.toUpperCase());
export const provisioningTone = (value: string): StatusVariant =>
    ['done', 'completed', 'approved', 'published'].includes(value)
        ? 'success'
        : ['failed', 'partially_failed', 'rejected'].includes(value)
          ? 'critical'
          : value === 'pending'
            ? 'warning'
            : value === 'in_progress'
              ? 'info'
              : 'neutral';
export const provisioningActionLabel: Record<string, string> = {
    assign: 'Assign responsibility',
    request_approval: 'Request approval',
    withdraw_approval: 'Withdraw approval',
    approve: 'Approve',
    reject: 'Reject',
    fulfil: 'Record fulfilment',
    fail: 'Record a failure',
    retry: 'Retry failed work',
    cancel: 'Cancel work',
    reopen: 'Reopen task',
    reschedule: 'Change effective date',
    reverse: 'Create reversal work',
    publish: 'Publish reviewed version',
    unpublish: 'Withdraw template',
    launch: 'Start workflow',
    create: 'Create work task',
};

export function ProvisioningProfileHeader({
    title,
    status,
    subline,
    tab,
    onTab,
    search,
    onSearch,
    actions,
    meters,
    workflow = false,
    template = false,
}: {
    title: string;
    status: string;
    subline: ReactNode;
    tab: string;
    onTab: (key: string) => void;
    search: string;
    onSearch: (value: string) => void;
    actions?: ReactNode;
    workflow?: boolean;
    template?: boolean;
    meters: { label: string; value: ReactNode; caption: string; tab: string }[];
}) {
    const [find, setFind] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const groups: GroupedProfileNavGroup[] = [
        {
            key: 'work',
            label: 'Provisioning',
            icon: Package,
            tabs: [
                {
                    key: 'details',
                    label: template ? 'Saved draft' : 'Details',
                    icon: FileText,
                },
                {
                    key: 'work',
                    label: template
                        ? 'Version preview'
                        : workflow
                          ? 'Work tasks'
                          : 'Approval & evidence',
                    icon: workflow ? ClipboardList : ShieldCheck,
                },
                { key: 'history', label: 'History', icon: Activity },
            ],
        },
    ];
    return (
        <>
            <PageHeader
                variant="profile"
                icon={workflow ? GitBranch : Package}
                backHref={
                    template
                        ? '/it/provisioning?view=templates'
                        : '/it/provisioning'
                }
                wrapTitle
                title={title}
                titleChip={
                    <PageHeaderStatusChip variant={provisioningTone(status)}>
                        {provisioningLabel(status)}
                    </PageHeaderStatusChip>
                }
                subline={subline}
                actions={
                    <>
                        <PageHeaderSearch
                            value={search}
                            onChange={onSearch}
                            placeholder="Search this work…"
                        />
                        <PageHeaderGlassButton
                            icon={RefreshCw}
                            disabled={refreshing}
                            onClick={() => {
                                setRefreshing(true);
                                router.reload({
                                    onFinish: () => setRefreshing(false),
                                });
                            }}
                        >
                            {refreshing ? 'Refreshing…' : 'Refresh'}
                        </PageHeaderGlassButton>
                        {actions}
                    </>
                }
                meters={meters.map((meter) => (
                    <PageHeaderMeterBlock
                        key={meter.label}
                        label={meter.label}
                        onClick={() => onTab(meter.tab)}
                    >
                        <PageHeaderMeterBig>{meter.value}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {meter.caption}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                ))}
                rail={
                    <GroupPillRail
                        groups={groups}
                        openGroup="work"
                        activeTab={tab}
                        onOpenGroup={(_, key) => onTab(key)}
                        onSearch={() => setFind(true)}
                        testIdPrefix="provisioning"
                        ariaLabel="Provisioning sections"
                    />
                }
            />
            <TierTwoTabs
                tabs={groups[0].tabs}
                activeTab={tab}
                onTab={onTab}
                panelId="provisioning-panel"
                testIdPrefix="provisioning"
                ariaLabel="Work sections"
                renderLink={(entry, className, inner, accessibility) => (
                    <Link
                        key={entry.key}
                        href={entry.href ?? '#'}
                        className={className}
                        {...accessibility}
                    >
                        {inner}
                    </Link>
                )}
            />
            <TabSearchPalette
                open={find}
                onClose={() => setFind(false)}
                groups={groups}
                onTab={(key) => {
                    onTab(key);
                    setFind(false);
                }}
                testIdPrefix="provisioning"
            />
        </>
    );
}

export function ProvisioningActions({
    actions,
    onAction,
}: {
    actions: string[];
    onAction: (operation: string) => void;
}) {
    return (
        <div className="flex flex-wrap gap-2">
            {actions.map((action) => (
                <Button
                    key={action}
                    variant={action === 'fulfil' ? 'default' : 'outline'}
                    onClick={() => onAction(action)}
                >
                    {provisioningActionLabel[action] ??
                        provisioningLabel(action)}
                </Button>
            ))}
        </div>
    );
}

export function ProvisioningFacts({
    facts,
    search = '',
}: {
    facts: { label: string; value: ReactNode; search?: string }[];
    search?: string;
}) {
    const needle = search.toLocaleLowerCase();
    return (
        <dl className="grid gap-5 sm:grid-cols-2">
            {facts
                .filter(
                    (fact) =>
                        !needle ||
                        (
                            fact.label +
                            ' ' +
                            (fact.search ??
                                (typeof fact.value === 'string'
                                    ? fact.value
                                    : ''))
                        )
                            .toLocaleLowerCase()
                            .includes(needle),
                )
                .map((fact) => (
                    <div key={fact.label}>
                        <dt className="text-sm text-muted-foreground">
                            {fact.label}
                        </dt>
                        <dd className="mt-1 text-sm font-medium whitespace-pre-wrap">
                            {fact.value ?? 'Not recorded'}
                        </dd>
                    </div>
                ))}
        </dl>
    );
}

export function ProvisioningHistory({
    events,
    search,
}: {
    events: ProvisioningEvent[];
    search: string;
}) {
    const rows = events.filter((event) =>
        (
            event.type +
            ' ' +
            event.actor +
            ' ' +
            String(event.details?.reason ?? event.details?.decision_note ?? '')
        )
            .toLocaleLowerCase()
            .includes(search.toLocaleLowerCase()),
    );
    return (
        <section aria-label="Work history" className="space-y-3">
            {rows.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    No matching recorded events.
                </p>
            ) : (
                rows.map((event) => (
                    <Card key={event.id}>
                        <CardContent className="space-y-2 p-4">
                            <p className="font-medium">
                                {provisioningLabel(event.type)}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                {formatDateTime(event.at)} ·{' '}
                                {event.actor ?? 'System'}
                            </p>
                            {typeof (
                                event.details?.reason ??
                                event.details?.decision_note
                            ) === 'string' && (
                                <p className="text-sm whitespace-pre-wrap">
                                    {String(
                                        event.details?.reason ??
                                            event.details?.decision_note,
                                    )}
                                </p>
                            )}
                        </CardContent>
                    </Card>
                ))
            )}
        </section>
    );
}

export function provisioningTaskRow(
    task: ProvisioningTask,
): SpecialistListItem {
    return {
        id: task.id,
        reference: task.reference,
        title: task.title,
        href: task.href,
        state: {
            label: provisioningLabel(task.status),
            tone: provisioningTone(task.status),
        },
        priority: provisioningLabel(task.priority),
        impact: task.employee.name,
        owner: task.readiness.worker?.name,
        facts: [
            { label: 'Due', value: formatDateOnly(task.due_date) },
            { label: 'Next action', value: task.readiness.next_action },
        ],
        alerts:
            task.status === 'failed'
                ? [{ label: 'Failure needs review', tone: 'critical' }]
                : task.readiness.blockers.length
                  ? [{ label: 'Waiting for action', tone: 'warning' }]
                  : [],
    };
}

export function ProvisioningTaskList({
    tasks,
    search = '',
}: {
    tasks: ProvisioningTask[];
    search?: string;
}) {
    const visible = tasks.filter((task) =>
        (
            task.title +
            ' ' +
            task.employee.name +
            ' ' +
            task.readiness.next_action
        )
            .toLocaleLowerCase()
            .includes(search.toLocaleLowerCase()),
    );
    return (
        <SpecialistRecordList
            title="Work tasks"
            icon={ClipboardList}
            rows={visible.map(provisioningTaskRow)}
            total={visible.length}
            links={[]}
        />
    );
}

export function ProvisioningSection({
    title,
    icon: Icon = FileText,
    children,
}: {
    title: string;
    icon?: LucideIcon;
    children: ReactNode;
}) {
    return (
        <Card>
            <CardContent className="space-y-5 p-5">
                <h2 className="flex items-center gap-2 text-base font-semibold">
                    <Icon className="size-4" aria-hidden />
                    {title}
                </h2>
                {children}
            </CardContent>
        </Card>
    );
}
