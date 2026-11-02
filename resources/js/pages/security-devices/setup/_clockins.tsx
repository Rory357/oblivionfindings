/**
 * Setup › Doors & access › Clock-ins from doors (S&D Phase H, decision D15; drawing pg-sdr-15n):
 * whether it's on, this morning in one line, how a badge suggests a clock-in, who hears about gaps
 * and what is kept. Turning it on (pop-up 92) sends the notice first and starts a week later; the
 * other rows are a draft saved after "Review changes" (Fleet's settings pattern).
 */
import { PopupConfirm } from '@/components/popup';
import { OnOff, SaveBar, SettingGroup, SettingRow } from '@/components/referrals/settings-ui';
import { DoorClockinSetupPopup } from '@/components/security-devices/door-clockin-setup-popup';
import { SettingsModal } from '@/components/settings/settings-modal';
import { FactRows, Ring, Surface } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Switch } from '@/components/ui/switch';
import { formatDate } from '@/lib/datetime';
import { formatDay, placesWords, sitesWords, windowWords, type SetupClockinsData } from '@/lib/security-devices/door-clockins';
import { Link, router } from '@inertiajs/react';
import axios from 'axios';
import { Bell, ChevronDown, Clock, DoorOpen } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';

type DraftKey = 'before_minutes' | 'after_minutes' | 'badge_time' | `gap_${'unconfirmed' | 'no_badge' | 'not_rostered'}_${'app' | 'email'}`;
type DraftValue = number | boolean;

const GAP_ROWS: { key: 'unconfirmed' | 'no_badge' | 'not_rostered'; label: string; hint: string }[] = [
    { key: 'unconfirmed', label: 'Badged in, but hasn’t confirmed', hint: 'A reminder to the person' },
    { key: 'no_badge', label: 'On the roster, no badge and no clock-in', hint: 'The house manager' },
    { key: 'not_rostered', label: 'Badged in, not on the roster', hint: 'The house manager' },
];

const DRAFT_WORDS: Record<DraftKey, string> = {
    before_minutes: 'Window: from',
    after_minutes: 'Window: until',
    badge_time: 'Clock in at the badge time',
    gap_unconfirmed_app: 'Badged in, but hasn’t confirmed: in-app',
    gap_unconfirmed_email: 'Badged in, but hasn’t confirmed: email',
    gap_no_badge_app: 'On the roster, no badge and no clock-in: in-app',
    gap_no_badge_email: 'On the roster, no badge and no clock-in: email',
    gap_not_rostered_app: 'Badged in, not on the roster: in-app',
    gap_not_rostered_email: 'Badged in, not on the roster: email',
};

function savedValues(data: SetupClockinsData): Record<DraftKey, DraftValue> {
    const gaps = data.settings.gaps;
    return {
        before_minutes: data.settings.before,
        after_minutes: data.settings.after,
        badge_time: data.settings.badge_time,
        gap_unconfirmed_app: gaps.unconfirmed.app,
        gap_unconfirmed_email: gaps.unconfirmed.email,
        gap_no_badge_app: gaps.no_badge.app,
        gap_no_badge_email: gaps.no_badge.email,
        gap_not_rostered_app: gaps.not_rostered.app,
        gap_not_rostered_email: gaps.not_rostered.email,
    };
}

function valueWords(key: DraftKey, value: DraftValue): string {
    if (key === 'before_minutes') return `${value} min before`;
    if (key === 'after_minutes') return `${value} min after`;
    return value ? 'On' : 'Off';
}

