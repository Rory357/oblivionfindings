/**
 * House mode pop-ups (S&D Phase F), on the pop-up kit:
 *  - 42 "Switch Aurora House to Night?" (confirm, 480): everything the mode will do, and what it
 *    won't, in the server's words. Needs securityDevices.modes.switch.
 *  - 43 "Edit Night mode · Aurora House" (form, 900): when it starts and what it does, from the safe
 *    actions only (SafeActions::choices()). There is no way to add a disarm or an unlock. Needs
 *    securityDevices.automations.manage. Also "Add a mode", the same form with a name.
 *
 * Modes check and remind; they never lock, arm, disarm or unlock (decision D12), and the words say so.
 */
import { TimePicker } from '@/components/fleet-assets/maintenance/time-picker';
import {
    PopupCallout,
    PopupConfirm,
    PopupField,
    PopupForm,
    PopupSection,
} from '@/components/popup';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    clockWords,
    PRIVACY_DAYS,
} from '@/lib/security-devices/camera-privacy';
import type {
    DeviceOption,
    HouseModeAction,
    HouseModeEditOptions,
    HouseModeSchedule,
    HouseSummary,
    SafeActionKey,
    SiteModeItem,
} from '@/pages/security-devices/sites/_types';
import { router } from '@inertiajs/react';
import {
    Check,
    Clock3,
    House,
    ListChecks,
    Moon,
    MoonStar,
    Plane,
    Sun,
    Tag,
    type LucideIcon,
} from 'lucide-react';
import { Fragment, useEffect, useState, type ReactNode } from 'react';

const UNCONFIRMED =
    'Result not confirmed: the connection may have dropped. Check the Modes tab before trying again, so it isn’t done twice.';

/** A mode's icon from its name: the moon for Night, the sun for Day. */
export function modeIcon(name: string, editing = false): LucideIcon {
    const key = name.toLowerCase();
    if (key.includes('night')) return editing ? MoonStar : Moon;
    if (key.includes('day') && !key.includes('holiday')) return Sun;
    if (key.includes('holiday') || key.includes('away')) return Plane;
    return House;
}

/** "**front door**" → bold, as the server marks device names. */
export function BoldText({ text }: { text: string }): ReactNode {
    const parts = text.split(/\*\*(.+?)\*\*/g);
    return (
        <>
            {parts.map((part, index) =>
                index % 2 === 1 ? (
                    <b key={index} className="font-semibold text-foreground">
                        {part}
                    </b>
                ) : (
                    <Fragment key={index}>{part}</Fragment>
                ),
            )}
        </>
    );
}

/** Run an Inertia visit and say "Result not confirmed" if no answer came back. */
function send(
    method: 'post' | 'put',
    url: string,
    data: Record<string, unknown>,
    handlers: {
        done: () => void;
        errors: (messages: Record<string, string>) => void;
        finish: () => void;
        unconfirmed: () => void;
    },
) {
    let settled = false;
    router[method](url, data as never, {
        preserveScroll: true,
        onSuccess: () => {
            settled = true;
            handlers.done();
        },
        onError: (errors) => {
            settled = true;
            handlers.errors(errors as Record<string, string>);
        },
        onFinish: () => {
            handlers.finish();
            if (!settled) handlers.unconfirmed();
        },
    });
}

/* ------------------------------------------------------------------ */
/*  42 · Switch the house to a mode                                    */
/* ------------------------------------------------------------------ */

