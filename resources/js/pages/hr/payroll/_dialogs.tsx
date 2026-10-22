import type { RequestPayload } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { Banknote, Landmark, Loader2, XCircle } from 'lucide-react';
import { useState, type ComponentType, type FormEvent } from 'react';

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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

/**
 * Net-pay bank evidence for a payroll run: accept & settle, record a bank
 * rejection, record the reconciliation.
 *
 * These used to be a chain of `window.prompt()` boxes: no labels, no
 * validation, and a blank or cancelled box part-way through dropped the whole
 * action without saying so (DESIGN.md "Browser prompt() / confirm() as a
 * form"). One dialog now owns all three, with labelled fields that follow the
 * server's rules (PayrollExportController payNet / rejectNetPay /
 * reconcileNetPay). The routes and payloads are unchanged.
 */
export type NetPayAction = 'settle' | 'reject' | 'reconcile';

export type NetPayRun = {
    id: number;
    /** e.g. "01 Oct 2026 – 14 Oct 2026" */
    periodLabel: string;
    /** Set once the bank's acceptance is on record; settle then reuses it. */
    acceptanceReference: string | null;
};

type FieldKey = 'reference' | 'reason' | 'evidence' | 'bank_transaction_id';
type FieldErrors = Partial<Record<FieldKey | 'form', string>>;

const COPY: Record<
    NetPayAction,
    {
        icon: ComponentType<{ className?: string }>;
        title: (accepted: boolean) => string;
        description: (accepted: boolean) => string;
        submit: (accepted: boolean) => string;
        destructive: boolean;
    }
> = {
    settle: {
        icon: Banknote,
        title: (accepted) =>
            accepted
                ? 'Settle this net pay?'
                : 'Record bank acceptance and settle?',
        description: (accepted) =>
            accepted
                ? 'The bank has already accepted this payment file. Settling posts the net pay to the ledger and marks the timesheets in this run as paid.'
                : 'Use this once the bank has accepted the payment file. It records the acceptance, posts the net pay to the ledger and marks the timesheets in this run as paid.',
        submit: (accepted) =>
            accepted ? 'Settle net pay' : 'Record acceptance and settle',
        destructive: false,
    },
    reject: {
        icon: XCircle,
        title: () => 'Record a bank rejection?',
        description: () =>
            'Use this when the bank has refused the payment file. Nothing is posted to the ledger and no one is marked as paid. This file can no longer be accepted; prepare a new bank file after fixing the problem.',
        submit: () => 'Record rejection',
        destructive: true,
    },
    reconcile: {
        icon: Landmark,
        title: () => 'Record the bank reconciliation?',
        description: () =>
            'Match this settled net pay to the payment on the bank statement. The amount must match the cleared bank transaction exactly.',
        submit: () => 'Record reconciliation',
        destructive: false,
    },
};

const SERVER_FIELD: Record<string, FieldKey | 'form'> = {
    acceptance_reference: 'reference',
    acceptance_evidence: 'evidence',
    reference: 'reference',
    reason: 'reason',
    evidence: 'evidence',
    bank_transaction_id: 'bank_transaction_id',
    idempotency_key: 'form',
    net_pay: 'form',
};

