import { ConfirmDialog } from '@/components/confirm-dialog';
import { PageHero } from '@/components/page';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
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
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/datetime';
import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    CheckCircle2,
    Download,
    FileCheck2,
    RefreshCw,
    Send,
    ShieldCheck,
    TriangleAlert,
    XCircle,
} from 'lucide-react';
import { useEffect, useState } from 'react';

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

type BatchPayload = {
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

function humanise(value: string): string {
    return value.replaceAll('_', ' ').replaceAll('.', ' ');
}

function statusTone(status: string): string {
    if (status === 'reconciled' || status === 'completed')
        return 'border-status-success/30 text-status-success';
    if (
        ['failed', 'blocked', 'rejected', 'mismatch', 'expired'].includes(
            status,
        )
    )
        return 'border-destructive/30 text-destructive';
    if (
        [
            'executing',
            'queued',
            'dispatching',
            'running',
            'reconciling',
        ].includes(status)
    )
        return 'border-primary/30 text-primary';

    return 'border-status-warning/30 text-status-warning';
}

function SafeSummary({ value }: { value: unknown }) {
    if (value === null || value === undefined || value === '') return null;
    if (typeof value === 'string') {
        return <p className="text-xs text-muted-foreground">{value}</p>;
    }
    if (typeof value !== 'object') {
        return <p className="text-xs text-muted-foreground">{String(value)}</p>;
    }
    const entries = Object.entries(value as Record<string, unknown>);
    if (entries.length === 0) return null;

    return (
        <dl className="grid gap-x-3 gap-y-1 text-xs sm:grid-cols-2">
            {entries.map(([key, entry]) => (
                <div key={key} className="flex gap-1">
                    <dt className="font-semibold">{humanise(key)}:</dt>
                    <dd className="text-muted-foreground">
                        {typeof entry === 'object'
                            ? JSON.stringify(entry)
                            : String(entry)}
                    </dd>
                </div>
            ))}
        </dl>
    );
}

export default function DeviceCommandBatchShow({
    batch,
}: {
    batch: BatchPayload;
}) {
    const [decisionOpen, setDecisionOpen] = useState(false);
    const [decision, setDecision] = useState<'approved' | 'rejected'>(
        'approved',
    );
    const [comment, setComment] = useState('');
    const [dispatchOpen, setDispatchOpen] = useState(false);
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        if (batch.status !== 'executing') return;

        const refresh = window.setInterval(
            () => router.reload({ only: ['batch'] }),
            10_000,
        );

        return () => window.clearInterval(refresh);
    }, [batch.status]);

    const submitDecision = () => {
        setSubmitting(true);
        router.post(
            `/security-devices/command-batches/${batch.id}/decision`,
            { decision, comment },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setDecisionOpen(false);
                    setComment('');
                },
                onFinish: () => setSubmitting(false),
            },
        );
    };

    const dispatch = () => {
        router.post(
            `/security-devices/command-batches/${batch.id}/dispatch`,
            {},
            { preserveScroll: true },
        );
    };

    const summaryCards = [
        ['Selected', batch.summary.selected],
        ['Included', batch.summary.included],
        ['Excluded', batch.summary.excluded],
        ['Reconciled', batch.summary.reconciled],
        ['Awaiting approval', batch.summary.awaitingApproval],
        ['Ready', batch.summary.ready],
        ['Executing', batch.summary.queuedOrRunning],
        ['Failed or blocked', batch.summary.failedOrBlocked],
    ];

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Security & Devices', href: '/security-devices' },
                { title: 'Management', href: batch.workspaceHref },
                {
                    title: batch.label,
                    href: `/security-devices/command-batches/${batch.id}`,
                },
            ]}
        >
            <Head title={`${batch.label} - Device management`} />
            <PageShell>
                <PageHero
                    variant="compact"
                    title={
                        <span className="flex items-center gap-3">
                            <FileCheck2 className="h-6 w-6 text-primary" />
                            {batch.label}
                        </span>
                    }
                    description={`Bulk command ${batch.uuid}`}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button asChild variant="outline" size="sm">
                                <Link href={batch.workspaceHref}>
                                    <ArrowLeft className="mr-2 h-4 w-4" />
                                    Management workspace
                                </Link>
                            </Button>
                            <Button asChild variant="outline" size="sm">
                                <a href={batch.exportHref}>
                                    <Download className="mr-2 h-4 w-4" />
                                    Download result ledger
                                </a>
                            </Button>
                        </div>
                    }
                />

                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    {summaryCards.map(([label, value]) => (
                        <Card key={label}>
                            <CardContent className="p-4">
                                <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                    {label}
                                </p>
                                <p className="mt-1 text-2xl font-bold">
                                    {value}
                                </p>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                <Card>
                    <CardHeader className="pb-3">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <CardTitle>Governance contract</CardTitle>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Requested by {batch.requestedBy ?? 'System'}{' '}
                                    at {formatDateTime(batch.requestedAt)}
                                </p>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <Badge
                                    variant="outline"
                                    className={statusTone(batch.status)}
                                >
                                    {humanise(batch.status)}
                                </Badge>
                                <Badge variant="outline">
                                    {humanise(batch.risk)} risk
                                </Badge>
                                <Badge variant="outline">
                                    {batch.summary.sites} Sites
                                </Badge>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <dl className="grid gap-4 text-sm lg:grid-cols-3">
                            <div>
                                <dt className="font-semibold">
                                    Operational reason
                                </dt>
                                <dd className="mt-1 text-muted-foreground">
                                    {batch.reason}
                                </dd>
                            </div>
                            <div>
                                <dt className="font-semibold">
                                    Possible impact
                                </dt>
                                <dd className="mt-1 text-muted-foreground">
                                    {batch.impact}
                                </dd>
                            </div>
                            <div>
                                <dt className="font-semibold">
                                    Expected state
                                </dt>
                                <dd className="mt-1 text-muted-foreground">
                                    {batch.expectedResult}
                                </dd>
                            </div>
                        </dl>
                        <SafeSummary value={batch.safeParameters} />
                        <div className="rounded-lg border bg-muted/20 p-3 text-sm">
                            <p className="flex items-center gap-2 font-semibold">
                                <ShieldCheck className="h-4 w-4" />
                                Independent child lifecycle
                            </p>
                            <p className="mt-1 text-muted-foreground">
                                {batch.partialSemantics}
                            </p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() =>
                                    router.reload({ only: ['batch'] })
                                }
                            >
                                <RefreshCw className="mr-2 h-4 w-4" />
                                Refresh results
                            </Button>
                            {batch.canApprove ? (
                                <Button
                                    type="button"
                                    onClick={() => setDecisionOpen(true)}
                                >
                                    <FileCheck2 className="mr-2 h-4 w-4" />
                                    Review {batch.summary.awaitingApproval}{' '}
                                    requests
                                </Button>
                            ) : null}
                            {batch.canDispatch ? (
                                <Button
                                    type="button"
                                    onClick={() => setDispatchOpen(true)}
                                >
                                    <Send className="mr-2 h-4 w-4" />
                                    Queue {batch.summary.ready} ready requests
                                </Button>
                            ) : null}
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Per-Device results</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto rounded-xl border">
                            <table className="w-full min-w-[68rem] text-left text-sm">
                                <thead className="bg-muted/70 text-xs text-muted-foreground">
                                    <tr>
                                        <th className="px-3 py-3">Device</th>
                                        <th className="px-3 py-3">Site</th>
                                        <th className="px-3 py-3">Inclusion</th>
                                        <th className="px-3 py-3">Command</th>
                                        <th className="px-3 py-3">
                                            Latest evidence
                                        </th>
                                        <th className="px-3 py-3">
                                            Next action
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {batch.targets.map((target) => {
                                        const status =
                                            target.command?.status ??
                                            'excluded';
                                        const successful =
                                            status === 'reconciled';

                                        return (
                                            <tr key={target.id}>
                                                <td className="px-3 py-3 align-top">
                                                    <Link
                                                        href={
                                                            target.device.href
                                                        }
                                                        className="frontline-focus font-semibold text-primary hover:underline"
                                                    >
                                                        {target.device.name}
                                                    </Link>
                                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                                        {target.device.uid} ·{' '}
                                                        {target.device
                                                            .provider ??
                                                            'Native'}
                                                    </p>
                                                </td>
                                                <td className="px-3 py-3 align-top">
                                                    {target.site ? (
                                                        <Link
                                                            href={
                                                                target.site.href
                                                            }
                                                            className="frontline-focus rounded-sm text-primary hover:underline"
                                                        >
                                                            {target.site.name}
                                                        </Link>
                                                    ) : (
                                                        'Site unavailable'
                                                    )}
                                                </td>
                                                <td className="px-3 py-3 align-top">
                                                    <div className="flex items-center gap-2">
                                                        {target.inclusionStatus ===
                                                        'included' ? (
                                                            <CheckCircle2 className="h-4 w-4 text-status-success" />
                                                        ) : (
                                                            <XCircle className="h-4 w-4 text-muted-foreground" />
                                                        )}
                                                        <span className="font-medium">
                                                            {humanise(
                                                                target.inclusionStatus,
                                                            )}
                                                        </span>
                                                    </div>
                                                    {target.safeExclusionReason ? (
                                                        <p className="mt-1 max-w-xs text-xs text-muted-foreground">
                                                            {
                                                                target.safeExclusionReason
                                                            }
                                                        </p>
                                                    ) : null}
                                                </td>
                                                <td className="px-3 py-3 align-top">
                                                    <Badge
                                                        variant="outline"
                                                        className={statusTone(
                                                            status,
                                                        )}
                                                    >
                                                        {humanise(status)}
                                                    </Badge>
                                                    {target.command
                                                        ?.approvedBy ? (
                                                        <p className="mt-1 text-xs text-muted-foreground">
                                                            Approved by{' '}
                                                            {
                                                                target.command
                                                                    .approvedBy
                                                            }
                                                        </p>
                                                    ) : null}
                                                </td>
                                                <td className="px-3 py-3 align-top">
                                                    {target.command
                                                        ?.latestReconciliation ? (
                                                        <div className="space-y-1">
                                                            <p className="flex items-center gap-2 font-medium">
                                                                {successful ? (
                                                                    <CheckCircle2 className="h-4 w-4 text-status-success" />
                                                                ) : (
                                                                    <TriangleAlert className="h-4 w-4 text-status-warning" />
                                                                )}
                                                                {humanise(
                                                                    target
                                                                        .command
                                                                        .latestReconciliation
                                                                        .outcome,
                                                                )}
                                                            </p>
                                                            <SafeSummary
                                                                value={
                                                                    target
                                                                        .command
                                                                        .latestReconciliation
                                                                        .safeEvidenceSummary
                                                                }
                                                            />
                                                        </div>
                                                    ) : target.command
                                                          ?.latestAttempt ? (
                                                        <SafeSummary
                                                            value={
                                                                target.command
                                                                    .latestAttempt
                                                                    .safeResult
                                                            }
                                                        />
                                                    ) : (
                                                        <span className="text-xs text-muted-foreground">
                                                            No execution
                                                            evidence
                                                        </span>
                                                    )}
                                                    {target.command
                                                        ?.safeFailureReason ? (
                                                        <p className="mt-1 max-w-xs text-xs text-destructive">
                                                            {
                                                                target.command
                                                                    .safeFailureReason
                                                            }
                                                        </p>
                                                    ) : null}
                                                </td>
                                                <td className="px-3 py-3 align-top">
                                                    {target.command ? (
                                                        <div className="mb-2">
                                                            <p className="text-xs font-semibold">
                                                                Expected state
                                                            </p>
                                                            <SafeSummary
                                                                value={
                                                                    target
                                                                        .command
                                                                        .expectedState
                                                                }
                                                            />
                                                        </div>
                                                    ) : null}
                                                    <p className="max-w-sm text-xs text-muted-foreground">
                                                        {target.command
                                                            ?.nextAction ??
                                                            'Resolve the exclusion before creating a new request.'}
                                                    </p>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>
            </PageShell>

            <Dialog open={decisionOpen} onOpenChange={setDecisionOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Review child command requests</DialogTitle>
                        <DialogDescription>
                            Your decision is applied independently to each child
                            still awaiting approval. Changed, expired or
                            ineligible children remain unchanged and are
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
                            <textarea
                                id="batch-decision-comment"
                                aria-describedby="batch-decision-comment-help"
                                rows={4}
                                value={comment}
                                onChange={(event) =>
                                    setComment(event.target.value)
                                }
                                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                placeholder="What did you verify across the target list, Site impact and expected state?"
                            />
                            <p
                                id="batch-decision-comment-help"
                                className="text-xs text-muted-foreground"
                            >
                                Minimum 10 characters. {comment.trim().length}{' '}
                                entered.
                            </p>
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setDecisionOpen(false)}
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
                            disabled={submitting || comment.trim().length < 10}
                        >
                            Record decision
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                open={dispatchOpen}
                onClose={() => setDispatchOpen(false)}
                onConfirm={dispatch}
                title="Queue ready child requests?"
                description={`Queue ${batch.summary.ready} independently governed child requests. Every Device is reauthorised and revalidated immediately before dispatch; changed targets close safely without execution.`}
                confirmText="Queue ready requests"
                variant="default"
            />
        </AppLayout>
    );
}
