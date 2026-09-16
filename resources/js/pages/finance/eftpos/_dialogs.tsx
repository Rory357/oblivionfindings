import { formatMoney } from '@/components/finance';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useForm } from '@inertiajs/react';
import { CreditCard, Scale } from 'lucide-react';
import { useEffect, type FormEvent } from 'react';

/** Radix Select cannot carry an empty-string item value — use a sentinel. */
const NONE = '__none';

export const EFTPOS_PROVIDERS: { value: string; label: string }[] = [
    { value: 'paymark', label: 'Paymark' },
    { value: 'worldline', label: 'Worldline' },
    { value: 'eftpos_nz', label: 'EFTPOS NZ' },
    { value: 'windcave', label: 'Windcave' },
];

export const eftposProviderLabel = (provider: string | null) =>
    EFTPOS_PROVIDERS.find((option) => option.value === provider)?.label ??
    provider ??
    '—';

export interface EditableTerminal {
    id: number;
    terminal_id: string;
    name: string;
    location: string | null;
    provider: string;
    has_merchant_id: boolean;
    bank_account_id: number | null;
    gl_account_id: number | null;
    is_active: boolean;
}

/**
 * Add or edit an EFTPOS terminal. Replaces the inline card form that used to
 * open in the page body, and gives `PUT eftpos/terminals/{id}` — which shipped
 * with no UI at all — its entry point.
 *
 * The merchant ID is stored encrypted and never sent to the page, so in edit
 * mode the field is left blank and only posted when someone types a new value.
 */
