import { formatMoney } from '@/components/finance';
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
import { useForm } from '@inertiajs/react';
import { Loader2, Undo2 } from 'lucide-react';

/**
 * Reverse one donor-fund application (W8). Donor-fund transactions are
 * immutable, so a mistake is corrected by a reversing entry, not an edit — this
 * posts a reversing journal through `finance.donor-funds.transactions.reverse`.
 * The backend requires a reason, so this is a small form dialog rather than a
 * bare confirm (DESIGN.md: never a browser prompt, never a confirm-as-form).
 */

export type ReversibleTransaction = {
    id: number;
    transaction_date: string;
    type: string;
    description: string;
    amount: number;
    reference: string | null;
    journal_number: string | null;
};

const newIdempotencyKey = () => globalThis.crypto.randomUUID();

const today = () => new Date().toISOString().slice(0, 10);

export function ReverseTransactionDialog({
    open,
    onClose,
    fundId,
    transaction,
}: {
    open: boolean;
    onClose: () => void;
    fundId: number;
    transaction: ReversibleTransaction;
}) {
    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent
                style={{
                    maxWidth: 'min(92vw, 720px)',
                    width: 'min(92vw, 720px)',
                }}
            >
                {open && (
                    <ReverseTransactionBody
                        onClose={onClose}
                        fundId={fundId}
                        transaction={transaction}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function ReverseTransactionBody({
    onClose,
    fundId,
    transaction,
}: {
    onClose: () => void;
    fundId: number;
    transaction: ReversibleTransaction;
}) {
    const form = useForm({
        idempotency_key: newIdempotencyKey(),
        transaction_date: today(),
        reason: '',
        reference: '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({
            idempotency_key: d.idempotency_key,
            transaction_date: d.transaction_date,
            reason: d.reason,
            reference: d.reference || null,
        }));
        form.post(
            `/finance/donor-funds/${fundId}/transactions/${transaction.id}/reverse`,
            {
                preserveScroll: true,
                onSuccess: () => onClose(),
                onError: () =>
                    form.setData('idempotency_key', newIdempotencyKey()),
            },
        );
    };

    // The controller returns the service's refusal under a `reversal` key, which
    // isn't one of the form's own fields.
    const pageErrors = form.errors as Record<string, string | undefined>;
    const reversalError = pageErrors.reversal;

    return (
        <form onSubmit={handleSubmit}>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <Undo2 className="h-4 w-4 text-primary" />
                    Reverse this transaction
                </DialogTitle>
                <DialogDescription>
                    Donor-fund entries can&rsquo;t be edited. This posts a
                    reversing journal to the ledger and puts{' '}
                    {formatMoney(transaction.amount)} back the way it came.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-3 flex flex-col gap-4">
                <div className="rounded-lg border p-3 text-sm">
                    <p className="font-medium">{transaction.description}</p>
                    <p className="text-muted-foreground">
                        {transaction.type} · {formatMoney(transaction.amount)}
                        {transaction.journal_number
                            ? ` · journal ${transaction.journal_number}`
                            : ''}
                    </p>
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div className="space-y-2">
                        <Label htmlFor="reversal_date">Reversal date</Label>
                        <Input
                            id="reversal_date"
                            type="date"
                            value={form.data.transaction_date}
                            onChange={(e) =>
                                form.setData('transaction_date', e.target.value)
                            }
                        />
                        {form.errors.transaction_date && (
                            <p className="text-sm text-destructive">
                                {form.errors.transaction_date}
                            </p>
                        )}
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="reversal_reference">
                            Reference{' '}
                            <span className="text-muted-foreground">
                                (optional)
                            </span>
                        </Label>
                        <Input
                            id="reversal_reference"
                            value={form.data.reference}
                            onChange={(e) =>
                                form.setData('reference', e.target.value)
                            }
                            placeholder="e.g. CR-2026-014"
                        />
                        {form.errors.reference && (
                            <p className="text-sm text-destructive">
                                {form.errors.reference}
                            </p>
                        )}
                    </div>
                </div>

                <div className="space-y-2">
                    <Label htmlFor="reversal_reason">
                        Why is it being reversed?
                    </Label>
                    <Textarea
                        id="reversal_reason"
                        rows={3}
                        value={form.data.reason}
                        onChange={(e) => form.setData('reason', e.target.value)}
                        placeholder="e.g. Receipt was recorded against the wrong fund."
                    />
                    {form.errors.reason && (
                        <p className="text-sm text-destructive">
                            {form.errors.reason}
                        </p>
                    )}
                </div>

                {reversalError && (
                    <p className="text-sm text-destructive">{reversalError}</p>
                )}
            </div>

            <DialogFooter className="mt-4">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button
                    type="submit"
                    variant="destructive"
                    disabled={
                        form.processing ||
                        !form.data.reason.trim() ||
                        !form.data.transaction_date
                    }
                >
                    {form.processing && (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    )}
                    Post reversal
                </Button>
            </DialogFooter>
        </form>
    );
}
