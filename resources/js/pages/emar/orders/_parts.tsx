import { ConfirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { formatDateOnly, formatDateTime, toDateInput } from '@/lib/datetime';
import { useState, type ReactNode } from 'react';
import type { Order } from './_types';

export function Field({
    id,
    label,
    children,
    error,
}: {
    id: string;
    label: string;
    children: ReactNode;
    error?: string;
}) {
    return (
        <div id={`${id}-field`} className="grid gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}
export function Note({ children }: { children: ReactNode }) {
    return (
        <div className="rounded-xl border bg-muted/40 p-3 text-sm text-muted-foreground">
            {children}
        </div>
    );
}
export function Errors({ errors }: { errors: Record<string, string> }) {
    const messages = [...new Set(Object.values(errors).filter(Boolean))];
    return messages.length ? (
        <div
            role="alert"
            className="rounded-xl border border-status-critical/30 bg-status-critical-bg p-3"
        >
            <p className="font-semibold">Please check these details</p>
            <ul className="list-inside list-disc text-sm">
                {messages.map((message) => (
                    <li key={message}>{message}</li>
                ))}
            </ul>
        </div>
    ) : null;
}
export function orderStatus(order: Order): {
    label: string;
    variant: StatusVariant;
    line: string;
} {
    const pending = order.pending;
    const checked = order.current;
    if (order.state === 'ceased')
        return {
            label: 'Stopped',
            variant: 'neutral',
            line:
                order.ceased_reason ?? 'Stop evidence is kept in the history.',
        };
    if (order.state === 'paused')
        return {
            label: 'Held',
            variant: 'warning',
            line: 'Doses are paused. The prescriber’s instruction is in the history.',
        };
    if (
        pending?.status === 'pending' &&
        pending.version.source_evidence.allergy_matches?.length &&
        !pending.allergy_confirmation
    )
        return {
            label: 'Prescriber must confirm',
            variant: 'critical',
            line: 'This version matches a recorded allergy. The prescriber confirms it is safe before it can be checked.',
        };
    if (pending?.status === 'sent_back')
        return {
            label: 'Sent back',
            variant: 'warning',
            line: `${pending.rejection_reason}. ${order.approval_status === 'verified' ? `Staff give version ${order.version} until a change is checked.` : 'This new order cannot be given.'}`,
        };
    if (pending)
        return {
            label: 'Waiting to be checked',
            variant: 'warning',
            line:
                order.approval_status === 'verified'
                    ? `Version ${pending.version.version_number} is waiting — staff give version ${order.version} until then.`
                    : 'A new order cannot be given until it is checked.',
        };
    if (order.approval_status !== 'verified')
        return {
            label: 'Waiting to be checked',
            variant: 'warning',
            line: 'This existing chart entry still needs its order check.',
        };
    if (checked?.second_due_at && !checked.second_checked_at)
        return {
            label: 'Second check due',
            variant: 'warning',
            line: `Checked alone. Another lead checks it by ${formatDateTime(checked.second_due_at)}.`,
        };
    if (checked?.written_due_at && !checked.written_confirmation)
        return {
            label: 'Written confirmation due',
            variant: 'warning',
            line: `Attach the prescriber’s confirmation by ${formatDateTime(checked.written_due_at)}.`,
        };
    if (order.end_date) {
        const today = toDateInput(new Date());
        const days = Math.round(
            (Date.parse(`${order.end_date}T00:00:00Z`) -
                Date.parse(`${today}T00:00:00Z`)) /
                86400000,
        );
        if (days < 0)
            return {
                label: 'Ended',
                variant: 'neutral',
                line: `Last day was ${formatDateOnly(order.end_date)}. This course cannot be given.`,
            };
        if (days <= 14)
            return {
                label: 'Ending soon',
                variant: 'warning',
                line: `Ends ${formatDateOnly(order.end_date)}. The last NZ day is included.`,
            };
        return {
            label: 'Checked',
            variant: 'success',
            line: `Ends ${formatDateOnly(order.end_date)}. The last NZ day is included.`,
        };
    }
    return { label: 'Checked', variant: 'success', line: '' };
}
export function OrderStatus({ order }: { order: Order }) {
    const state = orderStatus(order);
    return (
        <div className="grid gap-1">
            <StatusBadge variant={state.variant}>{state.label}</StatusBadge>
            {state.line && (
                <p className="text-caption max-w-72 text-muted-foreground">
                    {state.line}
                </p>
            )}
        </div>
    );
}
export function useDraftClose(
    dirty: boolean,
    processing: boolean,
    close: () => void,
) {
    const [confirm, setConfirm] = useState(false);
    return {
        close: () => {
            if (!processing) {
                if (dirty) setConfirm(true);
                else close();
            }
        },
        confirm: (
            <ConfirmDialog
                frontline
                open={confirm}
                onClose={() => setConfirm(false)}
                onConfirm={close}
                title="Discard this draft?"
                description="Your saved orders are kept. The unsaved details in this draft will be discarded."
                confirmText="Discard draft"
                cancelText="Keep editing"
            />
        ),
    };
}
export function SourceFiles({
    files,
}: {
    files: { id: number; file_name: string; purpose: string }[];
}) {
    return (
        <div className="grid gap-2">
            {files.map((file) => (
                <div
                    key={file.id}
                    className="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-2"
                >
                    <span className="min-w-0 text-sm break-all">
                        {file.file_name}
                    </span>
                    <span className="flex gap-2">
                        <Button asChild variant="outline" size="sm">
                            <a
                                href={`/emar/order-files/${file.id}`}
                                target="_blank"
                                rel="noreferrer"
                            >
                                View
                            </a>
                        </Button>
                        <Button asChild variant="ghost" size="sm">
                            <a href={`/emar/order-files/${file.id}?download=1`}>
                                Download
                            </a>
                        </Button>
                    </span>
                </div>
            ))}
        </div>
    );
}
