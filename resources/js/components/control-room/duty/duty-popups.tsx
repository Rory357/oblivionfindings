import { PeopleMultiPicker, PeoplePicker } from '@/components/hr/people-picker';
import { PopupField, PopupForm } from '@/components/popup';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { SelectInput } from '@/components/wizard/primitives';
import { formatDateTime } from '@/lib/datetime';
import { router, useForm } from '@inertiajs/react';
import { Clock, MessageSquarePlus, PlayCircle } from 'lucide-react';
import { useRef, useState } from 'react';

/**
 * Duty handover pop-ups (the kit, DESIGN.md "The pop-up kit"): Start duty
 * and Add operator note. Preparing and accepting a handover stay on the full
 * handover page — preparing is recoverable and does not move ownership, and
 * acceptance is a separate reviewed step by the incoming lead.
 */

type Person = { id: number; name: string };

const UNCONFIRMED =
    "We couldn't confirm the result — the connection may have dropped. Check the page before trying again, so nothing is recorded twice.";

/**
 * An honest outcome: success closes, a refusal stays open with its reason,
 * and a response that never arrives says the result is unknown.
 */
function useSubmitOutcome(onDone: () => void) {
    const [flashError, setFlashError] = useState<string | undefined>();
    const [unconfirmed, setUnconfirmed] = useState(false);
    const settled = useRef(false);

    const options = {
        preserveScroll: true,
        onStart: () => {
            settled.current = false;
            setFlashError(undefined);
            setUnconfirmed(false);
        },
        onSuccess: (page: { props: Record<string, unknown> }) => {
            settled.current = true;
            const error = (page.props as { flash?: { error?: string } }).flash?.error;
            if (error) setFlashError(error);
            else onDone();
        },
        onError: () => {
            settled.current = true;
        },
        onCancel: () => {
            settled.current = true;
        },
        onFinish: () => {
            if (!settled.current) setUnconfirmed(true);
        },
    };

    const checkResult = () => {
        setUnconfirmed(false);
        router.reload({ preserveScroll: true, onSuccess: () => onDone() });
    };

    return { options, flashError, unconfirmed, checkResult };
}

const people = (list: Person[]) =>
    list.map((person) => ({ value: String(person.id), label: person.name }));

/** Start duty — the people taking responsibility now. Planned staffing stays in Rostering. */
export function StartDutyPopup({
    open,
    onClose,
    eligibleLeads,
    staff,
}: {
    open: boolean;
    onClose: () => void;
    eligibleLeads: Person[];
    staff: Person[];
}) {
    const form = useForm<{
        name: string;
        shift_lead_user_id: string;
        team_members: string[];
    }>({ name: '', shift_lead_user_id: '', team_members: [] });
    const close = () => {
        form.reset();
        form.clearErrors();
        onClose();
    };
    const submit = useSubmitOutcome(close);

    return (
        <PopupForm
            open={open}
            onClose={close}
            icon={PlayCircle}
            title="Start duty"
            description="Record the people taking responsibility now"
            callout={
                submit.unconfirmed
                    ? { tone: 'warning', content: UNCONFIRMED }
                    : {
                          tone: 'info',
                          content:
                              'Planned shifts and staffing stay in Workforce › Rostering. This records who is on the Control Room desk now.',
                      }
            }
            error={submit.flashError}
            dirty={form.isDirty}
            processing={form.processing}
            processingLabel="Starting…"
            submitDisabled={
                !submit.unconfirmed &&
                (!form.data.name.trim() || !form.data.shift_lead_user_id)
            }
            submitLabel={submit.unconfirmed ? 'Check result' : 'Start duty'}
            onSubmit={() => {
                if (submit.unconfirmed) return submit.checkResult();
                form.transform((data) => ({
                    name: data.name.trim(),
                    shift_lead_user_id: Number(data.shift_lead_user_id),
                    team_members: data.team_members.map(Number),
                }));
                form.post('/control-room/shifts', submit.options);
            }}
        >
            <PopupField
                label="Duty name"
                htmlFor="duty-name"
                required
                error={form.errors.name}
            >
                <Input
                    id="duty-name"
                    value={form.data.name}
                    maxLength={255}
                    onChange={(event) => form.setData('name', event.target.value)}
                    placeholder="For example, Friday day duty"
                    autoComplete="off"
                />
            </PopupField>
            <PopupField
                label="Duty lead"
                required
                hint="Only people who can manage alerts can lead a duty."
                error={form.errors.shift_lead_user_id}
            >
                <PeoplePicker
                    value={form.data.shift_lead_user_id}
                    onChange={(value) => form.setData('shift_lead_user_id', value)}
                    people={people(eligibleLeads)}
                    placeholder="Choose the duty lead"
                />
            </PopupField>
            <PopupField
                label="Team members"
                hint="Optional — the people working the desk with the lead."
                error={form.errors.team_members}
            >
                <PeopleMultiPicker
                    value={form.data.team_members}
                    onChange={(value) => form.setData('team_members', value)}
                    people={people(staff)}
                    placeholder="Choose team members"
                />
            </PopupField>
        </PopupForm>
    );
}

