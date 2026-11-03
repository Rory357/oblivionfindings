/**
 * Network gear's Settings tab (S&D v13, pg-sdr-3f), in Fleet's settings pattern: titled groups of
 * short rows and switches, a changed row gets a soft tint, a Changed badge and what it was (never a
 * coloured edge, DESIGN.md rule 8), and the Review changes bar counts the changes and flags one that
 * loosens a safety check (pop-up 80: listed old → new, a reason, the destructive save).
 *
 * Everything saves through routes that already exist: the device update (name, room), Move to
 * another Site (pop-up 72), Update login (pop-up 63), Watch (the offline-alert route, as pop-up 9),
 * stop watching (each check's own deactivate route, with its reason code) and Remove device
 * (pop-up 11; its history stays, under Devices › Upkeep › Retired).
 */
import { ConfirmDialog } from '@/components/confirm-dialog';
import { OnOff, SaveBar, SettingGroup, SettingRow } from '@/components/referrals/settings-ui';
import { SettingsModal } from '@/components/settings/settings-modal';
import { FactRows, QuietLine } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { Command, CommandEmpty, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateOnly, WORKER_TIMEZONE } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { Bell, Check, ChevronsUpDown, KeyRound, Tag } from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { MainSide, plural } from './_device-page-parts';
import type { DevicePageProps, SettingsTab, StaffChoice } from './_device-page-types';

/** NativeMonitoringDefinitionService's four stop-watching reasons, in the words 2e shows them in. */
export const STOP_REASONS: { value: string; label: string }[] = [
    { value: 'obsolete', label: 'No longer needed' },
    { value: 'replaced', label: 'Replaced' },
    { value: 'coverage_removed', label: 'No longer covered' },
    { value: 'device_retired', label: 'Being retired' },
];

type Draft = {
    name: string;
    room: string;
    lookedAfterId: number | null;
    sshUser: string;
    watch: boolean;
    offline: boolean;
    updateReady: boolean;
};
type Change = { key: keyof Draft; label: string; from: string; to: string; loosens: string | null };

const SSH_USER_RE = /^[a-zA-Z0-9._-]{1,64}$/;

function onOff(value: boolean): string {
    return value ? 'On' : 'Off';
}

function firstError(error: unknown, fallback: string): string {
    if (axios.isAxiosError(error)) {
        const data = error.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined;
        const first = data?.errors ? Object.values(data.errors)[0]?.[0] : null;
        if (first) return first;
        if (error.response?.status === 403) return 'You can’t change this here.';
        if (!error.response) return 'Result not confirmed. Reload the page to see what was saved.';
    }
    return fallback;
}

/** A changed row: the soft tint, a Changed badge and what it was. Never a coloured edge. */
function Changed({ changed, children }: { changed: boolean; children: ReactNode }) {
    return <div className={cn(changed && 'bg-status-warning-bg/60')}>{children}</div>;
}

function RowLabel({ label, changed, loosens }: { label: string; changed: boolean; loosens?: boolean }) {
    return (
        <span className="inline-flex flex-wrap items-center gap-2">
            {label}
            {changed ? (
                <StatusBadge variant="warning" size="sm">
                    Changed
                </StatusBadge>
            ) : null}
            {changed && loosens ? (
                <StatusBadge variant="critical" size="sm">
                    Loosens a safety check
                </StatusBadge>
            ) : null}
        </span>
    );
}

function ReadOnly({ children }: { children: ReactNode }) {
    return <span className="rounded-md border border-border bg-card px-2.5 py-1 text-[13px]">{children}</span>;
}