export function TerminalDialog({
    open,
    onClose,
    terminal = null,
    bankAccounts,
    glAccounts,
}: {
    open: boolean;
    onClose: () => void;
    terminal?: EditableTerminal | null;
    bankAccounts: { id: number; name: string }[];
    glAccounts: { id: number; code: string; name: string }[];
}) {
    const isEdit = Boolean(terminal);

    const form = useForm({
        terminal_id: terminal?.terminal_id ?? '',
        name: terminal?.name ?? '',
        location: terminal?.location ?? '',
        provider: terminal?.provider ?? 'paymark',
        merchant_id: '',
        bank_account_id: terminal?.bank_account_id
            ? String(terminal.bank_account_id)
            : NONE,
        gl_account_id: terminal?.gl_account_id
            ? String(terminal.gl_account_id)
            : NONE,
        is_active: terminal?.is_active ?? true,
    });
    const { data, setData, processing, errors, reset, clearErrors } = form;

    useEffect(() => {
        if (!open) return;
        clearErrors();
        setData({
            terminal_id: terminal?.terminal_id ?? '',
            name: terminal?.name ?? '',
            location: terminal?.location ?? '',
            provider: terminal?.provider ?? 'paymark',
            merchant_id: '',
            bank_account_id: terminal?.bank_account_id
                ? String(terminal.bank_account_id)
                : NONE,
            gl_account_id: terminal?.gl_account_id
                ? String(terminal.gl_account_id)
                : NONE,
            is_active: terminal?.is_active ?? true,
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, terminal?.id]);

    const submit = (event: FormEvent) => {
        event.preventDefault();

        form.transform((current) => {
            const payload: Record<string, unknown> = {
                name: current.name,
                location: current.location,
                provider: current.provider,
                bank_account_id:
                    current.bank_account_id === NONE
                        ? null
                        : current.bank_account_id,
                gl_account_id:
                    current.gl_account_id === NONE
                        ? null
                        : current.gl_account_id,
            };

            if (isEdit) {
                payload.is_active = current.is_active;
                // Omitted entirely when blank, so the stored value survives.
                if (current.merchant_id) {
                    payload.merchant_id = current.merchant_id;
                }
            } else {
                payload.terminal_id = current.terminal_id;
                payload.merchant_id = current.merchant_id;
            }

            return payload;
        });

        const done = {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onClose();
            },
        };

        if (isEdit && terminal) {
            form.put(`/finance/eftpos/terminals/${terminal.id}`, done);
        } else {
            form.post('/finance/eftpos/terminals', done);
        }
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) onClose();
            }}
        >
            <DialogContent
                style={{
                    maxWidth: 'min(94vw, 620px)',
                    width: 'min(94vw, 620px)',
                }}
            >
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <CreditCard className="h-4 w-4 text-primary" />
                            {isEdit ? 'Edit terminal' : 'Add an EFTPOS terminal'}
                        </DialogTitle>
                        <DialogDescription>
                            A terminal links card takings to the bank account
                            they settle into and the GL clearing account they
                            post through.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="terminal-id">Terminal ID</Label>
                            <Input
                                id="terminal-id"
                                value={data.terminal_id}
                                disabled={isEdit}
                                onChange={(event) =>
                                    setData('terminal_id', event.target.value)
                                }
                                placeholder="e.g. T001234"
                            />
                            {isEdit ? (
                                <p className="text-[12px] text-muted-foreground">
                                    The terminal ID is fixed once the device is
                                    registered.
                                </p>
                            ) : null}
                            <InputError message={errors.terminal_id} />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="terminal-name">Name</Label>
                            <Input
                                id="terminal-name"
                                value={data.name}
                                onChange={(event) =>
                                    setData('name', event.target.value)
                                }
                                placeholder="e.g. Front desk terminal"
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="terminal-location">Location</Label>
                            <Input
                                id="terminal-location"
                                value={data.location}
                                onChange={(event) =>
                                    setData('location', event.target.value)
                                }
                                placeholder="e.g. Main office"
                            />
                            <InputError message={errors.location} />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="terminal-provider">Provider</Label>
                            <Select
                                value={data.provider}
                                onValueChange={(value) =>
                                    setData('provider', value)
                                }
                            >
                                <SelectTrigger id="terminal-provider">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {EFTPOS_PROVIDERS.map((option) => (
                                        <SelectItem
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.provider} />
                        </div>

                        <div className="space-y-1.5 sm:col-span-2">
                            <Label htmlFor="terminal-merchant">
                                Merchant ID
                            </Label>
                            <Input
                                id="terminal-merchant"
                                value={data.merchant_id}
                                onChange={(event) =>
                                    setData('merchant_id', event.target.value)
                                }
                                placeholder={
                                    isEdit
                                        ? terminal?.has_merchant_id
                                            ? 'Stored — type a new ID to replace it'
                                            : 'Not set'
                                        : 'The merchant ID from your provider'
                                }
                            />
                            <p className="text-[12px] text-muted-foreground">
                                Stored encrypted, so it is never shown back to
                                you.
                            </p>
                            <InputError message={errors.merchant_id} />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="terminal-bank">
                                Settlement account
                            </Label>
                            <Select
                                value={data.bank_account_id}
                                onValueChange={(value) =>
                                    setData('bank_account_id', value)
                                }
                            >
                                <SelectTrigger id="terminal-bank">
                                    <SelectValue placeholder="Select an account" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>
                                        No settlement account
                                    </SelectItem>
                                    {bankAccounts.map((account) => (
                                        <SelectItem
                                            key={account.id}
                                            value={String(account.id)}
                                        >
                                            {account.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.bank_account_id} />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="terminal-gl">
                                GL clearing account
                            </Label>
                            <Select
                                value={data.gl_account_id}
                                onValueChange={(value) =>
                                    setData('gl_account_id', value)
                                }
                            >
                                <SelectTrigger id="terminal-gl">
                                    <SelectValue placeholder="Select an account" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>
                                        No GL clearing account
                                    </SelectItem>
                                    {glAccounts.map((account) => (
                                        <SelectItem
                                            key={account.id}
                                            value={String(account.id)}
                                        >
                                            {account.code} — {account.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.gl_account_id} />
                        </div>

                        {isEdit ? (
                            <div className="flex items-center gap-2 sm:col-span-2">
                                <Checkbox
                                    id="terminal-active"
                                    checked={data.is_active}
                                    onCheckedChange={(checked) =>
                                        setData('is_active', checked === true)
                                    }
                                />
                                <Label
                                    htmlFor="terminal-active"
                                    className="font-normal"
                                >
                                    Terminal is in service
                                </Label>
                            </div>
                        ) : null}
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
                            disabled={
                                processing ||
                                !data.name ||
                                (!isEdit &&
                                    (!data.terminal_id || !data.merchant_id))
                            }
                        >
                            {processing && <Spinner className="mr-2" />}
                            {isEdit ? 'Save terminal' : 'Add terminal'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export interface UnmatchedBankTransaction {
    id: number;
    transaction_date: string;
    amount: number;
    description: string | null;
    reference: string | null;
}

/**
 * Reconcile an EFTPOS batch against the bank line it settled into (W6). The
 * page used to post `bank_transaction_id: null` every time, so the matching
 * half of the workflow could never happen even though the candidate
 * transactions were already on the page.
 */
export function ReconcileBatchDialog({
    open,
    onClose,
    batch,
    transactions,
}: {
    open: boolean;
    onClose: () => void;
    batch: { id: number; batch_number: string; settlement_amount: number } | null;
    transactions: UnmatchedBankTransaction[];
}) {
    const form = useForm({
        bank_transaction_id: NONE,
        discrepancy_notes: '',
    });
    const { data, setData, processing, errors, reset, clearErrors } = form;

    useEffect(() => {
        if (!open) return;
        clearErrors();
        setData({ bank_transaction_id: NONE, discrepancy_notes: '' });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, batch?.id]);

    const selected = transactions.find(
        (transaction) => String(transaction.id) === data.bank_transaction_id,
    );
    const difference =
        selected && batch ? selected.amount - batch.settlement_amount : 0;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!batch) return;
        form.transform((current) => ({
            bank_transaction_id:
                current.bank_transaction_id === NONE
                    ? null
                    : current.bank_transaction_id,
            discrepancy_notes: current.discrepancy_notes,
        }));
        form.post(`/finance/eftpos/batches/${batch.id}/reconcile`, {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onClose();
            },
        });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) onClose();
            }}
        >
            <DialogContent
                style={{
                    maxWidth: 'min(94vw, 600px)',
                    width: 'min(94vw, 600px)',
                }}
            >
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <Scale className="h-4 w-4 text-primary" />
                            Reconcile batch {batch?.batch_number}
                        </DialogTitle>
                        <DialogDescription>
                            Pick the bank transaction this settlement of{' '}
                            {batch ? formatMoney(batch.settlement_amount) : ''}{' '}
                            landed as. Reconciling posts the batch against the
                            bank line; any gap is recorded as a discrepancy.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="mt-3 space-y-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="batch-bank-transaction">
                                Bank transaction
                            </Label>
                            <Select
                                value={data.bank_transaction_id}
                                onValueChange={(value) =>
                                    setData('bank_transaction_id', value)
                                }
                            >
                                <SelectTrigger id="batch-bank-transaction">
                                    <SelectValue placeholder="Select a bank transaction" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>
                                        Reconcile without a bank line
                                    </SelectItem>
                                    {transactions.map((transaction) => (
                                        <SelectItem
                                            key={transaction.id}
                                            value={String(transaction.id)}
                                        >
                                            {transaction.transaction_date} ·{' '}
                                            {formatMoney(transaction.amount)} ·{' '}
                                            {transaction.description ??
                                                transaction.reference ??
                                                'No description'}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {transactions.length === 0 ? (
                                <p className="text-[12px] text-muted-foreground">
                                    No unreconciled deposits are available to
                                    match. Import or refresh bank transactions
                                    first.
                                </p>
                            ) : null}
                            {selected ? (
                                <p
                                    className={
                                        difference === 0
                                            ? 'text-[12px] text-status-success'
                                            : 'text-[12px] text-status-warning'
                                    }
                                >
                                    {difference === 0
                                        ? 'Exact match to the settlement amount.'
                                        : `Difference of ${formatMoney(difference)} against the settlement amount.`}
                                </p>
                            ) : null}
                            <InputError message={errors.bank_transaction_id} />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="batch-notes">
                                Discrepancy notes (optional)
                            </Label>
                            <Textarea
                                id="batch-notes"
                                rows={3}
                                maxLength={1000}
                                value={data.discrepancy_notes}
                                onChange={(event) =>
                                    setData(
                                        'discrepancy_notes',
                                        event.target.value,
                                    )
                                }
                                placeholder="e.g. Provider fee deducted before settlement."
                            />
                            <InputError message={errors.discrepancy_notes} />
                        </div>
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
                        <Button type="submit" disabled={processing}>
                            {processing && <Spinner className="mr-2" />}
                            Reconcile batch
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