/** The state card at the top (15n "next"). */
function StateCard({ data, onTurnOn, onTurnOff }: { data: SetupClockinsData; onTurnOn: () => void; onTurnOff: () => void }) {
    const sites = sitesWords(data.places);
    const told = data.notice ? `Staff were told on ${formatDate(data.notice.sent_at)}.` : '';
    let label: string;
    let title: string;
    let sub: string;
    let tone: 'success' | 'info' | 'none';
    if (data.state === 'on') {
        tone = 'success';
        label = data.since ? `On since ${formatDay(data.since)}` : 'On';
        title = `Clock-ins from doors are on at ${sites}`;
        const today = data.today;
        sub = [
            told,
            today
                ? `This morning: ${today.badge} confirmed from a badge, ${today.other_way} clocked in another way, ${today.gaps === 1 ? '1 gap' : `${today.gaps} gaps`} for managers.`
                : '',
        ]
            .filter(Boolean)
            .join(' ');
    } else if (data.state === 'starting') {
        tone = 'info';
        label = `Starts ${formatDay(data.starts_on)}`;
        title = `Clock-ins from doors start on ${formatDay(data.starts_on)} at ${sites}`;
        sub = `${told} Nothing is suggested before then.`.trim();
    } else {
        tone = 'none';
        label = data.turned_off_at ? `Off since ${formatDate(data.turned_off_at)}` : 'Off';
        title = 'Clock-ins from doors are off';
        sub = 'No door event touches attendance. Turning it on sends staff a notice first, and it starts a week later.';
    }

    return (
        <Surface aria-label="Clock-ins from doors">
            <div className="flex flex-wrap items-center gap-4 px-[18px] py-4">
                <span className="popup-lens" aria-hidden="true">
                    <DoorOpen className="size-5" />
                </span>
                <div className="grid min-w-0 flex-1 basis-80 gap-1">
                    <p className="text-caption flex items-center gap-2 font-semibold">
                        <Ring tone={tone} />
                        {label}
                    </p>
                    <h2 className="text-section-title">{title}</h2>
                    {sub ? <p className="text-subtle">{sub}</p> : null}
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    {data.state !== 'off' && data.can.change ? (
                        <Button type="button" variant="outline" onClick={onTurnOff}>
                            Turn off
                        </Button>
                    ) : null}
                    {data.state === 'off' && data.can.change ? (
                        <Button type="button" onClick={onTurnOn}>
                            Turn on
                        </Button>
                    ) : null}
                    {data.state === 'on' && data.can.review ? (
                        <Button asChild>
                            <Link href="/security-devices/access?tab=clockins">Today’s clock-ins</Link>
                        </Button>
                    ) : null}
                </div>
            </div>
        </Surface>
    );
}

