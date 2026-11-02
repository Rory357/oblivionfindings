/**
 * Pop-up 65, Link to a person (S&D rebuild, Phase F): use a sensor in someone's care, like their
 * falls plan. The person's consent to monitoring sensors is checked before anything is linked; when
 * it isn't active the main button stays off and the pop-up says where to record it. Alerts from the
 * link go to whoever is on shift at the house.
 */
import { TimePicker } from '@/components/fleet-assets/maintenance/time-picker';
import { PopupCallout, PopupFacts, PopupField, PopupForm } from '@/components/popup';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { TilePicker } from '@/components/wizard/primitives';
import { formatDate } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import axios from 'axios';
import { Bed, DoorOpen, Ellipsis, Link2, Thermometer } from 'lucide-react';
import { useEffect, useState } from 'react';

type Purpose = 'falls' | 'wandering' | 'room' | 'other';

type Person = {
    id: number;
    name: string;
    consent: { active: boolean; consent_id: number | null; until: string | null };
    record_href: string;
    plans: { id: number; title: string; review: string | null }[];
};

type Options = { site: { id: number | null; name: string | null }; people: Person[] };

const PURPOSES: { key: Purpose; label: string; description: string; icon: typeof Bed }[] = [
    { key: 'falls', label: 'Falls plan', description: 'Tell night staff if out of bed too long', icon: Bed },
    { key: 'wandering', label: 'Wandering', description: 'Tell staff if a door opens at night', icon: DoorOpen },
    { key: 'room', label: 'Room readings', description: 'Temperature or air in their room', icon: Thermometer },
    { key: 'other', label: 'Other', description: 'Say why in the note', icon: Ellipsis },
];

