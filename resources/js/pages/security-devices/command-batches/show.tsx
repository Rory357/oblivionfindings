import { ConfirmDialog } from '@/components/confirm-dialog';
import { EntityChip, EntityStatusChip } from '@/components/lists';
import type { EntityTableColumn } from '@/components/lists/entity-table';
import { EvidenceRecords } from '@/components/lists/evidence-records';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageHeaderViewToggle,
} from '@/components/page';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { type StatusVariant } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import AppLayout from '@/layouts/app-layout';
import { formatDateTimeLong } from '@/lib/datetime';
import { formatReadableOperationalValue } from '@/lib/readable-operational-state';
import type { VisitOptions } from '@inertiajs/core';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    Building2,
    Download,
    FileCheck2,
    LayoutGrid,
    List,
    RefreshCw,
    Send,
    ShieldCheck,
} from 'lucide-react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';

type BatchTarget = {
    id: number;
    position: number;
    inclusionStatus: 'included' | 'excluded';
    safeExclusionCode: string | null;
    safeExclusionReason: string | null;
    device: {
        id: number;
        uid: string;
        name: string;
        category: string;
        subcategory: string | null;
        provider: string | null;
        status: string;
        health: string;
        href: string;
    };
    site: { id: number; name: string; href: string } | null;
    command: {
        id: number;
        uuid: string;
        status: string;
        requestedBy: string | null;
        approvedBy: string | null;
        expiresAt: string | null;
        safeFailureReason: string | null;
        expectedState: Record<string, unknown>;
        nextAction: string;
        latestAttempt: {
            number: number;
            status: string;
            runtime: string;
            safeResult: Record<string, unknown>;
            safeFailureReason: string | null;
            completedAt: string | null;
        } | null;
        latestReconciliation: {
            outcome: string;
            safeEvidenceSummary: Record<string, unknown> | string | null;
            observedAt: string | null;
        } | null;
    } | null;
};

export type BatchPayload = {
    actor_id: number;
    as_of: string;
    requestedById: number;
    id: number;
    uuid: string;
    workspace: string;
    workspaceHref: string;
    capability: string;
    label: string;
    risk: string;
    confirmationMode: string;
    impact: string;
    expectedResult: string;
    reason: string;
    safeParameters: Record<string, unknown>;
    requestedBy: string | null;
    requestedAt: string | null;
    impactAcknowledgedAt: string | null;
    status: string;
    summary: {
        selected: number;
        included: number;
        excluded: number;
        sites: number;
        awaitingApproval: number;
        ready: number;
        queuedOrRunning: number;
        terminal: number;
        reconciled: number;
        failedOrBlocked: number;
    };
    targets: BatchTarget[];
    canApprove: boolean;
    canDispatch: boolean;
    exportHref: string;
    partialSemantics: string;
};

type CurrentAuth = {
    user?: { id: number } | null;
    can?: {
        securityDevices?: {
            devicesView?: boolean;
            commandsApprove?: boolean;
            commandsAdmin?: boolean;
        };
    };
};
type ReadContext = { actor: number | undefined; id: number; uuid: string };
function sameReadContext(left: ReadContext, right: ReadContext): boolean {
    return (
        left.actor === right.actor &&
        left.id === right.id &&
        left.uuid === right.uuid
    );
}
type BatchView =
    | 'overview'
    | 'targets'
    | 'review'
    | 'ready'
    | 'results'
    | 'excluded';
const views: { key: BatchView; label: string }[] = [
    { key: 'overview', label: 'Batch overview' },
    { key: 'targets', label: 'All targets' },
    { key: 'review', label: 'Awaiting review' },
    { key: 'ready', label: 'Ready to queue' },
    { key: 'results', label: 'Execution & results' },
    { key: 'excluded', label: 'Excluded targets' },
];
const progressing = [
    'queued',
    'dispatching',
    'accepted',
    'running',
    'succeeded',
    'reconciling',
    'uncertain',
];
const unsuccessful = [
    'failed',
    'blocked',
    'rejected',
    'mismatch',
    'expired',
    'cancelled',
];
const readHeader = 'X-Command-Batch-Read';
/** Server rule: DecideDeviceCommandRequest `comment` max:1000. */
const COMMENT_MAX = 1000;

