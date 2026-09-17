import { useForm } from '@inertiajs/react';
import { Rss } from 'lucide-react';
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

/**
 * Connect a bank feed — three fields, so a dialog rather than a routed page.
 * Co-located with the page that opens it (the payment-runs precedent).
 */

export const BANK_FEED_PROVIDERS: { value: string; label: string }[] = [
    { value: 'asb', label: 'ASB' },
    { value: 'anz', label: 'ANZ' },
    { value: 'westpac', label: 'Westpac' },
    { value: 'bnz', label: 'BNZ' },
];

export const providerLabel = (provider: string) =>
    BANK_FEED_PROVIDERS.find((option) => option.value === provider)?.label ??
    provider;

export function ConnectFeedDialog({
    open,
    onClose,
    availableAccounts,
}: {
    open: boolean;
    onClose: () => void;
    availableAccounts: { id: number; name: string; bank_name: string }[];
}) {
    const { data, setData, post, processing, errors, reset, clearErrors } =
        useForm({
            bank_account_id: '',
            provider: '',
            sync_from_date: '',
        });

    useEffect(() => {
        if (!open) return;
        clearErrors();
        reset();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post('/finance/bank-feeds', {
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
                            <Rss className="h-4 w-4 text-primary" />
                            Connect a bank feed
                        </DialogTitle>
                        <DialogDescription>
                            A feed imports transactions for one bank account
                            automatically. Each account can carry a single feed.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="mt-3 space-y-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="feed-account">Bank account</Label>
                            <Select
                                value={data.bank_account_id}
                                onValueChange={(value) =>
                                    setData('bank_account_id', value)
                                }
                            >
                                <SelectTrigger id="feed-account">
                                    <SelectValue placeholder="Select a bank account" />
                                </SelectTrigger>
                                <SelectContent>
                                    {availableAccounts.map((account) => (
                                        <SelectItem
                                            key={account.id}
                                            value={String(account.id)}
                                        >
                                            {account.name} ({account.bank_name})
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.bank_account_id} />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="feed-provider">Bank provider</Label>
                            <Select
                                value={data.provider}
                                onValueChange={(value) =>
                                    setData('provider', value)
                                }
                            >
                                <SelectTrigger id="feed-provider">
                                    <SelectValue placeholder="Select a provider" />
                                </SelectTrigger>
                                <SelectContent>
                                    {BANK_FEED_PROVIDERS.map((option) => (
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

                        <div className="space-y-1.5">
                            <Label htmlFor="feed-from">
                                Sync from (optional)
                            </Label>
                            <Input
                                id="feed-from"
                                type="date"
                                value={data.sync_from_date}
                                onChange={(event) =>
                                    setData('sync_from_date', event.target.value)
                                }
                            />
                            <p className="text-[12px] text-muted-foreground">
                                Leave blank to import the last 30 days.
                            </p>
                            <InputError message={errors.sync_from_date} />
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
                                !data.provider
                            }
                        >
                            {processing && <Spinner className="mr-2" />}
                            Connect feed
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
