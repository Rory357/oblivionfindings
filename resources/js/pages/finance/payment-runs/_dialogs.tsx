import type { RequestPayload } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import {
    Banknote,
    CheckCircle2,
    Landmark,
    Loader2,
    XCircle,
} from 'lucide-react';
import { useState, type ComponentType, type FormEvent } from 'react';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

/**
 * Bank settlement evidence — the four external-settlement transitions on a
 * payment run (accept · reject · settle · reconcile).
 *
 * These used to be collected through a chain of `window.prompt()` calls: no
 * labels, no validation, no cancel semantics past the first step, and the
 * sequence aborted silently half-way through what the service records as
 * immutable compliance evidence (DESIGN.md "Browser prompt()/confirm() as a
 * form"). One dialog now owns all four, with real fields, per-field validation
 * and a processing state; the routes and payloads are unchanged.
 */
export type SettlementAction = 'accept' | 'reject' | 'settle' | 'reconcile';

type FieldErrors = Partial<
    Record<'reference' | 'reason' | 'evidence' | 'bank_transaction_id', string>
>;

const COPY: Record<
    SettlementAction,
    {
        icon: ComponentType<{ className?: string }>;
        title: string;
        description: string;
        submit: string;
        evidenceLabel: string;
        evidenceHint: string;
    }
> = {
    accept: {
        icon: CheckCircle2,
        title: 'Record bank acceptance',
        description:
            'The bank has accepted the payment file. Recording the acceptance evidence makes the run ready to settle — it does not pay the bills yet.',
        submit: 'Record acceptance',
        evidenceLabel: 'Confirmation digest',
        evidenceHint:
            'The digest or immutable evidence reference the bank returned with the acceptance.',
    },
    reject: {
        icon: XCircle,
        title: 'Record bank rejection',
        description:
            'The bank has rejected the payment file. Recording the rejection releases the bills so they can go into a corrected run.',
        submit: 'Record rejection',
        evidenceLabel: 'Rejection digest',
        evidenceHint:
            'The digest or immutable evidence reference the bank returned with the rejection.',
    },
    settle: {
        icon: Banknote,
        title: 'Settle this accepted run?',
        description:
            'This settles the accepted run against the acceptance the bank already confirmed: the bills are marked paid and this posts a journal to the ledger.',
        submit: 'Settle run',
        evidenceLabel: '',
        evidenceHint: '',
    },
    reconcile: {
        icon: Landmark,
        title: 'Record bank reconciliation',
        description:
            'Match this settled run to the cleared bank transaction on the statement. This closes the run against the bank feed.',
        submit: 'Record reconciliation',
        evidenceLabel: 'Reconciliation digest',
        evidenceHint:
            'The digest or immutable evidence reference for the cleared transaction.',
    },
};