function LookedAfterPicker({
    options,
    value,
    fallbackLabel,
    onChange,
}: {
    options: StaffChoice[];
    value: number | null;
    fallbackLabel: string | null;
    onChange: (next: number | null) => void;
}) {
    const [open, setOpen] = useState(false);
    const chosen = options.find((option) => option.id === value) ?? null;
    const display = chosen ? chosen.label : value !== null && fallbackLabel ? fallbackLabel : 'Not set';

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    id="sd-settings-looked-after"
                    type="button"
                    variant="outline"
                    role="combobox"
                    aria-expanded={open}
                    aria-label="Looked after by"
                    className="h-9 w-64 max-w-full justify-between font-normal"
                >
                    <span className="truncate">{display}</span>
                    <ChevronsUpDown className="size-4 shrink-0 text-muted-foreground" />
                </Button>
            </PopoverTrigger>
            <PopoverContent align="end" className="w-72 p-0">
                <Command>
                    <CommandInput placeholder="Find a staff member…" />
                    <CommandList>
                        <CommandEmpty>No staff member matches.</CommandEmpty>
                        <CommandItem
                            value="Not set"
                            onSelect={() => {
                                onChange(null);
                                setOpen(false);
                            }}
                        >
                            <Check className={cn('mr-2 size-4', value === null ? 'opacity-100' : 'opacity-0')} />
                            <span>Not set</span>
                        </CommandItem>
                        {options.map((option) => (
                            <CommandItem
                                key={option.id}
                                value={`${option.name} ${option.sub}`}
                                onSelect={() => {
                                    onChange(option.id);
                                    setOpen(false);
                                }}
                            >
                                <Check className={cn('mr-2 size-4 shrink-0', value === option.id ? 'opacity-100' : 'opacity-0')} />
                                <span className="flex min-w-0 flex-col">
                                    <span className="truncate text-sm font-medium">{option.name}</span>
                                    <span className="truncate text-xs text-muted-foreground">{option.sub}</span>
                                </span>
                            </CommandItem>
                        ))}
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}

