/**
 * "Add a note" (board M14, answer 20): a submitted report is never edited. A manager adds a dated note beside the
 * reporter's words and the reporter is told. Posts to the existing POST /incidents/{incident}/notes, which keeps the
 * rule (incidents.approve + the incident view policy + not a draft).
 */
import { useForm } from '@inertiajs/react';
import { MessageSquarePlus } from 'lucide-react';

import { PopupField, PopupForm } from '@/components/popup';
import { Textarea } from '@/components/ui/textarea';

import { INCIDENT_NOTE_FIELD_LABELS, type IncidentNoteField } from './incident-progress';

const MIN = 5;
const MAX = 2000;

export function IncidentNotePopup({
    incidentId,
    reference,
    reporter,
    field,
    words,
    onClose,
}: {
    incidentId: number;
    reference: string;
    reporter: string | null;
    field: IncidentNoteField;
    /** The reporter's words the note sits beside. */
    words: string | null;
    onClose: () => void;
}) {
    const form = useForm<{ field: IncidentNoteField; note: string }>({ field, note: '' });
    const label = INCIDENT_NOTE_FIELD_LABELS[field];
    const length = form.data.note.trim().length;
    return (
        <PopupForm
            open
            onClose={onClose}
            size="sm"
            icon={MessageSquarePlus}
            title="Add a note"
            description={`${reporter ?? 'The person who reported it'} is told. The report itself stays as it was written.`}
            context={{ title: `${reference} · ${label}`, note: words ? <span className="line-clamp-2">{words}</span> : undefined }}
            error={form.errors.field}
            dirty={form.isDirty}
            draftName="this note"
            processing={form.processing}
            processingLabel="Adding…"
            submitDisabled={length < MIN || length > MAX}
            submitLabel="Add note"
            onSubmit={() =>
                form.post(`/incidents/${incidentId}/notes`, {
                    preserveScroll: true,
                    onSuccess: () => onClose(),
                })
            }
        >
            <PopupField
                label="Note"
                htmlFor="incident-note"
                required
                hint={`Shown beside “${label}” with your name and today’s date. It can’t be changed later. ${MIN}–${MAX} characters.`}
                error={form.errors.note}
            >
                <Textarea
                    id="incident-note"
                    rows={5}
                    maxLength={MAX}
                    value={form.data.note}
                    onChange={(event) => form.setData('note', event.target.value)}
                    placeholder="What you learned after the report, in plain words"
                />
            </PopupField>
        </PopupForm>
    );
}