export function SettlementEvidenceDialog({
    action,
    paymentRunId,
    runNumber,
    acceptanceReference,
    onClose,
}: {
    /** `null` keeps the dialog closed. */
    action: SettlementAction | null;
    paymentRunId: number;
    runNumber: string;
    /** The reference the bank returned on acceptance — settle replays it. */
    acceptanceReference?: string | null;
    onClose: () => void;
}) {
    return (
        <Dialog open={action !== null} onOpenChange={(open) => !open && onClose()}>
            <DialogContent
                className="max-h-[90vh] overflow-y-auto"
                style={{ maxWidth: 'min(92vw, 560px)', width: 'min(92vw, 560px)' }}
            >
                {action !== null && (
                    <SettlementEvidenceBody
                        action={action}
                        paymentRunId={paymentRunId}
                        runNumber={runNumber}
                        acceptanceReference={acceptanceReference}
                        onClose={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function SettlementEvidenceBody({
    action,
    paymentRunId,
    runNumber,
    acceptanceReference,
    onClose,
}: {
    action: SettlementAction;
    paymentRunId: number;
    runNumber: string;
    acceptanceReference?: string | null;
    onClose: () => void;
}) {
    const copy = COPY[action];
    const Icon = copy.icon;

    const [reference, setReference] = useState('');
    const [reason, setReason] = useState('');
    const [evidence, setEvidence] = useState('');
    const [bankTransactionId, setBankTransactionId] = useState('');
    const [errors, setErrors] = useState<FieldErrors>({});
    const [processing, setProcessing] = useState(false);

    const validate = (): FieldErrors => {
        const next: FieldErrors = {};
        if (action === 'settle') return next;

        if (!reference.trim()) next.reference = 'Enter the bank reference.';
        else if (reference.trim().length > 255)
            next.reference = 'Keep the reference under 255 characters.';

        if (!evidence.trim())
            next.evidence = `Enter the ${copy.evidenceLabel.toLowerCase()}.`;

        if (action === 'reject') {
            if (!reason.trim())
                next.reason = 'Enter the reason the bank gave.';
            else if (reason.trim().length > 1000)
                next.reason = 'Keep the reason under 1000 characters.';
        }

        if (action === 'reconcile') {
            const id = Number(bankTransactionId);
            if (!bankTransactionId.trim())
                next.bank_transaction_id =
                    'Enter the cleared bank transaction ID.';
            else if (!Number.isSafeInteger(id) || id < 1)
                next.bank_transaction_id =
                    'The bank transaction ID must be a whole number above zero.';
        }

        return next;
    };

    const payload = (): RequestPayload => {
        const ref = reference.trim();
        const digest = evidence.trim();

        if (action === 'accept') {
            return {
                idempotency_key:
                    `accept:${paymentRunId}:${ref}:${digest}`.slice(0, 128),
                reference: ref,
                evidence: { confirmation_digest: digest },
            };
        }
        if (action === 'reject') {
            return {
                idempotency_key:
                    `reject:${paymentRunId}:${ref}:${digest}`.slice(0, 128),
                reference: ref,
                reason: reason.trim(),
                evidence: { rejection_digest: digest },
            };
        }
        if (action === 'reconcile') {
            const id = Number(bankTransactionId);
            return {
                idempotency_key: `payment-run-reconcile:${paymentRunId}:${id}`,
                bank_transaction_id: id,
                reference: ref,
                evidence: { reconciliation_digest: digest },
            };
        }
        return {
            idempotency_key: `settle:${paymentRunId}:${acceptanceReference ?? ''}`,
        };
    };

    const handleSubmit = (event: FormEvent) => {
        event.preventDefault();
        const found = validate();
        setErrors(found);
        if (Object.keys(found).length > 0) return;

        router.post(
            `/finance/payment-runs/${paymentRunId}/${action}`,
            payload(),
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => onClose(),
            },
        );
    };

    const settleBlocked = action === 'settle' && !acceptanceReference;

    return (
        <form onSubmit={handleSubmit}>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <Icon className="h-4 w-4 text-primary" />
                    {copy.title}
                </DialogTitle>
                <DialogDescription>{copy.description}</DialogDescription>
            </DialogHeader>

            <div className="mt-3 space-y-4">
                <p className="text-[13px] text-muted-foreground">
                    Payment run{' '}
                    <span className="font-medium text-foreground">
                        {runNumber}
                    </span>
                </p>

                {action === 'settle' ? (
                    settleBlocked ? (
                        <p className="text-[13px] text-status-critical">
                            This run has no bank acceptance reference recorded
                            yet, so it can&rsquo;t be settled. Record the bank
                            acceptance first.
                        </p>
                    ) : (
                        <div className="space-y-1">
                            <span className="text-[13px] text-muted-foreground">
                                Acceptance reference
                            </span>
                            <p className="font-mono text-[13px] text-foreground">
                                {acceptanceReference}
                            </p>
                        </div>
                    )
                ) : (
                    <>
                        {action === 'reconcile' && (
                            <div className="space-y-1.5">
                                <Label htmlFor="settlement-bank-transaction">
                                    Cleared bank transaction ID
                                </Label>
                                <Input
                                    id="settlement-bank-transaction"
                                    inputMode="numeric"
                                    value={bankTransactionId}
                                    onChange={(e) =>
                                        setBankTransactionId(e.target.value)
                                    }
                                    placeholder="e.g. 10482"
                                />
                                <p className="text-[12px] text-muted-foreground">
                                    The bank transaction this run cleared
                                    against, from the bank feed.
                                </p>
                                <InputError
                                    message={errors.bank_transaction_id}
                                />
                            </div>
                        )}

                        <div className="space-y-1.5">
                            <Label htmlFor="settlement-reference">
                                Bank reference
                            </Label>
                            <Input
                                id="settlement-reference"
                                value={reference}
                                onChange={(e) => setReference(e.target.value)}
                                placeholder="The reference the bank returned"
                            />
                            <InputError message={errors.reference} />
                        </div>

                        {action === 'reject' && (
                            <div className="space-y-1.5">
                                <Label htmlFor="settlement-reason">
                                    Rejection reason
                                </Label>
                                <Textarea
                                    id="settlement-reason"
                                    rows={2}
                                    value={reason}
                                    onChange={(e) => setReason(e.target.value)}
                                    placeholder="Why the bank rejected the file"
                                />
                                <InputError message={errors.reason} />
                            </div>
                        )}

                        <div className="space-y-1.5">
                            <Label htmlFor="settlement-evidence">
                                {copy.evidenceLabel}
                            </Label>
                            <Input
                                id="settlement-evidence"
                                value={evidence}
                                onChange={(e) => setEvidence(e.target.value)}
                                placeholder="e.g. sha256:… or the bank's evidence reference"
                            />
                            <p className="text-[12px] text-muted-foreground">
                                {copy.evidenceHint}
                            </p>
                            <InputError message={errors.evidence} />
                        </div>
                    </>
                )}
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
                <Button type="submit" disabled={processing || settleBlocked}>
                    {processing && (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    )}
                    {copy.submit}
                </Button>
            </DialogFooter>
        </form>
    );
}