function humanise(value: string): string {
    return value.replaceAll('_', ' ').replaceAll('.', ' ');
}
function tone(status: string): StatusVariant {
    if (status === 'reconciled' || status === 'completed') return 'success';
    if (unsuccessful.includes(status) || status === 'completed_with_failures')
        return 'critical';
    if (progressing.includes(status) || status === 'executing') return 'info';
    if (status.startsWith('awaiting_') || status === 'partially_completed')
        return 'warning';
    return 'neutral';
}
function time(value: string | null | undefined): string {
    if (!value) return 'Not recorded';
    if (!/(Z|[+-]\d{2}:\d{2})$/.test(value)) return 'Time zone not recorded';
    return formatDateTimeLong(value, 'Time unavailable');
}
function SafeSummary({ value }: { value: unknown }) {
    if (value === null || value === undefined || value === '')
        return <p className="text-sm text-muted-foreground">Not recorded</p>;
    if (typeof value !== 'object')
        return (
            <p className="text-sm break-words text-muted-foreground">
                {formatReadableOperationalValue(value)}
            </p>
        );
    const entries = Object.entries(value as Record<string, unknown>);
    if (!entries.length)
        return <p className="text-sm text-muted-foreground">None recorded</p>;
    return (
        <>
            {entries.map(([key, entry]) => (
                <ReviewRow
                    key={key}
                    label={humanise(key)}
                    value={formatReadableOperationalValue(entry)}
                />
            ))}
        </>
    );
}
function targetStatus(target: BatchTarget): string {
    return target.command?.status ?? target.inclusionStatus;
}
/**
 * The words a person can see on a target's card or row. Search matches only
 * these — never links, internal tokens or other fields that are not shown.
 */
function searchableText(target: BatchTarget): string {
    return [
        target.device.name,
        target.device.uid,
        target.device.provider ?? 'Native',
        target.site?.name ?? 'Site unavailable',
        `target ${target.id}`,
        humanise(targetStatus(target)),
        humanise(target.inclusionStatus),
        target.safeExclusionReason,
        target.command?.safeFailureReason,
        target.command?.nextAction ??
            'Resolve the exclusion before creating a new request.',
        target.command?.uuid ?? 'No child command',
        target.command?.approvedBy ?? target.command?.requestedBy,
    ]
        .filter((part): part is string => Boolean(part))
        .join(' ');
}
const evidenceColumns: EntityTableColumn<BatchTarget>[] = [
    {
        key: 'identity',
        label: 'Device & Site',
        width: '1.2fr',
        cell: (target) => (
            <>
                <ReviewRow
                    label="Device"
                    value={
                        <Link href={target.device.href}>
                            {target.device.name}
                        </Link>
                    }
                />
                <ReviewRow label="Device ID" value={target.device.id} />
                <ReviewRow label="Device UID" value={target.device.uid} />
                <ReviewRow
                    label="Provider"
                    value={target.device.provider ?? 'Native'}
                />
                <ReviewRow
                    label="Category"
                    value={humanise(target.device.category)}
                />
                <ReviewRow
                    label="Subcategory"
                    value={
                        target.device.subcategory
                            ? humanise(target.device.subcategory)
                            : 'Not recorded'
                    }
                />
                <ReviewRow
                    label="Device state"
                    value={humanise(target.device.status)}
                />
                <ReviewRow
                    label="Recorded health"
                    value={humanise(target.device.health)}
                />
                <ReviewRow
                    label="Site"
                    value={
                        target.site ? (
                            <Link href={target.site.href}>
                                {target.site.name}
                            </Link>
                        ) : (
                            'Site unavailable'
                        )
                    }
                />
                <ReviewRow
                    label="Site ID"
                    value={target.site?.id ?? 'Not recorded'}
                />
            </>
        ),
    },
    {
        key: 'inclusion',
        label: 'Target selection',
        width: '1fr',
        cell: (target) => (
            <>
                <ReviewRow label="Target ID" value={target.id} />
                <ReviewRow label="Selection position" value={target.position} />
                <ReviewRow
                    label="Inclusion"
                    value={humanise(target.inclusionStatus)}
                />
                <ReviewRow
                    label="Exclusion code"
                    value={
                        target.safeExclusionCode
                            ? humanise(target.safeExclusionCode)
                            : 'Not recorded'
                    }
                />
                <ReviewRow
                    label="Exclusion reason"
                    value={target.safeExclusionReason ?? 'Not recorded'}
                />
            </>
        ),
    },
    {
        key: 'command',
        label: 'Command request',
        width: '1fr',
        cell: (target) =>
            target.command ? (
                <>
                    <ReviewRow label="Command ID" value={target.command.id} />
                    <ReviewRow
                        label="Command reference"
                        value={target.command.uuid}
                    />
                    <ReviewRow
                        label="Command state"
                        value={
                            <EntityStatusChip
                                variant={tone(target.command.status)}
                            >
                                {humanise(target.command.status)}
                            </EntityStatusChip>
                        }
                    />
                    <ReviewRow
                        label="Requested by"
                        value={target.command.requestedBy ?? 'Not recorded'}
                    />
                    <ReviewRow
                        label="Approved by"
                        value={target.command.approvedBy ?? 'Not recorded'}
                    />
                    <ReviewRow
                        label="Expires"
                        value={time(target.command.expiresAt)}
                    />
                    <ReviewRow
                        label="Failure reason"
                        value={
                            target.command.safeFailureReason ?? 'Not recorded'
                        }
                    />
                    <p className="text-sm font-semibold">Expected state</p>
                    <SafeSummary value={target.command.expectedState} />
                </>
            ) : (
                <p className="text-sm text-muted-foreground">
                    No child command was created for this target.
                </p>
            ),
    },
    {
        key: 'attempt',
        label: 'Execution evidence',
        width: '1fr',
        cell: (target) =>
            target.command?.latestAttempt ? (
                <>
                    <ReviewRow
                        label="Attempt number"
                        value={target.command.latestAttempt.number}
                    />
                    <ReviewRow
                        label="Attempt state"
                        value={humanise(target.command.latestAttempt.status)}
                    />
                    <ReviewRow
                        label="Execution runtime"
                        value={target.command.latestAttempt.runtime}
                    />
                    <ReviewRow
                        label="Completed"
                        value={time(target.command.latestAttempt.completedAt)}
                    />
                    <ReviewRow
                        label="Attempt failure"
                        value={
                            target.command.latestAttempt.safeFailureReason ??
                            'Not recorded'
                        }
                    />
                    <p className="text-sm font-semibold">Recorded result</p>
                    <SafeSummary
                        value={target.command.latestAttempt.safeResult}
                    />
                </>
            ) : (
                <p className="text-sm text-muted-foreground">
                    No execution evidence has been recorded.
                </p>
            ),
    },
    {
        key: 'reconciliation',
        label: 'Reconciliation & next action',
        width: '1fr',
        cell: (target) => (
            <>
                {target.command?.latestReconciliation ? (
                    <>
                        <ReviewRow
                            label="Reconciliation outcome"
                            value={humanise(
                                target.command.latestReconciliation.outcome,
                            )}
                        />
                        <ReviewRow
                            label="Observed"
                            value={time(
                                target.command.latestReconciliation.observedAt,
                            )}
                        />
                        <p className="text-sm font-semibold">
                            Observed evidence
                        </p>
                        <SafeSummary
                            value={
                                target.command.latestReconciliation
                                    .safeEvidenceSummary
                            }
                        />
                    </>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        No reconciliation evidence has been recorded.
                    </p>
                )}
                <ReviewRow
                    label="Next action"
                    value={
                        target.command?.nextAction ??
                        'Resolve the exclusion before creating a new request.'
                    }
                />
            </>
        ),
    },
];