export function SetupClockins({ data }: { data: SetupClockinsData }) {
    const saved = useMemo(() => savedValues(data), [data]);
    const [draft, setDraft] = useState<Partial<Record<DraftKey, DraftValue>>>({});
    const [popup, setPopup] = useState(false);
    const [confirmOff, setConfirmOff] = useState(false);
    const [turningOff, setTurningOff] = useState(false);
    const [offError, setOffError] = useState<string | null>(null);
    const [reviewing, setReviewing] = useState(false);
    const [saving, setSaving] = useState(false);
    const [saveError, setSaveError] = useState<string | null>(null);
    const canChange = data.can.change;
    // The window, the badge-time switch and gap alerts apply to every Site.
    const canShared = canChange && data.can.shared;

    useEffect(() => setDraft({}), [data]);

    // Unsaved switches: leaving asks first (Fleet's unsaved-draft guard, as the rest of Setup).
    const dirty = Object.keys(draft).length > 0;
    const [leaving, setLeaving] = useState<string | null>(null);
    const allowLeave = useRef(false);
    useEffect(() => {
        if (!dirty) return;
        const beforeUnload = (event: BeforeUnloadEvent) => {
            if (allowLeave.current) return;
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', beforeUnload);
        const remove = router.on('before', (event) => {
            const visit = event.detail.visit;
            if (allowLeave.current || visit.method !== 'get' || visit.only.length > 0) return;
            event.preventDefault();
            setLeaving(visit.url.toString());
        });
        return () => {
            window.removeEventListener('beforeunload', beforeUnload);
            remove();
        };
    }, [dirty]);

    const value = (key: DraftKey): DraftValue => (key in draft ? (draft[key] as DraftValue) : saved[key]);
    const set = (key: DraftKey, next: DraftValue) =>
        setDraft((current) => {
            const copy = { ...current };
            if (saved[key] === next) delete copy[key];
            else copy[key] = next;
            return copy;
        });
    const changes = Object.entries(draft) as [DraftKey, DraftValue][];
    const hint = (words: string) => (canChange ? words : `${words}. You can’t change this here.`);
    const sharedHint = (words: string) => (canShared ? words : canChange ? `${words}. It applies to every Site, so only someone who looks after every Site changes it.` : `${words}. You can’t change this here.`);
    const reload = () => router.reload({ only: ['clockins', 'meters'] });

    const save = async () => {
        setSaving(true);
        setSaveError(null);
        try {
            await axios.patch('/security-devices/setup/clockins/settings', { changes: Object.fromEntries(changes) }, { headers: { Accept: 'application/json' } });
            setReviewing(false);
            toast.success(changes.length === 1 ? '1 change saved' : `${changes.length} changes saved`);
            reload();
        } catch (problem) {
            const response = axios.isAxiosError(problem) ? problem.response : undefined;
            const errors = (response?.data as { errors?: Record<string, string[]> } | undefined)?.errors;
            setSaveError(errors ? (Object.values(errors)[0]?.[0] ?? null) : response?.status === 403 ? 'You can’t change these settings.' : response ? 'They weren’t saved. Try again.' : 'Result not confirmed. Reload the page to see whether they were saved.');
        } finally {
            setSaving(false);
        }
    };

    const turnOff = async () => {
        setTurningOff(true);
        setOffError(null);
        try {
            await axios.post('/security-devices/setup/clockins/off', {}, { headers: { Accept: 'application/json' } });
            setConfirmOff(false);
            toast.success('Clock-ins from doors are off');
            reload();
        } catch (problem) {
            const response = axios.isAxiosError(problem) ? problem.response : undefined;
            setOffError(response?.status === 403 ? 'You can’t change clock-ins from doors.' : response ? 'It wasn’t turned off. Try again.' : 'Result not confirmed. Reload the page to check before trying again.');
        } finally {
            setTurningOff(false);
        }
    };

    const doorsWords = data.places.length ? placesWords(data.places) : 'None yet';

    return (
        <div className="flex flex-col gap-5">
            <StateCard data={data} onTurnOn={() => setPopup(true)} onTurnOff={() => setConfirmOff(true)} />

            <SettingGroup icon={Clock} title="How a badge suggests a clock-in" caption="Nothing is clocked in until the person confirms">
                <SettingRow
                    label="Suggest a clock-in from a door badge"
                    hint={hint(
                        data.state === 'off'
                            ? 'Off · turning it on sends staff a notice first, and it starts a week later'
                            : `${data.state === 'on' && data.since ? `On since ${formatDay(data.since)}` : `Starts ${formatDay(data.starts_on)}`} · turning it off stops new suggestions; confirmed clock-ins stay`,
                    )}
                    control={
                        <OnOff
                            label="Suggest a clock-in from a door badge"
                            checked={data.state !== 'off'}
                            disabled={!canChange}
                            onChange={(next) => (next ? setPopup(true) : setConfirmOff(true))}
                        />
                    }
                />
                <SettingRow
                    label="Window around a rostered start"
                    hint={sharedHint('Badges outside it don’t suggest anything')}
                    control={
                        <span className="flex flex-wrap items-center gap-2">
                            <Select value={String(value('before_minutes'))} onValueChange={(next) => set('before_minutes', Number(next))} disabled={!canShared}>
                                <SelectTrigger className="w-36" aria-label="Window: from">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {data.options.before.map((minutes) => (
                                        <SelectItem key={minutes} value={String(minutes)}>
                                            {minutes} min before
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <span aria-hidden="true">–</span>
                            <Select value={String(value('after_minutes'))} onValueChange={(next) => set('after_minutes', Number(next))} disabled={!canShared}>
                                <SelectTrigger className="w-32" aria-label="Window: until">
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
                        </span>
                    }
                />
                <SettingRow
                    label="Doors that count"
                    hint={hint('Entry doors only, not the server room or staff office')}
                    control={
                        <Button type="button" variant="outline" className="max-w-[28rem] justify-between gap-2" disabled={!canChange} onClick={() => setPopup(true)} aria-label={`Doors that count: ${doorsWords}. Change`}>
                            <span className="truncate">{doorsWords}</span>
                            <ChevronDown aria-hidden="true" className="size-4 shrink-0" />
                        </Button>
                    }
                />
                <SettingRow
                    label="Clock in at the badge time"
                    hint={sharedHint('They can choose “Clock in now instead”')}
                    control={<OnOff label="Clock in at the badge time" checked={value('badge_time') === true} disabled={!canShared} onChange={(next) => set('badge_time', next)} />}
                />
                <SettingRow
                    label="Clock-out from doors"
                    hint="Most doors open from the inside with a button, so there’s no badge on the way out"
                    control={
                        <StatusBadge variant="neutral" size="sm">
                            Not used
                        </StatusBadge>
                    }
                />
                <SettingRow
                    label="Faces and fingerprints"
                    hint="Never used for clock-ins, even on readers that support them (decision D18, NZ Biometric Processing Privacy Code)"
                    control={
                        <StatusBadge variant="neutral" size="sm">
                            Never
                        </StatusBadge>
                    }
                />
            </SettingGroup>

            <SettingGroup
                icon={Bell}
                title="Who hears about gaps"
                caption="Checked 15 minutes after each rostered start"
                actions={
                    <span className="text-caption hidden gap-6 pr-1 sm:flex" aria-hidden="true">
                        <span className="w-12 text-center">In-app</span>
                        <span className="w-12 text-center">Email</span>
                    </span>
                }
            >
                {GAP_ROWS.map((row) => (
                    <SettingRow
                        key={row.key}
                        label={row.label}
                        hint={row.hint}
                        control={
                            <span className="flex gap-6">
                                {(['app', 'email'] as const).map((channel) => {
                                    const key = `gap_${row.key}_${channel}` as DraftKey;
                                    const name = `${row.label}: ${channel === 'app' ? 'In-app' : 'Email'}`;
                                    return (
                                        <span key={channel} className="flex w-12 flex-col items-center gap-1">
                                            <Switch checked={value(key) === true} onCheckedChange={(next) => set(key, next)} disabled={!canShared} aria-label={name} />
                                            <span className="text-caption sm:hidden" aria-hidden="true">
                                                {channel === 'app' ? 'In-app' : 'Email'}
                                            </span>
                                        </span>
                                    );
                                })}
                            </span>
                        }
                    />
                ))}
            </SettingGroup>

            <FactRows
                title="What is kept"
                rows={[
                    ['Confirmed clock-in', 'A time record with the timesheet, kept 6 years (Employment Relations Act and Holidays Act records)'],
                    ['The door event itself', 'Names kept 90 days (D14), like every other door event'],
                    ['A suggestion nobody confirmed', 'Not a time record; goes with the door event at 90 days'],
                ]}
            />

            {canChange ? (
                <SaveBar
                    count={changes.length}
                    onDiscard={() => setDraft({})}
                    onReview={() => setReviewing(true)}
                    busy={saving}
                    note="Nothing changes until you review and save. Each change is kept in Change history."
                />
            ) : null}

            {reviewing ? (
                <SettingsModal
                    title={`Save ${changes.length === 1 ? '1 change' : `${changes.length} changes`} to clock-ins from doors?`}
                    description="Setup › Review changes"
                    onClose={() => setReviewing(false)}
                    footer={
                        <>
                            <Button variant="outline" onClick={() => setReviewing(false)} disabled={saving}>
                                Back to editing
                            </Button>
                            <Button onClick={save} disabled={saving}>
                                {saving ? 'Saving…' : 'Save changes'}
                            </Button>
                        </>
                    }
                >
                    <ul className="grid gap-2">
                        {changes.map(([key, next]) => (
                            <li key={key} className="text-sm">
                                <b>{DRAFT_WORDS[key]}:</b> {valueWords(key, saved[key])} → {valueWords(key, next)}
                            </li>
                        ))}
                    </ul>
                    {changes.some(([key]) => key === 'before_minutes' || key === 'after_minutes') ? (
                        <p className="text-caption mt-3">New window: {windowWords(Number(value('before_minutes')), Number(value('after_minutes')))}</p>
                    ) : null}
                    {saveError ? (
                        <p role="alert" className="mt-3 text-sm text-status-critical">
                            {saveError}
                        </p>
                    ) : null}
                </SettingsModal>
            ) : null}

            {canChange ? <DoorClockinSetupPopup open={popup} data={data} onClose={() => setPopup(false)} onSaved={reload} /> : null}

            <PopupConfirm
                open={leaving !== null}
                onClose={() => setLeaving(null)}
                onConfirm={() => {
                    const target = leaving;
                    allowLeave.current = true;
                    setLeaving(null);
                    if (target) router.visit(target);
                }}
                variant="warning"
                title="Leave without saving?"
                description={changes.length === 1 ? 'Your change to 1 setting hasn’t been saved and will be lost.' : `Your changes to ${changes.length} settings haven’t been saved and will be lost.`}
                cancelLabel="Keep editing"
                confirmLabel="Leave without saving"
            />

            <PopupConfirm
                open={confirmOff}
                onClose={() => setConfirmOff(false)}
                onConfirm={turnOff}
                icon={DoorOpen}
                title="Turn off clock-ins from doors?"
                description={`Badges stop suggesting clock-ins at ${sitesWords(data.places) || 'your Sites'} straight away, and suggestions nobody answered close. Clock-ins people already confirmed stay on their timesheets. Turning it on again tells staff first.`}
                confirmLabel="Turn off"
                processing={turningOff}
                processingLabel="Turning off…"
                error={offError ?? undefined}
            />
        </div>
    );
}
