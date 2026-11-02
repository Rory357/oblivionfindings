/**
 * House automations (S&D rebuild drawings sdr-40 Add automation and sdr-41 Test), plus turning one
 * off or on and removing it. All on the pop-up kit, at kit widths: the wizard is WizardShell (1100),
 * the test is a Viewer (720) and the rest are Confirms (480).
 *
 * An automation only ever takes the safe actions the server lists (SafeActions::choices(), D12): tell
 * people, open an alert, turn camera privacy on, and check that doors are locked and the alarm is
 * armed. There is no way to add a disarm or an unlock, and the server refuses one anyway.
 */
import { TimePicker } from '@/components/fleet-assets/maintenance/time-picker';
import { VehicleSearchSelect } from '@/components/fleet-assets/vehicle-workspace/search-select';
import {
    DiscardDraftConfirm,
    PopupCallout,
    PopupConfirm,
    PopupContext,
    PopupField,
    PopupLens,
    PopupSection,
    PopupViewer,
    usePopupClose,
} from '@/components/popup';
import { failure } from '@/components/security-devices/alert-popups';
import { ChoiceTiles } from '@/components/security-devices/device-popups';
import { Ring } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/wizard/shell';
import { formatTime } from '@/lib/datetime';
import type {
    AutomationAction,
    AutomationDeviceGroup,
    AutomationForm,
    AutomationRow,
    AutomationRunResult,
    AutomationTrigger,
    AutomationTriggerKind,
    SafeActionKey,
} from '@/pages/security-devices/alerts/_types';
import axios from 'axios';
import {
    ArrowDown,
    ArrowUp,
    CircleCheck,
    Clock,
    DoorOpen,
    Filter,
    FlaskConical,
    LoaderCircle,
    MapPin,
    MoreHorizontal,
    Play,
    Plus,
    Power,
    PowerOff,
    Thermometer,
    Trash2,
    Wifi,
    WifiOff,
    Workflow,
    Zap,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { toast } from 'sonner';

const BASE = '/security-devices/automations';
const JSON_HEADERS = { headers: { Accept: 'application/json' } };

/** The automation a pop-up acts on, as the page knows it. */
export type AutomationTarget = { id: number; name: string };

type Failure = ReturnType<typeof failure>;

/* ------------------------------------------------------------------ */
/*  Words                                                              */
/* ------------------------------------------------------------------ */

const DAYS: { value: string; label: string }[] = [
    { value: '1', label: 'Mon' },
    { value: '2', label: 'Tue' },
    { value: '3', label: 'Wed' },
    { value: '4', label: 'Thu' },
    { value: '5', label: 'Fri' },
    { value: '6', label: 'Sat' },
    { value: '7', label: 'Sun' },
];

const TRIGGER_GROUP: Partial<
    Record<AutomationTriggerKind, AutomationDeviceGroup>
> = {
    door_opened: 'doors',
    reading: 'sensors',
    device_offline: 'any',
    device_back: 'any',
};

/**
 * Our own words where the safe action's description says more than an automation offers today
 * (it can't name people one by one yet), so the pop-up never promises what isn't built.
 */
const DESCRIPTION: Partial<Record<SafeActionKey, string>> = {
    notify: 'The staff on shift at the house, or people in a role, in the app (and by push or email if chosen).',
};

const actionDescription = (key: SafeActionKey, fallback: string) =>
    DESCRIPTION[key] ?? fallback;

const ANY_LABEL: Record<AutomationDeviceGroup, string> = {
    doors: 'Any door at the Site',
    sensors: 'Any sensor at the Site',
    any: 'Any device at the Site',
    cameras: 'Any camera',
    panels: 'Any alarm',
};

const WHICH_LABEL: Partial<Record<AutomationTriggerKind, string>> = {
    door_opened: 'Which door',
    reading: 'Which sensor',
    device_offline: 'Which device',
    device_back: 'Which device',
};

/** "21:00" → "9:00 pm" (an NZ wall-clock time with no date). */
export function clockWords(hhmm: string): string {
    const match = /^([01]\d|2[0-3]):([0-5]\d)$/.exec(hhmm);
    if (!match) return '—';
    const hour = Number(match[1]);
    return `${hour % 12 === 0 ? 12 : hour % 12}:${match[2]} ${hour >= 12 ? 'pm' : 'am'}`;
}

function daysWords(days: number[]): string {
    const sorted = [...new Set(days)].sort((a, b) => a - b).join(',');
    if (sorted === '1,2,3,4,5,6,7') return 'every day';
    if (sorted === '1,2,3,4,5') return 'on weekdays';
    if (sorted === '6,7') return 'on weekends';
    return `on ${listWords(
        days
            .slice()
            .sort((a, b) => a - b)
            .map((day) => DAYS[day - 1]?.label ?? '?'),
    )}`;
}

function listWords(items: string[], joiner = 'and'): string {
    if (items.length <= 1) return items[0] ?? 'nothing';
    return `${items.slice(0, -1).join(', ')} ${joiner} ${items[items.length - 1]}`;
}

/* ------------------------------------------------------------------ */
/*  Small kit pieces                                                   */
/* ------------------------------------------------------------------ */

const CHIP_GROUP =
    'flex flex-wrap justify-start gap-1.5 rounded-none shadow-none data-[variant=outline]:shadow-none';
const CHIP =
    'rounded-full! border! px-3 data-[state=on]:border-primary data-[state=on]:bg-primary-fill/10 data-[state=on]:text-primary';

function TickChips({
    label,
    options,
    value,
    onChange,
}: {
    label: string;
    options: { value: string; label: string }[];
    value: string[];
    onChange: (next: string[]) => void;
}) {
    return (
        <ToggleGroup
            type="multiple"
            variant="outline"
            aria-label={label}
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
                    {option.label}
                </ToggleGroupItem>
            ))}
        </ToggleGroup>
    );
}

function OneChoice<T extends string>({
    label,
    options,
    value,
    onChange,
}: {
    label: string;
    options: { value: T; label: string }[];
    value: T;
    onChange: (next: T) => void;
}) {
    return (
        <ToggleGroup
            type="single"
            variant="outline"
            aria-label={label}
            value={value}
            onValueChange={(next) => {
                if (next) onChange(next as T);
            }}
            className={CHIP_GROUP}
        >
            {options.map((option) => (
                <ToggleGroupItem
                    key={option.value}
                    value={option.value}
                    className={CHIP}
                >
                    {option.label}
                </ToggleGroupItem>
            ))}
        </ToggleGroup>
    );
}

/** A switch with its On / Off word (the settings pattern), as a row in a pop-up. */
function SwitchRow({
    id,
    label,
    hint,
    checked,
    onChange,
}: {
    id: string;
    label: string;
    hint?: string;
    checked: boolean;
    onChange: (on: boolean) => void;
}) {
    return (
        <div className="flex items-center justify-between gap-4 rounded-[10px] border border-border bg-muted/40 px-3.5 py-2.5">
            <div className="min-w-0">
                <label
                    htmlFor={id}
                    className="text-sm font-semibold text-foreground"
                >
                    {label}
                </label>
                {hint ? <p className="text-caption mt-0.5">{hint}</p> : null}
            </div>
            <span className="flex shrink-0 items-center gap-2">
                <span
                    className="text-sm text-muted-foreground"
                    aria-hidden="true"
                >
                    {checked ? 'On' : 'Off'}
                </span>
                <Switch id={id} checked={checked} onCheckedChange={onChange} />
            </span>
        </div>
    );
}