export default function DeviceCommandBatchShow({
    batch,
}: {
    batch: BatchPayload;
}) {
    const { auth } = usePage<{ auth?: CurrentAuth }>().props;
    const actor = auth?.user?.id;
    const [sourceContext] = useState<ReadContext>(() => ({
        actor: batch.actor_id,
        id: batch.id,
        uuid: batch.uuid,
    }));
    const readContext = useRef<ReadContext>({
        actor,
        id: batch.id,
        uuid: batch.uuid,
    });
    useLayoutEffect(() => {
        readContext.current = { actor, id: batch.id, uuid: batch.uuid };
    }, [actor, batch.id, batch.uuid]);
    const [denied, setDenied] = useState(false);
    const [notice, setNotice] = useState('');
    const alive = useRef(true);
    const sequence = useRef(0);
    const active = useRef<{
        token: number;
        marker: string;
        url: string;
        context: ReadContext;
        complete: boolean;
        cancel?: () => void;
    } | null>(null);
    const path = `/security-devices/command-batches/${batch.id}`;
    const canRead = Boolean(
        actor &&
        actor === sourceContext.actor &&
        batch.actor_id === sourceContext.actor &&
        batch.id === sourceContext.id &&
        batch.uuid === sourceContext.uuid &&
        auth?.can?.securityDevices?.devicesView === true,
    );
    const canApprove =
        canRead &&
        batch.canApprove &&
        batch.requestedById !== actor &&
        auth?.can?.securityDevices?.commandsApprove === true;
    const canDispatch =
        canRead &&
        batch.canDispatch &&
        (batch.requestedById === actor ||
            auth?.can?.securityDevices?.commandsAdmin === true);
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
            active.current = null;
        };
    }, []);
    useEffect(
        () =>
            router.on('invalid', (event) => {
                const owned = active.current;
                if (!alive.current || !owned) return;
                const response = event.detail.response;
                if (
                    response.config.method?.toLowerCase() !== 'get' ||
                    typeof response.config.url !== 'string'
                )
                    return;
                const headers = response.config.headers;
                const marker =
                    typeof headers?.get === 'function'
                        ? headers.get(readHeader)
                        : headers?.[readHeader];
                if (String(marker) !== owned.marker) return;
                let url: URL;
                try {
                    url = new URL(response.config.url, window.location.origin);
                } catch {
                    return;
                }
                if (url.href !== owned.url) return;
                event.preventDefault();
                active.current = null;
                if (!sameReadContext(readContext.current, owned.context))
                    return;
                if ([401, 403, 404].includes(response.status)) {
                    setDenied(true);
                    setNotice('');
                } else
                    setNotice(
                        'The batch could not be refreshed. Previous records remain dated by their read time. Try Refresh results again.',
                    );
            }),
        [],
    );
    const refresh = () => {
        const complete = denied || !canRead;
        const context: ReadContext = { actor, id: batch.id, uuid: batch.uuid };
        if (active.current) {
            if (
                !complete ||
                (active.current.complete &&
                    sameReadContext(active.current.context, context))
            )
                return;
            const older = active.current;
            active.current = null;
            older.cancel?.();
        }
        const token = ++sequence.current;
        const url = new URL(window.location.href);
        url.hash = '';
        const marker = Array.from(
            crypto.getRandomValues(new Uint32Array(4)),
            (part) => part.toString(16).padStart(8, '0'),
        ).join('');
        active.current = { token, marker, url: url.href, context, complete };
        let settled = false;
        setNotice('');
        const options: VisitOptions = {
            headers: { [readHeader]: marker },
            onCancelToken: (ticket) => {
                if (active.current?.token === token)
                    active.current.cancel = () => ticket.cancel();
            },
            onSuccess: (page) => {
                if (!alive.current || active.current?.token !== token) return;
                settled = true;
                if (complete || !sameReadContext(readContext.current, context))
                    return;
                const next = page.props as {
                    batch?: BatchPayload;
                    auth?: CurrentAuth;
                };
                setDenied(
                    !(
                        page.component ===
                            'security-devices/command-batches/show' &&
                        next.batch?.id === batch.id &&
                        next.batch.uuid === batch.uuid &&
                        next.batch.actor_id === actor &&
                        next.auth?.user?.id === actor &&
                        next.auth.can?.securityDevices?.devicesView === true
                    ),
                );
            },
            onCancel: () => {
                settled = true;
            },
            onFinish: () => {
                if (!alive.current || active.current?.token !== token) return;
                active.current = null;
                if (!settled && sameReadContext(readContext.current, context))
                    setNotice(
                        'The batch could not be refreshed. Previous records remain dated by their read time. Try Refresh results again.',
                    );
            },
        };
        if (complete)
            router.visit(url.href, {
                ...options,
                method: 'get',
                preserveState: false,
                preserveScroll: true,
            });
        else router.reload({ ...options, only: ['batch', 'auth'] });
    };
    const breadcrumbs = [
        { title: 'Home', href: '/dashboard' },
        { title: 'Security & Devices', href: '/security-devices' },
        ...(canRead && !denied
            ? [{ title: 'Management', href: batch.workspaceHref }]
            : []),
        {
            title: canRead && !denied ? batch.label : 'Command batch',
            href: path,
        },
    ];
    if (!canRead || denied)
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Command batch" />
                <div className="min-w-0 space-y-5">
                    <PageHeader
                        icon={FileCheck2}
                        title="Command batch"
                        subline="Batch records are restricted for the current access."
                        actions={
                            <PageHeaderGlassButton
                                onClick={refresh}
                                icon={RefreshCw}
                            >
                                Refresh results
                            </PageHeaderGlassButton>
                        }
                    />
                    <div role="status">
                        <EmptyState
                            icon={FileCheck2}
                            title="Command batch records are restricted"
                            description="Restore access, then use Refresh results to read the batch again."
                        />
                    </div>
                </div>
            </AppLayout>
        );
    return (
        <BatchWorkspace
            key={`${sourceContext.actor}:${sourceContext.id}:${sourceContext.uuid}`}
            batch={batch}
            canApprove={canApprove}
            canDispatch={canDispatch}
            refresh={refresh}
            notice={notice}
            breadcrumbs={breadcrumbs}
        />
    );
}

