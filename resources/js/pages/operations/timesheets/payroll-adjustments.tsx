import { ConfirmDialog } from '@/components/confirm-dialog';
import { PageHeader, PageHeaderGlassButton } from '@/components/page';
import PageShell from '@/components/page-shell';
import { usePayrollAdjustmentCommand } from '@/components/timesheets/use-payroll-adjustment-command';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { EmptyList } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import {
    formatDateOnly,
    formatDateTimeInZone,
    WORKER_TIMEZONE,
} from '@/lib/datetime';
import type { SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Check,
    ClipboardCheck,
    ExternalLink,
    Loader2,
    RefreshCw,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

export type PayrollAmendment = {
    id: number;
    timesheet_id: number;
    staff_name: string;
    client_name: string;
    site_name: string;
    work_date: string | null;
    original_values: Record<string, unknown>;
    proposed_values: Record<string, unknown>;
    reason: string | null;
    requested_by: string | null;
    reviewed_by: string | null;
    reviewed_at: string | null;
    payroll_reference: string | null;
    timesheet_url: string;
};
type Props = {
    canProcess: boolean;
    evidence?: { timezone: string };
    amendments: {
        data: PayrollAmendment[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
};
const queueUrl = '/operations/timesheets/payroll-adjustments';
const labels: Record<string, string> = {
    starts_at: 'Start',
    ends_at: 'Finish',
    break_minutes: 'Break (minutes)',
    total_hours: 'Recorded hours',
    mileage_km: 'Mileage (km)',
    notes: 'Notes',
    allowance_notes: 'Allowances',
    public_holiday: 'Public holiday',
    work_date: 'Work date',
    sleepover: 'Sleepover',
    on_call: 'On call',
    is_residential_billable: 'Residential billing',
};
export function adjustmentChanges(amendment: PayrollAmendment) {
    return Object.entries(amendment.proposed_values ?? {})
        .filter(
            ([key, value]) =>
                JSON.stringify(amendment.original_values?.[key] ?? null) !==
                JSON.stringify(value ?? null),
        )
        .map(([key, after]) => ({
            key,
            label: labels[key] ?? key.replaceAll('_', ' '),
            before: amendment.original_values?.[key],
            after,
        }));
}
export function adjustmentValue(
    key: string,
    value: unknown,
    timezone: string,
): string {
    if (value === null || value === undefined || value === '')
        return 'Not recorded';
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    if (typeof value === 'string' && key === 'work_date')
        return formatDateOnly(value, value);
    if (typeof value === 'string' && ['starts_at', 'ends_at'].includes(key))
        return formatDateTimeInZone(value, timezone);
    return typeof value === 'object' ? JSON.stringify(value) : String(value);
}
function ChangeDetails({
    amendment,
    timezone,
}: {
    amendment: PayrollAmendment;
    timezone: string;
}) {
    const changes = adjustmentChanges(amendment);
    return changes.length ? (
        <dl className="space-y-3">
            {changes.map((change) => (
                <div
                    key={change.key}
                    className="rounded-md border border-border p-3"
                >
                    <dt className="mb-2 font-semibold">{change.label}</dt>
                    <dd className="grid gap-3 sm:grid-cols-2">
                        <div>
                            <span className="block text-xs text-muted-foreground">
                                Original
                            </span>
                            <span className="[overflow-wrap:anywhere] whitespace-pre-wrap">
                                {adjustmentValue(
                                    change.key,
                                    change.before,
                                    timezone,
                                )}
                            </span>
                        </div>
                        <div>
                            <span className="block text-xs text-muted-foreground">
                                Approved correction
                            </span>
                            <span className="[overflow-wrap:anywhere] whitespace-pre-wrap">
                                {adjustmentValue(
                                    change.key,
                                    change.after,
                                    timezone,
                                )}
                            </span>
                        </div>
                    </dd>
                </div>
            ))}
        </dl>
    ) : (
        <p className="text-muted-foreground">No value changes recorded.</p>
    );
}
function AmendmentReview({
    amendment,
    current,
    canProcess,
    timezone,
    onClose,
    onReload,
    onPendingChange,
    onUnconfirmed,
    requiresRefresh,
}: {
    amendment: PayrollAmendment;
    current?: PayrollAmendment;
    canProcess: boolean;
    timezone: string;
    onClose: () => void;
    onReload: () => void;
    onPendingChange: (pending: boolean) => void;
    onUnconfirmed: (id: number) => void;
    requiresRefresh: boolean;
}) {
    const actor = Number(usePage<SharedData>().props.auth.user?.id ?? 0);
    const [initialActor] = useState(actor);
    const [confirm, setConfirm] = useState(false);
    const command = usePayrollAdjustmentCommand(
        `${actor}:${amendment.id}:${current?.timesheet_id ?? amendment.timesheet_id}`,
    );
    useEffect(() => {
        onPendingChange(command.pending);
        return () => onPendingChange(false);
    }, [command.pending, onPendingChange]);
    useEffect(() => {
        if (command.outcome?.status === 'unknown') onUnconfirmed(amendment.id);
    }, [command.outcome?.status, amendment.id, onUnconfirmed]);
    const currentMatches =
        current !== undefined &&
        JSON.stringify(current) === JSON.stringify(amendment);
    const allowed =
        actor > 0 && actor === initialActor && canProcess && currentMatches;
    const confirmed =
        command.outcome?.status === 'confirmed'
            ? command.outcome.receipt
            : null;
    const close = () => {
        if (!command.busy.current) onClose();
    };
    const save = () => {
        if (!allowed || command.busy.current || command.held.current) return;
        setConfirm(false);
        command.submit({
            actorId: initialActor,
            amendmentId: amendment.id,
            timesheetId: amendment.timesheet_id,
        });
    };
    return (
        <>
            <Dialog open onOpenChange={(open) => !open && close()}>
                <DialogContent
                    className="max-h-[90vh] overflow-y-auto [&_button]:min-h-[44px]"
                    style={{
                        width: 'min(92vw, 720px)',
                        maxWidth: 'min(92vw, 720px)',
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            Payroll adjustment #{amendment.id}
                        </DialogTitle>
                        <DialogDescription>
                            {amendment.staff_name} ·{' '}
                            {formatDateOnly(amendment.work_date)} ·{' '}
                            {amendment.site_name || 'Site unavailable'}
                        </DialogDescription>
                    </DialogHeader>
                    {confirmed ? (
                        <div role="status" className="space-y-3">
                            <Check className="size-7 text-status-success" />
                            <p className="font-semibold">
                                {confirmed.changed
                                    ? 'External processing recorded'
                                    : 'External processing was already recorded'}
                            </p>
                            <p>
                                Recorded{' '}
                                {formatDateTimeInZone(
                                    confirmed.applied_at,
                                    timezone,
                                )}
                                . This records the organisation’s handling of
                                the correction; payment status is managed
                                separately.
                            </p>
                            <Button onClick={close}>Done</Button>
                        </div>
                    ) : (
                        <div className="space-y-4 text-sm">
                            <p>
                                Check the approved correction against your
                                payroll system. Record processing only after it
                                has been handled externally.
                            </p>
                            <dl className="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <dt className="text-muted-foreground">
                                        Person supported
                                    </dt>
                                    <dd>
                                        {amendment.client_name ||
                                            'Not linked to an individual'}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        Payroll reference
                                    </dt>
                                    <dd className="[overflow-wrap:anywhere]">
                                        {amendment.payroll_reference ||
                                            'Not recorded'}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        Requested by
                                    </dt>
                                    <dd>
                                        {amendment.requested_by ||
                                            'Not recorded'}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        Approved
                                    </dt>
                                    <dd>
                                        {amendment.reviewed_by ||
                                            'Reviewer unavailable'}{' '}
                                        ·{' '}
                                        {formatDateTimeInZone(
                                            amendment.reviewed_at,
                                            timezone,
                                        )}
                                    </dd>
                                </div>
                            </dl>
                            <ChangeDetails
                                amendment={amendment}
                                timezone={timezone}
                            />
                            <div>
                                <h3 className="font-semibold">
                                    Reason for correction
                                </h3>
                                <p className="mt-1 [overflow-wrap:anywhere] whitespace-pre-wrap">
                                    {amendment.reason || 'No reason recorded'}
                                </p>
                            </div>
                            {!allowed &&
                                !command.pending &&
                                !command.outcome && (
                                    <p
                                        role="alert"
                                        className="text-status-warning"
                                    >
                                        This adjustment or your access has
                                        changed. Close this review and reload
                                        the queue before recording processing.
                                    </p>
                                )}
                            {(command.outcome?.status === 'unknown' ||
                                requiresRefresh) && (
                                <div
                                    role="alert"
                                    className="space-y-3 rounded-md border border-status-warning/40 p-3"
                                >
                                    <p>
                                        <AlertTriangle className="mr-2 inline size-4" />
                                        {command.outcome?.status === 'unknown'
                                            ? command.outcome.message
                                            : 'This adjustment has an unconfirmed processing attempt. Reload the queue before another attempt.'}
                                    </p>
                                    <Button
                                        variant="outline"
                                        onClick={onReload}
                                    >
                                        <RefreshCw className="size-4" />
                                        Reload pending adjustments
                                    </Button>
                                </div>
                            )}
                            <div className="flex flex-wrap justify-end gap-2 border-t pt-4">
                                <Button
                                    variant="outline"
                                    disabled={command.pending}
                                    onClick={() => {
                                        if (!command.busy.current)
                                            router.visit(
                                                amendment.timesheet_url,
                                            );
                                    }}
                                >
                                    <ExternalLink className="size-4" />
                                    View timesheet
                                </Button>
                                <Button
                                    variant="outline"
                                    onClick={close}
                                    disabled={command.pending}
                                >
                                    Close review
                                </Button>
                                <Button
                                    disabled={
                                        !allowed ||
                                        command.pending ||
                                        command.held.current
                                    }
                                    onClick={() => setConfirm(true)}
                                >
                                    {command.pending ? (
                                        <>
                                            <Loader2 className="size-4 animate-spin" />
                                            Recording…
                                        </>
                                    ) : (
                                        'Record external processing'
                                    )}
                                </Button>
                            </div>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
            <ConfirmDialog
                open={confirm}
                onClose={() => setConfirm(false)}
                onConfirm={save}
                variant="default"
                processing={false}
                frontline
                title="Record external processing?"
                confirmText="Record processing"
                description={
                    <p>
                        Confirm that adjustment #{amendment.id} for{' '}
                        {amendment.staff_name} has been handled in your payroll
                        system. This removes it from the pending queue. It does
                        not change the original timesheet or mark wages as paid.
                    </p>
                }
            />
        </>
    );
}
export default function PayrollAdjustmentsPending({
    amendments,
    canProcess = false,
    evidence,
}: Props) {
    const timezone = evidence?.timezone ?? WORKER_TIMEZONE;
    const actor = Number(usePage<SharedData>().props.auth.user?.id ?? 0);
    const [selection, setSelection] = useState<{
        actor: number;
        amendment: PayrollAmendment;
    } | null>(null);
    const selected = selection?.actor === actor ? selection.amendment : null;
    const setSelected = (amendment: PayrollAmendment | null) =>
        setSelection(amendment ? { actor, amendment } : null);
    useEffect(() => {
        if (selection && selection.actor !== actor) setSelection(null);
    }, [actor, selection]);
    const [loading, setLoading] = useState(false),
        [error, setError] = useState('');
    const busy = useRef(false);
    const [writePending, setWritePending] = useState(false);
    const [heldIds, setHeldIds] = useState<number[]>([]);
    const markUnconfirmed = useCallback(
        (id: number) =>
            setHeldIds((ids) => (ids.includes(id) ? ids : [...ids, id])),
        [],
    );
    const visit = (page: number, closeReview = false) => {
        if (busy.current || writePending) return;
        busy.current = true;
        setLoading(true);
        setError('');
        if (closeReview) setSelected(null);
        let received = false;
        const finish = () => {
            busy.current = false;
            setLoading(false);
            if (!received)
                setError(
                    'Could not reload the queue. The previous results are still shown. Try again.',
                );
        };
        try {
            router.get(
                queueUrl,
                { page },
                {
                    preserveScroll: true,
                    preserveState: true,
                    onSuccess: () => {
                        received = true;
                        setHeldIds([]);
                    },
                    onFinish: finish,
                },
            );
        } catch {
            finish();
        }
    };
    return (
        <AppLayout>
            <Head title="Payroll adjustments" />
            <PageShell>
                <PageHeader
                    variant="profile"
                    frontline
                    wrapTitle
                    backHref="/operations/timesheets"
                    icon={ClipboardCheck}
                    title="Payroll adjustments"
                    subline={`${amendments.total} pending in your permitted sites · Record corrections handled in your payroll system`}
                    actions={
                        <PageHeaderGlassButton
                            icon={RefreshCw}
                            disabled={loading || writePending}
                            onClick={() => visit(amendments.current_page)}
                        >
                            Reload queue
                        </PageHeaderGlassButton>
                    }
                />
                <p className="text-sm text-muted-foreground">
                    Approved corrections awaiting a record of external
                    processing. Recording this step does not mark a timesheet as
                    paid.
                </p>
                {error && (
                    <div
                        role="alert"
                        className="flex flex-wrap items-center gap-3 text-status-warning"
                    >
                        <p>{error}</p>
                        <Button
                            variant="outline"
                            disabled={loading || writePending}
                            onClick={() => visit(amendments.current_page)}
                        >
                            Retry loading
                        </Button>
                    </div>
                )}
                <section
                    aria-label="Pending payroll adjustments"
                    aria-busy={loading}
                >
                    {amendments.data.length === 0 ? (
                        <>
                            <EmptyList
                                icon={
                                    <Check className="size-8 text-status-success" />
                                }
                                title="No pending adjustments here"
                                heading="No pending adjustments here"
                                description="There are no pending adjustments on this page within your permitted sites. This does not confirm payment."
                            />
                            {amendments.current_page > 1 && (
                                <Button
                                    className="mt-3 min-h-[44px]"
                                    variant="outline"
                                    disabled={loading || writePending}
                                    onClick={() => visit(1)}
                                >
                                    Go to first page
                                </Button>
                            )}
                        </>
                    ) : (
                        <>
                            <div className="space-y-3 md:hidden">
                                {amendments.data.map((a) => (
                                    <Card
                                        key={a.id}
                                        className="space-y-3 p-4"
                                        aria-label={`Adjustment ${a.id}`}
                                    >
                                        <div>
                                            <h2 className="font-semibold">
                                                {a.staff_name}
                                            </h2>
                                            <p className="text-sm text-muted-foreground">
                                                {formatDateOnly(a.work_date)} ·{' '}
                                                {a.site_name ||
                                                    'Site unavailable'}
                                            </p>
                                        </div>
                                        <p className="text-sm">
                                            {adjustmentChanges(a).length}{' '}
                                            changed field
                                            {adjustmentChanges(a).length === 1
                                                ? ''
                                                : 's'}{' '}
                                            · Approved{' '}
                                            {formatDateTimeInZone(
                                                a.reviewed_at,
                                                timezone,
                                            )}
                                        </p>
                                        <p className="text-sm [overflow-wrap:anywhere] whitespace-pre-wrap">
                                            {a.reason || 'No reason recorded'}
                                        </p>
                                        <Button
                                            className="min-h-[44px]"
                                            variant="outline"
                                            disabled={loading || writePending}
                                            onClick={() => setSelected(a)}
                                        >
                                            Review adjustment #{a.id}
                                        </Button>
                                    </Card>
                                ))}
                            </div>
                            <div className="hidden overflow-x-auto rounded-xl border border-border md:block">
                                <table className="w-full text-left text-sm">
                                    <thead className="border-b bg-muted/40">
                                        <tr>
                                            <th className="p-3">
                                                Staff / work date
                                            </th>
                                            <th className="p-3">Site</th>
                                            <th className="p-3">
                                                Approved correction
                                            </th>
                                            <th className="p-3">
                                                Payroll reference
                                            </th>
                                            <th className="p-3">
                                                <span className="sr-only">
                                                    Actions
                                                </span>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {amendments.data.map((a) => (
                                            <tr key={a.id}>
                                                <td className="p-3 align-top">
                                                    <div className="font-medium">
                                                        {a.staff_name}
                                                    </div>
                                                    <div className="text-muted-foreground">
                                                        {formatDateOnly(
                                                            a.work_date,
                                                        )}
                                                    </div>
                                                </td>
                                                <td className="p-3 align-top">
                                                    {a.site_name ||
                                                        'Site unavailable'}
                                                </td>
                                                <td className="max-w-lg p-3 align-top">
                                                    <p className="[overflow-wrap:anywhere] whitespace-pre-wrap">
                                                        {a.reason ||
                                                            'No reason recorded'}
                                                    </p>
                                                    <p className="mt-1 text-xs text-muted-foreground">
                                                        {
                                                            adjustmentChanges(a)
                                                                .length
                                                        }{' '}
                                                        changed field
                                                        {adjustmentChanges(a)
                                                            .length === 1
                                                            ? ''
                                                            : 's'}{' '}
                                                        ·{' '}
                                                        {a.reviewed_by ||
                                                            'Reviewer unavailable'}{' '}
                                                        ·{' '}
                                                        {formatDateTimeInZone(
                                                            a.reviewed_at,
                                                            timezone,
                                                        )}
                                                    </p>
                                                </td>
                                                <td className="max-w-48 p-3 align-top">
                                                    {a.payroll_reference ? (
                                                        <Badge
                                                            variant="outline"
                                                            className="[overflow-wrap:anywhere] whitespace-normal"
                                                        >
                                                            {
                                                                a.payroll_reference
                                                            }
                                                        </Badge>
                                                    ) : (
                                                        'Not recorded'
                                                    )}
                                                </td>
                                                <td className="p-3 align-top">
                                                    <Button
                                                        className="min-h-[44px]"
                                                        variant="outline"
                                                        disabled={
                                                            loading ||
                                                            writePending
                                                        }
                                                        onClick={() =>
                                                            setSelected(a)
                                                        }
                                                    >
                                                        Review #{a.id}
                                                    </Button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </>
                    )}
                </section>
                <nav
                    aria-label="Adjustment pages"
                    className="flex flex-wrap items-center justify-between gap-3"
                >
                    <p className="text-sm text-muted-foreground">
                        Page {amendments.current_page} of {amendments.last_page}{' '}
                        · {amendments.total} pending in your permitted sites
                    </p>
                    <div className="flex gap-2">
                        <Button
                            className="min-h-[44px]"
                            variant="outline"
                            disabled={
                                loading ||
                                writePending ||
                                amendments.current_page <= 1
                            }
                            onClick={() => visit(amendments.current_page - 1)}
                        >
                            Previous
                        </Button>
                        <Button
                            className="min-h-[44px]"
                            variant="outline"
                            disabled={
                                loading ||
                                writePending ||
                                amendments.current_page >= amendments.last_page
                            }
                            onClick={() => visit(amendments.current_page + 1)}
                        >
                            Next
                        </Button>
                    </div>
                </nav>
                {selected && (
                    <AmendmentReview
                        key={selected.id}
                        amendment={selected}
                        current={amendments.data.find(
                            (a) => a.id === selected.id,
                        )}
                        canProcess={
                            canProcess &&
                            !error &&
                            !loading &&
                            !heldIds.includes(selected.id)
                        }
                        timezone={timezone}
                        onClose={() => setSelected(null)}
                        onReload={() => visit(amendments.current_page, true)}
                        onPendingChange={setWritePending}
                        requiresRefresh={heldIds.includes(selected.id)}
                        onUnconfirmed={markUnconfirmed}
                    />
                )}
            </PageShell>
        </AppLayout>
    );
}
