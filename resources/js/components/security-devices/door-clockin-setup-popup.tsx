/**
 * Pop-up 92 (S&D Phase H, decision D15): turn on clock-ins from doors, or change which doors
 * count and the window. Adding a door always sends the notice staff must get first (Privacy Act
 * IPP3; door events used for a second purpose, IPP10), and it counts only from a start day at
 * least a week later. Faces and fingerprints (D18) and residents' fobs (D19) are never used, and
 * that isn't a choice. Removing a door takes effect at once, without a notice.
 */
import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { PopupField, PopupForm, PopupSection } from '@/components/popup';
import { Checkbox } from '@/components/ui/checkbox';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { toDateInput } from '@/lib/datetime';
import { defaultNotice, doorPlace, formatDay, sitesWords, type ClockinPlace, type SetupClockinsData } from '@/lib/security-devices/door-clockins';
import axios from 'axios';
import { Check, Clock, DoorOpen, Megaphone, Shield } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

type Errors = Partial<Record<'door_ids' | 'before' | 'after' | 'notice' | 'starts_on', string>>;

function placesFor(data: SetupClockinsData, ids: number[]): ClockinPlace[] {
    const bySite = new Map<string, string[]>();
    for (const door of data.doors.filter((option) => ids.includes(option.id))) {
        bySite.set(door.site.name, [...(bySite.get(door.site.name) ?? []), door.name]);
    }
    return [...bySite.entries()].sort(([a], [b]) => a.localeCompare(b)).map(([site, doors]) => ({ site, doors }));
}

