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
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
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
import { useForm } from '@inertiajs/react';
import { Receipt } from 'lucide-react';
import { useEffect, type FormEvent } from 'react';

/** Radix Select cannot carry an empty-string item value — use a sentinel. */
const NO_ACCOUNT = '__none';

export const PETTY_CASH_TYPES: {
    value: string;
    label: string;
    variant: 'success' | 'critical' | 'info';
}[] = [
    { value: 'expense', label: 'Expense', variant: 'critical' },
    { value: 'top_up', label: 'Top up', variant: 'success' },
    { value: 'adjustment', label: 'Adjustment', variant: 'info' },
];

export const pettyCashType = (type: string) =>
    PETTY_CASH_TYPES.find((option) => option.value === type) ?? {
        value: type,
        label: type,
        variant: 'info' as const,
    };

const today = () => new Date().toISOString().split('T')[0];

/**
 * Record a petty cash transaction, with the receipt upload the form has always
 * been missing: `receipt_path` sat in the page's form state with no input, so
 * the transactions table's Receipt column could never be filled (W7/D11).
 */
export function PettyCashTransactionDialog({
    open,
    onClose,
    fundId,
    expenseAccounts,
}: {
    open: boolean;
    onClose: () => void;
    fundId: number;
    expenseAccounts: { id: number; code: string; name: string }[];
}) {
    const form = useForm<{
        transaction_date: string;
        type: string;
        amount: string;
        description: string;
        account_id: string;
        receipt: File | null;
    }>({
        transaction_date: today(),
        type: 'expense',
        amount: '',
        description: '',
        account_id: NO_ACCOUNT,
        receipt: null,
    });
    const { data, setData, processing, errors, reset, clearErrors } = form;

    useEffect(() => {
        if (!open) return;
        clearErrors();
        setData({
            transaction_date: today(),
            type: 'expense',
            amount: '',
            description: '',
            account_id: NO_ACCOUNT,
            receipt: null,
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((current) => ({
            transaction_date: current.transaction_date,
            type: current.type,
            amount: current.amount,
            description: current.description,
            account_id:
                current.type === 'expense' && current.account_id !== NO_ACCOUNT
                    ? current.account_id
                    : null,
            receipt: current.receipt,
        }));
        form.post(`/finance/petty-cash/${fundId}/transaction`, {
            // The receipt is a real upload, so the request must be multipart.
            forceFormData: true,
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
                            <Receipt className="h-4 w-4 text-primary" />
                            Record a transaction
                        </DialogTitle>
                        <DialogDescription>
                            An expense posts to the ledger against the account
                            you choose. Attach the receipt so the fund can be
                            audited without chasing paper.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="petty-date">Date</Label>
                            <Input
                                id="petty-date"
                                type="date"
                                value={data.transaction_date}
                                onChange={(event) =>
                                    setData(
                                        'transaction_date',
                                        event.target.value,
                                    )
                                }
                            />
                            <InputError message={errors.transaction_date} />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="petty-type">Type</Label>
                            <Select
                                value={data.type}
                                onValueChange={(value) =>
                                    setData('type', value)
                                }
                            >
                                <SelectTrigger id="petty-type">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {PETTY_CASH_TYPES.map((option) => (
                                        <SelectItem
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.type} />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="petty-amount">Amount (NZD)</Label>
                            <Input
                                id="petty-amount"
                                type="number"
                                step="0.01"
                                min="0.01"
                                placeholder="0.00"
                                value={data.amount}
                                onChange={(event) =>
                                    setData('amount', event.target.value)
                                }
                            />
                            <InputError message={errors.amount} />
                        </div>

                        {data.type === 'expense' ? (
                            <div className="space-y-1.5">
                                <Label htmlFor="petty-account">
                                    Expense account
                                </Label>
                                <Select
                                    value={data.account_id}
                                    onValueChange={(value) =>
                                        setData('account_id', value)
                                    }
                                >
                                    <SelectTrigger id="petty-account">
                                        <SelectValue placeholder="Select an account" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NO_ACCOUNT}>
                                            No expense account
                                        </SelectItem>
                                        {expenseAccounts.map((account) => (
                                            <SelectItem
                                                key={account.id}
                                                value={String(account.id)}
                                            >
                                                {account.code} — {account.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <p className="text-[12px] text-muted-foreground">
                                    An expense with an account posts a journal
                                    to the ledger.
                                </p>
                                <InputError message={errors.account_id} />
                            </div>
                        ) : null}

                        <div className="space-y-1.5 sm:col-span-2">
                            <Label htmlFor="petty-description">
                                Description
                            </Label>
                            <Input
                                id="petty-description"
                                value={data.description}
                                onChange={(event) =>
                                    setData('description', event.target.value)
                                }
                                placeholder="What was this for?"
                            />
                            <InputError message={errors.description} />
                        </div>

                        <div className="space-y-2 sm:col-span-2">
                            <Label htmlFor="petty-receipt">
                                Receipt (optional)
                            </Label>
                            {data.receipt ? (
                                <StagedFileCard
                                    file={data.receipt}
                                    onRemove={() => setData('receipt', null)}
                                />
                            ) : (
                                <FileDropzone
                                    id="petty-receipt"
                                    multiple={false}
                                    accept=".pdf,.jpg,.jpeg,.png,.webp,.heic"
                                    title="Drag a receipt here"
                                    hint="PDF or an image, up to 10 MB"
                                    onFiles={(files) =>
                                        setData('receipt', files[0] ?? null)
                                    }
                                />
                            )}
                            <InputError message={errors.receipt} />
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
                        <Button
                            type="submit"
                            disabled={processing || data.amount === ''}
                        >
                            {processing && <Spinner className="mr-2" />}
                            Record transaction
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
