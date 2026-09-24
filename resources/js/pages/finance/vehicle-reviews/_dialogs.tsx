import { formatMoney } from '@/components/finance';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { TilePicker } from '@/components/wizard/primitives';
import { formatDateTime } from '@/lib/datetime';
import { useForm } from '@inertiajs/react';
import {
    Car,
    CheckCircle2,
    FileText,
    Loader2,
    XCircle,
    type LucideIcon,
} from 'lucide-react';
import { useState, type FormEvent } from 'react';

export type ReviewFile = {
    id: number;
    name: string | null;
    url: string | null;
    waiting: boolean;
};

export type ReviewRequest = {
    id: number;
    reference: string | null;
    type_label: string;
    status: 'submitted' | 'resolved' | 'declined';
    status_label: string;
    tone: 'success' | 'warning' | 'neutral';
    vehicle: {
        id: number;
        name: string;
        registration: string | null;
        site: string | null;
        url: string | null;
    };
    amount: number | null;
    note: string | null;
    source: string | null;
    requested_by: string | null;
    requested_at: string | null;
    decided_by: string | null;
    decided_at: string | null;
    decision_note: string | null;
    lock_version: number;
    files: ReviewFile[];
    history: Array<{
        id: number;
        label: string;
        actor: string | null;
        note: string | null;
        occurred_at: string | null;
    }>;
    own_request: boolean;
    can_decide: boolean;
};

type Decision = 'resolved' | 'declined';

const DECISIONS: Array<{
    value: Decision;
    label: string;
    detail: string;
    icon: LucideIcon;
}> = [
    {
        value: 'resolved',
        label: 'Resolve',
        detail: 'Finance has dealt with it, or will through its own records.',
        icon: CheckCircle2,
    },
    {
        value: 'declined',
        label: 'Decline',
        detail: 'Not a Finance matter, or not agreed. Say what happens next.',
        icon: XCircle,
    },
];

export const vehicleLabel = (request: ReviewRequest) =>
    [request.vehicle.name, request.vehicle.registration, request.vehicle.site]
        .filter(Boolean)
        .join(' · ');

