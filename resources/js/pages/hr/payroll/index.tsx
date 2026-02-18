import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Download, Plus } from 'lucide-react';
import { useState, type FormEvent } from 'react';

interface PayrollRun {
    id: number;
    period_start: string;
    period_end: string;
    status: 'draft' | 'locked' | 'exported';
    total_hours: number;
    total_gross: number;
    items_count: number;
    created_at: string;
    locked_at: string | null;
    exported_at: string | null;
    validation_errors: string[];
}

interface Props {
    runs: {
        data: PayrollRun[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    can: { manage: boolean; export_data: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Payroll', href: '/hr/payroll' },
];

const statusConfig: Record<string, { className: string; label: string }> = {
    draft: {
        className: 'border-yellow-500/30 text-yellow-400 bg-yellow-500/10',
        label: 'Draft',
    },
    locked: {
        className: 'border-blue-500/30 text-blue-400 bg-blue-500/10',
        label: 'Locked',
    },
    exported: {
        className: 'border-emerald-500/30 text-emerald-400 bg-emerald-500/10',
        label: 'Exported',
    },
};

function formatCurrency(amount: number): string {
    return new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency: 'NZD',
    }).format(amount);
}

function toDateInputValue(date: Date): string {
    const offsetDate = new Date(
        date.getTime() - date.getTimezoneOffset() * 60000,
    );
    return offsetDate.toISOString().slice(0, 10);
}

function formatDate(value: string | null): string {
    if (!value) {
        return '\u2014';
    }

    const date = new Date(`${value}T00:00:00`);
    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('en-NZ', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(date);
}

export default function PayrollIndex({ runs, can }: Props) {
    const [isCreateDialogOpen, setIsCreateDialogOpen] = useState(false);
    const page = usePage<{ errors?: Record<string, string | string[]> }>();
    const { data, setData, post, processing, errors, clearErrors, reset } =
        useForm({
            period_start: '',
            period_end: '',
            notes: '',
        });

    const lockError = page.props?.errors?.lock;
    const periodError = page.props?.errors?.period;

    function handleExport(runId: number) {
        router.post(
            `/hr/payroll/runs/${runId}/export`,
            {},
            { preserveScroll: true },
        );
    }

    function openCreateRunDialog() {
        const periodStart = new Date();
        const periodEnd = new Date(periodStart);
        periodEnd.setDate(periodEnd.getDate() + 13);

        clearErrors();
        setData({
            period_start: toDateInputValue(periodStart),
            period_end: toDateInputValue(periodEnd),
            notes: '',
        });
        setIsCreateDialogOpen(true);
    }

    function handleCreateRunSubmit(event: FormEvent) {
        event.preventDefault();

        post('/hr/payroll/runs', {
            preserveScroll: true,
            onSuccess: () => {
                setIsCreateDialogOpen(false);
                reset();
            },
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Payroll" />
            <div className="flex flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-bold">Payroll Runs</h1>
                    {can.manage && (
                        <Button onClick={openCreateRunDialog}>
                            <Plus className="mr-2 h-4 w-4" />
                            Create Run
                        </Button>
                    )}
                </div>

                <div className="text-xs text-muted-foreground">
                    Tip: use the Create Run button to enter period dates and
                    generate draft payroll items.
                </div>

                {lockError ? (
                    <Card className="border-red-400/40 bg-red-500/5">
                        <CardContent className="py-3 text-sm text-red-500">
                            {Array.isArray(lockError)
                                ? lockError.join(' ')
                                : lockError}
                        </CardContent>
                    </Card>
                ) : null}

                <Dialog
                    open={isCreateDialogOpen}
                    onOpenChange={setIsCreateDialogOpen}
                >
                    <DialogContent className="sm:max-w-md">
                        <DialogHeader>
                            <DialogTitle>Create Payroll Run</DialogTitle>
                            <DialogDescription>
                                Enter the payroll period dates to generate a
                                draft run.
                            </DialogDescription>
                        </DialogHeader>
                        <form
                            onSubmit={handleCreateRunSubmit}
                            className="space-y-4"
                        >
                            <div className="space-y-2">
                                <Label htmlFor="period_start">
                                    Period start
                                </Label>
                                <Input
                                    id="period_start"
                                    type="date"
                                    value={data.period_start}
                                    onChange={(event) =>
                                        setData(
                                            'period_start',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                                {(errors.period_start ||
                                    (typeof periodError === 'string'
                                        ? periodError
                                        : null)) && (
                                    <p className="text-xs text-red-500">
                                        {errors.period_start ||
                                            (typeof periodError === 'string'
                                                ? periodError
                                                : null)}
                                    </p>
                                )}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="period_end">Period end</Label>
                                <Input
                                    id="period_end"
                                    type="date"
                                    value={data.period_end}
                                    min={data.period_start || undefined}
                                    onChange={(event) =>
                                        setData(
                                            'period_end',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                                {errors.period_end && (
                                    <p className="text-xs text-red-500">
                                        {errors.period_end}
                                    </p>
                                )}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="notes">Notes (optional)</Label>
                                <Input
                                    id="notes"
                                    value={data.notes}
                                    onChange={(event) =>
                                        setData('notes', event.target.value)
                                    }
                                    placeholder="Optional payroll notes"
                                />
                            </div>
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setIsCreateDialogOpen(false)}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Creating...' : 'Create Run'}
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>

                {/* Table */}
                <Card>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/50">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Period
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Status
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        Total Hours
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        Total Gross
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        Items
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Created
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {runs.data.map((run) => {
                                    const config =
                                        statusConfig[run.status] ||
                                        statusConfig.draft;
                                    return (
                                        <tr
                                            key={run.id}
                                            className="hover:bg-muted/30"
                                        >
                                            <td className="px-4 py-3">
                                                <span className="font-medium">
                                                    {formatDate(
                                                        run.period_start,
                                                    )}{' '}
                                                    -{' '}
                                                    {formatDate(run.period_end)}
                                                </span>
                                                {run.validation_errors?.length >
                                                0 ? (
                                                    <div className="mt-1 text-xs text-red-500">
                                                        {
                                                            run
                                                                .validation_errors[0]
                                                        }
                                                        {run.validation_errors
                                                            .length > 1
                                                            ? ` (+${run.validation_errors.length - 1} more)`
                                                            : ''}
                                                    </div>
                                                ) : null}
                                            </td>
                                            <td className="px-4 py-3">
                                                <Badge
                                                    variant="outline"
                                                    className={config.className}
                                                >
                                                    {config.label}
                                                </Badge>
                                            </td>
                                            <td className="px-4 py-3 text-right text-muted-foreground">
                                                {run.total_hours.toFixed(1)}h
                                            </td>
                                            <td className="px-4 py-3 text-right font-medium">
                                                {formatCurrency(
                                                    run.total_gross,
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-right text-muted-foreground">
                                                {run.items_count}
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {formatDate(run.created_at)}
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <div className="flex items-center justify-end gap-2">
                                                    {run.status === 'draft' &&
                                                        can.manage && (
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                onClick={() =>
                                                                    router.post(
                                                                        `/hr/payroll/runs/${run.id}/lock`,
                                                                        {},
                                                                        {
                                                                            preserveScroll: true,
                                                                        },
                                                                    )
                                                                }
                                                            >
                                                                Lock
                                                            </Button>
                                                        )}
                                                    {can.export_data &&
                                                        run.status ===
                                                            'locked' && (
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                onClick={() =>
                                                                    handleExport(
                                                                        run.id,
                                                                    )
                                                                }
                                                            >
                                                                <Download className="mr-1 h-3 w-3" />
                                                                Export
                                                            </Button>
                                                        )}
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                                {runs.data.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={7}
                                            className="px-4 py-8 text-center text-muted-foreground"
                                        >
                                            No payroll runs found.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>

                {/* Pagination */}
                {runs.last_page > 1 && (
                    <div className="flex items-center justify-between">
                        <p className="text-sm text-muted-foreground">
                            Showing{' '}
                            {(runs.current_page - 1) * runs.per_page + 1} to{' '}
                            {Math.min(
                                runs.current_page * runs.per_page,
                                runs.total,
                            )}{' '}
                            of {runs.total} results
                        </p>
                        <div className="flex items-center gap-1">
                            {runs.links.map((link, i) => (
                                <Button
                                    key={i}
                                    variant={
                                        link.active ? 'default' : 'outline'
                                    }
                                    size="sm"
                                    disabled={!link.url}
                                    onClick={() =>
                                        link.url &&
                                        router.get(
                                            link.url,
                                            {},
                                            { preserveState: true },
                                        )
                                    }
                                >
                                    <span
                                        dangerouslySetInnerHTML={{
                                            __html: link.label,
                                        }}
                                    />
                                </Button>
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
