/**
 * Setup's settings, kept as a draft until "Review changes" (Fleet settings pattern): changed
 * rows are marked, the save bar counts them, and the review (pop-up 80) lists every change old →
 * new, calls out one that loosens a safety check, asks why, and saves it with the destructive
 * button.
 */
import { OnOff, SaveBar, SettingGroup, SettingRow } from '@/components/referrals/settings-ui';
import { SettingsModal } from '@/components/settings/settings-modal';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { router } from '@inertiajs/react';
import axios from 'axios';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useMemo, useState, type ReactNode } from 'react';
import type { SettingRow as SettingData } from './_types';

type Value = boolean | string | number | null;

/** What still gets through during quiet hours (Stephan, 6 Nov: routers and hubs, "Let them through"). */
export const QUIET_HOURS_HINT =
    'Safety alerts, care rules, emergencies and internet-exposure checks never wait · a router, gateway or hub that stops answering still tells IT on call straight away';

export const SETTING_WORDS: Record<string, { label: string; hint: string; loosens?: string }> = {
    'scans.daily': {
        label: 'Scan every day',
        hint: 'At 6:00 am unless a network has its own time. Scans are gentle: at most 20 checks a second',
    },
    'scans.server_direct': {
        label: 'Let the Oblivion server scan networks it can reach directly',
        hint: 'Only when the server is inside your network or has a VPN to it',
        loosens: 'The Oblivion server will reach into networks itself, not only through each Site’s hub.',
    },
    'notify.new_devices.app': { label: 'Tell me when a new device appears', hint: 'A notice in the app, with Add or Ignore on Found devices' },
    'alerts.devices.role': { label: 'A device or hub stops answering', hint: 'After the checks in Checks; a rule can name someone else' },
    'alerts.devices.app': {
        label: 'Devices and hubs not answering: app',
        hint: '',
        loosens: 'Nobody would see an app notice when a device or hub stops answering.',
    },
    'alerts.devices.email': { label: 'Devices and hubs not answering: email', hint: '' },
    'alerts.devices.push': { label: 'Devices and hubs not answering: push', hint: '' },
    'notify.new_devices.email': { label: 'New device found by a scan: email', hint: '' },
    'notify.new_devices.push': { label: 'New device found by a scan: push', hint: '' },
    // Safety fix round (7 Nov 2026): safety and care alerts are attended to, not snoozed, and escalate within minutes.
    'alerts.safety.snooze': {
        label: 'Allow snoozing safety and care alerts',
        hint: 'Off: panic buttons, duress, alarms, forced doors, leaks, falls and care rules can only be attended to or resolved',
        loosens: 'People could hide a panic button, alarm, fall or care alert for a while, up to the longest snooze below.',
    },
    'alerts.safety.snooze_max': {
        label: 'Longest snooze',
        hint: 'Only when snoozing is allowed. When the snooze ends the alert comes back and reminds again',
        loosens: 'A snoozed safety or care alert would stay hidden for longer.',
    },
    'alerts.escalate.urgent': {
        label: 'Panic, duress, falls, alarms and forced doors',
        hint: 'If nobody has said they’re on it, the on-call manager for that house is told',
        loosens: 'The on-call manager would hear later about a panic button, duress, fall, alarm or forced door nobody has answered.',
    },
    'alerts.escalate.care': {
        label: 'Care rules, water leaks and hot water',
        hint: 'If nobody has said they’re on it, the on-call manager is told; care rules also go to Control Room',
        loosens: 'The on-call manager would hear later about a care alert nobody has answered.',
    },
    'alerts.safety.roster': { label: 'Use the roster to find who is on shift', hint: 'From Rostering and clock-ins, at the moment it happens; off, or nobody on shift: everyone at that Site who can acknowledge alerts' },
    'alerts.safety.email': { label: 'Safety alerts: email', hint: '', loosens: 'Staff on shift would no longer get safety alerts by email.' },
    'alerts.safety.push': { label: 'Safety alerts: push', hint: '', loosens: 'Staff on shift would no longer get safety alerts on their phone.' },
    'alerts.summary.app': { label: 'Daily summary: app', hint: '' },
    'alerts.summary.email': { label: 'Daily summary: email', hint: '' },
    'alerts.summary.push': { label: 'Daily summary: push', hint: '' },
    'alerts.quiet_hours.on': {
        label: 'Hold device alerts overnight',
        hint: QUIET_HOURS_HINT,
    },
    'alerts.quiet_hours.start': { label: 'Quiet hours start', hint: '' },
    'alerts.quiet_hours.end': { label: 'Quiet hours end', hint: '' },
    'mqtt.allow_unencrypted': {
        label: 'Allow unencrypted MQTT through a Site’s hub',
        hint: 'Port 1883, only from the house network to its hub. Never from the internet. Refused at Sites whose address range another Site uses',
        loosens: 'Sensor gateways may send readings unencrypted on the house network to its hub.',
    },
    'devices.watch_on_add': {
        label: 'Watch devices as soon as they’re added',
        hint: 'With the check above, through the Site’s hub or the server; devices with no address are added without it',
        loosens: 'Devices added from now on won’t be watched for going offline until someone turns it on for each.',
    },
    'devices.watch_phones': { label: 'Phones and tablets', hint: 'Off: found but never watched or alerted on' },
    // S&D Phase H (pg-sdr-15m): Setup › Doors & access.
    'access.changes_from_oblivion': {
        label: 'People and their cards',
        hint: 'Give access, add a card at a reader, turn a lost card off and make visitor passes, from Oblivion',
    },
    'access.leavers_off': {
        label: 'Turn cards and PINs off when someone leaves',
        hint: 'From HR, just after midnight at the end of their last day (decision D20). Only people matched to staff',
        loosens: 'Leavers’ cards and PINs would keep opening doors until someone turns them off by hand.',
    },
};

