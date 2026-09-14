import {
    provisioningProgress,
    provisioningStatus,
    type MyProvisioningRow,
} from '@/components/it/my-provisioning-list';
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
import { formatFileSize } from '@/components/ui/file-dropzone';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import { Head, router } from '@inertiajs/react';
import { Activity, FileText, Package, RefreshCw } from 'lucide-react';
import { useState } from 'react';

interface TrackingRequest extends MyProvisioningRow {
    viewer_user_id: number;
    catalogue_version: number;
    submitted_at: string | null;
    answers: { label: string; value: string }[];
    attachments?: {
        id: number;
        name: string;
        size: number;
        url: string;
        catalogue_field_label: string;
    }[];
    events: { id: number; label: string; at: string | null }[];
    tasks?: {
        stage: number;
        type: string;
        status: string;
        approval_status: string;
        due_date: string | null;
    }[];
}

const approvalLabel = (status: string) =>
    ({
        not_required: 'Not required',
        pending: 'Waiting for approval',
        approved: 'Approved',
        rejected: 'Declined',
        expired: 'Approval expired',
        cancelled: 'Needs a new review',
    })[status] ?? status.replaceAll('_', ' ');

const trackingGroups: GroupedProfileNavGroup[] = [
    {
        key: 'request',
        label: 'Request',
        icon: Package,
        tabs: [
            { key: 'details', label: 'Request details', icon: FileText },
            { key: 'activity', label: 'Activity', icon: Activity },
        ],
    },
];

