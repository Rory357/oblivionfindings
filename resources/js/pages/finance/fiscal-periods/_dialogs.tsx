import { useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';

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

export type FiscalPeriod = {
    id: number;
    name: string;
    start_date: string;
    end_date: string;
    status: 'open' | 'closed' | 'locked';
    closed_at: string | null;
    closed_by: string | null;
};

/**
 * Fiscal period add/edit — a name and two dates, so it is a simple dialog
 * (POPUP_STYLE_GUIDE "simple single-section form"). `period` prefills it and
 * PUTs the update; only an OPEN period can be edited (the list gates the entry
 * point the same way the controller does).
 */
export function FiscalPeriodDialog({
    open,
    onClose,
    period,
}: {
    open: boolean;
    onClose: () => void;
    period?: FiscalPeriod | null;
}) {
    const isEdit = !!period;

    const { data, setData, post, put, processing, errors, reset, clearErrors } =
        useForm({
            name: period?.name ?? '',
            start_date: period?.start_date ?? '',
            end_date: period?.end_date ?? '',
        });

    const close = () => {
        reset();
        clearErrors();
        onClose();
    };

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => close() };
        if (isEdit && period) {
            put(`/finance/fiscal-periods/${period.id}`, options);
        } else {
            post('/finance/fiscal-periods', options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={(next) => !next && close()}>
            <DialogContent
                style={{
                    maxWidth: 'min(92vw, 720px)',
                    width: 'min(92vw, 720px)',
                }}
            >
                <DialogHeader>
                    <DialogTitle>
                        {isEdit ? 'Edit fiscal period' : 'New fiscal period'}
                    </DialogTitle>
                    <DialogDescription>
                        {isEdit
                            ? 'Update the name and dates of this accounting period.'
                            : 'Add an accounting period that journals, invoices and bills can post into.'}
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="flex flex-col gap-4">
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="period-name">Period name</Label>
                        <Input
                            id="period-name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="e.g. FY 2025-26 Q1"
                            required
                        />
                        {errors.name ? (
                            <p className="text-sm text-destructive">
                                {errors.name}
                            </p>
                        ) : null}
                    </div>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="period-start">Start date</Label>
                            <Input
                                id="period-start"
                                type="date"
                                value={data.start_date}
                                onChange={(e) =>
                                    setData('start_date', e.target.value)
                                }
                                required
                            />
                            {errors.start_date ? (
                                <p className="text-sm text-destructive">
                                    {errors.start_date}
                                </p>
                            ) : null}
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="period-end">End date</Label>
                            <Input
                                id="period-end"
                                type="date"
                                value={data.end_date}
                                onChange={(e) =>
                                    setData('end_date', e.target.value)
                                }
                                required
                            />
                            {errors.end_date ? (
                                <p className="text-sm text-destructive">
                                    {errors.end_date}
                                </p>
                            ) : null}
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={close}
                            disabled={processing}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing
                                ? 'Saving…'
                                : isEdit
                                  ? 'Save period'
                                  : 'Create period'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