const ROLE_IT = '__it_for_site__';

export type SettingsDraft = ReturnType<typeof useSettingsDraft>;

export function useSettingsDraft(settings: Record<string, SettingData> | null) {
    const [draft, setDraft] = useState<Record<string, Value>>({});
    // A save (or another page's props) brings new saved values: the draft starts again.
    useEffect(() => setDraft({}), [settings]);

    const saved = (key: string): Value => {
        const row = settings?.[key];
        if (!row) return null;
        return row.type === 'bool' ? row.on : row.value;
    };
    const value = (key: string): Value => (key in draft ? draft[key] : saved(key));
    const set = (key: string, next: Value) =>
        setDraft((current) => {
            const copy = { ...current };
            if (saved(key) === next) delete copy[key];
            else copy[key] = next;
            return copy;
        });
    const changes = useMemo(
        () =>
            Object.entries(draft).map(([key, next]) => {
                const row = settings?.[key];
                const savedMinutes = row?.type === 'minutes' ? row.value : null;
                const loosens =
                    (row?.type === 'bool' && ((row.loosens_when_on && next === true) || (row.loosens_when_off && next === false))) ||
                    (row?.type === 'minutes' && row.loosens_when_up && typeof next === 'number' && savedMinutes !== null && next > savedMinutes);
                return { key, from: saved(key), to: next, loosens: Boolean(loosens) };
            }),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [draft, settings],
    );

    return { settings, value, set, changes, dirty: changes.length > 0, discard: () => setDraft({}) };
}

function ChangedBadge({ draft, keys }: { draft: SettingsDraft; keys: string[] }) {
    const unsaved = keys.some((key) => draft.changes.some((change) => change.key === key));
    const changed = keys.some((key) => draft.settings?.[key]?.changed);
    if (unsaved) {
        return (
            <StatusBadge variant="warning" size="sm">
                Not saved
            </StatusBadge>
        );
    }
    return changed ? (
        <StatusBadge variant="neutral" size="sm">
            Changed from the default
        </StatusBadge>
    ) : null;
}

export function labelWithBadge(label: ReactNode, draft: SettingsDraft, keys: string[]) {
    return (
        <span className="inline-flex flex-wrap items-center gap-2">
            {label}
            <ChangedBadge draft={draft} keys={keys} />
        </span>
    );
}

export function formatClock(value: string | null | undefined): string {
    if (!value || !/^\d{2}:\d{2}$/.test(value)) return value ?? '—';
    const [hour, minute] = value.split(':').map(Number);
    const twelve = hour % 12 || 12;
    const suffix = hour >= 12 ? 'pm' : 'am';
    return `${twelve}:${String(minute).padStart(2, '0')} ${suffix}`;
}

/** One titled group of On/Off rows. */
export function SwitchGroup({ icon, title, caption, keys, draft }: { icon: LucideIcon; title: string; caption: string; keys: string[]; draft: SettingsDraft }) {
    const rows = keys.filter((key) => draft.settings?.[key]?.type === 'bool');
    if (rows.length === 0) return null;

    return (
        <SettingGroup icon={icon} title={title} caption={caption}>
            {rows.map((key) => {
                const words = SETTING_WORDS[key] ?? { label: key, hint: '' };
                const row = draft.settings![key];
                return (
                    <SettingRow
                        key={key}
                        label={labelWithBadge(words.label, draft, [key])}
                        hint={row.can_change ? words.hint : `${words.hint}. You can’t change this here.`}
                        control={<OnOff label={words.label} checked={draft.value(key) === true} onChange={(next) => draft.set(key, next)} disabled={!row.can_change} />}
                    />
                );
            })}
        </SettingGroup>
    );
}