export function NetPayEvidenceDialog({
    action,
    run,
    onClose,
}: {
    /** `null` keeps the dialog closed. */
    action: NetPayAction | null;
    run: NetPayRun | null;
    onClose: () => void;
}) {
    const open = action !== null && run !== null;
    // Lives here so Escape / outside clicks can't close mid-request.
    const [processing, setProcessing] = useState(false);

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next && !processing) onClose();
            }}
        >
            <DialogContent
                className="max-h-[90vh] overflow-y-auto"
                style={{
                    maxWidth: 'min(92vw, 560px)',
                    width: 'min(92vw, 560px)',
                }}
            >
                {open && (
                    <NetPayEvidenceBody
                        key={`${action}:${run.id}`}
                        action={action}
                        run={run}
                        processing={processing}
                        setProcessing={setProcessing}
                        onClose={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function NetPayEvidenceBody({
    action,
    run,
    processing,
    setProcessing,
    onClose,
}: {
    action: NetPayAction;
    run: NetPayRun;
    processing: boolean;
    setProcessing: (processing: boolean) => void;
    onClose: () => void;
}) {
    const copy = COPY[action];
    const Icon = copy.icon;
    // Settle only asks for the acceptance when the bank's is not on record yet.
    const accepted = action === 'settle' && run.acceptanceReference !== null;
    const asksForEvidence = !accepted;

    const [reference, setReference] = useState('');
    const [reason, setReason] = useState('');
    const [evidence, setEvidence] = useState('');
    const [bankTransactionId, setBankTransactionId] = useState('');
    const [errors, setErrors] = useState<FieldErrors>({});

    const validate = (): FieldErrors => {
        const next: FieldErrors = {};
        if (!asksForEvidence) return next;

        const ref = reference.trim();
        if (!ref) next.reference = 'Enter the bank’s reference.';
        else if (ref.length > 255)
            next.reference = 'Keep the reference to 255 characters or fewer.';

        if (!evidence.trim())
            next.evidence =
                'Enter the confirmation code or evidence reference.';

        if (action === 'reject') {
            const why = reason.trim();
            if (!why) next.reason = 'Enter the reason the bank gave.';
            else if (why.length > 1000)
                next.reason = 'Keep the reason to 1000 characters or fewer.';
        }

        if (action === 'reconcile') {
            const raw = bankTransactionId.trim();
            const id = Number(raw);
            if (!raw)
                next.bank_transaction_id =
                    'Enter the cleared bank transaction number.';
            else if (!/^\d+$/.test(raw) || !Number.isSafeInteger(id) || id < 1)
                next.bank_transaction_id =
                    'Use the whole number shown on the bank transaction.';
        }

        return next;
    };

    const payload = (): RequestPayload => {
        const ref = reference.trim();
        const proof = evidence.trim();

        if (action === 'settle') {
            const acceptanceReference = run.acceptanceReference ?? ref;
            return {
                idempotency_key: `payroll-net:${run.id}:${acceptanceReference}`,
                acceptance_reference: acceptanceReference,
                acceptance_evidence: accepted
                    ? undefined
                    : { confirmation_digest: proof },
            };
        }
        if (action === 'reject') {
            return {
                idempotency_key:
                    `payroll-reject:${run.id}:${ref}:${proof}`.slice(0, 128),
                reference: ref,
                reason: reason.trim(),
                evidence: { rejection_digest: proof },
            };
        }
        const id = Number(bankTransactionId.trim());
        return {
            idempotency_key: `payroll-reconcile:${run.id}:${id}`,
            bank_transaction_id: id,
            reference: ref,
            evidence: { reconciliation_digest: proof },
        };
    };

    const url = {
        settle: `/hr/payroll/runs/${run.id}/pay`,
        reject: `/hr/payroll/runs/${run.id}/reject-net-pay`,
        reconcile: `/hr/payroll/runs/${run.id}/reconcile-net-pay`,
    }[action];

    const handleSubmit = (event: FormEvent) => {
        event.preventDefault();
        const found = validate();
        setErrors(found);
        if (Object.keys(found).length > 0) return;

        router.post(url, payload(), {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onError: (serverErrors) => {
                const mapped: FieldErrors = {};
                for (const [key, message] of Object.entries(serverErrors)) {
                    const field =
                        SERVER_FIELD[key] ??
                        SERVER_FIELD[key.split('.')[0]] ??
                        'form';
                    mapped[field] ??= String(message);
                }
                setErrors(mapped);
            },
            onSuccess: () => onClose(),
        });
    };

    return (
        <form onSubmit={handleSubmit} noValidate>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <Icon className="h-4 w-4 text-primary" />
                    {copy.title(accepted)}
                </DialogTitle>
                <DialogDescription>
                    {copy.description(accepted)}
                </DialogDescription>
            </DialogHeader>

            <div className="mt-3 space-y-4">
                <p className="text-[13px] text-muted-foreground">
                    Payroll run{' '}
                    <span className="font-medium text-foreground">
                        {run.periodLabel}
                    </span>
                </p>

                {accepted ? (
                    <div className="space-y-1">
                        <span className="text-[13px] text-muted-foreground">
                            Bank acceptance reference
                        </span>
                        <p className="font-mono text-[13px] text-foreground">
                            {run.acceptanceReference}
                        </p>
                    </div>
                ) : (
                    <>
                        {action === 'reconcile' && (
                            <div className="space-y-1.5">
                                <Label htmlFor="net-pay-bank-transaction">
                                    Cleared bank transaction number{' '}
                                    <span className="text-status-critical">
                                        *
                                    </span>
                                </Label>
                                <Input
                                    id="net-pay-bank-transaction"
                                    inputMode="numeric"
                                    value={bankTransactionId}
                                    onChange={(e) =>
                                        setBankTransactionId(e.target.value)
                                    }
                                    placeholder="e.g. 10482"
                                    aria-invalid={!!errors.bank_transaction_id}
                                />
                                <p className="text-[12px] text-muted-foreground">
                                    The bank transaction this net pay cleared
                                    against, as listed in the bank feed.
                                </p>
                                <InputError
                                    message={errors.bank_transaction_id}
                                />
                            </div>
                        )}

                        <div className="space-y-1.5">
                            <Label htmlFor="net-pay-reference">
                                {action === 'settle'
                                    ? 'Bank acceptance reference'
                                    : action === 'reject'
                                      ? 'Bank rejection reference'
                                      : 'Bank reconciliation reference'}{' '}
                                <span className="text-status-critical">*</span>
                            </Label>
                            <Input
                                id="net-pay-reference"
                                value={reference}
                                maxLength={255}
                                onChange={(e) => setReference(e.target.value)}
                                placeholder="The reference the bank gave you"
                                aria-invalid={!!errors.reference}
                            />
                            <InputError message={errors.reference} />
                        </div>

                        {action === 'reject' && (
                            <div className="space-y-1.5">
                                <Label htmlFor="net-pay-reason">
                                    Reason the bank gave{' '}
                                    <span className="text-status-critical">
                                        *
                                    </span>
                                </Label>
                                <Textarea
                                    id="net-pay-reason"
                                    rows={2}
                                    maxLength={1000}
                                    value={reason}
                                    onChange={(e) => setReason(e.target.value)}
                                    placeholder="e.g. Account number on line 4 is closed"
                                    aria-invalid={!!errors.reason}
                                />
                                <InputError message={errors.reason} />
                            </div>
                        )}

                        <div className="space-y-1.5">
                            <Label htmlFor="net-pay-evidence">
                                Confirmation code or evidence reference{' '}
                                <span className="text-status-critical">*</span>
                            </Label>
                            <Input
                                id="net-pay-evidence"
                                value={evidence}
                                onChange={(e) => setEvidence(e.target.value)}
                                placeholder="e.g. the code on the bank’s confirmation"
                                aria-invalid={!!errors.evidence}
                            />
                            <p className="text-[12px] text-muted-foreground">
                                What the bank sent back, so anyone checking
                                later can find the bank&rsquo;s own record.
                            </p>
                            <InputError message={errors.evidence} />
                        </div>
                    </>
                )}

                <InputError message={errors.form} />
            </div>

            <DialogFooter className="mt-4">
                <Button
                    type="button"
                    variant="outline"
                    onClick={onClose}
                    disabled={processing}
                >
                    Cancel
                </Button>
                <Button
                    type="submit"
                    variant={copy.destructive ? 'destructive' : 'default'}
                    disabled={processing}
                >
                    {processing && (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    )}
                    {copy.submit(accepted)}
                </Button>
            </DialogFooter>
        </form>
    );
}
