/* eslint-disable no-restricted-syntax -- the post-event review modal uses styled native outcome
   toggles / checkbox inside a Dialog; all colours are semantic tokens. */
import { SummaryRow } from '@/components/meds/wizard-shell';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { router } from '@inertiajs/react';
import { Ban, Check, ClipboardCheck } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

export type ReviewRecord = {
    id: number;
    client_id: number;
    client_name: string;
    site_name: string | null;
    staff: string;
    reason: string;
    reason_category: string | null;
    minutes: number | null;
    created_at: string | null;
    review_outcome: string | null;
    incident_report_id: number | null;
    events: { action: string; detail: string | null; at: string | null }[];
};

type IncidentOption = { id: number; label: string; date: string | null };

const fmtDateTime = (iso: string | null) => (iso ? new Date(iso).toLocaleString('en-NZ', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—');

export function ReviewDialog({ record, incidents, onClose }: { record: ReviewRecord; incidents: IncidentOption[]; onClose: () => void }) {
    const [outcome, setOutcome] = useState<'justified' | 'not_justified' | ''>(record.review_outcome === 'justified' || record.review_outcome === 'not_justified' ? record.review_outcome : '');
    const [notes, setNotes] = useState('');
    const [incidentId, setIncidentId] = useState<number | null>(record.incident_report_id);
    const [busy, setBusy] = useState(false);

    const save = () => {
        if (!outcome) return;
        setBusy(true);
        router.post(`/emar/clients/${record.client_id}/break-glass/${record.id}/review`, {
            review_outcome: outcome,
            review_notes: notes.trim() || null,
            incident_report_id: incidentId,
        }, {
            preserveScroll: true,
            onSuccess: () => { toast.success(`Review saved — marked ${outcome === 'justified' ? 'justified' : 'not justified'}`); onClose(); },
            onError: () => toast.error('Could not save review'),
            onFinish: () => setBusy(false),
        });
    };

    return (
        <Dialog open onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="sm:max-w-[560px]">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2.5">
                        <span className="grid h-8 w-8 place-items-center rounded-lg bg-primary/10 text-primary"><ClipboardCheck className="h-4 w-4" /></span>
                        Review activation
                    </DialogTitle>
                    <DialogDescription>Post-event sign-off · {record.client_name}</DialogDescription>
                </DialogHeader>

                <div className="rounded-lg border px-4">
                    <SummaryRow label="Staff" value={record.staff} />
                    <SummaryRow label="Client" value={`${record.client_name}${record.site_name ? ` · ${record.site_name}` : ''}`} />
                    <SummaryRow label="When · duration" value={`${fmtDateTime(record.created_at)}${record.minutes != null ? ` · ${record.minutes} min` : ''}`} />
                    <SummaryRow label="Reason" value={record.reason_category ?? record.reason} />
                </div>

                <div>
                    <div className="mb-1.5 text-sm font-medium">Outcome</div>
                    <div className="flex gap-2">
                        <button type="button" onClick={() => setOutcome('justified')} className={`flex flex-1 items-center justify-center gap-2 rounded-lg border py-2.5 text-sm font-semibold ${outcome === 'justified' ? 'border-status-success bg-status-success-bg text-status-success' : 'border-border hover:bg-muted'}`}><Check className="h-4 w-4" />Justified</button>
                        <button type="button" onClick={() => setOutcome('not_justified')} className={`flex flex-1 items-center justify-center gap-2 rounded-lg border py-2.5 text-sm font-semibold ${outcome === 'not_justified' ? 'border-status-critical bg-status-critical-bg text-status-critical' : 'border-border hover:bg-muted'}`}><Ban className="h-4 w-4" />Not justified</button>
                    </div>
                </div>

                <Textarea value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Reviewer notes (optional)…" className="min-h-16" />

                <div className="rounded-lg border">
                    <div className="border-b bg-muted/40 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Accessed during this window</div>
                    {record.events.length === 0 ? (
                        <p className="px-3 py-2.5 text-sm text-muted-foreground">No medication activity was recorded under this grant.</p>
                    ) : (
                        <ul className="max-h-40 divide-y overflow-y-auto text-sm">
                            {record.events.map((e, i) => (
                                <li key={i} className="flex items-center justify-between gap-2 px-3 py-1.5">
                                    <span>{e.action === 'viewed_mar' ? 'Viewed MAR chart' : e.action === 'recorded_dose' ? 'Recorded dose' : e.action}{e.detail ? ` — ${e.detail}` : ''}</span>
                                    <span className="shrink-0 text-xs text-muted-foreground">{fmtDateTime(e.at)}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <div>
                    <div className="mb-1.5 text-sm font-medium">Link incident report</div>
                    {incidents.length === 0 ? (
                        <p className="text-sm text-muted-foreground">No incident reports recorded for this client.</p>
                    ) : (
                        <select value={incidentId ?? ''} onChange={(e) => setIncidentId(e.target.value ? Number(e.target.value) : null)} className="h-10 w-full rounded-lg border border-input bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring">
                            <option value="">— None —</option>
                            {incidents.map((inc) => (
                                <option key={inc.id} value={inc.id}>{inc.label}{inc.date ? ` · ${inc.date}` : ''}</option>
                            ))}
                        </select>
                    )}
                </div>

                <div className="flex items-center justify-end gap-2">
                    <Button variant="outline" onClick={onClose} disabled={busy}>Cancel</Button>
                    <Button onClick={save} disabled={busy || !outcome}><Check className="h-4 w-4" />Save review</Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
