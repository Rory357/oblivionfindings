import { useForm } from '@inertiajs/react';
import { Scale } from 'lucide-react';
import { useEffect, type FormEvent } from 'react';

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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';

import { formatMoney } from './money';

/**
 * Start a bank reconciliation — three fields (account, statement date, closing
 * balance), so it is a dialog and not a routed page (DESIGN.md "Full-page
 * create/edit wizard for a three-field form"). Opened from the reconciliation
 * index's primary button and from a bank account's "Start reconciliation",
 * which passes `bankAccountId` instead of a query string.
 *
 * Deliberately not exported from `components/finance/index.ts` — import it by
 * path, like the period filter.
 */

export interface ReconciliationBankAccount {
    id: number;
    name: string;
    current_balance?: string | number | null;
}

const today = () => new Date().toISOString().split('T')[0];

export function StartReconciliationDialog({
    open,
    onClose,
    bankAccounts,
    bankAccountId = null,
}: {
    open: boolean;
    onClose: () => void;
    bankAccounts: ReconciliationBankAccount[];
    /** Preselect the account when opened from a bank account record. */
    bankAccountId?: number | null;
}) {
    const { data, setData, post, processing, errors, reset, clearErrors } =
        useForm({
            bank_account_id: bankAccountId ? String(bankAccountId) : '',
            statement_date: today(),
            statement_balance: '',
        });

    useEffect(() => {
        if (!open) return;
        clearErrors();
        setData({
            bank_account_id: bankAccountId
                ? String(bankAccountId)
                : bankAccounts.length === 1
                  ? String(bankAccounts[0].id)
                  : '',
            statement_date: today(),
            statement_balance: '',
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, bankAccountId]);

    const selected = bankAccounts.find(
        (account) => account.id === Number(data.bank_account_id),
    );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post('/finance/bank-reconciliation', {
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
                    maxWidth: 'min(92vw, 520px)',
                    width: 'min(92vw, 520px)',
                }}
            >
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <Scale className="h-4 w-4 text-primary" />
                            Start a reconciliation
                        </DialogTitle>
                        <DialogDescription>
                            Reconcile a bank statement against the ledger. The
                            closing balance on the statement is what the
                            workbench measures your matched items against.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="mt-3 space-y-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="reconciliation-account">
                                Bank account
                            </Label>
                            <Select
                                value={data.bank_account_id}
                                onValueChange={(value) =>
                                    setData('bank_account_id', value)
                                }
                            >
                                <SelectTrigger id="reconciliation-account">
                                    <SelectValue placeholder="Select a bank account" />
                                </SelectTrigger>
                                <SelectContent>
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
                            {selected?.current_balance != null && (
                                <p className="text-[12px] text-muted-foreground">
                                    Ledger balance today:{' '}
                                    {formatMoney(selected.current_balance)}
                                </p>
                            )}
                            <InputError message={errors.bank_account_id} />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="reconciliation-date">
                                Statement date
                            </Label>
                            <Input
                                id="reconciliation-date"
                                type="date"
                                value={data.statement_date}
                                onChange={(event) =>
                                    setData(
                                        'statement_date',
                                        event.target.value,
                                    )
                                }
                            />
                            <InputError message={errors.statement_date} />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="reconciliation-balance">
                                Statement closing balance (NZD)
                            </Label>
                            <Input
                                id="reconciliation-balance"
                                type="number"
                                step="0.01"
                                placeholder="0.00"
                                value={data.statement_balance}
                                onChange={(event) =>
                                    setData(
                                        'statement_balance',
                                        event.target.value,
                                    )
                                }
                            />
                            <InputError message={errors.statement_balance} />
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
                            disabled={
                                processing ||
                                !data.bank_account_id ||
                                data.statement_balance === ''
                            }
                        >
                            {processing && <Spinner className="mr-2" />}
                            Start reconciliation
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default StartReconciliationDialog;