export function SwitchHouseMode({
    open,
    onClose,
    siteId,
    siteName,
    house,
    modeId,
}: {
    open: boolean;
    onClose: () => void;
    siteId: number;
    siteName: string;
    house: HouseSummary;
    modeId: number | null;
}) {
    const mode = house.modes.find((row) => row.id === modeId) ?? null;
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | undefined>();

    useEffect(() => {
        if (open) {
            setProcessing(false);
            setError(undefined);
        }
    }, [open, modeId]);

    if (!mode) return null;

    const confirm = () => {
        setProcessing(true);
        setError(undefined);
        send(
            'post',
            `/security-devices/sites/${siteId}/modes/${mode.id}/switch`,
            {},
            {
                done: onClose,
                errors: (messages) =>
                    setError(
                        Object.values(messages)[0] ??
                            'The mode couldn’t be switched. Try again.',
                    ),
                finish: () => setProcessing(false),
                unconfirmed: () => setError(UNCONFIRMED),
            },
        );
    };

    return (
        <PopupConfirm
            open={open}
            onClose={onClose}
            onConfirm={confirm}
            icon={modeIcon(mode.name)}
            title={`Switch ${siteName} to ${mode.name}?`}
            description={
                <p>
                    {mode.summary.map((line, index) => (
                        <Fragment key={index}>
                            {index > 0 ? ' ' : null}
                            <BoldText text={line} />
                        </Fragment>
                    ))}
                </p>
            }
            context={{
                icon: House,
                title: siteName,
                tag: house.current
                    ? `Mode: ${house.current.name}`
                    : 'No mode yet',
                note: mode.usual ?? 'Switched by hand only',
            }}
            confirmLabel={`Switch to ${mode.name}`}
            processing={processing}
            processingLabel="Switching…"
            error={error}
            testId="sd-switch-mode"
        />
    );
}

/* ------------------------------------------------------------------ */
/*  43 · Edit a mode (or add one)                                      */
/* ------------------------------------------------------------------ */

type Draft = {
    name: string;
    scheduled: boolean;
    days: number[];
    starts: string;
    on: Record<SafeActionKey, boolean>;
    message: string;
    cameras: number[];
    until: string;
    doors: number[];
    panels: number[];
    after: number;
};

const AFTER_OPTIONS: { value: number; label: string }[] = [
    { value: 0, label: 'When it starts' },
    { value: 15, label: '15 min after' },
    { value: 30, label: '30 min after' },
    { value: 60, label: '1 hour after' },
];

function draftFrom(
    mode: SiteModeItem | null,
    options: HouseModeEditOptions,
): Draft {
    const find = <K extends HouseModeAction['action']>(key: K) =>
        (mode?.actions.find((action) => action.action === key) as
            | Extract<HouseModeAction, { action: K }>
            | undefined) ?? null;
    const notify = find('notify');
    const privacy = find('camera_privacy_on');
    const doors = find('door_locked_check');
    const alarm = find('alarm_armed_check');
    return {
        name: mode?.name ?? '',
        scheduled: mode ? mode.schedule !== null : true,
        days: mode?.schedule?.days ?? [1, 2, 3, 4, 5, 6, 7],
        starts: mode?.schedule?.starts ?? '21:00',
        on: {
            notify: notify !== null,
            open_alert: find('open_alert') !== null,
            camera_privacy_on: privacy !== null,
            door_locked_check: doors !== null,
            alarm_armed_check: alarm !== null,
        },
        message: notify?.message ?? '',
        cameras: privacy?.cameras ?? [],
        until: privacy?.until ?? '06:00',
        // A single door or panel is ticked for you.
        doors:
            doors?.doors ??
            (options.doors.length === 1 ? [options.doors[0].id] : []),
        panels:
            alarm?.panels ??
            (options.panels.length === 1 ? [options.panels[0].id] : []),
        after: alarm?.after_minutes ?? 15,
    };
}

/** The actions to send, in the order shown, with the key each list index stands for. */
function actionsFrom(
    draft: Draft,
    choices: SafeActionKey[],
): { actions: HouseModeAction[]; keys: SafeActionKey[] } {
    const actions: HouseModeAction[] = [];
    const keys: SafeActionKey[] = [];
    for (const key of choices) {
        if (!draft.on[key]) continue;
        keys.push(key);
        if (key === 'notify')
            actions.push({
                action: 'notify',
                message: draft.message.trim() || null,
            });
        if (key === 'open_alert') actions.push({ action: 'open_alert' });
        if (key === 'camera_privacy_on')
            actions.push({
                action: 'camera_privacy_on',
                cameras: draft.cameras,
                until: draft.until,
            });
        if (key === 'door_locked_check')
            actions.push({ action: 'door_locked_check', doors: draft.doors });
        if (key === 'alarm_armed_check')
            actions.push({
                action: 'alarm_armed_check',
                panels: draft.panels,
                after_minutes: draft.after,
            });
    }
    return { actions, keys };
}