/** One review request: what Fleet asked, its evidence, and Finance's decision. */
export function ReviewRequestDialog({
    request,
    onClose,
}: {
    request: ReviewRequest | null;
    onClose: () => void;
}) {
    return (
        <Dialog
            open={request !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent
                className="max-h-[90vh] overflow-y-auto"
                style={{
                    maxWidth: 'min(92vw, 720px)',
                    width: 'min(92vw, 720px)',
                }}
            >
                {request && (
                    <ReviewRequestBody
                        key={`${request.id}-${request.lock_version}`}
                        request={request}
                        onClose={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function ReviewRequestBody({
    request,
    onClose,
}: {
    request: ReviewRequest;
    onClose: () => void;
}) {
    const [requestKey] = useState(() => crypto.randomUUID());
    const form = useForm<{
        decision: Decision | '';
        note: string;
        expected_version: number;
        request_key: string;
    }>({
        decision: '',
        note: '',
        expected_version: request.lock_version,
        request_key: requestKey,
    });
    const open = request.status === 'submitted';

    const submit = (event: FormEvent) => {
        event.preventDefault();
        // Show every missing field at once; the server checks them again.
        const missing: Partial<Record<'decision' | 'note', string>> = {};
        if (!form.data.decision) {
            missing.decision =
                'Choose whether to resolve or decline the request.';
        }
        if (!form.data.note.trim()) {
            missing.note = 'Record the Finance decision and any next step.';
        }
        if (Object.keys(missing).length > 0) {
            form.setError(missing);
            return;
        }
        form.post(`/finance/vehicle-reviews/${request.id}/decision`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <form onSubmit={submit}>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <Car className="h-4 w-4 text-primary" aria-hidden />
                    {request.type_label}
                    {request.reference ? ` · ${request.reference}` : ''}
                </DialogTitle>
                <DialogDescription>
                    {open
                        ? 'Record Finance’s decision with a note. It changes no Finance record by itself; the requester sees it on the vehicle.'
                        : 'Finance’s decision on this request, kept with its history.'}
                </DialogDescription>
            </DialogHeader>

            <div className="mt-3 grid gap-3">
                <div className="flex items-start gap-3 rounded-xl border border-primary/40 bg-primary/10 p-3">
                    <span className="mt-0.5 shrink-0 rounded-lg bg-background/60 p-1.5">
                        <Car className="h-4 w-4 text-primary" aria-hidden />
                    </span>
                    <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="text-sm font-medium">
                                {vehicleLabel(request)}
                            </span>
                            <StatusBadge variant={request.tone}>
                                {request.status_label}
                            </StatusBadge>
                        </div>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            Requested by {request.requested_by ?? 'Fleet'}
                            {request.requested_at
                                ? ` · ${formatDateTime(request.requested_at)}`
                                : ''}
                        </p>
                    </div>
                </div>

                <dl className="grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt className="text-xs text-muted-foreground">
                            Amount
                        </dt>
                        <dd className="font-medium tabular-nums">
                            {request.amount === null
                                ? 'Not given'
                                : formatMoney(request.amount)}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-xs text-muted-foreground">About</dt>
                        <dd className="font-medium">
                            {request.source ?? 'The vehicle'}
                        </dd>
                    </div>
                    <div className="sm:col-span-2">
                        <dt className="text-xs text-muted-foreground">
                            What Fleet asked
                        </dt>
                        <dd className="whitespace-pre-line">
                            {request.note ?? 'No note recorded.'}
                        </dd>
                    </div>
                </dl>

                <section aria-label="Evidence">
                    <h3 className="text-xs text-muted-foreground">Evidence</h3>
                    {request.files.length === 0 ? (
                        <p className="mt-1 text-sm text-muted-foreground">
                            No files were attached.
                        </p>
                    ) : (
                        <ul className="mt-1 grid gap-1.5">
                            {request.files.map((file) => (
                                <li
                                    key={file.id}
                                    className="flex items-center gap-2 text-sm"
                                >
                                    <FileText
                                        className="h-4 w-4 shrink-0 text-muted-foreground"
                                        aria-hidden
                                    />
                                    {file.url ? (
                                        <a
                                            href={file.url}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="truncate text-primary underline-offset-4 hover:underline"
                                        >
                                            {file.name ?? 'File'}
                                        </a>
                                    ) : (
                                        <span className="truncate">
                                            {file.name ?? 'File'}
                                        </span>
                                    )}
                                    {!file.url && (
                                        <span className="text-xs text-muted-foreground">
                                            {file.waiting
                                                ? 'Waiting for its virus check'
                                                : 'Can’t be opened'}
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                {!open && (
                    <section
                        aria-label="Decision"
                        className="rounded-xl border border-border p-3 text-sm"
                    >
                        <p className="font-medium">
                            {request.status_label}
                            {request.decided_by
                                ? ` by ${request.decided_by}`
                                : ''}
                            {request.decided_at
                                ? ` · ${formatDateTime(request.decided_at)}`
                                : ''}
                        </p>
                        {request.decision_note && (
                            <p className="mt-1 whitespace-pre-line text-muted-foreground">
                                {request.decision_note}
                            </p>
                        )}
                    </section>
                )}

                {open && request.own_request && (
                    <p className="rounded-xl border border-border p-3 text-sm text-muted-foreground">
                        You asked for this review, so someone else in Finance
                        decides it.
                    </p>
                )}

                {request.can_decide && (
                    <>
                        <fieldset>
                            <legend className="text-sm font-medium">
                                Decision{' '}
                                <span className="text-status-critical">*</span>
                            </legend>
                            <div className="mt-1.5">
                                <TilePicker
                                    value={form.data.decision}
                                    onChange={(value) => {
                                        form.setData(
                                            'decision',
                                            value as Decision,
                                        );
                                        form.clearErrors('decision');
                                    }}
                                    options={DECISIONS.map((option) => ({
                                        key: option.value,
                                        label: option.label,
                                        description: option.detail,
                                        icon: option.icon,
                                    }))}
                                />
                            </div>
                            <InputError
                                className="mt-1 text-xs"
                                message={form.errors.decision}
                            />
                        </fieldset>
                        <div>
                            <Label htmlFor="vehicle-review-note">
                                Decision note{' '}
                                <span className="text-status-critical">*</span>
                            </Label>
                            <Textarea
                                id="vehicle-review-note"
                                className="mt-1.5"
                                rows={3}
                                maxLength={5000}
                                placeholder="e.g. Credit note requested from the supplier; the bill is on hold until it arrives."
                                value={form.data.note}
                                aria-invalid={Boolean(form.errors.note)}
                                onChange={(event) => {
                                    form.setData('note', event.target.value);
                                    form.clearErrors('note');
                                }}
                            />
                            <InputError
                                className="mt-1 text-xs"
                                message={form.errors.note}
                            />
                        </div>
                    </>
                )}

                {request.history.length > 0 && (
                    <section aria-label="History">
                        <h3 className="text-xs text-muted-foreground">
                            History
                        </h3>
                        <ol className="mt-1 grid gap-1 text-sm">
                            {request.history.map((entry) => (
                                <li key={entry.id}>
                                    <span className="font-medium">
                                        {entry.label}
                                    </span>
                                    {entry.actor ? ` · ${entry.actor}` : ''}
                                    {entry.occurred_at
                                        ? ` · ${formatDateTime(entry.occurred_at)}`
                                        : ''}
                                    {entry.note && (
                                        <span className="block text-xs text-muted-foreground">
                                            {entry.note}
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ol>
                    </section>
                )}
            </div>

            <DialogFooter className="mt-4">
                <Button type="button" variant="outline" onClick={onClose}>
                    {request.can_decide ? 'Cancel' : 'Close'}
                </Button>
                {request.vehicle.url && (
                    <Button type="button" variant="outline" asChild>
                        <a href={request.vehicle.url}>Open vehicle</a>
                    </Button>
                )}
                {request.can_decide && (
                    <Button type="submit" disabled={form.processing}>
                        {form.processing && (
                            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                        )}
                        Record decision
                    </Button>
                )}
            </DialogFooter>
        </form>
    );
}