export function SettingsTabView({
    props,
    settings,
    onMove,
    onLogin,
    onRemove,
}: {
    props: DevicePageProps;
    settings: SettingsTab;
    onMove: (() => void) | null;
    onLogin: (() => void) | null;
    onRemove: (() => void) | null;
}) {
    const { device, can, actions } = props;
    const noun = settings.noun;
    const watching = settings.watching;
    const staffOptions = useMemo(() => settings.staff_options ?? [], [settings.staff_options]);
    const canSsh = settings.ssh !== undefined && settings.ssh !== null;
    const savedLookedAfterLabel = settings.about.looked_after_by?.label ?? null;
    const saved: Draft = useMemo(
        () => ({
            name: settings.name,
            room: settings.room ?? '',
            lookedAfterId: settings.about.looked_after_by?.id ?? null,
            sshUser: settings.ssh?.user ?? '',
            watch: watching.on,
            offline: watching.offline_alert,
            updateReady: watching.tell_it_update_ready ?? true,
        }),
        [
            settings.name,
            settings.room,
            settings.about.looked_after_by?.id,
            settings.ssh?.user,
            watching.on,
            watching.offline_alert,
            watching.tell_it_update_ready,
        ],
    );
    const [draft, setDraft] = useState<Draft>(saved);
    useEffect(() => setDraft(saved), [saved]);
    const [reviewing, setReviewing] = useState(false);
    const [reason, setReason] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [leaving, setLeaving] = useState<string | null>(null);
    const allowLeave = useRef(false);

    const canRename = can.edit && settings.edit !== null;
    const canUpdateReady = can.watch || can.edit;
    const canWatchOn = can.watch && !watching.on && !!settings.address && settings.site_id !== null;
    const canWatchOff = can.watch && watching.on && !watching.by_service && watching.stop_ids.length > 0;
    const set = <K extends keyof Draft>(key: K, value: Draft[K]) => setDraft((current) => ({ ...current, [key]: value }));

    const labelForStaff = (id: number | null): string => {
        if (id === null) return 'Not set';
        const match = staffOptions.find((option) => option.id === id);
        if (match) return match.label;
        if (id === saved.lookedAfterId && savedLookedAfterLabel) return savedLookedAfterLabel;
        return 'Staff member';
    };

    const dependents = watching.dependents;
    const changes: Change[] = [];
    if (draft.name.trim() !== saved.name) changes.push({ key: 'name', label: 'Name', from: saved.name, to: draft.name.trim(), loosens: null });
    if (draft.room.trim() !== saved.room) changes.push({ key: 'room', label: 'Room or spot', from: saved.room || 'Not set', to: draft.room.trim() || 'Not set', loosens: null });
    if (draft.lookedAfterId !== saved.lookedAfterId) {
        changes.push({
            key: 'lookedAfterId',
            label: 'Looked after by',
            from: labelForStaff(saved.lookedAfterId),
            to: labelForStaff(draft.lookedAfterId),
            loosens: null,
        });
    }
    if (canSsh && draft.sshUser.trim() !== saved.sshUser) {
        changes.push({
            key: 'sshUser',
            label: 'SSH user',
            from: saved.sshUser || 'From the saved login',
            to: draft.sshUser.trim() || 'From the saved login',
            loosens: null,
        });
    }
    if (draft.watch !== saved.watch) {
        changes.push({
            key: 'watch',
            label: `Watch this ${noun}`,
            from: onOff(saved.watch),
            to: onOff(draft.watch),
            loosens: draft.watch
                ? null
                : `Nobody is told if it stops working${watching.relied_on ? `${dependents > 0 ? `, and the ${plural(dependents, 'device', 'devices')} behind it can’t be checked` : ''}: other devices rely on this ${noun}` : ''}.`,
        });
    } else if (draft.watch && draft.offline !== saved.offline) {
        changes.push({
            key: 'offline',
            label: 'Alert if it goes offline',
            from: onOff(saved.offline),
            to: onOff(draft.offline),
            loosens: draft.offline ? null : `If the ${noun} goes offline, nobody is told${dependents > 0 ? ` and the ${plural(dependents, 'device', 'devices')} behind it go grey` : ''}.`,
        });
    }
    if (draft.updateReady !== saved.updateReady) {
        changes.push({
            key: 'updateReady',
            label: 'Tell IT when an update is ready',
            from: onOff(saved.updateReady),
            to: onOff(draft.updateReady),
            loosens: null,
        });
    }
    const loosening = changes.filter((change) => change.loosens !== null);
    const stopping = changes.some((change) => (change.key === 'watch' && !draft.watch) || (change.key === 'offline' && !draft.offline));
    const dirty = changes.length > 0;

    // Unsaved changes: leaving asks first (Fleet's unsaved-draft guard).
    useEffect(() => {
        if (!dirty) return;
        allowLeave.current = false;
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

    const save = async () => {
        if (stopping && !reason) {
            setError('Choose why it’s no longer watched.');
            return;
        }
        const trimmedSsh = draft.sshUser.trim();
        if (canSsh && trimmedSsh !== saved.sshUser && trimmedSsh !== '' && !SSH_USER_RE.test(trimmedSsh)) {
            setError('Enter an SSH username (letters, numbers, dots, hyphens or underscores only, up to 64 characters — never a password).');
            return;
        }
        setSaving(true);
        setError(null);
        const base = `/security-devices/devices/${device.id}`;
        const json = { headers: { Accept: 'application/json' } };
        try {
            const renamed = changes.find((change) => change.key === 'name');
            const moved = changes.find((change) => change.key === 'room');
            if ((renamed || moved) && settings.edit) {
                await axios.put(
                    base,
                    {
                        _modal: true,
                        name: draft.name.trim(),
                        domain: settings.edit.domain,
                        category: settings.edit.category,
                        subcategory: settings.edit.subcategory,
                        location_description: draft.room.trim() || null,
                    },
                    json,
                );
            }
            const extraPayload: Record<string, unknown> = {};
            if (changes.some((change) => change.key === 'lookedAfterId')) {
                extraPayload.looked_after_by_user_id = draft.lookedAfterId;
            }
            if (canSsh && changes.some((change) => change.key === 'sshUser')) {
                extraPayload.ssh_user = trimmedSsh || null;
            }
            if (changes.some((change) => change.key === 'updateReady')) {
                extraPayload.tell_it_update_ready = draft.updateReady;
            }
            if (Object.keys(extraPayload).length > 0) {
                await axios.patch(`${base}/settings`, extraPayload, json);
            }
            const watch = changes.find((change) => change.key === 'watch');
            const offline = changes.find((change) => change.key === 'offline');
            if ((watch && draft.watch) || (offline && draft.offline)) {
                await axios.post(`${base}/offline-alert`, { enabled: true }, json);
            }
            const stopIds = watch && !draft.watch ? watching.stop_ids : offline && !draft.offline ? watching.offline_ids : [];
            for (const monitorId of stopIds) {
                await axios.post(`/security-devices/monitoring/native-monitors/${monitorId}/deactivate`, { reason_code: reason }, json);
            }
            allowLeave.current = true;
            setReviewing(false);
            setReason('');
            router.reload();
        } catch (failure) {
            setError(firstError(failure, 'Not everything was saved. Reload the page to see what changed, then try again.'));
            router.reload({ only: ['settings', 'device', 'meters', 'tabs'] });
        } finally {
            setSaving(false);
        }
    };

    const nameChanged = draft.name.trim() !== saved.name;
    const roomChanged = draft.room.trim() !== saved.room;
    const lookedAfterChanged = draft.lookedAfterId !== saved.lookedAfterId;
    const sshChanged = canSsh && draft.sshUser.trim() !== saved.sshUser;
    const watchChanged = draft.watch !== saved.watch;
    const offlineChanged = !watchChanged && draft.offline !== saved.offline;
    const updateReadyChanged = draft.updateReady !== saved.updateReady;
    const added = settings.about.added_at
        ? `${formatDateOnly(new Date(settings.about.added_at).toLocaleDateString('en-CA', { timeZone: WORKER_TIMEZONE }))}${settings.about.added_by ? ` by ${settings.about.added_by}` : ''}${settings.about.from_scan ? ', from a scan' : ''}`
        : null;

    return (
        <MainSide
            main={
                <>
                    <SettingGroup
                        icon={Tag}
                        title="Name and place"
                        caption="Moving it to another house keeps its history"
                        actions={
                            onMove && settings.site_id !== null ? (
                                <Button type="button" variant="outline" size="sm" onClick={onMove}>
                                    Move to another Site
                                </Button>
                            ) : null
                        }
                    >
                        <Changed changed={nameChanged}>
                            <SettingRow
                                label={<RowLabel label="Name" changed={nameChanged} />}
                                hint={nameChanged ? `Was ${saved.name}` : 'What people call it'}
                                htmlFor={canRename ? 'sd-settings-name' : undefined}
                                control={
                                    canRename ? (
                                        <Input id="sd-settings-name" className="h-9 w-64 max-w-full" value={draft.name} maxLength={255} onChange={(event) => set('name', event.target.value)} />
                                    ) : (
                                        <ReadOnly>{settings.name}</ReadOnly>
                                    )
                                }
                            />
                        </Changed>
                        <SettingRow
                            label="Site"
                            hint={onMove ? 'Use Move to another Site to change it' : 'Where it is'}
                            control={<ReadOnly>{settings.site ?? 'No Site'}</ReadOnly>}
                        />
                        <Changed changed={roomChanged}>
                            <SettingRow
                                label={<RowLabel label="Room or spot" changed={roomChanged} />}
                                hint={roomChanged ? `Was ${saved.room || 'not set'}` : 'Where in the house'}
                                htmlFor={canRename ? 'sd-settings-room' : undefined}
                                control={
                                    canRename ? (
                                        <Input
                                            id="sd-settings-room"
                                            className="h-9 w-64 max-w-full"
                                            value={draft.room}
                                            maxLength={255}
                                            placeholder="e.g. Comms cupboard"
                                            onChange={(event) => set('room', event.target.value)}
                                        />
                                    ) : (
                                        <ReadOnly>{settings.room ?? 'Not set'}</ReadOnly>
                                    )
                                }
                            />
                        </Changed>
                        <Changed changed={lookedAfterChanged}>
                            <SettingRow
                                label={<RowLabel label="Looked after by" changed={lookedAfterChanged} />}
                                hint={lookedAfterChanged ? `Was ${labelForStaff(saved.lookedAfterId)}` : `Who looks after this ${noun}`}
                                htmlFor={canRename ? 'sd-settings-looked-after' : undefined}
                                control={
                                    canRename ? (
                                        <LookedAfterPicker
                                            options={staffOptions}
                                            value={draft.lookedAfterId}
                                            fallbackLabel={savedLookedAfterLabel}
                                            onChange={(next) => set('lookedAfterId', next)}
                                        />
                                    ) : (
                                        <ReadOnly>{savedLookedAfterLabel ?? 'Not set'}</ReadOnly>
                                    )
                                }
                            />
                        </Changed>
                        {settings.checked_by ? <SettingRow label="Checked by" hint="Follows the Site" control={<ReadOnly>{settings.checked_by}</ReadOnly>} /> : null}
                    </SettingGroup>

                    <SettingGroup
                        icon={KeyRound}
                        title="How Oblivion reaches it"
                        caption={settings.checked_by ? `Through ${settings.checked_by} · nothing is opened to the internet` : 'Put it at a Site so a hub can reach it'}
                        actions={
                            onLogin ? (
                                <Button type="button" variant="outline" size="sm" onClick={onLogin}>
                                    Update login
                                </Button>
                            ) : null
                        }
                    >
                        <SettingRow label="Address" hint={can.edit ? 'Change it with Edit' : 'Its IP address or name'} control={<ReadOnly>{settings.address ?? 'Not set'}</ReadOnly>} />
                        {actions.login ? (
                            <SettingRow
                                label="Saved login"
                                hint={`Stored ${actions.login.store} · used by ${actions.login.used_by}`}
                                control={
                                    <span className="inline-flex items-center gap-2">
                                        <ReadOnly>{actions.login.name}</ReadOnly>
                                        <StatusBadge variant={actions.login.state.tone} size="sm">
                                            {actions.login.state.text}
                                        </StatusBadge>
                                    </span>
                                }
                            />
                        ) : null}
                        {canSsh ? (
                            <Changed changed={sshChanged}>
                                <SettingRow
                                    label={<RowLabel label="SSH user" changed={sshChanged} />}
                                    hint={
                                        sshChanged
                                            ? `Was ${saved.sshUser || 'from the saved login'} · username only, never a password`
                                            : 'Username only, never a password · leave blank to use the saved login'
                                    }
                                    htmlFor="sd-settings-ssh-user"
                                    control={
                                        <Input
                                            id="sd-settings-ssh-user"
                                            type="text"
                                            autoComplete="off"
                                            spellCheck={false}
                                            className="h-9 w-64 max-w-full"
                                            value={draft.sshUser}
                                            maxLength={64}
                                            placeholder="From the saved login"
                                            onChange={(event) => set('sshUser', event.target.value)}
                                        />
                                    }
                                />
                            </Changed>
                        ) : null}
                        {settings.read_through ? <SettingRow label="Read through" hint="How Oblivion reads it" control={<ReadOnly>{settings.read_through}</ReadOnly>} /> : null}
                    </SettingGroup>

                    <SettingGroup icon={Bell} title="Watching" caption="What Oblivion checks and tells people about">
                        <Changed changed={watchChanged}>
                            <SettingRow
                                label={<RowLabel label={`Watch this ${noun}`} changed={watchChanged} loosens={watchChanged && !draft.watch} />}
                                hint={
                                    watching.by_service
                                        ? 'Its connected service reports on it, so it stays watched. Stop that in the service itself.'
                                        : watchChanged
                                          ? `Was ${onOff(saved.watch).toLowerCase()}. Off stops checks and alerts: it shows grey “not watched” and keeps its history`
                                          : `${watching.every ? `Checked ${watching.every} from ${watching.checked_by}. ` : ''}Off stops checks and alerts: it shows grey “not watched” and keeps its history${!watching.on && (!settings.address || settings.site_id === null) ? '. It needs a Site and an address first (Edit)' : ''}`
                                }
                                control={
                                    <OnOff
                                        label={`Watch this ${noun}`}
                                        checked={draft.watch}
                                        onChange={(next) => set('watch', next)}
                                        disabled={!(watching.on ? canWatchOff : canWatchOn) && draft.watch === saved.watch}
                                    />
                                }
                            />
                        </Changed>
                        {watching.on && !watching.by_service && draft.watch ? (
                            <Changed changed={offlineChanged}>
                                <SettingRow
                                    label={<RowLabel label="Alert if it goes offline" changed={offlineChanged} loosens={offlineChanged && !draft.offline} />}
                                    hint={
                                        offlineChanged
                                            ? `Was ${onOff(saved.offline).toLowerCase()}. ${changes.find((change) => change.key === 'offline')?.loosens ?? ''}`
                                            : `Its online check${dependents > 0 ? `; the ${plural(dependents, 'device', 'devices')} behind it go grey, not red, if it goes offline` : ''}`
                                    }
                                    control={
                                        <OnOff
                                            label="Alert if it goes offline"
                                            checked={draft.offline}
                                            onChange={(next) => set('offline', next)}
                                            disabled={!can.watch || (saved.offline && watching.offline_ids.length === 0) || (!saved.offline && !settings.address)}
                                        />
                                    }
                                />
                            </Changed>
                        ) : null}
                        {watching.backup ? (
                            <SettingRow
                                label="Back up its settings"
                                hint={`A copy ${watching.backup.every ?? 'on its schedule'} · Compare shows what changed`}
                                control={
                                    <StatusBadge variant="success" size="sm">
                                        On
                                    </StatusBadge>
                                }
                            />
                        ) : null}
                        <Changed changed={updateReadyChanged}>
                            <SettingRow
                                label={<RowLabel label="Tell IT when an update is ready" changed={updateReadyChanged} />}
                                hint={updateReadyChanged ? `Was ${onOff(saved.updateReady).toLowerCase()} · in the 6:00 am summary` : 'In the 6:00 am summary'}
                                control={
                                    <OnOff
                                        label="Tell IT when an update is ready"
                                        checked={draft.updateReady}
                                        onChange={(next) => set('updateReady', next)}
                                        disabled={!canUpdateReady}
                                    />
                                }
                            />
                        </Changed>
                        {watching.other_checks > 0 && watching.on ? (
                            <SettingRow
                                label="Other checks"
                                hint={`${plural(watching.other_checks, 'check', 'checks')} this tab can’t turn off, listed under Checks & alerts`}
                            />
                        ) : null}
                    </SettingGroup>

                    <QuietLine
                        title={`Remove this ${noun}`}
                        text={`Remove device takes it off every list and stops its checks; its history stays, read-only, under Devices › Upkeep › Retired. ${watching.on && !watching.by_service ? `Turn off Watch this ${noun} first. ` : ''}The ${noun} itself isn’t changed.`}
                        action={onRemove ? { label: 'Remove device', onClick: onRemove } : null}
                    />

                    {can.edit || can.watch || canSsh ? (
                        <SaveBar
                            count={changes.length}
                            onDiscard={() => setDraft(saved)}
                            onReview={() => {
                                setError(null);
                                setReviewing(true);
                            }}
                            busy={saving}
                            note={
                                loosening.length > 0
                                    ? `${loosening.length} loosens a safety check · nothing changes until you review and save`
                                    : 'Nothing changes until you review and save. Each change goes in its History.'
                            }
                        />
                    ) : null}

                    {reviewing ? (
                        <SettingsModal
                            title={`Save ${changes.length === 1 ? '1 change' : `${changes.length} changes`} to ${saved.name}?`}
                            description="Settings › Review changes"
                            onClose={() => setReviewing(false)}
                            footer={
                                <>
                                    <Button variant="outline" onClick={() => setReviewing(false)} disabled={saving}>
                                        Back to editing
                                    </Button>
                                    <Button variant={loosening.length > 0 ? 'destructive' : 'default'} onClick={save} disabled={saving || changes.length === 0}>
                                        {saving ? 'Saving…' : loosening.length > 0 ? 'Save, including the loosened check' : 'Save changes'}
                                    </Button>
                                </>
                            }
                        >
                            <ul className="grid gap-2">
                                {changes.map((change) => (
                                    <li key={change.key} className="text-sm">
                                        <b>{change.label}:</b> {change.from} → {change.to}
                                    </li>
                                ))}
                            </ul>
                            {loosening.length > 0 ? (
                                <div className="mt-4 grid gap-2">
                                    {loosening.map((change) => (
                                        <p key={change.key} className="text-sm">
                                            <b>This loosens a safety check.</b> {change.loosens}
                                        </p>
                                    ))}
                                </div>
                            ) : null}
                            {stopping ? (
                                <div className="mt-4 grid gap-1.5">
                                    <Label htmlFor="sd-settings-reason">Why is it no longer watched?</Label>
                                    <Select value={reason} onValueChange={setReason}>
                                        <SelectTrigger id="sd-settings-reason" className="w-64" aria-label="Why is it no longer watched?">
                                            <SelectValue placeholder="Choose a reason" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {STOP_REASONS.map((option) => (
                                                <SelectItem key={option.value} value={option.value}>
                                                    {option.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <p className="text-caption">Kept with it and shown under Devices › Upkeep › Not watched</p>
                                </div>
                            ) : null}
                            {error ? (
                                <p role="alert" className="mt-3 text-sm text-status-critical">
                                    {error}
                                </p>
                            ) : null}
                        </SettingsModal>
                    ) : null}
                    <ConfirmDialog
                        open={leaving !== null}
                        onClose={() => setLeaving(null)}
                        onConfirm={() => {
                            const target = leaving;
                            allowLeave.current = true;
                            setLeaving(null);
                            if (target) router.visit(target);
                        }}
                        title="Leave without saving?"
                        description={changes.length === 1 ? 'Your change to 1 setting hasn’t been saved and will be lost.' : `Your changes to ${changes.length} settings haven’t been saved and will be lost.`}
                        confirmText="Leave without saving"
                        cancelText="Keep editing"
                    />
                </>
            }
            side={
                <>
                    <FactRows
                        title={`About this ${noun}`}
                        rows={[
                            ['Model', settings.about.model],
                            ...(added ? ([['Added', added]] as [string, string][]) : []),
                            ['Looked after by', savedLookedAfterLabel ?? 'Not set'],
                        ]}
                    />
                    {settings.it.length > 0 ? <FactRows title="For IT" rows={settings.it} /> : null}
                </>
            }
        />
    );
}