/* The pop-up kit's tick chips: a tick as well as the tint, so colour is never the only sign. */
const CHIP_GROUP =
    'flex flex-wrap justify-start gap-1.5 rounded-none shadow-none data-[variant=outline]:shadow-none';
const CHIP = 'rounded-full! border! px-3 data-[state=on]:border-primary';

function TickChips({
    label,
    options,
    value,
    onChange,
    invalid,
}: {
    label: string;
    options: { value: string; label: string }[];
    value: string[];
    onChange: (next: string[]) => void;
    invalid?: boolean;
}) {
    return (
        <ToggleGroup
            type="multiple"
            variant="outline"
            aria-label={label}
            aria-invalid={invalid || undefined}
            value={value}
            onValueChange={onChange}
            className={CHIP_GROUP}
        >
            {options.map((option) => (
                <ToggleGroupItem
                    key={option.value}
                    value={option.value}
                    className={CHIP}
                >
                    {value.includes(option.value) ? (
                        <Check aria-hidden="true" className="size-3.5" />
                    ) : null}
                    {option.label}
                </ToggleGroupItem>
            ))}
        </ToggleGroup>
    );
}

function OneChip({
    label,
    options,
    value,
    onChange,
}: {
    label: string;
    options: { value: string; label: string }[];
    value: string;
    onChange: (next: string) => void;
}) {
    return (
        <ToggleGroup
            type="single"
            variant="outline"
            aria-label={label}
            value={value}
            onValueChange={(next) => {
                if (next) onChange(next);
            }}
            className={CHIP_GROUP}
        >
            {options.map((option) => (
                <ToggleGroupItem
                    key={option.value}
                    value={option.value}
                    className={CHIP}
                >
                    {value === option.value ? (
                        <Check aria-hidden="true" className="size-3.5" />
                    ) : null}
                    {option.label}
                </ToggleGroupItem>
            ))}
        </ToggleGroup>
    );
}

/** A switch with its On / Off word, as a row in a pop-up (the settings pattern); settings show beneath when on. */
function SwitchRow({
    id,
    label,
    hint,
    checked,
    disabled,
    onChange,
    children,
}: {
    id: string;
    label: string;
    hint?: ReactNode;
    checked: boolean;
    disabled?: boolean;
    onChange: (on: boolean) => void;
    children?: ReactNode;
}) {
    return (
        <div className="rounded-[10px] border border-border bg-muted/40 px-3.5 py-2.5 sm:col-span-full">
            <div className="flex items-center justify-between gap-4">
                <div className="min-w-0">
                    <label
                        htmlFor={id}
                        className="text-sm font-semibold text-foreground"
                    >
                        {label}
                    </label>
                    {hint ? (
                        <p className="text-caption mt-0.5">{hint}</p>
                    ) : null}
                </div>
                <span className="flex shrink-0 items-center gap-2">
                    <span
                        className="text-sm text-muted-foreground"
                        aria-hidden="true"
                    >
                        {checked ? 'On' : 'Off'}
                    </span>
                    <Switch
                        id={id}
                        checked={checked}
                        disabled={disabled}
                        onCheckedChange={onChange}
                    />
                </span>
            </div>
            {checked && children ? (
                <div className="mt-3 grid gap-3 border-t border-border pt-3">
                    {children}
                </div>
            ) : null}
        </div>
    );
}

/**
 * What an action does for a house mode, where it's narrower than the shared words: a mode tells only
 * the staff on shift, in the app, and its alerts come only from what its checks find.
 */
const MODE_HINTS: Partial<Record<SafeActionKey, string>> = {
    notify: 'The staff on shift at the house are told of the switch, in the app.',
    open_alert:
        'A door not locked or an alarm not armed opens an alert in Security & devices › Alerts, instead of a message.',
};

const deviceChips = (options: DeviceOption[]) =>
    options.map((option) => ({ value: String(option.id), label: option.name }));