export default function ProvisioningTracking({
    request,
}: {
    request: TrackingRequest;
}) {
    const [tab, setTab] = useState<'details' | 'activity'>('details');
    const [search, setSearch] = useState('');
    const [findOpen, setFindOpen] = useState(false);
    const go = (key: string) =>
        setTab(key === 'activity' ? 'activity' : 'details');
    const [refreshing, setRefreshing] = useState(false);
    const needle = search.trim().toLocaleLowerCase();
    const answers = request.answers.filter((answer) =>
        `${answer.label} ${answer.value}`.toLocaleLowerCase().includes(needle),
    );
    const events = request.events.filter((event) =>
        event.label.toLocaleLowerCase().includes(needle),
    );
    const attachments = (request.attachments ?? []).filter((file) =>
        `${file.catalogue_field_label} ${file.name}`
            .toLocaleLowerCase()
            .includes(needle),
    );
    const refresh = () => {
        if (refreshing) return;
        setRefreshing(true);
        router.reload({
            only: ['request'],
            onFinish: () => setRefreshing(false),
        });
    };
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'IT & Support', href: '/it' },
                { title: 'My requests', href: '/it?tab=my-tickets' },
                { title: request.reference, href: request.href },
            ]}
        >
            <Head title={`${request.reference} · ${request.title}`} />
            <PageHeader
                variant="profile"
                icon={Package}
                backHref="/it?tab=my-tickets"
                title={request.title}
                wrapTitle
                titleChip={
                    <PageHeaderStatusChip
                        variant={
                            request.status === 'failed'
                                ? 'critical'
                                : request.status === 'done'
                                  ? 'success'
                                  : request.status === 'cancelled'
                                    ? 'neutral'
                                    : request.status === 'pending'
                                      ? 'warning'
                                      : 'info'
                        }
                    >
                        {provisioningStatus(request.status)}
                    </PageHeaderStatusChip>
                }
                subline={`${request.reference} · ${request.type} request · Submitted ${formatDateTime(request.submitted_at)}`}
                actions={
                    <>
                        <PageHeaderSearch
                            value={search}
                            onChange={setSearch}
                            placeholder="Search this request…"
                        />
                        <PageHeaderGlassButton
                            icon={RefreshCw}
                            disabled={refreshing}
                            onClick={refresh}
                        >
                            {refreshing ? 'Refreshing…' : 'Refresh status'}
                        </PageHeaderGlassButton>
                    </>
                }
                meters={
                    <>
                        <PageHeaderMeterBlock
                            label="Request status"
                            onClick={() => setTab('details')}
                        >
                            <PageHeaderMeterBig>
                                {provisioningStatus(request.status)}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {provisioningProgress(request)}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Approval"
                            onClick={() => setTab('details')}
                        >
                            <PageHeaderMeterBig>
                                {approvalLabel(request.approval_status)}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                Approval requirement
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Submitted details"
                            onClick={() => setTab('details')}
                        >
                            <PageHeaderMeterBig>
                                {request.answers.length}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                Form version {request.catalogue_version}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Activity"
                            onClick={() => setTab('activity')}
                        >
                            <PageHeaderMeterBig>
                                {request.events.length}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                Recorded updates
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    </>
                }
                rail={
                    <GroupPillRail
                        groups={trackingGroups}
                        openGroup="request"
                        activeTab={tab}
                        onOpenGroup={(_group, key) => go(key)}
                        onSearch={() => setFindOpen(true)}
                        testIdPrefix="it-provisioning-tracking"
                        ariaLabel="Request workspace"
                    />
                }
            />
            <TierTwoTabs
                tabs={trackingGroups[0].tabs}
                activeTab={tab}
                onTab={go}
                testIdPrefix="it-provisioning-tracking"
                ariaLabel="Request sections"
                panelId="it-provisioning-tracking-panel"
                renderLink={(entry, className, inner, accessibility) => (
                    // eslint-disable-next-line no-restricted-syntax -- TierTwoTabs supplies the approved tab geometry and keyboard contract.
                    <button
                        key={entry.key}
                        type="button"
                        className={className}
                        {...accessibility}
                        onClick={() => go(entry.key)}
                    >
                        {inner}
                    </button>
                )}
            />
            <TabSearchPalette
                open={findOpen}
                onClose={() => setFindOpen(false)}
                groups={trackingGroups}
                onTab={go}
                testIdPrefix="it-provisioning-tracking"
                searchLabel="Find a section in this request"
            />
            <div className="mt-5 grid min-w-0 gap-5 lg:grid-cols-[minmax(0,2fr)_minmax(20rem,1fr)]">
                <section
                    id="it-provisioning-tracking-panel"
                    className="min-w-0 rounded-xl border border-border bg-card p-5"
                    aria-label={
                        tab === 'details'
                            ? 'Submitted details'
                            : 'Request activity'
                    }
                >
                    <h2 className="text-section-title">
                        {tab === 'details'
                            ? 'Submitted details'
                            : 'Request activity'}
                    </h2>
                    {tab === 'details' ? (
                        <>
                            <p className="text-subtle mt-1">
                                Your original answers are retained with form
                                version {request.catalogue_version}.
                            </p>
                            {answers.length ? (
                                <dl className="mt-5 space-y-5">
                                    {answers.map((answer, index) => (
                                        <div key={index}>
                                            <dt className="text-sm font-semibold">
                                                {answer.label}
                                            </dt>
                                            <dd className="mt-1 text-sm break-words whitespace-pre-wrap">
                                                {answer.value}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                            ) : (
                                <p className="text-subtle mt-5">
                                    {needle
                                        ? 'No submitted details match this search.'
                                        : 'This form did not require any additional details.'}
                                </p>
                            )}
                            {attachments.length > 0 && (
                                <section
                                    className="mt-5"
                                    aria-label="Submitted files"
                                >
                                    <h3 className="text-sm font-semibold">
                                        Submitted files
                                    </h3>
                                    <ul className="mt-3 divide-y divide-border">
                                        {attachments.map((file) => (
                                            <li key={file.id} className="py-3">
                                                <a
                                                    className="frontline-focus rounded-sm text-sm font-semibold break-words text-primary hover:underline"
                                                    href={file.url}
                                                    aria-label={`${file.name} (opens in a new tab)`}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                >
                                                    {file.name}
                                                    <span className="sr-only">
                                                        {' '}
                                                        (opens in a new tab)
                                                    </span>
                                                </a>
                                                <p className="text-caption mt-1">
                                                    {file.catalogue_field_label}{' '}
                                                    ·{' '}
                                                    {formatFileSize(file.size)}
                                                </p>
                                            </li>
                                        ))}
                                    </ul>
                                </section>
                            )}
                        </>
                    ) : events.length ? (
                        <ol className="mt-5 space-y-4">
                            {events.map((event) => (
                                <li
                                    key={event.id}
                                    className="flex items-start gap-3"
                                >
                                    <Activity
                                        className="mt-1 size-4 shrink-0 text-primary"
                                        aria-hidden="true"
                                    />
                                    <div>
                                        <p className="text-sm font-semibold">
                                            {event.label}
                                        </p>
                                        <time
                                            className="text-subtle"
                                            dateTime={event.at ?? undefined}
                                        >
                                            {formatDateTime(event.at)}
                                        </time>
                                    </div>
                                </li>
                            ))}
                        </ol>
                    ) : (
                        <p className="text-subtle mt-5">
                            {needle
                                ? 'No activity matches this search.'
                                : 'No public activity has been recorded yet.'}
                        </p>
                    )}
                </section>
                <aside
                    className="min-w-0 self-start rounded-xl border border-border bg-card p-5"
                    aria-label="Request progress"
                >
                    <h2 className="text-section-title">Request progress</h2>
                    <div className="mt-4">
                        <StatusBadge
                            status={request.status}
                            label={provisioningStatus(request.status)}
                        />
                    </div>
                    <p className="mt-3 text-sm">
                        {request.status === 'failed'
                            ? 'IT needs to review this request before work can continue.'
                            : request.status === 'cancelled'
                              ? 'This request has been cancelled.'
                              : request.status === 'done'
                                ? 'IT has recorded this work as completed.'
                                : request.approval_status === 'pending'
                                  ? 'This request is waiting for approval before IT can complete the work.'
                                  : 'IT will update this request as the work progresses.'}
                    </p>
                    <dl className="mt-5 space-y-4 text-sm">
                        <div>
                            <dt className="text-muted-foreground">Approval</dt>
                            <dd className="mt-1">
                                <StatusBadge
                                    status={request.approval_status}
                                    label={approvalLabel(
                                        request.approval_status,
                                    )}
                                />
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Due date</dt>
                            <dd>
                                {formatDateOnly(
                                    request.due_date,
                                    'Not scheduled',
                                )}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">
                                Last updated
                            </dt>
                            <dd>{formatDateTime(request.updated_at)}</dd>
                        </div>
                    </dl>
                    {request.tasks && request.tasks.length > 1 && (
                        <section className="mt-5" aria-label="Work steps">
                            <h3 className="text-sm font-semibold">
                                Work steps
                            </h3>
                            <ol className="mt-3 divide-y divide-border">
                                {request.tasks.map((task, index) => (
                                    <li
                                        key={index}
                                        className="flex flex-wrap items-center justify-between gap-2 py-3 text-sm"
                                    >
                                        <span>
                                            Step {task.stage} ·{' '}
                                            {task.type.replaceAll('_', ' ')}
                                            {task.due_date
                                                ? ' · due ' +
                                                  formatDateOnly(task.due_date)
                                                : ''}
                                        </span>
                                        <span className="flex flex-wrap gap-2">
                                            {task.approval_status !==
                                                'not_required' &&
                                                task.status !== 'done' && (
                                                    <StatusBadge
                                                        status={
                                                            task.approval_status
                                                        }
                                                        label={approvalLabel(
                                                            task.approval_status,
                                                        )}
                                                    />
                                                )}
                                            <StatusBadge
                                                status={task.status}
                                                label={provisioningStatus(
                                                    task.status,
                                                )}
                                            />
                                        </span>
                                    </li>
                                ))}
                            </ol>
                        </section>
                    )}
                </aside>
            </div>
        </AppLayout>
    );
}
