/* Review flags on an H&S event (job 3, Stephan 26 Oct 2026).
 *
 * The exposure behind this event changed after a decision was made on it: it was corrected after the WorkSafe
 * decision was signed, or its severity rose after the event was closed. Nothing changes the signed decision or
 * reopens the event automatically, so a person who manages H&S checks it and records what they decided. The flag
 * holds no narrative or clinical text: only the reason and the severity step. */
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { Field, InfoCard } from '@/components/wizard/primitives';
import { useForm } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import { useState, type FormEvent } from 'react';

export type EventReviewFlag = {
    id: number;
    reason: 'worksafe_review' | 'escalated_after_close';
    source_revision: number | null;
    from_severity: string | null;
    to_severity: string | null;
    raised_at: string | null;
    can_resolve: boolean;
};

const SEVERITY: Record<string, string> = { low: 'low', medium: 'medium', high: 'high', critical: 'critical' };

function severityStep(flag: EventReviewFlag): string {
    if (!flag.from_severity || !flag.to_severity || flag.from_severity === flag.to_severity) return '';
    return ` Its severity went from ${SEVERITY[flag.from_severity] ?? flag.from_severity} to ${SEVERITY[flag.to_severity] ?? flag.to_severity}.`;
}

export function EventReviewFlags({ eventId, flags, canDecideWorksafe }: { eventId: number; flags: EventReviewFlag[] | undefined; canDecideWorksafe: boolean }) {
    if (!flags?.length) return null;
    return (
        <div className="flex flex-col gap-3" aria-label="Changes to review">
            {flags.map((flag) => (
                <ReviewFlag key={flag.id} eventId={eventId} flag={flag} canDecideWorksafe={canDecideWorksafe} />
            ))}
        </div>
    );
}

function ReviewFlag({ eventId, flag, canDecideWorksafe }: { eventId: number; flag: EventReviewFlag; canDecideWorksafe: boolean }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ note: '' });
    const worksafe = flag.reason === 'worksafe_review';
    const title = worksafe ? 'Check the WorkSafe decision again' : 'Severity rose after this event was closed';
    const body = worksafe
        ? `The exposure record was corrected after the WorkSafe decision was signed.${severityStep(flag)} The decision hasn’t been changed. ${canDecideWorksafe ? 'Check it still stands, or record a new decision.' : 'Check it still stands.'}`
        : `A correction to the exposure record raised its severity after the event was closed.${severityStep(flag)} The event stays closed. Record what you checked and what happens next.`;
    const cancel = () => {
        form.reset();
        form.clearErrors();
        setOpen(false);
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/health-safety/events/${eventId}/review-flags/${flag.id}/resolve`, {
            preserveScroll: true,
            onSuccess: (page) => {
                if (!(page.props as { flash?: { error?: string } }).flash?.error) cancel();
            },
        });
    };

    return (
        <InfoCard icon={ShieldAlert} tone="warn">
            <p className="font-semibold">{title}</p>
            <p>{body}</p>
            {flag.can_resolve && !open ? (
                <div className="mt-2">
                    <Button type="button" size="sm" variant="outline" onClick={() => setOpen(true)}>
                        Mark reviewed
                    </Button>
                </div>
            ) : null}
            {open ? (
                <form onSubmit={submit} className="mt-3 flex flex-col gap-3">
                    <Field label="What did you check and decide?" required error={form.errors.note}>
                        <Textarea value={form.data.note} maxLength={2000} rows={3} onChange={(event) => form.setData('note', event.target.value)} />
                    </Field>
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="outline" onClick={cancel}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing || form.data.note.trim().length < 10}>
                            Mark reviewed
                        </Button>
                    </div>
                </form>
            ) : null}
        </InfoCard>
    );
}