export function PersonLinkPopup({
    open,
    device,
    onClose,
    onLinked,
}: {
    open: boolean;
    device: { id: number; name: string; site?: string | null; note?: string | null };
    onClose: () => void;
    onLinked: () => void;
}) {
    const [options, setOptions] = useState<Options | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [personId, setPersonId] = useState<string>('');
    const [purpose, setPurpose] = useState<Purpose>('falls');
    const [planId, setPlanId] = useState<string>('');
    const [minutes, setMinutes] = useState('10');
    const [from, setFrom] = useState('22:00');
    const [to, setTo] = useState('06:00');
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | undefined>(undefined);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

    useEffect(() => {
        if (!open) return;
        let cancelled = false;
        setOptions(null);
        setLoadError(null);
        axios
            .get<Options>(`/security-devices/devices/${device.id}/care-link/options`)
            .then((response) => {
                if (!cancelled) setOptions(response.data);
            })
            .catch(() => {
                if (!cancelled) setLoadError('The people at this house couldn’t be loaded. Close this and try again.');
            });
        return () => {
            cancelled = true;
        };
    }, [open, device.id]);

    const person = options?.people.find((row) => String(row.id) === personId) ?? null;
    const hasRule = purpose === 'falls' || purpose === 'wandering';
    const minutesNumber = Number.parseInt(minutes, 10);
    const ruleWords =
        purpose === 'falls'
            ? `Alert night staff if ${person?.name ?? 'they are'} out of bed for over ${Number.isFinite(minutesNumber) ? minutesNumber : '…'} minutes between ${from} and ${to}`
            : purpose === 'wandering'
              ? `Alert staff on shift if the door opens between ${from} and ${to}`
              : null;

    const submit = async () => {
        if (!person) return;
        setBusy(true);
        setError(undefined);
        setFieldErrors({});
        try {
            await axios.post(`/security-devices/devices/${device.id}/care-link`, {
                client_id: person.id,
                purpose,
                care_plan_id: planId === '' ? null : Number(planId),
                rule: purpose === 'falls' ? { minutes: minutesNumber, from, to } : purpose === 'wandering' ? { from, to } : null,
                note: note.trim() === '' ? null : note.trim(),
            });
            onLinked();
        } catch (failure) {
            const response = axios.isAxiosError(failure) ? failure.response : undefined;
            if (response?.status === 422 && response.data?.errors) {
                const errors = Object.fromEntries(Object.entries(response.data.errors as Record<string, string[]>).map(([key, list]) => [key, list[0] ?? '']));
                setFieldErrors(errors);
                setError(errors.client_id ?? errors.rule ?? errors.care_plan_id ?? errors.purpose ?? 'Check the highlighted fields.');
            } else {
                setError(response?.status === 403 ? 'You can’t link devices to people.' : response ? 'It wasn’t linked. Try again.' : 'Result not confirmed. Reload to check whether it was linked.');
            }
        } finally {
            setBusy(false);
        }
    };

    const consentBlocked = person !== null && !person.consent.active;

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            size="md"
            icon={Link2}
            title="Link to a person"
            description="Use this device in someone’s care, like their falls plan."
            context={{ title: device.name, tag: device.site ?? undefined, note: device.note ?? undefined }}
            error={error ?? loadError ?? undefined}
            submitLabel="Link device"
            processingLabel="Linking…"
            processing={busy}
            submitDisabled={person === null || consentBlocked || (purpose === 'falls' && !(minutesNumber >= 1 && minutesNumber <= 120)) || (hasRule && from === to)}
            onSubmit={() => void submit()}
            dirty={personId !== ''}
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <PopupField label="Person" htmlFor="sd-link-person" required error={fieldErrors.client_id} wide>
                    <Select value={personId} onValueChange={(value) => { setPersonId(value); setPlanId(''); }} disabled={options === null}>
                        <SelectTrigger id="sd-link-person" aria-invalid={!!fieldErrors.client_id}>
                            <SelectValue placeholder={options === null ? 'Loading people…' : options.people.length === 0 ? 'No one lives here yet' : 'Choose a person'} />
                        </SelectTrigger>
                        <SelectContent>
                            {(options?.people ?? []).map((row) => (
                                <SelectItem key={row.id} value={String(row.id)}>
                                    {row.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </PopupField>

                <PopupField label="Why" required wide>
                    <TilePicker value={purpose} onChange={(value) => setPurpose(value as Purpose)} options={PURPOSES.map((option) => ({ key: option.key, label: option.label, description: option.description, icon: option.icon }))} cols={2} />
                </PopupField>

                {person !== null && person.plans.length > 0 ? (
                    <PopupField label="Care plan" htmlFor="sd-link-plan" hint={`From ${person.name}’s care plans`} error={fieldErrors.care_plan_id} wide>
                        <Select value={planId} onValueChange={setPlanId}>
                            <SelectTrigger id="sd-link-plan">
                                <SelectValue placeholder="Choose a care plan (optional)" />
                            </SelectTrigger>
                            <SelectContent>
                                {person.plans.map((plan) => (
                                    <SelectItem key={plan.id} value={String(plan.id)}>
                                        {plan.title}
                                        {plan.review ? ` · review ${formatDate(plan.review)}` : ''}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </PopupField>
                ) : null}

                {purpose === 'falls' ? (
                    <PopupField label="Out of bed for over" htmlFor="sd-link-minutes" required hint="minutes, at night" error={fieldErrors.rule}>
                        <Input id="sd-link-minutes" type="number" min={1} max={120} inputMode="numeric" value={minutes} onChange={(event) => setMinutes(event.target.value)} />
                    </PopupField>
                ) : null}
                {hasRule ? (
                    <>
                        <PopupField label="Night from" htmlFor="sd-link-from" required>
                            <TimePicker id="sd-link-from" label="Night hours start" value={from} onChange={setFrom} />
                        </PopupField>
                        <PopupField label="Until" htmlFor="sd-link-to" required hint={to < from ? 'the next morning' : undefined}>
                            <TimePicker id="sd-link-to" label="Night hours end" value={to} onChange={setTo} />
                        </PopupField>
                    </>
                ) : null}
                {purpose === 'other' ? (
                    <PopupField label="Note" htmlFor="sd-link-note" hint="Why this device is linked" wide>
                        <Input id="sd-link-note" value={note} maxLength={500} onChange={(event) => setNote(event.target.value)} />
                    </PopupField>
                ) : null}
            </div>

            <PopupFacts
                rows={[
                    ['Who’s told', `Staff on shift at ${options?.site.name ?? 'the house'}`],
                    ...(ruleWords ? ([['What the plan says', ruleWords]] as [string, string][]) : []),
                ]}
            />

            {person !== null ? (
                person.consent.active ? (
                    <PopupCallout tone="success">
                        Consent checked: {person.name}’s consent to monitoring sensors is active{person.consent.until ? ` (until ${formatDate(person.consent.until)})` : ''}.
                    </PopupCallout>
                ) : (
                    <PopupCallout tone="warning">
                        {person.name}’s consent to monitoring sensors isn’t active. Record it in Clients first.{' '}
                        <Link href={person.record_href} className="underline underline-offset-2">
                            Record consent
                        </Link>
                    </PopupCallout>
                )
            ) : null}
        </PopupForm>
    );
}
