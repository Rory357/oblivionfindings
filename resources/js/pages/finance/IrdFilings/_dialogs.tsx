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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatDateOnly } from '@/lib/datetime';
import { useForm } from '@inertiajs/react';
import { Landmark, Loader2, Users } from 'lucide-react';
import { useState } from 'react';

/**
 * The two ways an IRD filing is created — from a prepared GST return, or from a
 * posted payroll run. Both were page-body forms before the design migration; a
 * filing is an entity you add, so each is a dialog (DESIGN.md "full-page
 * create/edit wizards").
 */

export type GstReturnOption = {
    id: number;
    period_start: string;
    period_end: string;
    gst_payable: string;
    status: string;
    ird_period: string;
};

export type PayrollRunOption = {
    id: number;
    period_start: string;
    period_end: string;
    total_gross: string | null;
    status: string;
};

const DIALOG_WIDTH = {
    maxWidth: 'min(92vw, 720px)',
    width: 'min(92vw, 720px)',
};

const shortDate = (value: string) => formatDateOnly(value.slice(0, 10), value);

function FieldError({ message }: { message?: string }) {
    if (!message) return null;
    return <p className="text-sm text-destructive">{message}</p>;
}

/* ------------------------------------------------------------------ */
/*  Filing from a GST return                                           */
/* ------------------------------------------------------------------ */

export function NewGstFilingDialog({
    open,
    onClose,
    gstReturns,
}: {
    open: boolean;
    onClose: () => void;
    gstReturns: GstReturnOption[];
}) {
    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent style={DIALOG_WIDTH}>
                {open && (
                    <NewGstFilingBody
                        onClose={onClose}
                        gstReturns={gstReturns}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function NewGstFilingBody({
    onClose,
    gstReturns,
}: {
    onClose: () => void;
    gstReturns: GstReturnOption[];
}) {
    const [gstReturnId, setGstReturnId] = useState('');
    const form = useForm({ ird_number: '' });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!gstReturnId) return;
        form.post(`/finance/ird-filings/from-gst/${gstReturnId}`, {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <form onSubmit={handleSubmit}>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <Landmark className="h-4 w-4 text-primary" />
                    New filing from a GST return
                </DialogTitle>
                <DialogDescription>
                    Turns a prepared GST return into an IRD filing you can
                    validate and submit.
                </DialogDescription>
            </DialogHeader>

            {gstReturns.length === 0 ? (
                <p className="mt-4 text-sm text-muted-foreground">
                    No GST returns are waiting to be filed. Prepare a GST return
                    first, then come back here.
                </p>
            ) : (
                <div className="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div className="space-y-2">
                        <Label htmlFor="gst_return_id">GST return</Label>
                        <Select
                            value={gstReturnId}
                            onValueChange={setGstReturnId}
                        >
                            <SelectTrigger id="gst_return_id">
                                <SelectValue placeholder="Select a GST return" />
                            </SelectTrigger>
                            <SelectContent>
                                {gstReturns.map((option) => (
                                    <SelectItem
                                        key={option.id}
                                        value={String(option.id)}
                                    >
                                        {option.ird_period} ·{' '}
                                        {shortDate(option.period_start)} –{' '}
                                        {shortDate(option.period_end)} (
                                        {formatMoney(option.gst_payable)})
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="gst_ird_number">IRD number</Label>
                        <Input
                            id="gst_ird_number"
                            value={form.data.ird_number}
                            onChange={(e) =>
                                form.setData('ird_number', e.target.value)
                            }
                            placeholder="e.g. 12-345-678"
                            maxLength={11}
                        />
                        <FieldError message={form.errors.ird_number} />
                    </div>
                </div>
            )}

            <DialogFooter className="mt-4">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button
                    type="submit"
                    disabled={
                        !gstReturnId ||
                        !form.data.ird_number ||
                        form.processing ||
                        gstReturns.length === 0
                    }
                >
                    {form.processing && (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    )}
                    Create filing
                </Button>
            </DialogFooter>
        </form>
    );
}

/* ------------------------------------------------------------------ */
/*  Payday filing from a payroll run                                   */
/* ------------------------------------------------------------------ */

export function NewPaydayFilingDialog({
    open,
    onClose,
    payrollRuns,
}: {
    open: boolean;
    onClose: () => void;
    payrollRuns: PayrollRunOption[];
}) {
    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent style={DIALOG_WIDTH}>
                {open && (
                    <NewPaydayFilingBody
                        onClose={onClose}
                        payrollRuns={payrollRuns}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function NewPaydayFilingBody({
    onClose,
    payrollRuns,
}: {
    onClose: () => void;
    payrollRuns: PayrollRunOption[];
}) {
    const [payrollRunId, setPayrollRunId] = useState('');
    const form = useForm({ ird_number: '' });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!payrollRunId) return;
        form.post(`/finance/ird-filings/from-payroll/${payrollRunId}`, {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <form onSubmit={handleSubmit}>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <Users className="h-4 w-4 text-primary" />
                    New payday filing from a payroll run
                </DialogTitle>
                <DialogDescription>
                    Builds the employment information filing for a payroll run
                    that has already posted to the general ledger.
                </DialogDescription>
            </DialogHeader>

            {payrollRuns.length === 0 ? (
                <p className="mt-4 text-sm text-muted-foreground">
                    No posted payroll runs are waiting to be filed. A run has to
                    reach the general ledger before its payday filing can be
                    created.
                </p>
            ) : (
                <div className="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div className="space-y-2">
                        <Label htmlFor="payroll_run_id">Payroll run</Label>
                        <Select
                            value={payrollRunId}
                            onValueChange={setPayrollRunId}
                        >
                            <SelectTrigger id="payroll_run_id">
                                <SelectValue placeholder="Select a posted payroll run" />
                            </SelectTrigger>
                            <SelectContent>
                                {payrollRuns.map((run) => (
                                    <SelectItem
                                        key={run.id}
                                        value={String(run.id)}
                                    >
                                        {shortDate(run.period_start)} –{' '}
                                        {shortDate(run.period_end)}
                                        {run.total_gross != null
                                            ? ` (${formatMoney(run.total_gross)})`
                                            : ''}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="payday_ird_number">IRD number</Label>
                        <Input
                            id="payday_ird_number"
                            value={form.data.ird_number}
                            onChange={(e) =>
                                form.setData('ird_number', e.target.value)
                            }
                            placeholder="e.g. 12-345-678"
                            maxLength={11}
                        />
                        <FieldError message={form.errors.ird_number} />
                    </div>
                </div>
            )}

            <DialogFooter className="mt-4">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button
                    type="submit"
                    disabled={
                        !payrollRunId ||
                        !form.data.ird_number ||
                        form.processing ||
                        payrollRuns.length === 0
                    }
                >
                    {form.processing && (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    )}
                    Create payday filing
                </Button>
            </DialogFooter>
        </form>
    );
}
