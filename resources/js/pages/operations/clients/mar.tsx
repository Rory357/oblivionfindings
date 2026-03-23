import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { AlertTriangle } from 'lucide-react';
import { useMemo, useState } from 'react';

type Props = {
  client: { id: number; first_name: string; last_name: string };
  date: string;
  rows: any[];
  history: any[];
  break_glass?: { id: number; reason: string; expires_at?: string | null } | null;
  has_open_controlled_discrepancy: boolean;
  witnesses?: Array<{ id: number; name: string }>;
  can: { record: boolean; correct: boolean; export: boolean; break_glass: boolean };
};

function toLocalDateTimeInput(iso?: string | null) {
  if (!iso) return '';
  const d = new Date(iso);
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function fromLocalDateTimeInput(v: string) {
  if (!v) return null;
  const d = new Date(v);
  return d.toISOString();
}

function pillForScheduleState(state: string) {
  switch (state) {
    case 'due':
      return { label: 'Due', className: 'bg-amber-100 text-amber-800 border-amber-200' };
    case 'due_soon':
      return { label: 'Due soon', className: 'bg-yellow-100 text-yellow-800 border-yellow-200' };
    case 'late':
      return { label: 'Late', className: 'bg-red-100 text-red-800 border-red-200' };
    case 'missed_auto':
      return { label: 'Overdue', className: 'bg-rose-100 text-rose-800 border-rose-200' };
    case 'upcoming':
      return { label: 'Upcoming', className: 'bg-slate-100 text-slate-700 border-slate-200' };
    case 'historical':
      return { label: 'Historical', className: 'bg-slate-100 text-slate-700 border-slate-200' };
    case 'prn':
      return { label: 'PRN', className: 'bg-indigo-100 text-indigo-800 border-indigo-200' };
    default:
      return { label: state, className: 'bg-slate-100 text-slate-700 border-slate-200' };
  }
}

function pillForStatus(status?: string | null) {
  switch (status) {
    case 'given':
      return { label: 'Given', className: 'bg-emerald-100 text-emerald-800 border-emerald-200' };
    case 'refused':
      return { label: 'Refused', className: 'bg-orange-100 text-orange-800 border-orange-200' };
    case 'withheld':
      return { label: 'Withheld', className: 'bg-yellow-100 text-yellow-800 border-yellow-200' };
    case 'missed':
      return { label: 'Missed', className: 'bg-rose-100 text-rose-800 border-rose-200' };
    default:
      return { label: 'Not recorded', className: 'bg-slate-100 text-slate-700 border-slate-200' };
  }
}

export default function ClientMar() {
  const { props } = usePage<Props>();
  const { client, date, rows, history, can, has_open_controlled_discrepancy, break_glass, witnesses = [] } = props;

  const auth: any = (usePage().props as any).auth;
  const myUserId = auth?.user?.id;

  const clientName = `${client.first_name} ${client.last_name}`.trim();

  const [selectedDate, setSelectedDate] = useState(date);

  const revokeBreakGlass = () => {
    if (!break_glass) return;
    router.delete(`/operations/clients/${client.id}/break-glass/${break_glass.id}`, {
      preserveScroll: true,
    });
  };

  const dueRows = useMemo(() => {
    return rows.filter((r) => r.scheduled_time !== 'PRN');
  }, [rows]);

  const prnRows = useMemo(() => rows.filter((r) => r.scheduled_time === 'PRN'), [rows]);

  const [adminOpen, setAdminOpen] = useState(false);
  const [adminRow, setAdminRow] = useState<any | null>(null);

  const adminForm = useForm({
    status: 'given',
    reason: '',
    dose_given: '',
    notes: '',
    scheduled_for: '' as string | null,
    administered_at: '',
    shift_id: '' as any,
    witnessed_by: '' as any,
  });

  const [corrOpen, setCorrOpen] = useState(false);
  const [corrRecord, setCorrRecord] = useState<any | null>(null);
  const corrForm = useForm({
    status: 'given',
    reason: '',
    dose_given: '',
    notes: '',
    administered_at: '',
    correction_reason: '',
  });

  const corrNeedsReason = useMemo(() => {
    if (!corrRecord) return false;
    const createdAt = corrRecord.created_at || corrRecord.record?.created_at;
    if (!createdAt) return true;
    const diffMinutes = (Date.now() - new Date(createdAt).getTime()) / 60000;
    return diffMinutes > 30;
  }, [corrRecord]);

  const canSubmitCorrection =
    !!corrRecord && (!corrNeedsReason || !!corrForm.data.correction_reason?.trim());

  const adminNeedsReason = useMemo(() => {
    if (!adminRow) return false;
    // Non-given outcomes always need a reason
    if (adminForm.data.status !== 'given') return true;
    // PRN "given" needs indication
    if (adminRow?.medication?.is_prn) return true;
    // Scheduled given outside time window needs a reason
    if (adminForm.data.scheduled_for && adminForm.data.administered_at) {
      try {
        const scheduled = new Date(adminForm.data.scheduled_for as any);
        const adminAt = new Date(fromLocalDateTimeInput(adminForm.data.administered_at as any) || new Date().toISOString());
        const diffMinutes = (adminAt.getTime() - scheduled.getTime()) / 60000;
        const earlyBefore = 60;
        const lateAfter = 30;
        if (diffMinutes < -earlyBefore || diffMinutes > lateAfter) return true;
      } catch {
        // ignore
      }
    }
    return false;
  }, [adminRow, adminForm.data.status, adminForm.data.scheduled_for, adminForm.data.administered_at]);

  const adminNeedsWitness = !!adminRow?.medication?.controlled_drug && adminForm.data.status === 'given';

  const canSubmitAdmin =
    !!adminRow &&
    (!adminNeedsReason || !!adminForm.data.reason?.trim()) &&
    (!adminNeedsWitness || (adminForm.data.witnessed_by && adminForm.data.witnessed_by !== '__none__'));

  function goToDate(d: string) {
    router.get(`/operations/clients/${client.id}/mar`, { date: d }, { preserveScroll: true, preserveState: true });
  }

  function openAdmin(row: any) {
    setAdminRow(row);
    adminForm.reset();
    adminForm.setData('scheduled_for', row.scheduled_for);
    adminForm.setData('status', 'given');
    adminForm.setData('administered_at', toLocalDateTimeInput(new Date().toISOString()));
    adminForm.setData('witnessed_by', '__none__');
    setAdminOpen(true);
  }

  function submitAdmin() {
    if (!adminRow) return;
    const mId = adminRow.medication.id;
    const payload: any = {
      ...adminForm.data,
      administered_at: fromLocalDateTimeInput(adminForm.data.administered_at as any),
      witnessed_by: adminForm.data.witnessed_by === '__none__' ? null : adminForm.data.witnessed_by,
    };
    adminForm.transform(() => payload).post(`/operations/clients/${client.id}/medical/medications/${mId}/administrations`, {
      preserveScroll: true,
      onSuccess: () => {
        setAdminOpen(false);
        setAdminRow(null);
      },
    });
  }

  function openCorrection(record: any) {
    setCorrRecord(record);
    corrForm.reset();
    corrForm.setData('status', record.status ?? 'given');
    corrForm.setData('reason', record.reason ?? '');
    corrForm.setData('dose_given', record.dose_given ?? '');
    corrForm.setData('notes', record.notes ?? '');
    corrForm.setData('administered_at', toLocalDateTimeInput(record.administered_at ?? new Date().toISOString()));
    setCorrOpen(true);
  }

  function submitCorrection() {
    if (!corrRecord) return;
    const payload: any = {
      ...corrForm.data,
      administered_at: fromLocalDateTimeInput(corrForm.data.administered_at as any),
    };
    corrForm.transform(() => payload).post(`/operations/clients/${client.id}/mar/administrations/${corrRecord.id}/corrections`, {
      preserveScroll: true,
      onSuccess: () => {
        setCorrOpen(false);
        setCorrRecord(null);
      },
    });
  }

  return (
    <AppLayout breadcrumbs={[{ title: 'Clients', href: '/clients' }, { title: clientName, href: `/operations/clients/${client.id}` }, { title: 'MAR', href: `/operations/clients/${client.id}/mar` }]}>
      <Head title={`MAR • ${clientName}`} />

      <div className="space-y-4">
        <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
          <div>
            <div className="text-sm text-slate-500">Client</div>
            <div className="text-lg font-semibold">{clientName}</div>
            <div className="text-xs text-slate-500">Daily MAR</div>
          </div>
          <div className="flex items-center gap-2">
            <div className="w-[170px]">
              <Label className="text-xs">Date</Label>
              <Input
                type="date"
                value={selectedDate}
                onChange={(e) => setSelectedDate(e.target.value)}
                onBlur={() => goToDate(selectedDate)}
              />
            </div>
            {can.export && (
              <Button variant="outline" onClick={() => (window.location.href = `/operations/clients/${client.id}/mar/export.csv?date=${selectedDate}`)}>
                Export CSV
              </Button>
            )}
          </div>
        </div>

        {has_open_controlled_discrepancy && (
          <div className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
            <div className="flex items-start gap-2">
              <AlertTriangle className="mt-0.5 h-4 w-4" />
              <div>
                <div className="font-medium">Open controlled-drug discrepancy</div>
                <div className="text-xs text-amber-800">Review and resolve before further controlled stock edits (unless override is granted).</div>
              </div>
            </div>
          </div>
        )}

        {break_glass && (
          <div className="rounded-md border border-slate-200 bg-slate-50 p-3 text-sm">
            <div className="flex items-start justify-between gap-3">
              <div>
                <div className="font-medium">Emergency access active</div>
                <div className="text-xs text-slate-600">
                  Reason: {break_glass.reason}
                  {break_glass.expires_at ? ` • Expires: ${new Date(break_glass.expires_at).toLocaleString()}` : ''}
                </div>
              </div>
              <Button variant="destructive" size="sm" onClick={revokeBreakGlass}>
                Revoke
              </Button>
            </div>
          </div>
        )}

        <Card>
          <CardHeader>
            <CardTitle className="text-base">Due doses</CardTitle>
          </CardHeader>
          <CardContent className="space-y-3">
            {dueRows.map((row, idx) => {
              const schedulePill = pillForScheduleState(row.schedule_state);
              const statusPill = pillForStatus(row.record?.status);

              return (
                <div key={`${row.medication.id}-${row.scheduled_time}-${idx}`} className="rounded-md border p-3">
                  <div className="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                    <div>
                      <div className="flex flex-wrap items-center gap-2">
                        <div className="text-sm font-medium">{row.medication.name}</div>
                        <Badge variant="outline" className={schedulePill.className}>{schedulePill.label}</Badge>
                        <Badge variant="outline" className={statusPill.className}>{statusPill.label}</Badge>
                        {row.record?.is_correction && (
                          <Badge variant="outline" className="bg-slate-100 text-slate-700 border-slate-200">Correction</Badge>
                        )}
                      </div>
                      <div className="mt-1 text-xs text-slate-500">
                        {row.scheduled_time} • {row.medication.dosage ?? '—'}
                        {row.medication.route ? ` • ${row.medication.route}` : ''}
                        {row.medication.form ? ` • ${row.medication.form}` : ''}
                        {row.medication.controlled_drug ? ' • Controlled (witness required)' : ''}
                      </div>
                      {row.record && (
                        <div className="mt-2 text-xs text-slate-600">
                          {row.record.administered_at ? `Administered: ${new Date(row.record.administered_at).toLocaleString()}` : ''}
                          {row.record.administered_by?.name ? ` • By: ${row.record.administered_by.name}` : ''}
                          {row.record.reason ? ` • Reason: ${row.record.reason}` : ''}
                        </div>
                      )}
                    </div>

                    <div className="flex items-center gap-2">
                      {can.record && !row.record && (
                        <Button onClick={() => openAdmin(row)}>Record</Button>
                      )}
                      {can.correct && row.record && (
                        <Button variant="outline" onClick={() => openCorrection(row.record)}>Correct</Button>
                      )}
                    </div>
                  </div>
                </div>
              );
            })}
            {!dueRows.length && <div className="text-sm text-slate-500">No due doses for this date.</div>}

            {prnRows.length > 0 && (
              <>
                <Separator />
                <div className="text-sm font-medium">PRN (as needed)</div>
                {prnRows.map((row, idx) => (
                  <div key={`prn-${row.medication.id}-${idx}`} className="rounded-md border p-3">
                    <div className="flex items-start justify-between gap-3">
                      <div>
                        <div className="flex items-center gap-2">
                          <div className="text-sm font-medium">{row.medication.name}</div>
                          <Badge variant="outline" className="bg-indigo-100 text-indigo-800 border-indigo-200">PRN</Badge>
                        </div>
                        <div className="mt-1 text-xs text-slate-500">
                          {row.medication.dosage ?? '—'}
                          {row.medication.route ? ` • ${row.medication.route}` : ''}
                          {row.medication.form ? ` • ${row.medication.form}` : ''}
                        </div>
                        {row.medication.prn_reason && (
                          <div className="mt-2 text-xs text-slate-600">Indication: {row.medication.prn_reason}</div>
                        )}
                      </div>
                      {can.record && (
                        <Button onClick={() => openAdmin(row)}>Record PRN</Button>
                      )}
                    </div>
                  </div>
                ))}
              </>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="text-base">Recent administrations (history)</CardTitle>
          </CardHeader>
          <CardContent className="space-y-2">
            {history.map((h: any) => (
              <div key={h.id} className="flex flex-col gap-1 rounded-md border p-3 md:flex-row md:items-center md:justify-between">
                <div>
                  <div className="flex flex-wrap items-center gap-2">
                    <div className="text-sm font-medium">{h.medication?.name ?? 'Medication'}</div>
                    <Badge variant="outline" className={pillForStatus(h.status).className}>{pillForStatus(h.status).label}</Badge>
                    {h.is_correction && (
                      <Badge variant="outline" className="bg-slate-100 text-slate-700 border-slate-200">Correction</Badge>
                    )}
                  </div>
                  <div className="text-xs text-slate-500">
                    {h.administered_at ? new Date(h.administered_at).toLocaleString() : ''}
                    {h.administeredBy?.name ? ` • ${h.administeredBy.name}` : ''}
                    {h.reason ? ` • Reason: ${h.reason}` : ''}
                    {h.correction_reason ? ` • Correction reason: ${h.correction_reason}` : ''}
                  </div>
                </div>
                {can.correct && (
                  <Button variant="outline" onClick={() => openCorrection(h)}>Correct</Button>
                )}
              </div>
            ))}
            {!history.length && <div className="text-sm text-slate-500">No administrations recorded for this date.</div>}
          </CardContent>
        </Card>
      </div>

      <Dialog open={adminOpen} onOpenChange={setAdminOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Record administration</DialogTitle>
          </DialogHeader>
          <div className="space-y-3">
            <div className="text-sm text-slate-600">
              {adminRow?.medication?.name} {adminRow?.scheduled_time ? `• ${adminRow.scheduled_time}` : ''}
            </div>
            <div className="grid gap-2">
              <Label>Status</Label>
              <Select value={adminForm.data.status} onValueChange={(v) => adminForm.setData('status', v)}>
                <SelectTrigger><SelectValue placeholder="Select status" /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="given">Given</SelectItem>
                  <SelectItem value="refused">Refused</SelectItem>
                  <SelectItem value="withheld">Withheld</SelectItem>
                  <SelectItem value="missed">Missed</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="grid gap-2">
              <Label>Administered at</Label>
              <Input
                type="datetime-local"
                value={adminForm.data.administered_at as any}
                onChange={(e) => adminForm.setData('administered_at', e.target.value)}
              />
            </div>

            {adminRow?.medication?.controlled_drug && adminForm.data.status === 'given' && (
              <div className="grid gap-2">
                <Label>Witness (required for controlled drugs)</Label>
                <Select
                  value={(adminForm.data.witnessed_by as any) || '__none__'}
                  onValueChange={(v) => adminForm.setData('witnessed_by', v)}
                >
                  <SelectTrigger><SelectValue placeholder="Select witness" /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="__none__">Select…</SelectItem>
                    {witnesses.filter((w) => w.id !== myUserId).map((w) => (
                      <SelectItem key={w.id} value={String(w.id)}>{w.name}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <div className="text-xs text-slate-500">Witness must be a different authorised user.</div>
              </div>
            )}
            <div className="grid gap-2">
              <Label>
                Reason / indication{adminNeedsReason ? ' (required)' : ' (optional)'}
              </Label>
              <Input value={adminForm.data.reason} onChange={(e) => adminForm.setData('reason', e.target.value)} />
            </div>
            <div className="grid gap-2">
              <Label>Dose given (optional)</Label>
              <Input value={adminForm.data.dose_given} onChange={(e) => adminForm.setData('dose_given', e.target.value)} />
            </div>
            <div className="grid gap-2">
              <Label>Notes (optional)</Label>
              <Input value={adminForm.data.notes} onChange={(e) => adminForm.setData('notes', e.target.value)} />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setAdminOpen(false)}>Cancel</Button>
            <Button onClick={submitAdmin} disabled={adminForm.processing || !canSubmitAdmin}>Save</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={corrOpen} onOpenChange={setCorrOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Correct administration (creates a correction entry)</DialogTitle>
          </DialogHeader>
          <div className="space-y-3">
            <div className="grid gap-2">
              <Label>Status</Label>
              <Select value={corrForm.data.status} onValueChange={(v) => corrForm.setData('status', v)}>
                <SelectTrigger><SelectValue placeholder="Select status" /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="given">Given</SelectItem>
                  <SelectItem value="refused">Refused</SelectItem>
                  <SelectItem value="withheld">Withheld</SelectItem>
                  <SelectItem value="missed">Missed</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="grid gap-2">
              <Label>Reason</Label>
              <Input value={corrForm.data.reason} onChange={(e) => corrForm.setData('reason', e.target.value)} />
            </div>
            <div className="grid gap-2">
              <Label>Dose given</Label>
              <Input value={corrForm.data.dose_given} onChange={(e) => corrForm.setData('dose_given', e.target.value)} />
            </div>
            <div className="grid gap-2">
              <Label>Notes</Label>
              <Input value={corrForm.data.notes} onChange={(e) => corrForm.setData('notes', e.target.value)} />
            </div>
            <div className="grid gap-2">
              <Label>Administered at</Label>
              <Input
                type="datetime-local"
                value={corrForm.data.administered_at as any}
                onChange={(e) => corrForm.setData('administered_at', e.target.value)}
              />
            </div>
            <div className="grid gap-2">
              <Label>Correction reason{corrNeedsReason ? ' (required)' : ' (optional)'}</Label>
              <Input value={corrForm.data.correction_reason} onChange={(e) => corrForm.setData('correction_reason', e.target.value)} />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setCorrOpen(false)}>Cancel</Button>
            <Button onClick={submitCorrection} disabled={corrForm.processing || !canSubmitCorrection}>Save correction</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </AppLayout>
  );
}