/** The step's question with its round lens (the drawn step head). */
function StepQuestion({
    icon,
    title,
    line,
}: {
    icon: LucideIcon;
    title: string;
    line: string;
}) {
    return (
        <div className="mb-5 flex items-start gap-3.5">
            <PopupLens icon={icon} />
            <div className="min-w-0 pt-0.5">
                <h2 className="text-section-title leading-snug">{title}</h2>
                <p className="mt-0.5 text-sm text-muted-foreground">{line}</p>
            </div>
        </div>
    );
}

/** A quiet line while something loads, or why it couldn't, with Retry. */
function LoadState({
    failed,
    loading,
    failedText,
    onRetry,
}: {
    failed: boolean;
    loading: string;
    failedText: string;
    onRetry: () => void;
}) {
    if (!failed) {
        return (
            <p
                className="flex items-center gap-2 text-sm text-muted-foreground"
                role="status"
            >
                <LoaderCircle
                    aria-hidden="true"
                    className="size-4 animate-spin motion-reduce:animate-none"
                />
                {loading}
            </p>
        );
    }
    return (
        <PopupCallout tone="warning">
            {failedText}{' '}
            <Button
                type="button"
                variant="link"
                className="h-auto p-0 align-baseline"
                onClick={onRetry}
            >
                Retry
            </Button>
        </PopupCallout>
    );
}

/* ------------------------------------------------------------------ */
/*  40 · Add automation (and Edit, the same wizard prefilled)          */
/* ------------------------------------------------------------------ */

const STEPS: WizardStep[] = [
    { key: 'when', label: 'When', blurb: 'What starts it', icon: Zap },
    { key: 'only-if', label: 'Only if', blurb: 'Conditions', icon: Filter },
    { key: 'then', label: 'Then', blurb: 'What it does', icon: Play },
    {
        key: 'check',
        label: 'Check and turn on',
        blurb: 'Name, review, on',
        icon: CircleCheck,
    },
];

type Draft = {
    kind: AutomationTriggerKind | '';
    deviceId: string;
    metric: string;
    direction: 'above' | 'below';
    value: string;
    at: string;
    days: string[];
    siteId: string;
    windowOn: boolean;
    from: string;
    to: string;
    /** House mode ids ("House mode is"); empty runs in any mode. */
    modes: string[];
    actions: AutomationAction[];
    name: string;
    named: boolean;
    isOn: boolean;
};

function emptyDraft(siteId: string): Draft {
    return {
        kind: '',
        deviceId: '',
        metric: 'temperature',
        direction: 'above',
        value: '',
        at: '21:00',
        days: DAYS.map((day) => day.value),
        siteId,
        windowOn: false,
        from: '22:00',
        to: '06:00',
        modes: [],
        actions: [
            {
                action: 'notify',
                who: 'on_shift',
                email: false,
                push: false,
                message: null,
            },
        ],
        name: '',
        named: false,
        isOn: true,
    };
}

function draftFrom(row: AutomationRow): Draft {
    const definition = row.definition!;
    const trigger = definition.trigger;
    const between = definition.conditions.between;
    return {
        kind: trigger.kind,
        deviceId:
            trigger.device_id !== null && trigger.device_id !== undefined
                ? String(trigger.device_id)
                : '',
        metric: trigger.metric ?? 'temperature',
        direction: trigger.direction ?? 'above',
        value: trigger.value !== undefined ? String(trigger.value) : '',
        at: trigger.at ?? '21:00',
        days: (trigger.days ?? [1, 2, 3, 4, 5, 6, 7]).map(String),
        siteId: String(definition.site_id),
        windowOn: between !== null,
        from: between?.from ?? '22:00',
        to: between?.to ?? '06:00',
        modes: (definition.conditions.modes ?? []).map(String),
        actions: definition.actions.map((action) => ({ ...action })),
        name: definition.name,
        named: true,
        isOn: definition.is_on,
    };
}

const TRIGGER_TILES: {
    value: AutomationTriggerKind;
    label: string;
    description: string;
    icon: LucideIcon;
}[] = [
    {
        value: 'door_opened',
        label: 'Door opened',
        description: 'A door or door sensor',
        icon: DoorOpen,
    },
    {
        value: 'reading',
        label: 'Reading too high or low',
        description: 'Temperature, humidity, CO₂',
        icon: Thermometer,
    },
    {
        value: 'device_offline',
        label: 'Device goes offline',
        description: 'Stops answering',
        icon: WifiOff,
    },
    {
        value: 'device_back',
        label: 'Device back online',
        description: 'Answering again',
        icon: Wifi,
    },
    {
        value: 'time',
        label: 'At a time of day',
        description: 'Every day or chosen days',
        icon: Clock,
    },
];

/** Devices chosen for actions belong to one Site: a new Site starts them again. */
function withoutDevices(actions: AutomationAction[]): AutomationAction[] {
    return actions.map((action) => ({
        ...action,
        ...(action.cameras ? { cameras: [] } : {}),
        ...(action.doors ? { doors: [] } : {}),
        ...(action.panels ? { panels: [] } : {}),
    }));
}

/** Fields that show their error beside them (the rest show at the top of the step). */
const INLINE =
    /^(trigger\.(kind|device_id|metric|direction|value|at|days)|site_id|conditions\.(between|modes)|actions\.\d+\.(action|who|role|message|cameras|until|doors|panels)|name)$/;

/** Which step a server field belongs to. */
function stepOf(field: string): number {
    if (field.startsWith('trigger')) return 0;
    if (field === 'site_id' || field.startsWith('conditions')) return 1;
    if (field.startsWith('actions')) return 2;
    return 3;
}