export function EditHouseMode({
    open,
    onClose,
    siteId,
    siteName,
    mode,
    options,
}: {
    open: boolean;
    onClose: () => void;
    siteId: number;
    siteName: string;
    /** Null adds a mode. */
    mode: SiteModeItem | null;
    options: HouseModeEditOptions;
}) {
    const [draft, setDraft] = useState<Draft>(() => draftFrom(mode, options));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [error, setError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);
    const start = draftFrom(mode, options);
    const choiceKeys = options.choices.map((choice) => choice.key);

    useEffect(() => {
        if (!open) return;
        setDraft(draftFrom(mode, options));
        setErrors({});
        setError(undefined);
        setProcessing(false);
        // Only when it opens: a prop refresh mustn't wipe what is being typed.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, mode?.id]);

    const dirty = JSON.stringify(draft) !== JSON.stringify(start);
    const change = (next: Partial<Draft>) =>
        setDraft((current) => ({ ...current, ...next }));
    const toggle = (key: SafeActionKey, on: boolean) =>
        setDraft((current) => ({
            ...current,
            on: { ...current.on, [key]: on },
        }));
    const adding = mode === null;
    const name = adding ? draft.name.trim() || 'the mode' : mode.name;
    const { keys } = actionsFrom(draft, choiceKeys);
    // Server errors arrive as actions.{index}.{field}; show each beside its action.
    const actionError = (
        key: SafeActionKey,
        field: string,
    ): string | undefined => {
        const index = keys.indexOf(key);
        return index < 0
            ? undefined
            : (errors[`actions.${index}.${field}`] ??
                  (field === 'action'
                      ? errors[`actions.${index}`]
                      : undefined));
    };

    const submit = () => {
        const found: Record<string, string> = {};
        if (adding && draft.name.trim() === '')
            found.name = 'Give the mode a short name (up to 40 letters).';
        if (draft.scheduled && draft.days.length === 0)
            found.schedule = 'Choose at least one day.';
        setErrors(found);
        if (Object.keys(found).length > 0) return;
        const built = actionsFrom(draft, choiceKeys);
        const schedule: HouseModeSchedule | null = draft.scheduled
            ? {
                  days: [...draft.days].sort((a, b) => a - b),
                  starts: draft.starts,
              }
            : null;
        setProcessing(true);
        setError(undefined);
        send(
            adding ? 'post' : 'put',
            adding
                ? `/security-devices/sites/${siteId}/modes`
                : `/security-devices/sites/${siteId}/modes/${mode.id}`,
            {
                ...(adding ? { name: draft.name.trim() } : {}),
                schedule,
                actions: built.actions,
            },
            {
                done: onClose,
                errors: (messages) => {
                    setErrors(messages);
                    const known = Object.keys(messages).every(
                        (key) =>
                            key === 'name' ||
                            key === 'schedule' ||
                            key.startsWith('actions.'),
                    );
                    if (!known)
                        setError(
                            Object.values(messages)[0] ??
                                'The mode couldn’t be saved. Try again.',
                        );
                },
                finish: () => setProcessing(false),
                unconfirmed: () => setError(UNCONFIRMED),
            },
        );
    };

    const hintFor = (key: SafeActionKey, description: string): ReactNode => {
        if (key === 'camera_privacy_on' && options.cameras.length === 0)
            return 'No cameras at this Site';
        if (key === 'door_locked_check' && options.doors.length === 0)
            return 'No doors at this Site';
        if (key === 'alarm_armed_check' && options.panels.length === 0)
            return 'No alarm panel at this Site';
        return MODE_HINTS[key] ?? description;
    };
    const unavailable = (key: SafeActionKey) =>
        (key === 'camera_privacy_on' && options.cameras.length === 0) ||
        (key === 'door_locked_check' && options.doors.length === 0) ||
        (key === 'alarm_armed_check' && options.panels.length === 0) ||
        (key === 'open_alert' &&
            options.doors.length === 0 &&
            options.panels.length === 0);

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            size="lg"
            icon={adding ? House : modeIcon(mode.name, true)}
            title={
                adding
                    ? `Add a mode · ${siteName}`
                    : `Edit ${mode.name} mode · ${siteName}`
            }
            description={
                adding
                    ? 'What it does, and when it starts'
                    : `What ${mode.name} does, and when it starts`
            }
            error={error}
            submitLabel={adding ? 'Add mode' : 'Save mode'}
            processingLabel="Saving…"
            processing={processing}
            dirty={dirty}
            draftName={adding ? 'this new mode' : `${mode.name} mode`}
            onSubmit={submit}
            testId="sd-edit-mode"
        >
            {adding ? (
                <PopupSection icon={Tag} title="Name">
                    <PopupField
                        label="Name"
                        htmlFor="sd-mode-name"
                        required
                        error={errors.name}
                    >
                        <Input
                            id="sd-mode-name"
                            value={draft.name}
                            maxLength={40}
                            placeholder="For example Visitors"
                            aria-invalid={!!errors.name || undefined}
                            onChange={(event) =>
                                change({ name: event.target.value })
                            }
                        />
                    </PopupField>
                </PopupSection>
            ) : null}

            <PopupSection icon={Clock3} title="When" columns={2}>
                <SwitchRow
                    id="sd-mode-scheduled"
                    label="Starts on a schedule"
                    hint={
                        draft.scheduled
                            ? 'It runs until the next mode starts'
                            : 'Off: it starts only when someone switches to it'
                    }
                    checked={draft.scheduled}
                    onChange={(on) => change({ scheduled: on })}
                />
                {draft.scheduled ? (
                    <>
                        {/* Top-aligned, so the two fields sit level whatever their hints. */}
                        <div className="grid items-start gap-x-[18px] gap-y-3 sm:col-span-full sm:grid-cols-2">
                            <PopupField
                                label="Starts"
                                htmlFor="sd-mode-starts"
                                required
                                hint="NZ time"
                            >
                                <TimePicker
                                    id="sd-mode-starts"
                                    label="Starts"
                                    value={draft.starts}
                                    onChange={(value) =>
                                        change({ starts: value })
                                    }
                                />
                            </PopupField>
                            <PopupField
                                label="Ends"
                                hint="A mode runs until the next one starts"
                            >
                                <p className="flex min-h-[44px] items-center rounded-[10px] border border-border bg-muted/40 px-3 text-sm text-muted-foreground">
                                    When the next mode starts
                                </p>
                            </PopupField>
                        </div>
                        <PopupField
                            label="Days"
                            required
                            wide
                            error={errors.schedule}
                        >
                            <TickChips
                                label="Days"
                                options={PRIVACY_DAYS.map((day) => ({
                                    value: String(day.value),
                                    label: day.label,
                                }))}
                                value={draft.days.map(String)}
                                onChange={(next) =>
                                    change({
                                        days: next
                                            .map(Number)
                                            .sort((a, b) => a - b),
                                    })
                                }
                                invalid={!!errors.schedule}
                            />
                        </PopupField>
                    </>
                ) : errors.schedule ? (
                    <p className="text-sm text-destructive sm:col-span-full">
                        {errors.schedule}
                    </p>
                ) : null}
            </PopupSection>

            <PopupSection icon={ListChecks} title="What it does">
                {options.choices.map((choice) => {
                    const id = `sd-mode-action-${choice.key}`;
                    const problem = actionError(choice.key, 'action');
                    return (
                        <div
                            key={choice.key}
                            className="grid gap-1 sm:col-span-full"
                        >
                            <SwitchRow
                                id={id}
                                label={choice.label}
                                hint={hintFor(choice.key, choice.description)}
                                checked={draft.on[choice.key]}
                                disabled={
                                    unavailable(choice.key) &&
                                    !draft.on[choice.key]
                                }
                                onChange={(on) => toggle(choice.key, on)}
                            >
                                {choice.key === 'notify' ? (
                                    <PopupField
                                        label="Message"
                                        htmlFor="sd-mode-message"
                                        hint="Optional · up to 200 letters"
                                        error={actionError('notify', 'message')}
                                    >
                                        <Input
                                            id="sd-mode-message"
                                            value={draft.message}
                                            maxLength={200}
                                            placeholder={`For example: ${name} has started, check the side gate`}
                                            onChange={(event) =>
                                                change({
                                                    message: event.target.value,
                                                })
                                            }
                                        />
                                    </PopupField>
                                ) : null}
                                {choice.key === 'camera_privacy_on' ? (
                                    <div className="grid items-start gap-3 sm:grid-cols-2">
                                        <PopupField
                                            label="Cameras"
                                            required
                                            error={actionError(
                                                'camera_privacy_on',
                                                'cameras',
                                            )}
                                        >
                                            <TickChips
                                                label="Cameras"
                                                options={deviceChips(
                                                    options.cameras,
                                                )}
                                                value={draft.cameras.map(
                                                    String,
                                                )}
                                                onChange={(next) =>
                                                    change({
                                                        cameras:
                                                            next.map(Number),
                                                    })
                                                }
                                                invalid={
                                                    !!actionError(
                                                        'camera_privacy_on',
                                                        'cameras',
                                                    )
                                                }
                                            />
                                        </PopupField>
                                        <PopupField
                                            label="Until"
                                            htmlFor="sd-mode-until"
                                            required
                                            hint="NZ time · switching modes never turns it off early"
                                            error={actionError(
                                                'camera_privacy_on',
                                                'until',
                                            )}
                                        >
                                            <TimePicker
                                                id="sd-mode-until"
                                                label="Privacy until"
                                                value={draft.until}
                                                onChange={(value) =>
                                                    change({ until: value })
                                                }
                                            />
                                        </PopupField>
                                    </div>
                                ) : null}
                                {choice.key === 'door_locked_check' ? (
                                    <PopupField
                                        label="Doors"
                                        required
                                        error={actionError(
                                            'door_locked_check',
                                            'doors',
                                        )}
                                    >
                                        <TickChips
                                            label="Doors"
                                            options={deviceChips(options.doors)}
                                            value={draft.doors.map(String)}
                                            onChange={(next) =>
                                                change({
                                                    doors: next.map(Number),
                                                })
                                            }
                                            invalid={
                                                !!actionError(
                                                    'door_locked_check',
                                                    'doors',
                                                )
                                            }
                                        />
                                    </PopupField>
                                ) : null}
                                {choice.key === 'alarm_armed_check' ? (
                                    <div className="grid items-start gap-3 sm:grid-cols-2">
                                        <PopupField
                                            label="Alarm"
                                            required
                                            error={actionError(
                                                'alarm_armed_check',
                                                'panels',
                                            )}
                                        >
                                            <TickChips
                                                label="Alarm"
                                                options={deviceChips(
                                                    options.panels,
                                                )}
                                                value={draft.panels.map(String)}
                                                onChange={(next) =>
                                                    change({
                                                        panels: next.map(
                                                            Number,
                                                        ),
                                                    })
                                                }
                                                invalid={
                                                    !!actionError(
                                                        'alarm_armed_check',
                                                        'panels',
                                                    )
                                                }
                                            />
                                        </PopupField>
                                        <PopupField
                                            label="Check"
                                            hint={
                                                draft.scheduled &&
                                                draft.after > 0
                                                    ? `By ${clockWords(addMinutes(draft.starts, draft.after))}`
                                                    : undefined
                                            }
                                            error={actionError(
                                                'alarm_armed_check',
                                                'after_minutes',
                                            )}
                                        >
                                            <OneChip
                                                label="When to check the alarm"
                                                options={AFTER_OPTIONS.map(
                                                    (option) => ({
                                                        value: String(
                                                            option.value,
                                                        ),
                                                        label: option.label,
                                                    }),
                                                )}
                                                value={String(draft.after)}
                                                onChange={(next) =>
                                                    change({
                                                        after: Number(next),
                                                    })
                                                }
                                            />
                                        </PopupField>
                                    </div>
                                ) : null}
                            </SwitchRow>
                            {problem ? (
                                <p className="text-sm text-destructive">
                                    {problem}
                                </p>
                            ) : null}
                        </div>
                    );
                })}
                <PopupCallout tone="info">
                    Modes check and remind. They never lock, arm, disarm or
                    unlock anything: a door left unlocked or an alarm not armed
                    is reported to the staff on shift to sort out by hand. A
                    person disarms or unlocks, with a confirm.
                </PopupCallout>
            </PopupSection>
        </PopupForm>
    );
}

/** "21:00" + 15 → "21:15", wrapping past midnight. */
function addMinutes(hhmm: string, minutes: number): string {
    const match = /^(\d{2}):(\d{2})$/.exec(hhmm);
    if (!match) return hhmm;
    const total =
        (Number(match[1]) * 60 + Number(match[2]) + minutes) % (24 * 60);
    return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
}