function BatchWorkspace({
    batch,
    canApprove,
    canDispatch,
    refresh,
    notice,
    breadcrumbs,
}: {
    batch: BatchPayload;
    canApprove: boolean;
    canDispatch: boolean;
    refresh: () => void;
    notice: string;
    breadcrumbs: { title: string; href: string }[];
}) {
    const [view, setView] = useState<BatchView>('overview');
    const [layout, setLayout] = useState<'cards' | 'table'>('cards');
    const [search, setSearch] = useState('');
    const [resultFilter, setResultFilter] = useState('all');
    const [decisionOpen, setDecisionOpen] = useState(false);
    const [decision, setDecision] = useState<'approved' | 'rejected'>(
        'approved',
    );
    const [comment, setComment] = useState('');
    const [dispatchOpen, setDispatchOpen] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [dispatching, setDispatching] = useState(false);
    const alive = useRef(true);
    const authority = useRef({ canApprove, canDispatch });
    useLayoutEffect(() => {
        authority.current = { canApprove, canDispatch };
    }, [canApprove, canDispatch]);
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
        };
    }, []);
    useEffect(() => {
        if (!canApprove) {
            setDecisionOpen(false);
            setComment('');
        }
    }, [canApprove]);
    useEffect(() => {
        if (!canDispatch) setDispatchOpen(false);
    }, [canDispatch]);
    useEffect(() => {
        if (batch.status !== 'executing') return;
        const interval = window.setInterval(refresh, 10_000);
        return () => window.clearInterval(interval);
    }, [batch.status, refresh]);
    const select = (next: BatchView, filter = 'all') => {
        setView(next);
        setResultFilter(filter);
        setSearch('');
    };
    const submitDecision = () => {
        if (
            !alive.current ||
            !authority.current.canApprove ||
            submitting ||
            comment.trim().length < 10
        )
            return;
        setSubmitting(true);
        router.post(
            `/security-devices/command-batches/${batch.id}/decision`,
            { decision, comment },
            {
                preserveScroll: true,
                onSuccess: () => {
                    if (alive.current) {
                        setDecisionOpen(false);
                        setComment('');
                    }
                },
                onFinish: () => {
                    if (alive.current) setSubmitting(false);
                },
            },
        );
    };
    const dispatch = () => {
        if (!alive.current || !authority.current.canDispatch || dispatching)
            return;
        setDispatching(true);
        router.post(
            `/security-devices/command-batches/${batch.id}/dispatch`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    if (alive.current) setDispatchOpen(false);
                },
                onFinish: () => {
                    if (alive.current) setDispatching(false);
                },
            },
        );
    };
    const meters = [
        {
            label: 'Selected',
            value: batch.summary.selected,
            caption: `${batch.summary.included} included · ${batch.summary.excluded} excluded · ${batch.summary.sites} Sites`,
            view: 'targets' as const,
        },
        {
            label: 'Awaiting approval',
            value: batch.summary.awaitingApproval,
            caption: 'Independent review required',
            view: 'review' as const,
        },
        {
            label: 'Ready',
            value: batch.summary.ready,
            caption: 'Ready for governed queue dispatch',
            view: 'ready' as const,
        },
        {
            label: 'In progress',
            value: batch.summary.queuedOrRunning,
            caption: 'Execution or reconciliation pending',
            view: 'results' as const,
            filter: 'in_progress',
        },
        {
            label: 'Reconciled',
            value: batch.summary.reconciled,
            caption: `${batch.summary.terminal} terminal child states; terminal does not mean success`,
            view: 'results' as const,
            filter: 'reconciled',
        },
        {
            label: 'Failed or blocked',
            value: batch.summary.failedOrBlocked,
            caption: 'Unsuccessful terminal children',
            view: 'results' as const,
            filter: 'attention',
        },
    ];
    const scoped = batch.targets.filter((target) => {
        if (view === 'excluded') return target.inclusionStatus === 'excluded';
        if (view === 'review')
            return target.command?.status === 'awaiting_approval';
        if (view === 'ready') return target.command?.status === 'ready';
        if (view === 'results') {
            if (resultFilter === 'reconciled')
                return target.command?.status === 'reconciled';
            if (resultFilter === 'attention')
                return unsuccessful.includes(target.command?.status ?? '');
            if (resultFilter === 'in_progress')
                return progressing.includes(target.command?.status ?? '');
            return target.command !== null;
        }
        return true;
    });
    const query = search.trim().toLocaleLowerCase();
    const targets = query
        ? scoped.filter((target) =>
              searchableText(target).toLocaleLowerCase().includes(query),
          )
        : scoped;
    const compact: EntityTableColumn<BatchTarget>[] = [
        {
            key: 'site',
            label: 'Site',
            width: '1fr',
            cell: (target) =>
                target.site ? (
                    <Link href={target.site.href}>{target.site.name}</Link>
                ) : (
                    'Site unavailable'
                ),
        },
        {
            key: 'state',
            label: 'Command state',
            width: '1fr',
            cell: (target) => (
                <EntityStatusChip variant={tone(targetStatus(target))}>
                    {humanise(targetStatus(target))}
                </EntityStatusChip>
            ),
        },
        {
            key: 'selection',
            label: 'Inclusion',
            width: '1fr',
            cell: (target) => (
                <EntityChip>{humanise(target.inclusionStatus)}</EntityChip>
            ),
        },
        {
            key: 'action',
            label: 'Next action',
            width: '1.8fr',
            cell: (target) => (
                <span className="block break-words whitespace-normal">
                    {target.command?.nextAction ??
                        target.safeExclusionReason ??
                        'Resolve the exclusion before creating a new request.'}
                </span>
            ),
        },
    ];
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${batch.label} - Device management`} />
            <div className="min-w-0 space-y-5">
                <PageHeader
                    variant="profile"
                    backHref={batch.workspaceHref}
                    icon={FileCheck2}
                    title={batch.label}
                    wrapTitle
                    titleChip={
                        <PageHeaderStatusChip variant={tone(batch.status)}>
                            {humanise(batch.status)}
                        </PageHeaderStatusChip>
                    }
                    subline={`Bulk command ${batch.uuid} · ${humanise(batch.risk)} risk`}
                    actions={
                        <>
                            {view !== 'overview' && (
                                <PageHeaderSearch
                                    value={search}
                                    onChange={setSearch}
                                    ariaLabel="Search batch targets"
                                    placeholder="Search these targets…"
                                />
                            )}
                            <PageHeaderGlassButton asChild icon={Download}>
                                <a href={batch.exportHref}>
                                    Download result ledger
                                </a>
                            </PageHeaderGlassButton>
                            <PageHeaderGlassButton
                                icon={RefreshCw}
                                onClick={refresh}
                            >
                                Refresh results
                            </PageHeaderGlassButton>
                            {canApprove ? (
                                <PageHeaderPrimaryButton
                                    icon={FileCheck2}
                                    onClick={() => setDecisionOpen(true)}
                                >
                                    Review {batch.summary.awaitingApproval}{' '}
                                    requests
                                </PageHeaderPrimaryButton>
                            ) : canDispatch ? (
                                <PageHeaderPrimaryButton
                                    icon={Send}
                                    onClick={() => setDispatchOpen(true)}
                                >
                                    Queue {batch.summary.ready} ready requests
                                </PageHeaderPrimaryButton>
                            ) : null}
                        </>
                    }
                    meters={meters.map((meter) => (
                        <PageHeaderMeterBlock
                            key={meter.label}
                            label={meter.label}
                            onClick={() => select(meter.view, meter.filter)}
                            ariaLabel={`View ${meter.label.toLowerCase()} targets`}
                        >
                            <PageHeaderMeterBig>
                                {meter.value}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {meter.caption}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    ))}
                    mobileSummary={`${humanise(batch.status)} · ${batch.summary.selected} selected targets`}
                    filters={
                        view === 'overview' ? (
                            <PageHeaderFilterButton
                                onClick={() => select('targets')}
                            >
                                Inspect selected targets
                            </PageHeaderFilterButton>
                        ) : (
                            <>
                                <PageHeaderFilterButton
                                    onClick={() => {
                                        setSearch('');
                                        setResultFilter('all');
                                    }}
                                >
                                    Reset filters
                                </PageHeaderFilterButton>
                                {view === 'results' && (
                                    <PageHeaderFilterSelect
                                        label="Result state"
                                        value={resultFilter}
                                        onChange={setResultFilter}
                                        options={[
                                            {
                                                value: 'all',
                                                label: 'All child requests',
                                            },
                                            {
                                                value: 'in_progress',
                                                label: 'In progress',
                                            },
                                            {
                                                value: 'reconciled',
                                                label: 'Reconciled',
                                            },
                                            {
                                                value: 'attention',
                                                label: 'Failed or blocked',
                                            },
                                        ]}
                                    />
                                )}
                                <PageHeaderViewToggle
                                    value={layout}
                                    onChange={setLayout}
                                    options={[
                                        {
                                            value: 'cards',
                                            label: 'Cards',
                                            icon: LayoutGrid,
                                        },
                                        {
                                            value: 'table',
                                            label: 'Table',
                                            icon: List,
                                        },
                                    ]}
                                />
                            </>
                        )
                    }
                    rail={
                        <PageHeaderRail
                            items={views}
                            value={view}
                            onSelect={select}
                            ariaLabel="Command batch sections"
                        />
                    }
                />
                <p className="text-sm text-muted-foreground">
                    Read started {time(batch.as_of)} · New Zealand time.
                    Retained results do not prove live Device state.
                </p>
                {notice && (
                    <Alert variant="warning" role="status">
                        <AlertTriangle />
                        <AlertDescription>{notice}</AlertDescription>
                    </Alert>
                )}
                {view === 'overview' ? (
                    <>
                        <ReviewCard
                            icon={ShieldCheck}
                            title="Request and impact"
                        >
                            <ReviewRow
                                label="Batch reference"
                                value={batch.uuid}
                            />
                            <ReviewRow
                                label="Capability"
                                value={humanise(batch.capability)}
                            />
                            <ReviewRow
                                label="Workspace"
                                value={
                                    <Link href={batch.workspaceHref}>
                                        {humanise(batch.workspace)}
                                    </Link>
                                }
                            />
                            <ReviewRow
                                label="Requested by"
                                value={batch.requestedBy ?? 'System'}
                            />
                            <ReviewRow
                                label="Requested"
                                value={time(batch.requestedAt)}
                            />
                            <ReviewRow
                                label="Confirmation mode"
                                value={humanise(batch.confirmationMode)}
                            />
                            <ReviewRow
                                label="Impact acknowledged"
                                value={time(batch.impactAcknowledgedAt)}
                            />
                            <ReviewRow
                                label="Operational reason"
                                value={batch.reason}
                            />
                            <ReviewRow
                                label="Possible impact"
                                value={batch.impact}
                            />
                            <ReviewRow
                                label="Expected state"
                                value={batch.expectedResult}
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={FileCheck2}
                            title="Recorded parameters"
                        >
                            <SafeSummary value={batch.safeParameters} />
                        </ReviewCard>
                        <ReviewCard
                            icon={ShieldCheck}
                            title="Independent child lifecycle"
                        >
                            <p className="text-sm text-muted-foreground">
                                {batch.partialSemantics}
                            </p>
                        </ReviewCard>
                        <div className="flex flex-wrap gap-3">
                            <Button asChild variant="outline">
                                <Link href={batch.workspaceHref}>
                                    <ArrowLeft className="size-4" />
                                    Management workspace
                                </Link>
                            </Button>
                            {canApprove && (
                                <Button onClick={() => setDecisionOpen(true)}>
                                    Review {batch.summary.awaitingApproval}{' '}
                                    requests
                                </Button>
                            )}
                            {canDispatch && (
                                <Button onClick={() => setDispatchOpen(true)}>
                                    Queue {batch.summary.ready} ready requests
                                </Button>
                            )}
                        </div>
                    </>
                ) : (
                    <EvidenceRecords
                        title={
                            views.find((item) => item.key === view)?.label ??
                            'Targets'
                        }
                        description="Each selected Device keeps its independent inclusion, child request, execution attempt and reconciliation evidence. Open evidence to review every retained field."
                        rows={targets}
                        view={layout}
                        total={scoped.length}
                        rowKey={(target) => target.id}
                        identity={(target) => ({
                            name: target.device.name,
                            icon: FileCheck2,
                            subline: `${target.device.uid} · target ${target.id}`,
                        })}
                        articleLabel={(target) =>
                            `Batch target ${target.id}: ${target.device.name}`
                        }
                        actionsFor={(target) => [
                            {
                                label: 'Open Device management',
                                icon: FileCheck2,
                                onClick: () => router.visit(target.device.href),
                            },
                            ...(target.site
                                ? [
                                      {
                                          label: 'Open Site',
                                          icon: Building2,
                                          onClick: () =>
                                              router.visit(target.site!.href),
                                      },
                                  ]
                                : []),
                        ]}
                        cardFor={(target) => ({
                            name: target.device.name,
                            icon: FileCheck2,
                            meridian:
                                tone(targetStatus(target)) === 'critical'
                                    ? 'critical'
                                    : target.command?.status === 'reconciled'
                                      ? 'success'
                                      : 'neutral',
                            subline: `${target.device.uid} · ${target.site?.name ?? 'Site unavailable'} · target ${target.id}`,
                            chips: (
                                <>
                                    <EntityStatusChip
                                        variant={tone(targetStatus(target))}
                                    >
                                        {humanise(targetStatus(target))}
                                    </EntityStatusChip>
                                    <EntityChip>
                                        {humanise(target.inclusionStatus)}
                                    </EntityChip>
                                    <EntityChip>
                                        {target.device.provider ?? 'Native'}
                                    </EntityChip>
                                </>
                            ),
                            alerts:
                                target.safeExclusionReason ||
                                target.command?.safeFailureReason ? (
                                    <EntityStatusChip
                                        variant={
                                            target.safeExclusionReason
                                                ? 'warning'
                                                : 'critical'
                                        }
                                    >
                                        {target.safeExclusionReason ??
                                            target.command?.safeFailureReason}
                                    </EntityStatusChip>
                                ) : null,
                            footer: {
                                primary:
                                    target.command?.nextAction ??
                                    'Resolve the exclusion before creating a new request.',
                                secondary:
                                    target.command?.uuid ?? 'No child command',
                                personName:
                                    target.command?.approvedBy ??
                                    target.command?.requestedBy,
                            },
                        })}
                        columns={evidenceColumns}
                        compactColumns={compact}
                        icon={FileCheck2}
                        empty={
                            search
                                ? 'No targets match this search'
                                : batch.targets.length === 0
                                  ? 'No selected targets were retained'
                                  : view === 'excluded'
                                    ? 'No excluded targets'
                                    : view === 'review'
                                      ? 'No child requests awaiting approval'
                                      : view === 'ready'
                                        ? 'No child requests ready to queue'
                                        : 'No targets in this result state'
                        }
                    />
                )}
                <Dialog
                    open={decisionOpen && canApprove}
                    onOpenChange={(open) => {
                        if (!submitting) setDecisionOpen(open);
                    }}
                >
                    <DialogContent
                        className="max-h-[90vh] overflow-y-auto"
                        style={{
                            width: 'min(92vw, 720px)',
                            maxWidth: 'min(92vw, 720px)',
                        }}
                    >
                        <DialogHeader>
                            <DialogTitle>
                                Review child command requests
                            </DialogTitle>
                            <DialogDescription>
                                Your decision is applied independently to each
                                child still awaiting approval. Changed, expired
                                or ineligible children remain unchanged and are
                                reported separately.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="space-y-4">
                            <div className="grid grid-cols-2 gap-2">
                                <Button
                                    type="button"
                                    variant={
                                        decision === 'approved'
                                            ? 'default'
                                            : 'outline'
                                    }
                                    onClick={() => setDecision('approved')}
                                    disabled={submitting}
                                >
                                    Approve
                                </Button>
                                <Button
                                    type="button"
                                    variant={
                                        decision === 'rejected'
                                            ? 'destructive'
                                            : 'outline'
                                    }
                                    onClick={() => setDecision('rejected')}
                                    disabled={submitting}
                                >
                                    Reject
                                </Button>
                            </div>
                            <div className="space-y-1.5">
                                <label
                                    htmlFor="batch-decision-comment"
                                    className="text-sm font-semibold"
                                >
                                    Decision comment
                                </label>
                                <Textarea
                                    id="batch-decision-comment"
                                    aria-describedby="batch-decision-comment-help"
                                    rows={4}
                                    maxLength={COMMENT_MAX}
                                    value={comment}
                                    onChange={(event) =>
                                        setComment(event.target.value)
                                    }
                                    disabled={submitting}
                                    placeholder="What did you verify across the target list, Site impact and expected state?"
                                />
                                <p
                                    id="batch-decision-comment-help"
                                    className="text-xs text-muted-foreground"
                                >
                                    Minimum 10 characters. Maximum{' '}
                                    {COMMENT_MAX.toLocaleString('en-NZ')}.{' '}
                                    {comment.trim().length} of{' '}
                                    {COMMENT_MAX.toLocaleString('en-NZ')}{' '}
                                    entered.
                                </p>
                            </div>
                        </div>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setDecisionOpen(false)}
                                disabled={submitting}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="button"
                                variant={
                                    decision === 'rejected'
                                        ? 'destructive'
                                        : 'default'
                                }
                                onClick={submitDecision}
                                disabled={
                                    submitting || comment.trim().length < 10
                                }
                            >
                                {submitting ? 'Recording…' : 'Record decision'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
                <ConfirmDialog
                    open={dispatchOpen && canDispatch}
                    onClose={() => {
                        if (!dispatching) setDispatchOpen(false);
                    }}
                    onConfirm={dispatch}
                    processing={dispatching}
                    title="Queue ready child requests?"
                    description={`Queue ${batch.summary.ready} independently governed child requests. Every Device is reauthorised and revalidated immediately before dispatch; changed targets close safely without execution.`}
                    confirmText={
                        dispatching ? 'Queueing…' : 'Queue ready requests'
                    }
                    variant="default"
                />
            </div>
        </AppLayout>
    );
}