export function DoorClockinSetupPopup({ open, data, onClose, onSaved }: { open: boolean; data: SetupClockinsData; onClose: () => void; onSaved: () => void }) {
    const counted = useMemo(() => data.doors.filter((door) => door.counts).map((door) => door.id), [data.doors]);
    const [doorIds, setDoorIds] = useState<number[]>(counted);
    const [before, setBefore] = useState(data.settings.before);
    const [after, setAfter] = useState(data.settings.after);
    const [startsOn, setStartsOn] = useState(data.earliest_start);
    const [email, setEmail] = useState(true);
    const [notice, setNotice] = useState('');
    const [edited, setEdited] = useState(false);
    const [errors, setErrors] = useState<Errors>({});
    const [failure, setFailure] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (!open) return;
        setDoorIds(counted);
        setBefore(data.settings.before);
        setAfter(data.settings.after);
        setStartsOn(data.earliest_start);
        setEmail(true);
        setEdited(false);
        setErrors({});
        setFailure(null);
    }, [open, counted, data.settings.before, data.settings.after, data.earliest_start]);

    const added = doorIds.filter((id) => !counted.includes(id));
    const removed = counted.filter((id) => !doorIds.includes(id));
    const needsNotice = added.length > 0;
    const addedPlaces = placesFor(data, added);
    const turningOn = data.state === 'off';

    // The notice follows the doors and the day until the person writes their own.
    const suggested = defaultNotice({ startsOn, places: addedPlaces, me: data.me });
    useEffect(() => {
        if (!edited) setNotice(suggested);
    }, [suggested, edited]);

    const dirty = edited || added.length > 0 || removed.length > 0 || before !== data.settings.before || after !== data.settings.after;
    const told = [...new Set(data.doors.filter((door) => added.includes(door.id)).map((door) => door.site.id))].reduce((sum, siteId) => sum + (data.told_by_site[String(siteId)] ?? 0), 0);

    const submit = async () => {
        const next: Errors = {};
        if (doorIds.length === 0 && counted.length === 0) next.door_ids = 'Choose at least one entry door.';
        if (needsNotice && notice.trim().length < 40) next.notice = 'Write the notice staff get before it starts.';
        if (needsNotice && (!startsOn || startsOn < data.earliest_start)) next.starts_on = `It starts at least a week after the notice goes out: ${formatDay(data.earliest_start)} or later.`;
        setErrors(next);
        if (Object.keys(next).length > 0) return;
        setSaving(true);
        setFailure(null);
        try {
            const { data: result } = await axios.put<{ added: number; removed: number; told: number; not_told: number; starts_on: string | null }>(
                '/security-devices/setup/clockins/doors',
                { door_ids: doorIds, before, after, notice: needsNotice ? notice.trim() : null, email, starts_on: needsNotice ? startsOn : null },
                { headers: { Accept: 'application/json' } },
            );
            if (result.added > 0) {
                const people = result.told === 1 ? '1 person' : `${result.told} people`;
                toast.success(`Notice sent to ${people}. It starts on ${formatDay(result.starts_on)}.`, {
                    description: result.not_told > 0 ? `${result.not_told === 1 ? '1 person' : `${result.not_told} people`} with a card aren’t matched to staff, so they weren’t told. They’re never suggested anything.` : undefined,
                });
            } else {
                toast.success('Saved');
            }
            onSaved();
            onClose();
        } catch (problem) {
            const response = axios.isAxiosError(problem) ? problem.response : undefined;
            const serverErrors = (response?.data as { errors?: Record<string, string[]> } | undefined)?.errors;
            if (serverErrors) {
                setErrors(Object.fromEntries(Object.entries(serverErrors).map(([key, value]) => [key, value[0]])) as Errors);
                setFailure(Object.values(serverErrors)[0]?.[0] ?? null);
            } else {
                setFailure(response?.status === 403 ? 'You can’t change clock-ins from doors.' : response ? 'It wasn’t saved. Try again.' : 'Result not confirmed. Reload the page to see whether the notice went out before trying again.');
            }
        } finally {
            setSaving(false);
        }
    };

    const sites = sitesWords(placesFor(data, needsNotice ? added : doorIds));
    const today = toDateInput(new Date());
    const submitLabel = needsNotice ? (turningOn ? 'Send notice and turn on' : 'Send notice and save') : 'Save changes';

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            size="md"
            icon={Clock}
            title={turningOn ? 'Turn on clock-ins from doors' : 'Change clock-ins from doors'}
            description="Badges near a rostered start suggest a clock-in; each person confirms it"
            callout={{
                tone: 'info',
                content: (
                    <>
                        <b className="font-semibold">Staff are told before it starts.</b> Door events are kept for building security; using them for clock-ins is a second use, so everyone with a card at{' '}
                        {sites || 'the Sites you choose'} gets the notice below first.
                    </>
                ),
            }}
            error={failure ?? undefined}
            submitLabel={submitLabel}
            processing={saving}
            processingLabel={needsNotice ? 'Sending…' : 'Saving…'}
            onSubmit={submit}
            dirty={dirty}
            draftName="Clock-ins from doors"
            testId="door-clockin-92"
        >
            <PopupSection icon={DoorOpen} title="Doors that count">
                {data.doors.length === 0 ? (
                    <p className="text-subtle">No doors at your Sites yet. Connect UniFi Access in Setup › Doors & access first.</p>
                ) : (
                    <div className="grid gap-2.5" role="group" aria-label="Doors that count">
                        {data.doors.map((door) => {
                            const id = `clockin-door-${door.id}`;
                            const ticked = doorIds.includes(door.id);
                            const hint = door.counts
                                ? door.starts_on && door.starts_on > today
                                    ? `counts from ${formatDay(door.starts_on)}`
                                    : 'counts now'
                                : door.readers.join(' · ');
                            return (
                                <label key={door.id} htmlFor={id} className="flex cursor-pointer items-start gap-2.5">
                                    <Checkbox
                                        id={id}
                                        checked={ticked}
                                        onCheckedChange={(checked) => setDoorIds((current) => (checked === true ? [...current, door.id] : current.filter((value) => value !== door.id)))}
                                        className="mt-0.5"
                                    />
                                    <span className="grid gap-0.5">
                                        <span className="text-sm font-medium">{doorPlace(door.site.name, door.name)}</span>
                                        {hint ? <span className="text-caption">{hint}</span> : null}
                                    </span>
                                </label>
                            );
                        })}
                        <p className="text-caption">Entry doors only: not inside doors like a staff office or a server room.</p>
                    </div>
                )}
                {errors.door_ids ? <p className="text-sm text-status-critical">{errors.door_ids}</p> : null}
            </PopupSection>

            <PopupSection icon={Clock} title="When" columns={2}>
                <PopupField label="From" htmlFor="clockin-before" error={errors.before}>
                    <Select value={String(before)} onValueChange={(value) => setBefore(Number(value))} disabled={!data.can.shared}>
                        <SelectTrigger id="clockin-before" aria-label="From">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {data.options.before.map((minutes) => (
                                <SelectItem key={minutes} value={String(minutes)}>
                                    {minutes} min before a rostered start
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </PopupField>
                <PopupField label="Until" htmlFor="clockin-after" error={errors.after}>
                    <Select value={String(after)} onValueChange={(value) => setAfter(Number(value))} disabled={!data.can.shared}>
                        <SelectTrigger id="clockin-after" aria-label="Until">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {data.options.after.map((minutes) => (
                                <SelectItem key={minutes} value={String(minutes)}>
                                    {minutes} min after
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </PopupField>
                <PopupField label="Clock-out" wide>
                    <p className="text-sm">Not taken from doors: exit buttons don’t badge</p>
                </PopupField>
            </PopupSection>

            <PopupSection icon={Shield} title="Never used" columns={2}>
                <PopupField label="Faces and fingerprints" hint="NZ Biometric Processing Privacy Code">
                    <p className="text-sm">Never, even on readers that can use them</p>
                </PopupField>
                <PopupField label="Residents’ fobs">
                    <p className="text-sm">Never: it’s their home, not work</p>
                </PopupField>
            </PopupSection>

            <PopupSection icon={Megaphone} title="What staff are told">
                {needsNotice ? (
                    <>
                        <PopupField label="Notice" htmlFor="clockin-notice" required error={errors.notice} hint={told > 0 ? `Goes to ${told === 1 ? '1 person' : `${told} people`} matched to staff with a card at ${sitesWords(addedPlaces)}` : 'Nobody matched to staff has a card there yet'}>
                            <Textarea
                                id="clockin-notice"
                                value={notice}
                                onChange={(event) => {
                                    setNotice(event.target.value);
                                    setEdited(true);
                                }}
                                rows={7}
                            />
                        </PopupField>
                        <PopupField label="Send by">
                            <ToggleGroup type="multiple" variant="outline" value={email ? ['app', 'email'] : ['app']} onValueChange={(next) => setEmail(next.includes('email'))} aria-label="Send by" className="justify-start">
                                <ToggleGroupItem value="app" disabled aria-label="App (always)" className="gap-1.5 px-3">
                                    <Check aria-hidden="true" className="size-3.5" />
                                    App
                                </ToggleGroupItem>
                                <ToggleGroupItem value="email" className="gap-1.5 px-3">
                                    {email ? <Check aria-hidden="true" className="size-3.5" /> : null}
                                    Email
                                </ToggleGroupItem>
                            </ToggleGroup>
                        </PopupField>
                        <PopupField label="Starts" htmlFor="clockin-starts" required hint="a week after the notice goes out" error={errors.starts_on}>
                            <DatePicker id="clockin-starts" label="Starts" value={startsOn} onChange={setStartsOn} invalid={Boolean(errors.starts_on)} />
                        </PopupField>
                    </>
                ) : (
                    <p className="text-subtle">
                        {removed.length > 0
                            ? 'No new doors, so no notice is needed. Doors you untick stop counting straight away; confirmed clock-ins stay.'
                            : 'Tick a door to write the notice. Staff are told before any new door counts.'}
                    </p>
                )}
            </PopupSection>
        </PopupForm>
    );
}
