import AppLayout from '@/layouts/app-layout';
import PageShell from '@/components/page-shell';
import FleetHero from '@/components/fleet-hero';
import { TimesheetStatusBadge } from '@/components/timesheet-status-badge';
import TimesheetReturnBanner from '@/components/timesheet-return-banner';
import { ShiftStatusBadge } from '@/components/shift-status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FileText, CalendarDays, CheckCircle2, AlertTriangle, ArrowRight } from 'lucide-react';

type Client = { id: number; first_name: string; last_name: string };

type Props = {
    timesheet: any;
    clients: Client[];
    canApprove: boolean;
    canSubmit: boolean;
    canEdit: boolean;
};

export default function TimesheetEdit({ timesheet, clients, canApprove, canSubmit, canEdit }: Props) {
    const { labels } = usePage().props as any;
    const timesheetLabel = labels?.['timesheet.singular'] ?? 'Timesheet';

    const form = useForm({
        client_id: timesheet.client_id,
        work_date: timesheet.work_date,
        starts_at: timesheet.starts_at?.slice(0, 16) ?? '',
        ends_at: timesheet.ends_at?.slice(0, 16) ?? '',
        break_minutes: timesheet.break_minutes ?? 0,
        mileage_km: Number(timesheet.mileage_km ?? 0),
        sleepover: !!timesheet.sleepover,
        on_call: !!timesheet.on_call,
        allowance_notes: timesheet.allowance_notes ?? '',
        public_holiday: !!timesheet.public_holiday,
        notes: timesheet.notes ?? '',
        is_residential_billable: !!timesheet.is_residential_billable,
    });

    const decision = useForm({
        decision_notes: timesheet.decision_notes ?? '',
        returned_notes: timesheet.returned_notes ?? '',
    });

    const status: string = timesheet.status ?? 'draft';
    const editable = !!canEdit;

    const clientName = (() => {
        const c = clients.find((c) => c.id === Number(timesheet.client_id));
        return c ? `${c.first_name} ${c.last_name}` : `Timesheet #${timesheet.id}`;
    })();

    const netHours = (() => {
        if (!timesheet.starts_at || !timesheet.ends_at) return '—';
        const s = new Date(timesheet.starts_at).getTime();
        const e = new Date(timesheet.ends_at).getTime();
        if (Number.isNaN(s) || Number.isNaN(e) || e <= s) return '—';
        const raw = (e - s) / (1000 * 60 * 60);
        const net = raw - (timesheet.break_minutes ?? 0) / 60;
        return `${net.toFixed(1)}h`;
    })();

    return (
        <AppLayout
            breadcrumbs={[
                { title: labels?.['timesheet.plural'] ?? 'Timesheets', href: '/timesheets' },
                { title: `${clientName} — ${timesheet.work_date}`, href: `/operations/timesheets/${timesheet.id}/edit` },
            ]}
        >
            <Head title={`${timesheetLabel} — ${clientName}`} />

            <PageShell>
                <FleetHero
                    title={clientName}
                    description={`${timesheetLabel} #${timesheet.id} — ${timesheet.work_date}`}
                    icon={<FileText className="h-7 w-7 text-white" />}
                    backHref="/timesheets"
                    backLabel="All timesheets"
                    stats={[
                        { label: 'Net Hours', value: netHours },
                        { label: 'Break', value: `${timesheet.break_minutes ?? 0}m` },
                    ]}
                    actions={
                        <TimesheetStatusBadge status={status} showIcon className="border-white/30 bg-white/10 text-white" />
                    }
                />

                {/* Status-specific banners */}
                {status === 'returned' ? (
                    <TimesheetReturnBanner
                        timesheetId={timesheet.id}
                        returnNote={timesheet.returned_notes}
                        hideAction
                    />
                ) : null}

                {(status === 'approved' || status === 'rejected') && timesheet.decision_notes ? (
                    <div className={`flex items-start gap-3 rounded-xl border p-4 ${status === 'approved' ? 'border-status-success/30 bg-status-success' : 'border-status-critical/30 bg-status-critical'}`}>
                        {status === 'approved' ? <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-status-success" /> : <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-status-critical" />}
                        <div>
                            <div className={`text-xs font-medium ${status === 'approved' ? 'text-status-success dark:text-status-success' : 'text-status-critical dark:text-status-critical'}`}>
                                {status === 'approved' ? 'Approved' : 'Rejected'} — decision notes
                            </div>
                            <div className={`mt-1 text-sm whitespace-pre-wrap ${status === 'approved' ? 'text-status-success dark:text-status-success' : 'text-status-critical dark:text-status-critical'}`}>
                                {timesheet.decision_notes}
                            </div>
                        </div>
                    </div>
                ) : null}

                {/* Workflow guidance */}
                {status === 'draft' ? (
                    <div className="flex items-center gap-3 rounded-xl border border-status-info/30 bg-status-info p-4">
                        <ArrowRight className="h-4 w-4 text-status-info shrink-0" />
                        <span className="text-sm text-status-info dark:text-status-info">This timesheet is a draft. Fill in the details and submit for approval when ready.</span>
                    </div>
                ) : status === 'submitted' ? (
                    <div className="flex items-center gap-3 rounded-xl border border-status-info/30 bg-status-info p-4">
                        <ArrowRight className="h-4 w-4 text-status-info shrink-0" />
                        <span className="text-sm text-status-info dark:text-status-info">This timesheet has been submitted and is awaiting manager review.</span>
                    </div>
                ) : null}

                {/* Linked shift context */}
                {timesheet.shift ? (
                    <Card className="border-primary/10">
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-xs font-medium uppercase tracking-wider text-muted-foreground">
                                <CalendarDays className="h-3.5 w-3.5" />
                                Linked Shift
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex flex-wrap items-center gap-3 text-sm">
                                <Link href={`/operations/shifts/${timesheet.shift.id}`} className="font-medium underline">
                                    Shift #{timesheet.shift.id}
                                </Link>
                                {timesheet.shift.status ? <ShiftStatusBadge status={timesheet.shift.status} /> : null}
                                <span className="text-muted-foreground">
                                    {String(timesheet.shift.shift_type ?? 'standard').replace('_', ' ')}
                                </span>
                                {timesheet.shift.service_context?.name ? (
                                    <Badge variant="outline" className="text-[10px]">{timesheet.shift.service_context.name}</Badge>
                                ) : null}
                                {timesheet.shift.location ? <span className="text-muted-foreground">{timesheet.shift.location}</span> : null}
                                {timesheet.shift.is_sleepover ? <Badge variant="outline" className="text-[10px]">Sleepover</Badge> : null}
                                {timesheet.shift.is_on_call ? <Badge variant="outline" className="text-[10px]">On-call</Badge> : null}
                            </div>
                        </CardContent>
                    </Card>
                ) : null}

                {/* Edit form */}
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        if (!editable) return;
                        form.put(`/operations/timesheets/${timesheet.id}`);
                    }}
                    className="max-w-2xl space-y-6"
                >
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="space-y-2">
                                <Label>Client</Label>
                                <Select
                                    value={String(form.data.client_id)}
                                    onValueChange={(v) => form.setData('client_id', v)}
                                    disabled={!editable || !!timesheet.shift}
                                >
                                    <SelectTrigger><SelectValue placeholder="Select client" /></SelectTrigger>
                                    <SelectContent>
                                        {clients.map((c) => (
                                            <SelectItem key={c.id} value={String(c.id)}>{c.first_name} {c.last_name}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="grid gap-4 md:grid-cols-2">
                                <div className="space-y-2">
                                    <Label>Work date</Label>
                                    <Input type="date" value={form.data.work_date} onChange={(e) => form.setData('work_date', e.target.value)} disabled={!editable} />
                                </div>
                                <div className="space-y-2">
                                    <Label>Break (minutes)</Label>
                                    <Input type="number" value={form.data.break_minutes} onChange={(e) => form.setData('break_minutes', Number(e.target.value))} disabled={!editable} />
                                </div>
                            </div>

                            <div className="grid gap-4 md:grid-cols-2">
                                <div className="space-y-2">
                                    <Label>Start</Label>
                                    <Input type="datetime-local" value={form.data.starts_at} onChange={(e) => form.setData('starts_at', e.target.value)} disabled={!editable} />
                                </div>
                                <div className="space-y-2">
                                    <Label>End</Label>
                                    <Input type="datetime-local" value={form.data.ends_at} onChange={(e) => form.setData('ends_at', e.target.value)} disabled={!editable} />
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label>Notes</Label>
                                <Textarea value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} rows={4} disabled={!editable} />
                            </div>

                            <div className="grid gap-4 md:grid-cols-2">
                                <div className="space-y-2">
                                    <Label>Mileage (km)</Label>
                                    <Input type="number" min={0} step="0.1" value={form.data.mileage_km} onChange={(e) => form.setData('mileage_km', Number(e.target.value))} disabled={!editable} />
                                </div>
                                <div className="space-y-2">
                                    <Label>Allowance notes</Label>
                                    <Input value={form.data.allowance_notes} onChange={(e) => form.setData('allowance_notes', e.target.value)} disabled={!editable} />
                                </div>
                            </div>

                            <div className="grid gap-3 md:grid-cols-4">
                                <div className="flex items-center gap-2 rounded-lg border p-3">
                                    <Checkbox checked={form.data.sleepover} onCheckedChange={(v) => form.setData('sleepover', Boolean(v))} disabled={!editable || !!timesheet.shift} />
                                    <Label className="text-sm">Sleepover</Label>
                                </div>
                                <div className="flex items-center gap-2 rounded-lg border p-3">
                                    <Checkbox checked={form.data.on_call} onCheckedChange={(v) => form.setData('on_call', Boolean(v))} disabled={!editable || !!timesheet.shift} />
                                    <Label className="text-sm">On-call</Label>
                                </div>
                                <div className="flex items-center gap-2 rounded-lg border p-3">
                                    <Checkbox checked={form.data.public_holiday} onCheckedChange={(v) => form.setData('public_holiday', Boolean(v))} disabled={!editable} />
                                    <Label className="text-sm">Public holiday</Label>
                                </div>
                                <div className="flex items-center gap-2 rounded-lg border p-3">
                                    <Checkbox checked={form.data.is_residential_billable} onCheckedChange={(v) => form.setData('is_residential_billable', Boolean(v))} disabled={!editable} />
                                    <Label className="text-sm">Residential billable</Label>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {Object.keys(form.errors).length > 0 && (
                        <div className="rounded-xl border border-status-critical/30 bg-status-critical-bg p-4 text-sm text-status-critical dark:border-status-critical/30 dark:bg-status-critical-bg dark:text-status-critical">
                            <p className="font-medium">Please fix the following errors:</p>
                            <ul className="mt-1 list-disc pl-5">
                                {Object.entries(form.errors).map(([field, message]) => (
                                    <li key={field}>{message}</li>
                                ))}
                            </ul>
                        </div>
                    )}

                    <div className="flex flex-wrap items-center gap-2">
                        {editable ? <Button type="submit" disabled={form.processing}>Save</Button> : null}
                        {canSubmit && (status === 'draft' || status === 'returned') ? (
                            <Button type="button" variant="outline" onClick={() => router.post(`/operations/timesheets/${timesheet.id}/submit`)}>
                                Submit for approval
                            </Button>
                        ) : null}
                        <Button type="button" variant="ghost" onClick={() => history.back()}>Back</Button>
                    </div>
                </form>

                {/* Manager decision panel */}
                {canApprove && status === 'submitted' ? (
                    <Card className="max-w-2xl border-primary/30 shadow-md">
                        <CardHeader className="bg-primary/5">
                            <CardTitle className="text-base">Manager Decision</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4 pt-4">
                            <div className="space-y-2">
                                <Label className="text-xs text-muted-foreground">Decision notes (optional for approve, required for reject)</Label>
                                <Textarea rows={3} value={decision.data.decision_notes} onChange={(e) => decision.setData('decision_notes', e.target.value)} placeholder="Optional notes for approval, required for rejection" />
                            </div>
                            <div className="space-y-2">
                                <Label className="text-xs text-muted-foreground">Return notes (required to return)</Label>
                                <Textarea rows={3} value={decision.data.returned_notes} onChange={(e) => decision.setData('returned_notes', e.target.value)} placeholder="What needs changing?" />
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <Button onClick={() => decision.post(`/operations/timesheets/${timesheet.id}/approve`, { preserveScroll: true })} disabled={decision.processing}>
                                    Approve
                                </Button>
                                <Button variant="outline" onClick={() => decision.post(`/operations/timesheets/${timesheet.id}/return`, { preserveScroll: true })} disabled={decision.processing}>
                                    Return for changes
                                </Button>
                                <Button variant="destructive" onClick={() => decision.post(`/operations/timesheets/${timesheet.id}/reject`, { preserveScroll: true })} disabled={decision.processing}>
                                    Reject
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                ) : null}

                {/* Payroll/export info */}
                {timesheet.exported_to_payroll_at ? (
                    <Card className="max-w-2xl border-status-success/20">
                        <CardContent className="p-4">
                            <div className="flex items-center gap-3">
                                <CheckCircle2 className="h-4 w-4 text-status-success shrink-0" />
                                <div>
                                    <div className="text-sm font-medium text-status-success dark:text-status-success">Exported to payroll</div>
                                    <div className="text-xs text-muted-foreground">
                                        Exported {new Date(timesheet.exported_to_payroll_at).toLocaleString()}
                                        {timesheet.payroll_reference ? ` · Ref: ${timesheet.payroll_reference}` : ''}
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                ) : null}

                {/* Reconciliation status */}
                {timesheet.reconciliation_status && timesheet.reconciliation_status !== 'clear' ? (
                    <Card className={`max-w-2xl ${timesheet.reconciliation_status === 'blocked' ? 'border-status-critical/20' : 'border-status-warning/20'}`}>
                        <CardContent className="p-4">
                            <div className="flex items-center gap-3">
                                <AlertTriangle className={`h-4 w-4 shrink-0 ${timesheet.reconciliation_status === 'blocked' ? 'text-status-critical' : 'text-status-warning'}`} />
                                <div>
                                    <div className={`text-sm font-medium ${timesheet.reconciliation_status === 'blocked' ? 'text-status-critical dark:text-status-critical' : 'text-status-warning dark:text-status-warning'}`}>
                                        Reconciliation: {timesheet.reconciliation_status === 'blocked' ? 'Blocked' : 'Needs review'}
                                    </div>
                                    {timesheet.reconciliation_findings?.summary ? (
                                        <div className="mt-1 text-xs text-muted-foreground">{timesheet.reconciliation_findings.summary}</div>
                                    ) : null}
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                ) : null}
            </PageShell>
        </AppLayout>
    );
}