export function AddAutomation({
    open,
    form,
    formFailed,
    load,
    editing,
    siteId,
    onClose,
    onSaved,
    onTest,
}: {
    open: boolean;
    /** Undefined while it loads; null without automations.manage. */
    form: AutomationForm | null | undefined;
    formFailed: boolean;
    load: () => void;
    /** Edit: the automation to change (the same wizard, prefilled). */
    editing: AutomationRow | null;
    /** The Site the page is filtered to, chosen first. */
    siteId?: string | null;
    onClose: () => void;
    /** Something was saved: the page reloads its list. */
    onSaved: () => void;
    /** "Test it now" on the last screen. */
    onTest: (target: AutomationTarget) => void;
}) {
    const [step, setStep] = useState(0);
    const [draft, setDraft] = useState<Draft>(() => emptyDraft(''));
    const [touched, setTouched] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [lost, setLost] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [saved, setSaved] = useState<{
        id: number;
        name: string;
        on: boolean;
    } | null>(null);

    const sites = useMemo(() => form?.sites ?? [], [form]);
    const firstSite =
        siteId && sites.some((site) => site.value === siteId)
            ? siteId
            : sites.length === 1
              ? sites[0].value
              : '';

    const reset = () => {
        setStep(0);
        setDraft(
            editing?.definition ? draftFrom(editing) : emptyDraft(firstSite),
        );
        setTouched(false);
        setErrors({});
        setLost(false);
        setSaved(null);
    };
    useEffect(() => {
        if (open) reset();
        // Only when it opens, or when the choices arrive after it opened.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, editing?.id, form ? 1 : 0]);

    const change = (next: Partial<Draft>) => {
        setTouched(true);
        setDraft((current) => ({ ...current, ...next }));
    };

    const siteName = (id: string) =>
        sites.find((site) => site.value === id)?.label ?? '';
    const group = draft.kind ? TRIGGER_GROUP[draft.kind] : undefined;
    const devicesAt = (site: string, which: AutomationDeviceGroup) =>
        form?.devices[site]?.[which] ?? [];

    // Trigger devices across the person's Sites (only the automation's Site when editing).
    const triggerOptions = useMemo(() => {
        if (!group) return [];
        const list = (
            editing
                ? sites.filter((site) => site.value === draft.siteId)
                : sites
        ).flatMap((site) =>
            devicesAt(site.value, group).map((device) => ({
                value: String(device.id),
                label: device.name,
                description: site.label,
                site: site.value,
            })),
        );
        return [
            {
                value: '',
                label: ANY_LABEL[group],
                description: 'The Site is chosen on the next step',
                site: '',
            },
            ...list,
        ];
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [form, group, editing, draft.siteId, sites]);
    const triggerDevice = triggerOptions.find(
        (option) => option.value !== '' && option.value === draft.deviceId,
    );
    const metric = form?.metrics.find(
        (option) => option.value === draft.metric,
    );
    // "House mode is": the chosen Site's house modes (none: the choice isn't shown).
    const siteModes = form?.modes?.[draft.siteId] ?? [];
    const chosenModes = siteModes
        .filter((mode) => draft.modes.includes(String(mode.id)))
        .map((mode) => mode.name);

    const whenWords = (): string => {
        const name = triggerDevice?.label ?? null;
        let sentence = '';
        switch (draft.kind) {
            case 'door_opened':
                sentence = name ? `${name} opens` : 'A door opens';
                break;
            case 'reading': {
                const label =
                    draft.metric === 'co2' || draft.metric === 'pm25'
                        ? (metric?.label ?? 'Reading')
                        : (metric?.label ?? 'Reading').toLowerCase();
                const value =
                    `${draft.value || '…'}${metric?.unit === '%' ? '%' : ` ${metric?.unit ?? ''}`}`.trim();
                sentence = name
                    ? `${name}: ${label} ${draft.direction} ${value}`
                    : `${label.charAt(0).toUpperCase()}${label.slice(1)} ${draft.direction} ${value} on any sensor`;
                break;
            }
            case 'device_offline':
                sentence = name
                    ? `${name} goes offline`
                    : 'A device goes offline';
                break;
            case 'device_back':
                sentence = name
                    ? `${name} is back online`
                    : 'A device comes back online';
                break;
            case 'time':
                sentence = `At ${clockWords(draft.at)} ${daysWords(draft.days.map(Number))}`;
                break;
            default:
                return '—';
        }
        const hours = draft.windowOn
            ? ` between ${clockWords(draft.from)} and ${clockWords(draft.to)}`
            : '';
        const modes = chosenModes.length
            ? ` in ${listWords(chosenModes, 'or')} mode`
            : '';
        return `${sentence}${hours}${modes}`;
    };
    const suggested = whenWords() === '—' ? '' : whenWords().slice(0, 80);
    const shownName = draft.named ? draft.name : suggested;

    const choices = form?.actions ?? [];
    const label = (key: SafeActionKey) =>
        choices.find((choice) => choice.key === key)?.label ?? key;
    const roleLabel = (role: string | null | undefined) =>
        form?.roles.find((option) => option.value === role)?.label ??
        role ??
        '';
    const deviceNames = (which: AutomationDeviceGroup, ids: number[] = []) =>
        listWords(
            ids.map(
                (id) =>
                    devicesAt(draft.siteId, which).find(
                        (device) => device.id === id,
                    )?.name ?? 'a device',
            ),
        );
    const actionWords = (action: AutomationAction): string => {
        switch (action.action) {
            case 'notify': {
                const how = [
                    'app',
                    action.push ? 'push' : null,
                    action.email ? 'email' : null,
                ]
                    .filter(Boolean)
                    .join(', ');
                const who =
                    action.who === 'role'
                        ? `people at the house with the ${roleLabel(action.role)} role`
                        : 'the staff on shift';
                return `Tell ${who} (${how})`;
            }
            case 'open_alert':
                return 'Open an alert for the staff on shift';
            case 'camera_privacy_on':
                return `Turn camera privacy on until ${clockWords(action.until ?? '')} for ${deviceNames('cameras', action.cameras)}`;
            case 'door_locked_check':
                return `Check ${deviceNames('doors', action.doors)} ${(action.doors ?? []).length === 1 ? 'is' : 'are'} locked`;
            case 'alarm_armed_check':
                return 'Check the alarm is armed';
        }
    };

    const setAction = (index: number, next: Partial<AutomationAction>) =>
        change({
            actions: draft.actions.map((action, at) =>
                at === index ? { ...action, ...next } : action,
            ),
        });
    const addAction = (key: SafeActionKey) => {
        const fresh: AutomationAction =
            key === 'notify'
                ? {
                      action: key,
                      who: 'on_shift',
                      email: false,
                      push: false,
                      message: null,
                  }
                : key === 'camera_privacy_on'
                  ? { action: key, cameras: [], until: '06:00' }
                  : key === 'door_locked_check'
                    ? { action: key, doors: [] }
                    : key === 'alarm_armed_check'
                      ? { action: key, panels: [] }
                      : { action: key };
        change({ actions: [...draft.actions, fresh] });
    };
    const moveAction = (index: number, by: -1 | 1) => {
        const next = [...draft.actions];
        const [moved] = next.splice(index, 1);
        next.splice(index + by, 0, moved);
        change({ actions: next });
    };

    /** The step's own checks before Continue (the server checks everything again). */
    const check = (which: number): Record<string, string> => {
        const found: Record<string, string> = {};
        if (which === 0) {
            if (!draft.kind)
                found['trigger.kind'] = 'Choose what starts the automation.';
            if (draft.kind === 'reading') {
                const value = Number(draft.value);
                if (draft.value.trim() === '' || !Number.isFinite(value))
                    found['trigger.value'] = 'Enter the value.';
                else if (metric && (value < metric.min || value > metric.max))
                    found['trigger.value'] =
                        `Enter a value from ${metric.min} to ${metric.max} ${metric.unit}.`;
            }
            if (draft.kind === 'time' && draft.days.length === 0)
                found['trigger.days'] = 'Choose at least one day.';
        }
        if (which === 1) {
            if (!draft.siteId) found.site_id = 'Choose the Site.';
            if (draft.windowOn && draft.from === draft.to)
                found['conditions.between'] =
                    'The start and end can’t be the same time.';
        }
        if (which === 2) {
            if (draft.actions.length === 0)
                found.actions = 'Add at least one action.';
            draft.actions.forEach((action, index) => {
                if (
                    action.action === 'notify' &&
                    action.who === 'role' &&
                    !action.role
                )
                    found[`actions.${index}.role`] = 'Choose the role to tell.';
                if (action.action === 'open_alert' && draft.kind === 'time')
                    found[`actions.${index}.action`] =
                        'A time of day has no device to open an alert on. Tell people instead.';
                if (
                    action.action === 'camera_privacy_on' &&
                    (action.cameras ?? []).length === 0
                )
                    found[`actions.${index}.cameras`] =
                        'Choose at least one camera at this Site.';
                if (
                    action.action === 'door_locked_check' &&
                    (action.doors ?? []).length === 0
                )
                    found[`actions.${index}.doors`] =
                        'Choose at least one door at this Site.';
                if (
                    action.action === 'alarm_armed_check' &&
                    (action.panels ?? []).length === 0
                )
                    found[`actions.${index}.panels`] =
                        'Choose the alarm at this Site.';
            });
        }
        if (which === 3 && shownName.trim().length < 3)
            found.name = 'Give the automation a name of at least 3 letters.';
        return found;
    };

    const payload = () => {
        const trigger: AutomationTrigger =
            draft.kind === 'time'
                ? {
                      kind: 'time',
                      at: draft.at,
                      days: draft.days.map(Number).sort((a, b) => a - b),
                  }
                : draft.kind === 'reading'
                  ? {
                        kind: 'reading',
                        device_id: draft.deviceId
                            ? Number(draft.deviceId)
                            : null,
                        metric: draft.metric,
                        direction: draft.direction,
                        value: Number(draft.value),
                    }
                  : {
                        kind: draft.kind as AutomationTriggerKind,
                        device_id: draft.deviceId
                            ? Number(draft.deviceId)
                            : null,
                    };
        return {
            site_id: Number(draft.siteId),
            name: shownName.trim(),
            is_on: draft.isOn,
            trigger,
            conditions: {
                between: draft.windowOn
                    ? { from: draft.from, to: draft.to }
                    : null,
                modes: draft.modes.map(Number).sort((a, b) => a - b),
            },
            actions: draft.actions,
            ...(editing ? { version: editing.version } : {}),
        };
    };

    const submit = () => {
        for (const which of [0, 1, 2, 3]) {
            const found = check(which);
            if (Object.keys(found).length > 0) {
                setErrors(found);
                setStep(which);
                return;
            }
        }
        setProcessing(true);
        setErrors({});
        setLost(false);
        const request = editing
            ? axios.put<{
                  id: number;
                  name: string;
                  on: boolean;
                  message: string;
              }>(`${BASE}/${editing.id}`, payload(), JSON_HEADERS)
            : axios.post<{
                  id: number;
                  name: string;
                  on: boolean;
                  message: string;
              }>(BASE, payload(), JSON_HEADERS);
        request
            .then(({ data }) => {
                onSaved();
                if (editing) {
                    toast.success(data.message);
                    onClose();
                    return;
                }
                setSaved({ id: data.id, name: data.name, on: data.on });
            })
            .catch((error) => {
                const outcome: Failure = failure(
                    error,
                    'The automation couldn’t be saved. Try again.',
                    'This automation changed after you opened it. Close this and look at it again.',
                );
                if (outcome.unconfirmed) {
                    setLost(true);
                    return;
                }
                if (outcome.fields) {
                    setErrors(outcome.fields);
                    const first = Object.keys(outcome.fields)[0];
                    if (first) setStep(stepOf(first));
                    return;
                }
                setErrors({
                    form:
                        outcome.error ??
                        'The automation couldn’t be saved. Try again.',
                });
            })
            .finally(() => setProcessing(false));
    };

    const next = () => {
        if (step === 3) return submit();
        const found = check(step);
        setErrors(found);
        if (Object.keys(found).length === 0) setStep(step + 1);
    };

    const dirty = open && !saved && touched;
    const guard = usePopupClose({ dirty, processing, onClose });
    // Errors with a place beside their field show there; the rest show at the top of their step.
    const stepErrors = Object.entries(errors).filter(
        ([field]) =>
            field === 'form' ||
            (stepOf(field) === step &&
                !(
                    INLINE.test(field) &&
                    !(field === 'site_id' && (editing || triggerDevice))
                )),
    );
    const stepError =
        errors.form ?? (stepErrors.length > 0 ? stepErrors[0][1] : undefined);
    const fieldError = (field: string) => errors[field];
    const title = editing ? `Edit ${editing.name}` : 'Add automation';
    const primary = editing
        ? 'Save changes'
        : draft.isOn
          ? 'Turn on automation'
          : 'Save automation';
    const used = new Set(draft.actions.map((action) => action.action));
    const maxActions = form?.max_actions ?? 5;
    const filled = [
        !!draft.kind,
        !!draft.siteId,
        draft.actions.length > 0,
        shownName.trim().length >= 3,
    ].filter(Boolean).length;

    return (
        <>
            <WizardShell
                open={open && !guard.asking}
                onClose={guard.requestClose}
                title={title}
                description="When this happens, and only if…, then do this"
                railIcon={Workflow}
                railTitle={title}
                railSub="When this happens, and only if…, then do this"
                steps={STEPS}
                stepIndex={step}
                onStepClick={(index) => {
                    setErrors({});
                    setStep(index);
                }}
                pct={Math.round((filled / 4) * 100)}
                maxWidth="min(94vw, 1100px)"
                success={
                    saved ? (
                        <WizardSuccessPane
                            title={
                                saved.on
                                    ? `${saved.name} is on`
                                    : `${saved.name} is saved`
                            }
                            blurb={
                                saved.on
                                    ? 'It runs the next time this happens. Test it now: only you get a message, marked “TEST”, and nothing else changes.'
                                    : 'It’s off until you turn it on from its menu. You can still test it: only you get a message, marked “TEST”.'
                            }
                            actions={
                                <>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={onClose}
                                    >
                                        Close
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={reset}
                                    >
                                        Add another
                                    </Button>
                                    <Button
                                        type="button"
                                        onClick={() =>
                                            onTest({
                                                id: saved.id,
                                                name: saved.name,
                                            })
                                        }
                                    >
                                        <FlaskConical aria-hidden="true" />
                                        Test it now
                                    </Button>
                                </>
                            }
                        />
                    ) : undefined
                }
                footerStart={
                    step === 0 ? (
                        <span className="text-caption">
                            Fields marked * are required
                        </span>
                    ) : (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => {
                                setErrors({});
                                setStep(step - 1);
                            }}
                            disabled={processing}
                        >
                            Back
                        </Button>
                    )
                }
                footerEnd={
                    <>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={guard.requestClose}
                            disabled={processing}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            onClick={next}
                            disabled={processing || !form}
                        >
                            {processing ? (
                                <LoaderCircle
                                    aria-hidden="true"
                                    className="animate-spin motion-reduce:animate-none"
                                />
                            ) : null}
                            {processing
                                ? 'Saving…'
                                : step === 3
                                  ? primary
                                  : 'Continue'}
                        </Button>
                    </>
                }
            >
                <WizardStepPane key={step}>
                    {lost ? (
                        <PopupCallout tone="warning" className="mb-4">
                            Result not confirmed: the connection may have
                            dropped. Close this and check the Rules &
                            automations list before saving again, so it isn’t
                            added twice.
                        </PopupCallout>
                    ) : null}
                    {stepError ? (
                        <PopupCallout tone="critical" className="mb-4">
                            {stepError}
                        </PopupCallout>
                    ) : null}

                    {form === null ? (
                        <PopupCallout tone="warning">
                            You can’t add automations. People who manage alert
                            rules and automations can.
                        </PopupCallout>
                    ) : form === undefined ? (
                        <LoadState
                            failed={formFailed}
                            loading="Loading your Sites and devices…"
                            failedText="The choices for an automation couldn’t load."
                            onRetry={load}
                        />
                    ) : step === 0 ? (
                        <>
                            <StepQuestion
                                icon={Zap}
                                title="What should start this automation?"
                                line="Pick the thing that happens."
                            />
                            <PopupSection
                                icon={Zap}
                                title="Trigger"
                                columns={2}
                            >
                                <PopupField
                                    label="When"
                                    required
                                    wide
                                    error={fieldError('trigger.kind')}
                                >
                                    <ChoiceTiles<AutomationTriggerKind>
                                        label="When"
                                        columns={3}
                                        value={draft.kind}
                                        onChange={(kind) =>
                                            change({
                                                kind,
                                                deviceId: editing
                                                    ? draft.deviceId
                                                    : '',
                                                actions:
                                                    kind === 'time'
                                                        ? draft.actions.filter(
                                                              (action) =>
                                                                  action.action !==
                                                                  'open_alert',
                                                          )
                                                        : draft.actions,
                                            })
                                        }
                                        options={TRIGGER_TILES}
                                    />
                                </PopupField>
                                {draft.kind && draft.kind !== 'time' ? (
                                    <PopupField
                                        label={
                                            WHICH_LABEL[draft.kind] ??
                                            'Which device'
                                        }
                                        error={fieldError('trigger.device_id')}
                                        hint={
                                            draft.kind === 'device_offline' ||
                                            draft.kind === 'device_back'
                                                ? 'When its online check fails or passes again'
                                                : undefined
                                        }
                                    >
                                        <VehicleSearchSelect
                                            label={
                                                WHICH_LABEL[draft.kind] ??
                                                'Which device'
                                            }
                                            value={draft.deviceId}
                                            options={triggerOptions}
                                            onChange={(value) => {
                                                const chosen =
                                                    triggerOptions.find(
                                                        (option) =>
                                                            option.value ===
                                                            value,
                                                    );
                                                change({
                                                    deviceId: value,
                                                    ...(chosen &&
                                                    chosen.site &&
                                                    chosen.site !== draft.siteId
                                                        ? {
                                                              siteId: chosen.site,
                                                              modes: [],
                                                              actions:
                                                                  withoutDevices(
                                                                      draft.actions,
                                                                  ),
                                                          }
                                                        : {}),
                                                });
                                            }}
                                            invalid={
                                                !!fieldError(
                                                    'trigger.device_id',
                                                )
                                            }
                                        />
                                    </PopupField>
                                ) : null}
                                {draft.kind === 'reading' ? (
                                    <>
                                        <PopupField
                                            label="Reading"
                                            error={fieldError('trigger.metric')}
                                        >
                                            <OneChoice
                                                label="Reading"
                                                value={draft.metric}
                                                options={(
                                                    form.metrics ?? []
                                                ).map((option) => ({
                                                    value: option.value,
                                                    label: option.label,
                                                }))}
                                                onChange={(value) =>
                                                    change({ metric: value })
                                                }
                                            />
                                        </PopupField>
                                        <PopupField
                                            label="Goes"
                                            error={fieldError(
                                                'trigger.direction',
                                            )}
                                        >
                                            <OneChoice<'above' | 'below'>
                                                label="Goes"
                                                value={draft.direction}
                                                options={[
                                                    {
                                                        value: 'above',
                                                        label: 'Above',
                                                    },
                                                    {
                                                        value: 'below',
                                                        label: 'Below',
                                                    },
                                                ]}
                                                onChange={(value) =>
                                                    change({ direction: value })
                                                }
                                            />
                                        </PopupField>
                                        <PopupField
                                            label="Value"
                                            htmlFor="automation-value"
                                            required
                                            hint="It runs when a reading crosses it, not on every reading past it"
                                            error={fieldError('trigger.value')}
                                        >
                                            <div className="relative">
                                                <Input
                                                    id="automation-value"
                                                    inputMode="decimal"
                                                    value={draft.value}
                                                    onChange={(event) =>
                                                        change({
                                                            value: event.target
                                                                .value,
                                                        })
                                                    }
                                                    aria-invalid={
                                                        !!fieldError(
                                                            'trigger.value',
                                                        )
                                                    }
                                                    className="pr-16"
                                                />
                                                <span className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-muted-foreground">
                                                    {metric?.unit ?? ''}
                                                </span>
                                            </div>
                                        </PopupField>
                                    </>
                                ) : null}
                                {draft.kind === 'time' ? (
                                    <>
                                        <PopupField
                                            label="At"
                                            htmlFor="automation-at"
                                            required
                                            hint="NZ time"
                                            error={fieldError('trigger.at')}
                                        >
                                            <TimePicker
                                                id="automation-at"
                                                label="At"
                                                value={draft.at}
                                                onChange={(value) =>
                                                    change({ at: value })
                                                }
                                            />
                                        </PopupField>
                                        <PopupField
                                            label="Days"
                                            required
                                            error={fieldError('trigger.days')}
                                        >
                                            <TickChips
                                                label="Days"
                                                options={DAYS}
                                                value={draft.days}
                                                onChange={(days) =>
                                                    change({ days })
                                                }
                                            />
                                        </PopupField>
                                    </>
                                ) : null}
                            </PopupSection>
                        </>
                    ) : step === 1 ? (
                        <>
                            <StepQuestion
                                icon={Filter}
                                title="When should it run?"
                                line="Leave these off to run every time."
                            />
                            <PopupSection icon={Filter} title="Conditions">
                                {editing || triggerDevice ? (
                                    <PopupContext
                                        icon={MapPin}
                                        title={
                                            siteName(draft.siteId) || 'One Site'
                                        }
                                        note={
                                            editing
                                                ? 'An automation stays at its Site'
                                                : `From ${triggerDevice?.label ?? 'the device'} you chose`
                                        }
                                    />
                                ) : (
                                    <PopupField
                                        label="Site"
                                        required
                                        error={fieldError('site_id')}
                                    >
                                        <VehicleSearchSelect
                                            label="Site"
                                            value={draft.siteId}
                                            options={sites}
                                            onChange={(value) =>
                                                change({
                                                    siteId: value,
                                                    modes:
                                                        value === draft.siteId
                                                            ? draft.modes
                                                            : [],
                                                    actions:
                                                        value === draft.siteId
                                                            ? draft.actions
                                                            : withoutDevices(
                                                                  draft.actions,
                                                              ),
                                                })
                                            }
                                            invalid={!!fieldError('site_id')}
                                        />
                                    </PopupField>
                                )}
                                <SwitchRow
                                    id="automation-window"
                                    label="Only between certain hours"
                                    hint="NZ time. The hours can run past midnight"
                                    checked={draft.windowOn}
                                    onChange={(windowOn) =>
                                        change({ windowOn })
                                    }
                                />
                                {draft.windowOn ? (
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <PopupField
                                            label="From"
                                            htmlFor="automation-from"
                                            error={fieldError(
                                                'conditions.between',
                                            )}
                                        >
                                            <TimePicker
                                                id="automation-from"
                                                label="From"
                                                value={draft.from}
                                                onChange={(from) =>
                                                    change({ from })
                                                }
                                            />
                                        </PopupField>
                                        <PopupField
                                            label="To"
                                            htmlFor="automation-to"
                                        >
                                            <TimePicker
                                                id="automation-to"
                                                label="To"
                                                value={draft.to}
                                                onChange={(to) =>
                                                    change({ to })
                                                }
                                            />
                                        </PopupField>
                                    </div>
                                ) : null}
                                {siteModes.length > 0 ? (
                                    <PopupField
                                        label="House mode is"
                                        hint="Leave them all off to run in any mode"
                                        error={fieldError('conditions.modes')}
                                    >
                                        <TickChips
                                            label="House mode is"
                                            options={siteModes.map((mode) => ({
                                                value: String(mode.id),
                                                label: mode.name,
                                            }))}
                                            value={draft.modes}
                                            onChange={(modes) =>
                                                change({ modes })
                                            }
                                        />
                                    </PopupField>
                                ) : null}
                            </PopupSection>
                        </>
                    ) : step === 2 ? (
                        <>
                            <StepQuestion
                                icon={Play}
                                title="What should happen?"
                                line="Add one or more actions. They run in order."
                            />
                            <PopupSection icon={Play} title="Actions">
                                {draft.actions.length === 0 ? (
                                    <p className="text-subtle">
                                        No actions yet.
                                    </p>
                                ) : (
                                    <ol
                                        className="grid gap-2"
                                        aria-label="Actions"
                                    >
                                        {draft.actions.map((action, index) => (
                                            <ActionRow
                                                key={action.action}
                                                index={index}
                                                count={draft.actions.length}
                                                action={action}
                                                label={label(action.action)}
                                                description={actionDescription(
                                                    action.action,
                                                    choices.find(
                                                        (choice) =>
                                                            choice.key ===
                                                            action.action,
                                                    )?.description ?? '',
                                                )}
                                                siteChosen={!!draft.siteId}
                                                devices={(which) =>
                                                    devicesAt(
                                                        draft.siteId,
                                                        which,
                                                    )
                                                }
                                                roles={form.roles}
                                                error={(field) =>
                                                    fieldError(
                                                        `actions.${index}.${field}`,
                                                    )
                                                }
                                                onChange={(next) =>
                                                    setAction(index, next)
                                                }
                                                onMove={(by) =>
                                                    moveAction(index, by)
                                                }
                                                onRemove={() =>
                                                    change({
                                                        actions:
                                                            draft.actions.filter(
                                                                (_, at) =>
                                                                    at !==
                                                                    index,
                                                            ),
                                                    })
                                                }
                                            />
                                        ))}
                                    </ol>
                                )}
                                {draft.actions.length < maxActions ? (
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                className="justify-self-start"
                                            >
                                                <Plus aria-hidden="true" />
                                                Add an action
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent
                                            align="start"
                                            className="w-80"
                                        >
                                            {choices.map((choice) => {
                                                const noDevice =
                                                    choice.key ===
                                                        'open_alert' &&
                                                    draft.kind === 'time';
                                                return (
                                                    <DropdownMenuItem
                                                        key={choice.key}
                                                        disabled={
                                                            used.has(
                                                                choice.key,
                                                            ) || noDevice
                                                        }
                                                        onSelect={() =>
                                                            addAction(
                                                                choice.key,
                                                            )
                                                        }
                                                        className="flex-col items-start gap-0.5"
                                                    >
                                                        <span className="font-medium">
                                                            {choice.label}
                                                        </span>
                                                        <span className="text-caption">
                                                            {used.has(
                                                                choice.key,
                                                            )
                                                                ? 'Already added'
                                                                : noDevice
                                                                  ? 'Needs a door, sensor or device to start it'
                                                                  : actionDescription(
                                                                        choice.key,
                                                                        choice.description,
                                                                    )}
                                                        </span>
                                                    </DropdownMenuItem>
                                                );
                                            })}
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                ) : null}
                                <PopupCallout tone="info">
                                    Automations can tell people, open an alert,
                                    turn camera privacy on, and check that doors
                                    are locked and the alarm is armed. They
                                    never lock, unlock, arm or disarm anything:
                                    a person does that.
                                </PopupCallout>
                            </PopupSection>
                        </>
                    ) : (
                        <>
                            <StepQuestion
                                icon={CircleCheck}
                                title="Name it and turn it on"
                                line="Check what it will do. Test it once it’s saved: only you get a message, marked “TEST”."
                            />
                            <div className="grid gap-4">
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <ReviewCard
                                        icon={Zap}
                                        title="When"
                                        onEdit={() => setStep(0)}
                                    >
                                        <ReviewRow
                                            label="Starts"
                                            value={whenWords()}
                                        />
                                    </ReviewCard>
                                    <ReviewCard
                                        icon={Filter}
                                        title="Only if"
                                        onEdit={() => setStep(1)}
                                    >
                                        <ReviewRow
                                            label="Site"
                                            value={
                                                siteName(draft.siteId) || '—'
                                            }
                                        />
                                        <ReviewRow
                                            label="Hours"
                                            value={
                                                draft.windowOn
                                                    ? `${clockWords(draft.from)} – ${clockWords(draft.to)}`
                                                    : 'Any time'
                                            }
                                        />
                                        {siteModes.length > 0 ? (
                                            <ReviewRow
                                                label="House mode"
                                                value={
                                                    chosenModes.length
                                                        ? listWords(
                                                              chosenModes,
                                                              'or',
                                                          )
                                                        : 'Any mode'
                                                }
                                            />
                                        ) : null}
                                    </ReviewCard>
                                    <ReviewCard
                                        icon={Play}
                                        title="Then"
                                        span
                                        onEdit={() => setStep(2)}
                                    >
                                        {draft.actions.length === 0 ? (
                                            <ReviewRow
                                                label="Actions"
                                                value="—"
                                            />
                                        ) : (
                                            draft.actions.map(
                                                (action, index) => (
                                                    <ReviewRow
                                                        key={action.action}
                                                        label={`${index + 1}`}
                                                        value={actionWords(
                                                            action,
                                                        )}
                                                    />
                                                ),
                                            )
                                        )}
                                    </ReviewCard>
                                </div>
                                <PopupSection
                                    icon={CircleCheck}
                                    title="Finish"
                                    columns={2}
                                >
                                    <PopupField
                                        label="Name"
                                        htmlFor="automation-name"
                                        required
                                        hint="Shown in the rules list and the change history"
                                        error={fieldError('name')}
                                    >
                                        <Input
                                            id="automation-name"
                                            maxLength={80}
                                            value={shownName}
                                            onChange={(event) =>
                                                change({
                                                    name: event.target.value,
                                                    named: true,
                                                })
                                            }
                                            aria-invalid={!!fieldError('name')}
                                        />
                                    </PopupField>
                                    <div className="self-end">
                                        <SwitchRow
                                            id="automation-on"
                                            label={
                                                editing
                                                    ? 'Turned on'
                                                    : 'Turn on now'
                                            }
                                            checked={draft.isOn}
                                            onChange={(isOn) =>
                                                change({ isOn })
                                            }
                                        />
                                    </div>
                                    <PopupField label="Test" wide>
                                        <p className="text-sm">
                                            After you save, use{' '}
                                            <b className="font-semibold">
                                                Test it
                                            </b>
                                            : only you get a message, marked
                                            “TEST”. Nobody else is told, no
                                            alert opens and no camera changes.
                                        </p>
                                    </PopupField>
                                </PopupSection>
                            </div>
                        </>
                    )}
                </WizardStepPane>
            </WizardShell>

            <DiscardDraftConfirm
                open={open && guard.asking}
                what={title}
                onKeepEditing={guard.keepEditing}
                onDiscard={guard.discard}
            />
        </>
    );
}

/** One action in the list: what it does, its settings, and its ⋯ menu (move, remove). */
function ActionRow({
    index,
    count,
    action,
    label,
    description,
    siteChosen,
    devices,
    roles,
    error,
    onChange,
    onMove,
    onRemove,
}: {
    index: number;
    count: number;
    action: AutomationAction;
    label: string;
    description: string;
    siteChosen: boolean;
    devices: (which: AutomationDeviceGroup) => { id: number; name: string }[];
    roles: { value: string; label: string }[];
    error: (field: string) => string | undefined;
    onChange: (next: Partial<AutomationAction>) => void;
    onMove: (by: -1 | 1) => void;
    onRemove: () => void;
}) {
    const id = `automation-action-${action.action}`;
    const pick = (
        which: AutomationDeviceGroup,
        key: 'cameras' | 'doors' | 'panels',
        noun: string,
        none: string,
    ): ReactNode => {
        const list = devices(which);
        return (
            <PopupField label={noun} required error={error(key)}>
                {!siteChosen ? (
                    <p className="text-subtle">
                        Choose the Site on the Only if step first.
                    </p>
                ) : list.length === 0 ? (
                    <p className="text-subtle">{none}</p>
                ) : (
                    <TickChips
                        label={noun}
                        options={list.map((device) => ({
                            value: String(device.id),
                            label: device.name,
                        }))}
                        value={(action[key] ?? []).map(String)}
                        onChange={(next) =>
                            onChange({ [key]: next.map(Number) })
                        }
                    />
                )}
            </PopupField>
        );
    };

    return (
        <li className="grid gap-3 rounded-xl border border-border bg-card p-3.5">
            <div className="flex items-start gap-3">
                <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-semibold text-muted-foreground">
                    {index + 1}
                </span>
                <div className="min-w-0 flex-1">
                    <p className="text-sm font-semibold">{label}</p>
                    <p className="text-caption">{description}</p>
                </div>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="frontline-hit size-8"
                            aria-label={`More for ${label}`}
                        >
                            <MoreHorizontal aria-hidden="true" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem
                            disabled={index === 0}
                            onSelect={() => onMove(-1)}
                        >
                            <ArrowUp aria-hidden="true" />
                            Move up
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            disabled={index === count - 1}
                            onSelect={() => onMove(1)}
                        >
                            <ArrowDown aria-hidden="true" />
                            Move down
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            variant="destructive"
                            onSelect={onRemove}
                        >
                            <Trash2 aria-hidden="true" />
                            Remove
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
            {error('action') ? (
                <p className="text-sm text-status-critical">
                    {error('action')}
                </p>
            ) : null}
            {action.action === 'notify' ? (
                <div className="grid gap-4 sm:grid-cols-2">
                    <PopupField
                        label="Who"
                        hint="“Staff on shift” comes from the roster at the moment it happens"
                        error={error('who')}
                    >
                        <OneChoice<'on_shift' | 'role'>
                            label="Who"
                            value={action.who ?? 'on_shift'}
                            options={[
                                { value: 'on_shift', label: 'Staff on shift' },
                                { value: 'role', label: 'People in a role' },
                            ]}
                            onChange={(who) =>
                                onChange({
                                    who,
                                    role:
                                        who === 'role'
                                            ? (action.role ?? null)
                                            : null,
                                })
                            }
                        />
                    </PopupField>
                    {action.who === 'role' ? (
                        <PopupField label="Role" required error={error('role')}>
                            <VehicleSearchSelect
                                label="Role"
                                value={action.role ?? ''}
                                options={roles}
                                onChange={(role) => onChange({ role })}
                                invalid={!!error('role')}
                            />
                        </PopupField>
                    ) : null}
                    <PopupField label="How" hint="Always in the app">
                        <TickChips
                            label="How"
                            options={[
                                { value: 'push', label: 'Push' },
                                { value: 'email', label: 'Email' },
                            ]}
                            value={[
                                ...(action.push ? ['push'] : []),
                                ...(action.email ? ['email'] : []),
                            ]}
                            onChange={(next) =>
                                onChange({
                                    push: next.includes('push'),
                                    email: next.includes('email'),
                                })
                            }
                        />
                    </PopupField>
                    <PopupField
                        label="Message"
                        htmlFor={`${id}-message`}
                        hint="Optional, added after what happened"
                        error={error('message')}
                    >
                        <Textarea
                            id={`${id}-message`}
                            rows={2}
                            maxLength={200}
                            value={action.message ?? ''}
                            onChange={(event) =>
                                onChange({ message: event.target.value })
                            }
                            placeholder="e.g. Check on the house now"
                        />
                    </PopupField>
                </div>
            ) : null}
            {action.action === 'camera_privacy_on' ? (
                <div className="grid gap-4 sm:grid-cols-2">
                    {pick(
                        'cameras',
                        'cameras',
                        'Cameras',
                        'No cameras at this Site that you can see.',
                    )}
                    <PopupField
                        label="Until"
                        htmlFor={`${id}-until`}
                        required
                        hint="NZ time. It never ends early"
                        error={error('until')}
                    >
                        <TimePicker
                            id={`${id}-until`}
                            label="Until"
                            value={action.until ?? '06:00'}
                            onChange={(until) => onChange({ until })}
                        />
                    </PopupField>
                </div>
            ) : null}
            {action.action === 'door_locked_check'
                ? pick(
                      'doors',
                      'doors',
                      'Doors',
                      'No doors at this Site that you can see.',
                  )
                : null}
            {action.action === 'alarm_armed_check'
                ? pick(
                      'panels',
                      'panels',
                      'Alarm',
                      'No alarm at this Site that you can see.',
                  )
                : null}
        </li>
    );
}

/* ------------------------------------------------------------------ */
/*  41 · Test it                                                       */
/* ------------------------------------------------------------------ */

export function AutomationTest({
    target,
    onClose,
    onRan,
}: {
    target: AutomationTarget | null;
    onClose: () => void;
    /** A test ran: the page refreshes the list's "Last ran". */
    onRan: () => void;
}) {
    const [run, setRun] = useState<AutomationRunResult | null>(null);
    const [running, setRunning] = useState(false);
    const [result, setResult] = useState<Failure>({});
    const started = useRef<number | null>(null);

    const start = (id: number) => {
        setRunning(true);
        setRun(null);
        setResult({});
        axios
            .post<{ run: AutomationRunResult }>(
                `${BASE}/${id}/test`,
                {},
                JSON_HEADERS,
            )
            .then(({ data }) => {
                setRun(data.run);
                onRan();
            })
            .catch((error) =>
                setResult(failure(error, 'The test couldn’t run. Try again.')),
            )
            .finally(() => setRunning(false));
    };

    useEffect(() => {
        if (!target) {
            started.current = null;
            return;
        }
        // Once per opening (a re-render never sends a second test).
        if (started.current === target.id) return;
        started.current = target.id;
        start(target.id);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [target?.id]);

    if (!target) return null;
    const now = new Date();

    return (
        <PopupViewer
            open
            onClose={onClose}
            size="md"
            icon={FlaskConical}
            title={`Test: ${target.name}`}
            description="Only you got a message, marked “TEST”. Nothing else happened."
            closeLabel="Done"
            footerStart={
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => start(target.id)}
                    disabled={running}
                >
                    Run again
                </Button>
            }
            testId="sd-automation-test"
        >
            {running ? (
                <LoadState
                    failed={false}
                    loading="Running the test…"
                    failedText=""
                    onRetry={() => undefined}
                />
            ) : result.unconfirmed ? (
                <PopupCallout tone="warning">
                    Result not confirmed: the connection may have dropped. Run
                    it again to see what it does; a test only ever tells you.
                </PopupCallout>
            ) : result.error ? (
                <PopupCallout tone="critical">{result.error}</PopupCallout>
            ) : run ? (
                <>
                    <p className="text-sm text-muted-foreground">
                        {run.summary}.
                    </p>
                    <ol className="grid divide-y divide-border">
                        {run.lines.map((line, index) => (
                            <li
                                key={index}
                                className="grid grid-cols-[5.5rem_1rem_minmax(0,1fr)] items-start gap-3 py-2.5 text-sm"
                            >
                                <time
                                    className="text-muted-foreground"
                                    dateTime={line.at}
                                >
                                    {formatTime(line.at)}
                                </time>
                                <span className="pt-0.5">
                                    <Ring
                                        tone={line.tone}
                                        label={
                                            line.tone === 'critical'
                                                ? 'Didn’t work'
                                                : line.tone === 'warning'
                                                  ? 'Needs a person'
                                                  : 'Done'
                                        }
                                    />
                                </span>
                                <span className="min-w-0">{line.text}</span>
                            </li>
                        ))}
                        <li className="grid grid-cols-[5.5rem_1rem_minmax(0,1fr)] items-start gap-3 py-2.5 text-sm">
                            <span className="text-muted-foreground">
                                Result
                            </span>
                            <span />
                            <b className="font-semibold">{run.result}</b>
                        </li>
                    </ol>
                    <p className="text-caption">
                        Tested {formatTime(run.at || now.toISOString())}. The
                        test is in the automation’s run log and the change
                        history.
                    </p>
                </>
            ) : null}
        </PopupViewer>
    );
}

/* ------------------------------------------------------------------ */
/*  Turn an automation off or on, and remove one                       */
/* ------------------------------------------------------------------ */

export function AutomationOnOff({
    row,
    onClose,
    onChanged,
}: {
    row: AutomationRow | null;
    onClose: () => void;
    onChanged: () => void;
}) {
    const [reason, setReason] = useState('');
    const [processing, setProcessing] = useState(false);
    const [result, setResult] = useState<Failure>({});
    useEffect(() => {
        setReason('');
        setResult({});
    }, [row?.id]);
    if (!row) return null;
    const turningOff = row.on;

    const confirm = () => {
        if (result.unconfirmed) {
            onChanged();
            onClose();
            return;
        }
        if (turningOff && reason.trim().length < 10)
            return setResult({
                fields: { reason: 'Say why in at least 10 characters.' },
            });
        setProcessing(true);
        setResult({});
        axios
            .post(
                `${BASE}/${row.id}/${turningOff ? 'off' : 'on'}`,
                {
                    version: row.version,
                    ...(turningOff ? { reason: reason.trim() } : {}),
                },
                JSON_HEADERS,
            )
            .then(() => {
                toast.success(
                    turningOff
                        ? 'Automation turned off.'
                        : 'Automation turned on.',
                );
                onChanged();
                onClose();
            })
            .catch((error) =>
                setResult(
                    failure(
                        error,
                        'The automation couldn’t be changed. Try again.',
                        'This automation changed after you opened it. Close this and look at it again.',
                    ),
                ),
            )
            .finally(() => setProcessing(false));
    };

    return (
        <PopupConfirm
            open
            onClose={onClose}
            onConfirm={confirm}
            variant={turningOff ? 'warning' : 'plain'}
            confirmDestructive={turningOff && !result.unconfirmed}
            icon={turningOff ? PowerOff : Power}
            title={
                turningOff
                    ? 'Turn off this automation?'
                    : 'Turn this automation back on?'
            }
            description={
                turningOff
                    ? 'It stops running until someone turns it back on, so the people it tells aren’t told by it. It stays in the history.'
                    : 'It runs again from now on, the next time this happens.'
            }
            context={{
                icon: Workflow,
                title: row.when,
                tag: row.where,
                note: row.name,
            }}
            confirmLabel={
                result.unconfirmed
                    ? 'Check result'
                    : turningOff
                      ? 'Turn off automation'
                      : 'Turn on automation'
            }
            processing={processing}
            error={result.error ?? result.fields?.version}
            testId="sd-automation-on-off"
        >
            {result.unconfirmed ? (
                <PopupCallout tone="warning">
                    Result not confirmed: the connection may have dropped. Check
                    the list before trying again, so it isn’t done twice.
                </PopupCallout>
            ) : null}
            {turningOff ? (
                <PopupField
                    label="Why turn it off?"
                    htmlFor="automation-off-reason"
                    required
                    hint="Kept in the history"
                    error={result.fields?.reason}
                >
                    <Textarea
                        id="automation-off-reason"
                        rows={3}
                        maxLength={500}
                        value={reason}
                        onChange={(event) => setReason(event.target.value)}
                        placeholder="e.g. The hot water tap is being replaced this week"
                    />
                </PopupField>
            ) : null}
        </PopupConfirm>
    );
}

export function RemoveAutomation({
    row,
    onClose,
    onRemoved,
}: {
    row: AutomationRow | null;
    onClose: () => void;
    onRemoved: () => void;
}) {
    const [reason, setReason] = useState('');
    const [processing, setProcessing] = useState(false);
    const [result, setResult] = useState<Failure>({});
    useEffect(() => {
        setReason('');
        setResult({});
    }, [row?.id]);
    if (!row) return null;

    const confirm = () => {
        if (result.unconfirmed) {
            onRemoved();
            onClose();
            return;
        }
        setProcessing(true);
        setResult({});
        axios
            .delete(`${BASE}/${row.id}`, {
                ...JSON_HEADERS,
                data: { version: row.version, reason: reason.trim() || null },
            })
            .then(() => {
                toast.success('Automation removed. It stays in the history.');
                onRemoved();
                onClose();
            })
            .catch((error) =>
                setResult(
                    failure(
                        error,
                        'The automation couldn’t be removed. Try again.',
                        'This automation changed after you opened it. Close this and look at it again.',
                    ),
                ),
            )
            .finally(() => setProcessing(false));
    };

    return (
        <PopupConfirm
            open
            onClose={onClose}
            onConfirm={confirm}
            variant="destructive"
            confirmDestructive={!result.unconfirmed}
            icon={Trash2}
            title="Remove this automation?"
            description="It stops running for good. It and its runs stay in the history."
            context={{
                icon: Workflow,
                title: row.when,
                tag: row.where,
                note: row.name,
            }}
            confirmLabel={
                result.unconfirmed ? 'Check result' : 'Remove automation'
            }
            processing={processing}
            error={result.error ?? result.fields?.version}
            testId="sd-automation-remove"
        >
            {result.unconfirmed ? (
                <PopupCallout tone="warning">
                    Result not confirmed: the connection may have dropped. Check
                    the list before trying again.
                </PopupCallout>
            ) : null}
            <PopupField
                label="Why remove it?"
                htmlFor="automation-remove-reason"
                hint="Optional. Kept in the history"
            >
                <Textarea
                    id="automation-remove-reason"
                    rows={2}
                    maxLength={500}
                    value={reason}
                    onChange={(event) => setReason(event.target.value)}
                />
            </PopupField>
        </PopupConfirm>
    );
}