const NOTE_TYPES = [
    { value: 'note', label: 'Note' },
    { value: 'action', label: 'Action' },
    { value: 'escalation', label: 'Escalation' },
    { value: 'decision', label: 'Decision' },
];

/** Add operator note — an observation, action or decision on the current duty. */
export function AddOperatorNotePopup({
    open,
    onClose,
    duty,
}: {
    open: boolean;
    onClose: () => void;
    duty: { id: number; name: string; starts_at: string; shift_lead: Person | null };
}) {
    const form = useForm<{
        type: string;
        content: string;
        is_pinned: boolean;
        requires_followup: boolean;
    }>({ type: 'note', content: '', is_pinned: false, requires_followup: false });
    const close = () => {
        form.reset();
        form.clearErrors();
        onClose();
    };
    const submit = useSubmitOutcome(close);

    return (
        <PopupForm
            open={open}
            onClose={close}
            size="md"
            icon={MessageSquarePlus}
            title="Add operator note"
            description="Record an observation, action or decision for this duty."
            context={{
                icon: Clock,
                title: duty.name,
                tag: duty.shift_lead ? `Lead: ${duty.shift_lead.name}` : undefined,
                note: `Started ${formatDateTime(duty.starts_at)}`,
            }}
            callout={submit.unconfirmed ? { tone: 'warning', content: UNCONFIRMED } : undefined}
            error={submit.flashError}
            dirty={form.isDirty}
            draftName={`Operator note for ${duty.name}`}
            processing={form.processing}
            submitDisabled={!submit.unconfirmed && !form.data.content.trim()}
            submitLabel={submit.unconfirmed ? 'Check result' : 'Add note'}
            onSubmit={() => {
                if (submit.unconfirmed) return submit.checkResult();
                form.post(`/control-room/shifts/${duty.id}/note`, submit.options);
            }}
        >
            <PopupField label="Note type" error={form.errors.type}>
                <SelectInput
                    value={form.data.type}
                    onChange={(value) => form.setData('type', value)}
                    placeholder="Choose a note type"
                    ariaLabel="Note type"
                    options={NOTE_TYPES}
                />
            </PopupField>
            <PopupField
                label="Note"
                htmlFor="operator-note-content"
                required
                hint={`${form.data.content.length}/2000`}
                error={form.errors.content}
            >
                <Textarea
                    id="operator-note-content"
                    rows={4}
                    maxLength={2000}
                    value={form.data.content}
                    onChange={(event) => form.setData('content', event.target.value)}
                    placeholder="What happened, what you did or what was decided"
                />
            </PopupField>
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="flex items-start gap-2.5">
                    <Checkbox
                        id="operator-note-pinned"
                        checked={form.data.is_pinned}
                        onCheckedChange={(value) => form.setData('is_pinned', value === true)}
                        className="mt-0.5"
                    />
                    <Label htmlFor="operator-note-pinned" className="grid gap-0.5 leading-snug">
                        <span className="font-semibold">Pin note</span>
                        <span className="text-caption font-normal">
                            Stays at the top and goes into the handover.
                        </span>
                    </Label>
                </div>
                <div className="flex items-start gap-2.5">
                    <Checkbox
                        id="operator-note-followup"
                        checked={form.data.requires_followup}
                        onCheckedChange={(value) =>
                            form.setData('requires_followup', value === true)
                        }
                        className="mt-0.5"
                    />
                    <Label htmlFor="operator-note-followup" className="grid gap-0.5 leading-snug">
                        <span className="font-semibold">Requires follow-up</span>
                        <span className="text-caption font-normal">
                            Listed for the incoming lead in the handover.
                        </span>
                    </Label>
                </div>
            </div>
        </PopupForm>
    );
}
