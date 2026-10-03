import InputError from '@/components/input-error';
import { SummaryRow } from '@/components/meds/wizard-shell';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { TilePicker } from '@/components/wizard/primitives';
import { formatDateTime } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { Grant } from './_types';
export type ReviewRecord = Grant;

export function ReviewDialog({ record, onClose }: { record: Grant; incidents?: unknown[]; onClose: () => void }) {
    const previous = record.reviews.at(-1);
    const [outcome, setOutcome] = useState(previous?.outcome ?? '');
    const [notes, setNotes] = useState('');
    const [why, setWhy] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    function save() {
        setBusy(true);
        router.post(`/emar/clients/${record.client_id}/break-glass/${record.id}/review`, {
            review_outcome: outcome, review_notes: notes.trim() || null,
            correction_reason: previous ? why.trim() : null, corrects_review_id: previous?.id ?? null,
        }, { preserveScroll: true, onSuccess: onClose, onError: setErrors, onFinish: () => setBusy(false) });
    }
    return <Dialog open onOpenChange={(v) => !v && onClose()}><DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
        <DialogHeader><DialogTitle>{previous ? 'Correct the review of' : 'Review'} EA-{record.id} — {record.client_name}</DialogTitle>
            <DialogDescription>{record.staff} · ended {formatDateTime(record.ended_at)}</DialogDescription></DialogHeader>
        <div className="space-y-5">
            <SummaryRow label="Why they used it" value={record.reason} /><SummaryRow label="Second person" value={record.cosign_label ?? 'Not recorded'} />
            <h3 className="text-section-title">What was done</h3>
            {record.events.length ? record.events.map((event, i) => <SummaryRow key={i} label={formatDateTime(event.at)} value={event.detail ?? event.action} />) : <p className="text-caption">No activity is recorded for this grant.</p>}
            {record.review_denial ? <Alert><AlertTitle>{record.review_denial}</AlertTitle><AlertDescription>An independent colleague reviews it.</AlertDescription></Alert> : null}
            {record.reviews.map((r, i) => <Alert key={r.id}><AlertTitle>{r.outcome === 'justified' ? 'Justified' : 'Not justified'}{i < record.reviews.length - 1 ? ' — corrected later' : ''}</AlertTitle><AlertDescription>{r.by} · {formatDateTime(r.at)}{r.notes ? ` · ${r.notes}` : ''}{r.correction_reason ? ` · Correction: ${r.correction_reason}` : ''}</AlertDescription></Alert>)}
            {record.can_review && <>
                {previous && <><Label htmlFor="review-correction-reason">Why the review is being corrected</Label><Textarea id="review-correction-reason" value={why} onChange={(e) => setWhy(e.target.value)} /><InputError message={errors.correction_reason} /></>}
                <Label id="emergency-review-outcome-label">Was it justified?</Label>
                <TilePicker value={outcome} onChange={setOutcome} labelledBy="emergency-review-outcome-label" options={[
                    { key: 'justified', label: 'Justified', description: 'It was needed, and only what was needed was done' },
                    { key: 'not_justified', label: 'Not justified', description: 'It wasn’t needed, or more was done than needed' },
                ]} />
                <Label htmlFor="emergency-review-notes">{outcome === 'not_justified' ? 'What wasn’t needed (required)' : 'Notes (optional)'}</Label>
                <Textarea id="emergency-review-notes" value={notes} onChange={(e) => setNotes(e.target.value)} />
                {outcome === 'not_justified' && <Alert><AlertTitle>Talk it through with {record.staff}</AlertTitle><AlertDescription>If a dose went wrong, report a medication error separately.</AlertDescription></Alert>}
                <p className="text-caption">Earlier reviews stay visible. Your name and the time are recorded with this {previous ? 'correction' : 'review'}.</p>
                {Object.values(errors).map((error) => <InputError key={error} message={error} />)}
            </>}
        </div>
        <DialogFooter><Button variant="outline" onClick={onClose}>Close</Button>
            {record.can_review && <Button disabled={busy || !outcome || outcome === 'not_justified' && notes.trim().length < 10 || !!previous && why.trim().length < 10} onClick={save}>{busy ? 'Saving…' : previous ? 'Save the correction' : 'Save the review'}</Button>}
        </DialogFooter>
    </DialogContent></Dialog>;
}