/** A row whose value is who is told: a role, or "IT for that Site". */
export function RoleRow({ settingKey, draft }: { settingKey: string; draft: SettingsDraft }) {
    const row = draft.settings?.[settingKey];
    if (!row || row.type !== 'role') return null;
    const words = SETTING_WORDS[settingKey] ?? { label: settingKey, hint: '' };
    const current = draft.value(settingKey);
    const id = `setting-${settingKey}`;

    return (
        <SettingRow
            label={labelWithBadge(words.label, draft, [settingKey])}
            hint={words.hint}
            htmlFor={id}
            control={
                <Select
                    value={typeof current === 'string' ? current : ROLE_IT}
                    onValueChange={(next) => draft.set(settingKey, next === ROLE_IT ? null : next)}
                    disabled={!row.can_change}
                >
                    <SelectTrigger id={id} className="w-60" aria-label={words.label}>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {row.options.map((option) => (
                            <SelectItem key={option.value ?? ROLE_IT} value={option.value ?? ROLE_IT}>
                                {option.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            }
        />
    );
}

/** A row whose value is a number of minutes from a short list (safety fix round, 7 Nov 2026). */
export function MinutesRow({ settingKey, draft, disabled = false, disabledText }: { settingKey: string; draft: SettingsDraft; disabled?: boolean; disabledText?: string }) {
    const row = draft.settings?.[settingKey];
    if (!row || row.type !== 'minutes') return null;
    const words = SETTING_WORDS[settingKey] ?? { label: settingKey, hint: '' };
    const current = draft.value(settingKey);
    const id = `setting-${settingKey}`;

    return (
        <SettingRow
            label={labelWithBadge(words.label, draft, [settingKey])}
            hint={row.can_change ? words.hint : `${words.hint}. You can’t change this here.`}
            htmlFor={id}
            control={
                disabled ? (
                    <span className="text-subtle">{disabledText ?? '—'}</span>
                ) : (
                    <Select value={String(typeof current === 'number' ? current : row.value)} onValueChange={(next) => draft.set(settingKey, Number(next))} disabled={!row.can_change}>
                        <SelectTrigger id={id} className="w-40" aria-label={words.label}>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {row.choices.map((choice) => (
                                <SelectItem key={choice} value={String(choice)}>
                                    {choice === 1 ? '1 minute' : `${choice} minutes`}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                )
            }
        />
    );
}

/** Rows with In-app / Email / Push switch columns (DESIGN.md: anything that notifies people). */
export function ChannelGroup({
    icon,
    title,
    caption,
    rows,
    draft,
}: {
    icon: LucideIcon;
    title: string;
    caption: string;
    /** A null key is a channel that can’t be turned off (safety alerts always show in the app). */
    rows: { label: string; hint: string; keys: [string | null, string, string] }[];
    draft: SettingsDraft;
}) {
    const visible = rows.filter((row) => row.keys.every((key) => key === null || draft.settings?.[key]?.type === 'bool'));
    if (visible.length === 0) return null;
    const columns = ['In-app', 'Email', 'Push'];

    return (
        <SettingGroup
            icon={icon}
            title={title}
            caption={caption}
            actions={
                <span className="text-caption hidden gap-6 pr-1 sm:flex" aria-hidden="true">
                    {columns.map((column) => (
                        <span key={column} className="w-12 text-center">
                            {column}
                        </span>
                    ))}
                </span>
            }
        >
            {visible.map((row) => (
                <SettingRow
                    key={row.label}
                    label={labelWithBadge(row.label, draft, row.keys.filter((key): key is string => key !== null))}
                    hint={row.hint}
                    control={
                        <span className="flex gap-6">
                            {row.keys.map((key, index) => {
                                const name = `${row.label}: ${columns[index]}`;
                                if (key === null) {
                                    return (
                                        <span key={`always-${index}`} className="flex w-12 flex-col items-center gap-1">
                                            <Switch checked disabled aria-label={`${name} (always on)`} />
                                            <span className="text-caption" aria-hidden="true">
                                                Always
                                            </span>
                                        </span>
                                    );
                                }
                                const setting = draft.settings![key];
                                return (
                                    <span key={key} className="flex w-12 flex-col items-center gap-1">
                                        <Switch
                                            checked={draft.value(key) === true}
                                            onCheckedChange={(next) => draft.set(key, next)}
                                            disabled={!setting.can_change}
                                            aria-label={name}
                                        />
                                        <span className="text-caption sm:hidden" aria-hidden="true">
                                            {columns[index]}
                                        </span>
                                    </span>
                                );
                            })}
                        </span>
                    }
                />
            ))}
        </SettingGroup>
    );
}

function valueWords(key: string, value: Value, draft: SettingsDraft): string {
    const row = draft.settings?.[key];
    if (row?.type === 'role') return row.options.find((option) => option.value === value)?.label ?? 'IT for that Site';
    if (row?.type === 'time') return formatClock(typeof value === 'string' ? value : null);
    if (row?.type === 'minutes') return typeof value === 'number' ? `${value} min` : '—';
    return value ? 'On' : 'Off';
}

/** The sticky save bar and its review pop-up (80). Hidden when the viewer can't change anything here. */
export function SettingsSave({ draft, what }: { draft: SettingsDraft; what: string }) {
    const [reviewing, setReviewing] = useState(false);
    const [saving, setSaving] = useState(false);
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | null>(null);
    const canChange = Object.values(draft.settings ?? {}).some((setting) => setting.can_change);
    if (!canChange) return null;
    const loosens = draft.changes.some((change) => change.loosens);
    const count = draft.changes.length;

    const save = async () => {
        if (loosens && reason.trim().length < 5) {
            setError('Say why you’re loosening this safety check.');
            return;
        }
        setSaving(true);
        setError(null);
        try {
            await axios.patch('/security-devices/setup/settings', {
                changes: Object.fromEntries(draft.changes.map((change) => [change.key, change.to])),
                reason: loosens ? reason.trim() : null,
            });
            setReviewing(false);
            setReason('');
            router.reload({ except: ['deviceForm'] });
        } catch (failure) {
            const status = axios.isAxiosError(failure) ? failure.response?.status : undefined;
            const data = axios.isAxiosError(failure) ? (failure.response?.data as { errors?: Record<string, string[]> } | undefined) : undefined;
            const first = data?.errors ? Object.values(data.errors)[0]?.[0] : null;
            setError(first ?? (status === 403 ? 'You can’t change these settings.' : status ? 'They weren’t saved. Try again.' : 'Result not confirmed. Reload the page to see whether they were saved.'));
        } finally {
            setSaving(false);
        }
    };

    return (
        <>
            <SaveBar
                count={count}
                onDiscard={draft.discard}
                onReview={() => setReviewing(true)}
                busy={saving}
                note="Nothing changes until you review and save. Each change is kept in Change history."
            />
            {reviewing ? (
                <SettingsModal
                    title={`Save ${count === 1 ? '1 change' : `${count} changes`} to ${what}?`}
                    description="Setup › Review changes"
                    onClose={() => setReviewing(false)}
                    footer={
                        <>
                            <Button variant="outline" onClick={() => setReviewing(false)} disabled={saving}>
                                Back to editing
                            </Button>
                            <Button variant={loosens ? 'destructive' : 'default'} onClick={save} disabled={saving}>
                                {saving ? 'Saving…' : loosens ? 'Save, including the loosened check' : 'Save changes'}
                            </Button>
                        </>
                    }
                >
                    <ul className="grid gap-2">
                        {draft.changes.map((change) => {
                            const words = SETTING_WORDS[change.key] ?? { label: change.key, hint: '' };
                            return (
                                <li key={change.key} className="text-sm">
                                    <b>{words.label}:</b> {valueWords(change.key, change.from, draft)} → {valueWords(change.key, change.to, draft)}
                                </li>
                            );
                        })}
                    </ul>
                    {loosens ? (
                        <div className="mt-4 grid gap-2">
                            {draft.changes
                                .filter((change) => change.loosens)
                                .map((change) => (
                                    <p key={change.key} className="text-sm">
                                        <b>This loosens a safety check.</b> {SETTING_WORDS[change.key]?.loosens}
                                    </p>
                                ))}
                            <Label htmlFor="settings-reason" className="mt-1">
                                Why loosen it?
                            </Label>
                            <Textarea
                                id="settings-reason"
                                value={reason}
                                onChange={(event) => setReason(event.target.value)}
                                placeholder="e.g. The server sits on the head office network"
                                rows={3}
                            />
                        </div>
                    ) : null}
                    {error ? (
                        <p role="alert" className="mt-3 text-sm text-status-critical">
                            {error}
                        </p>
                    ) : null}
                </SettingsModal>
            ) : null}
        </>
    );
}
