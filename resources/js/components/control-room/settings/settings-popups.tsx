import { plainLabel } from '@/components/control-room/alert-record/alert-labels';
import {
    ALERT_SOURCES,
    QUEUE_ROLES,
    SEVERITIES,
    type ConfigOption,
    type MaintenanceWindowData,
    type OptionGroup,
    type PlaybookOption,
    type SignalOutboxRow,
    type SignalRuleData,
    type SignalSourceOption,
    type SignalTypeOption,
    type SiteOption,
    type TriageQueueData,
} from '@/components/control-room/settings/types';
import { DateTimeField } from '@/components/fleet-assets/maintenance/date-time-field';
import {
    PopupConfirm,
    PopupFacts,
    PopupField,
    PopupForm,
    PopupSection,
} from '@/components/popup';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { SelectInput } from '@/components/wizard/primitives';
import {
    formatDateTime,
    toDatetimeLocal,
    workerTimeOffsets,
} from '@/lib/datetime';
import type { Page } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import {
    CalendarClock,
    Inbox,
    ListPlus,
    RadioTower,
    RefreshCw,
    SlidersHorizontal,
    ToggleRight,
    Trash2,
    Wrench,
} from 'lucide-react';
import { useState } from 'react';

type Errors = Record<string, string | undefined>;

/**
 * Save through Inertia and close only when the server took it. A flash
 * error (which Inertia still reports as success) keeps the pop-up open
 * with the message; field errors show under their fields.
 */
function useSave(onDone: () => void) {
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Errors>({});
    const [error, setError] = useState<string | undefined>();
    const send = (
        method: 'post' | 'put' | 'delete',
        url: string,
        data: Record<string, unknown> = {},
    ) => {
        setProcessing(true);
        setError(undefined);
        const options = {
            preserveScroll: true,
            onSuccess: (page: Page) => {
                const flash = (page.props as { flash?: { error?: string } }).flash;
                if (flash?.error) setError(flash.error);
                else onDone();
            },
            onError: (next: Errors) => {
                setErrors(next);
                if (Object.keys(next).length === 0)
                    setError('This could not be saved. Your entries are kept — try again.');
            },
            onFinish: () => setProcessing(false),
        };
        if (method === 'delete') router.delete(url, options);
        else router[method](url, data as never, options);
    };
    return { send, processing, errors, error };
}

/** A switch with its On / Off word (DESIGN.md settings pattern). */
export function SwitchRow({
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
        <div className="flex items-start justify-between gap-4 rounded-[10px] border border-border px-3.5 py-2.5 sm:col-span-full">
            <div className="min-w-0">
                <label htmlFor={id} className="text-sm font-semibold text-foreground">
                    {label}
                </label>
                {hint ? <p className="text-caption mt-0.5">{hint}</p> : null}
            </div>
            <span className="flex shrink-0 items-center gap-2">
                <span className="text-sm text-muted-foreground" aria-hidden="true">
                    {checked ? 'On' : 'Off'}
                </span>
                <Switch id={id} checked={checked} onCheckedChange={onChange} />
            </span>
        </div>
    );
}

/** Tick chips for "any of these" choices; none ticked means all. */
function Chips<T extends string>({
    label,
    options,
    value,
    onChange,
    format = plainLabel,
}: {
    label: string;
    options: readonly T[];
    value: string[];
    onChange: (next: string[]) => void;
    format?: (value: T) => string;
}) {
    return (
        <ToggleGroup
            type="multiple"
            variant="outline"
            aria-label={label}
            value={value}
            onValueChange={onChange}
            className="flex flex-wrap justify-start gap-1.5 rounded-none shadow-none"
        >
            {options.map((option) => (
                <ToggleGroupItem
                    key={option}
                    value={option}
                    className="rounded-full! border! px-3 data-[state=on]:border-primary data-[state=on]:bg-primary-fill/10 data-[state=on]:text-primary"
                >
                    {format(option)}
                </ToggleGroupItem>
            ))}
        </ToggleGroup>
    );
}

const idOrNull = (value: string) => (value ? Number(value) : null);

/* ------------------------------------------------------------------ */
/*  Routing rule                                                       */
/* ------------------------------------------------------------------ */

export function SignalRulePopup({
    rule,
    signalTypes,
    signalSources,
    playbooks,
    onClose,
}: {
    rule: SignalRuleData | null;
    signalTypes: SignalTypeOption[];
    signalSources: SignalSourceOption[];
    playbooks: PlaybookOption[];
    onClose: () => void;
}) {
    const initial = {
        name: rule?.name ?? '',
        signal_type_id: rule?.signal_type_id?.toString() ?? '',
        signal_source_id: rule?.signal_source_id?.toString() ?? '',
        priority: rule?.priority?.toString() ?? '100',
        conditions: rule?.conditions ? JSON.stringify(rule.conditions, null, 2) : '',
        output_severity: rule?.output_severity ?? '',
        output_escalation_level: rule?.output_escalation_level?.toString() ?? '0',
        output_tier: rule?.output_tier?.toString() ?? '1',
        dedup_window_minutes: rule?.dedup_window_minutes?.toString() ?? '',
        deduplicate: rule?.deduplicate ?? false,
        suppress_in_maintenance: rule?.suppress_in_maintenance ?? true,
        playbook_id: rule?.playbook_id?.toString() ?? '',
        is_active: rule?.is_active ?? true,
    };
    const [form, setForm] = useState(initial);
    const [conditionsError, setConditionsError] = useState<string>();
    const save = useSave(onClose);
    const set = <K extends keyof typeof form>(key: K, value: (typeof form)[K]) =>
        setForm((current) => ({ ...current, [key]: value }));
    const dirty = JSON.stringify(form) !== JSON.stringify(initial);

    const submit = () => {
        let conditions: unknown = null;
        if (form.conditions.trim()) {
            try {
                conditions = JSON.parse(form.conditions);
            } catch {
                setConditionsError('Enter valid JSON, for example {"site_id": 1}.');
                return;
            }
        }
        setConditionsError(undefined);
        const typeId = idOrNull(form.signal_type_id);
        save.send(rule ? 'put' : 'post', rule ? `/control-room/settings/rules/${rule.id}` : '/control-room/settings/rules', {
            name: form.name,
            signal_type_id: typeId,
            signal_type_code: typeId
                ? (signalTypes.find((t) => t.id === typeId)?.code ?? null)
                : null,
            signal_source_id: idOrNull(form.signal_source_id),
            priority: Number(form.priority),
            conditions,
            output_severity: form.output_severity || null,
            output_escalation_level: form.output_escalation_level
                ? Number(form.output_escalation_level)
                : null,
            output_tier: form.output_tier ? Number(form.output_tier) : null,
            dedup_window_minutes: form.dedup_window_minutes
                ? Number(form.dedup_window_minutes)
                : null,
            deduplicate: form.deduplicate,
            suppress_in_maintenance: form.suppress_in_maintenance,
            playbook_id: idOrNull(form.playbook_id),
            is_active: form.is_active,
        });
    };

    return (
        <PopupForm
            open
            onClose={onClose}
            size="lg"
            icon={RadioTower}
            title={rule ? 'Edit routing rule' : 'Add routing rule'}
            description="Decides how a matching signal becomes an alert."
            error={save.error}
            submitLabel="Save rule"
            processing={save.processing}
            submitDisabled={!form.name.trim()}
            onSubmit={submit}
            dirty={dirty}
            draftName={rule ? `changes to ${rule.name}` : 'a new routing rule'}
        >
            <PopupSection icon={RadioTower} title="Which signals" columns={2}>
                <PopupField label="Rule name" htmlFor="rule-name" required wide error={save.errors.name}>
                    <Input
                        id="rule-name"
                        value={form.name}
                        onChange={(e) => set('name', e.target.value)}
                        placeholder="e.g. Fall detected — critical"
                    />
                </PopupField>
                <PopupField label="Signal type" htmlFor="rule-signal-type" error={save.errors.signal_type_id}>
                    <SelectInput
                        value={form.signal_type_id || 'any'}
                        onChange={(v) => set('signal_type_id', v === 'any' ? '' : v)}
                        placeholder="Any type"
                        ariaLabel="Signal type"
                        options={[
                            { value: 'any', label: 'Any type' },
                            ...signalTypes.map((t) => ({ value: String(t.id), label: t.name })),
                        ]}
                    />
                </PopupField>
                <PopupField label="Signal source" htmlFor="rule-signal-source" error={save.errors.signal_source_id}>
                    <SelectInput
                        value={form.signal_source_id || 'any'}
                        onChange={(v) => set('signal_source_id', v === 'any' ? '' : v)}
                        placeholder="Any source"
                        ariaLabel="Signal source"
                        options={[
                            { value: 'any', label: 'Any source' },
                            ...signalSources.map((s) => ({ value: String(s.id), label: s.name })),
                        ]}
                    />
                </PopupField>
                <PopupField
                    label="Rule priority"
                    htmlFor="rule-priority"
                    required
                    hint="A whole number from 0 to 1000."
                    error={save.errors.priority}
                >
                    <Input
                        id="rule-priority"
                        type="number"
                        min={0}
                        max={1000}
                        value={form.priority}
                        onChange={(e) => set('priority', e.target.value)}
                    />
                </PopupField>
                <PopupField
                    label="Only when the signal has (JSON)"
                    htmlFor="rule-conditions"
                    hint='Optional, for example {"site_id": 1}.'
                    error={conditionsError ?? save.errors.conditions}
                    wide
                >
                    <Textarea
                        id="rule-conditions"
                        rows={3}
                        className="font-mono text-sm"
                        value={form.conditions}
                        aria-invalid={conditionsError ? true : undefined}
                        onChange={(e) => {
                            set('conditions', e.target.value);
                            setConditionsError(undefined);
                        }}
                        placeholder='{"site_id": 1, "severity_hint": "critical"}'
                    />
                </PopupField>
            </PopupSection>

            <PopupSection icon={SlidersHorizontal} title="What the alert gets" columns={2}>
                <PopupField label="Severity" htmlFor="rule-output-severity" error={save.errors.output_severity}>
                    <SelectInput
                        value={form.output_severity || 'inherit'}
                        onChange={(v) => set('output_severity', v === 'inherit' ? '' : v)}
                        placeholder="Keep the signal's severity"
                        ariaLabel="Severity"
                        options={[
                            { value: 'inherit', label: "Keep the signal's severity" },
                            ...SEVERITIES.map((s) => ({ value: s, label: plainLabel(s) })),
                        ]}
                    />
                </PopupField>
                <PopupField
                    label="Escalation level"
                    htmlFor="rule-escalation"
                    hint="A whole number from 0 to 10."
                    error={save.errors.output_escalation_level}
                >
                    <Input
                        id="rule-escalation"
                        type="number"
                        min={0}
                        max={10}
                        value={form.output_escalation_level}
                        onChange={(e) => set('output_escalation_level', e.target.value)}
                    />
                </PopupField>
                <PopupField
                    label="Repeat window (minutes)"
                    htmlFor="rule-dedup"
                    hint="Repeats within this many minutes join the open alert. Empty uses 30."
                    error={save.errors.dedup_window_minutes}
                >
                    <Input
                        id="rule-dedup"
                        type="number"
                        min={0}
                        value={form.dedup_window_minutes}
                        onChange={(e) => set('dedup_window_minutes', e.target.value)}
                        placeholder="30"
                    />
                </PopupField>
                <PopupField label="Response procedure" htmlFor="rule-playbook" error={save.errors.playbook_id}>
                    <SelectInput
                        value={form.playbook_id || 'none'}
                        onChange={(v) => set('playbook_id', v === 'none' ? '' : v)}
                        placeholder="None"
                        ariaLabel="Response procedure"
                        options={[
                            { value: 'none', label: 'None' },
                            ...playbooks.map((p) => ({ value: String(p.id), label: p.name })),
                        ]}
                    />
                </PopupField>
            </PopupSection>

            <PopupSection icon={ToggleRight} title="Behaviour">
                <SwitchRow
                    id="rule-suppress"
                    label="Hold during maintenance"
                    hint="No alerts from this rule while a maintenance window is on."
                    checked={form.suppress_in_maintenance}
                    onChange={(on) => set('suppress_in_maintenance', on)}
                />
                <SwitchRow
                    id="rule-dedup-toggle"
                    label="Join repeats to the open alert"
                    hint="Uses the repeat window above."
                    checked={form.deduplicate}
                    onChange={(on) => set('deduplicate', on)}
                />
                <SwitchRow
                    id="rule-active"
                    label="Rule is on"
                    hint="When off, matching signals are not routed by this rule."
                    checked={form.is_active}
                    onChange={(on) => set('is_active', on)}
                />
            </PopupSection>
        </PopupForm>
    );
}

export function DeleteRuleConfirm({ rule, onClose }: { rule: SignalRuleData; onClose: () => void }) {
    const save = useSave(onClose);
    return (
        <PopupConfirm
            open
            onClose={onClose}
            onConfirm={() => save.send('delete', `/control-room/settings/rules/${rule.id}`)}
            variant="destructive"
            title="Delete routing rule?"
            description={
                <>
                    <b className="font-semibold text-foreground">{rule.name}</b> will be
                    deleted. Signals it matched will no longer be routed by it. This
                    can&apos;t be undone.
                </>
            }
            confirmLabel="Delete rule"
            processing={save.processing}
            error={save.error}
        />
    );
}

/** Turning a rule off can stop alerts, so it is confirmed (a safety check loosened). */
export function TurnOffRuleConfirm({ rule, onClose }: { rule: SignalRuleData; onClose: () => void }) {
    const save = useSave(onClose);
    return (
        <PopupConfirm
            open
            onClose={onClose}
            onConfirm={() =>
                save.send('put', `/control-room/settings/rules/${rule.id}`, {
                    ...rule,
                    conditions: rule.conditions ?? null,
                    is_active: false,
                })
            }
            variant="warning"
            title="Turn off this rule?"
            description={
                <>
                    While <b className="font-semibold text-foreground">{rule.name}</b> is
                    off, signals it matches are not routed by it. You can turn it back on
                    at any time.
                </>
            }
            confirmLabel="Turn off rule"
            confirmDestructive
            processing={save.processing}
            error={save.error}
        />
    );
}

/* ------------------------------------------------------------------ */
/*  Queue                                                              */
/* ------------------------------------------------------------------ */

export function TriageQueuePopup({
    queue,
    allQueues,
    onClose,
}: {
    queue: TriageQueueData | null;
    allQueues: TriageQueueData[];
    onClose: () => void;
}) {
    const initial = {
        name: queue?.name ?? '',
        code: queue?.code ?? '',
        tier: queue?.tier?.toString() ?? '1',
        description: queue?.description ?? '',
        handle_severities: queue?.handle_severities ?? [],
        handle_sources: queue?.handle_sources ?? [],
        assigned_roles: queue?.assigned_roles ?? [],
        auto_escalate_after_minutes: queue?.auto_escalate_after_minutes?.toString() ?? '',
        escalate_to_queue_id: queue?.escalate_to_queue_id?.toString() ?? '',
        is_active: queue?.is_active ?? true,
    };
    const [form, setForm] = useState(initial);
    const save = useSave(onClose);
    const set = <K extends keyof typeof form>(key: K, value: (typeof form)[K]) =>
        setForm((current) => ({ ...current, [key]: value }));
    const others = allQueues.filter((q) => q.id !== queue?.id);

    return (
        <PopupForm
            open
            onClose={onClose}
            size="md"
            icon={Inbox}
            title={queue ? 'Edit queue' : 'Add queue'}
            description="A queue collects alerts for a team and moves them on if no one picks them up."
            error={save.error}
            submitLabel="Save queue"
            processing={save.processing}
            submitDisabled={!form.name.trim() || !form.code.trim()}
            onSubmit={() =>
                save.send(queue ? 'put' : 'post', queue ? `/control-room/settings/queues/${queue.id}` : '/control-room/settings/queues', {
                    name: form.name,
                    code: form.code,
                    tier: Number(form.tier),
                    description: form.description || null,
                    handle_severities: form.handle_severities,
                    handle_sources: form.handle_sources,
                    assigned_roles: form.assigned_roles,
                    auto_escalate_after_minutes: form.auto_escalate_after_minutes
                        ? Number(form.auto_escalate_after_minutes)
                        : null,
                    escalate_to_queue_id: idOrNull(form.escalate_to_queue_id),
                    is_active: form.is_active,
                })
            }
            dirty={JSON.stringify(form) !== JSON.stringify(initial)}
            draftName={queue ? `changes to ${queue.name}` : 'a new queue'}
        >
            <PopupSection columns={2}>
                <PopupField label="Queue name" htmlFor="queue-name" required error={save.errors.name}>
                    <Input id="queue-name" value={form.name} onChange={(e) => set('name', e.target.value)} />
                </PopupField>
                <PopupField label="Queue code" htmlFor="queue-code" required error={save.errors.code}>
                    <Input
                        id="queue-code"
                        value={form.code}
                        onChange={(e) => set('code', e.target.value)}
                        placeholder="e.g. tier-1-general"
                    />
                </PopupField>
                <PopupField label="Level" htmlFor="queue-tier" required hint="A whole number from 1 to 5." error={save.errors.tier}>
                    <Input
                        id="queue-tier"
                        type="number"
                        min={1}
                        max={5}
                        value={form.tier}
                        onChange={(e) => set('tier', e.target.value)}
                    />
                </PopupField>
                <PopupField
                    label="Move on after (minutes)"
                    htmlFor="queue-escalate-mins"
                    hint="Leave empty to keep alerts here."
                    error={save.errors.auto_escalate_after_minutes}
                >
                    <Input
                        id="queue-escalate-mins"
                        type="number"
                        min={1}
                        value={form.auto_escalate_after_minutes}
                        onChange={(e) => set('auto_escalate_after_minutes', e.target.value)}
                        placeholder="Never"
                    />
                </PopupField>
                <PopupField label="Move on to" htmlFor="queue-escalate-to" error={save.errors.escalate_to_queue_id} wide>
                    <SelectInput
                        value={form.escalate_to_queue_id || 'none'}
                        onChange={(v) => set('escalate_to_queue_id', v === 'none' ? '' : v)}
                        placeholder="No next queue"
                        ariaLabel="Move on to"
                        options={[
                            { value: 'none', label: 'No next queue' },
                            ...others.map((q) => ({ value: String(q.id), label: `${q.name} (level ${q.tier})` })),
                        ]}
                    />
                </PopupField>
                <PopupField label="Description" htmlFor="queue-desc" wide error={save.errors.description}>
                    <Textarea
                        id="queue-desc"
                        rows={2}
                        value={form.description}
                        onChange={(e) => set('description', e.target.value)}
                    />
                </PopupField>
            </PopupSection>
            <PopupSection title="What it takes" hint="Nothing picked means all.">
                <PopupField label="Severities">
                    <Chips
                        label="Severities"
                        options={SEVERITIES}
                        value={form.handle_severities}
                        onChange={(next) => set('handle_severities', next)}
                    />
                </PopupField>
                <PopupField label="Sources">
                    <Chips
                        label="Sources"
                        options={ALERT_SOURCES}
                        value={form.handle_sources}
                        onChange={(next) => set('handle_sources', next)}
                    />
                </PopupField>
                <PopupField label="Given to">
                    <Chips
                        label="Given to"
                        options={QUEUE_ROLES}
                        value={form.assigned_roles}
                        onChange={(next) => set('assigned_roles', next)}
                    />
                </PopupField>
            </PopupSection>
            <PopupSection>
                <SwitchRow
                    id="queue-active"
                    label="Queue is on"
                    checked={form.is_active}
                    onChange={(on) => set('is_active', on)}
                />
            </PopupSection>
        </PopupForm>
    );
}

export function TurnOffQueueConfirm({ queue, onClose }: { queue: TriageQueueData; onClose: () => void }) {
    const save = useSave(onClose);
    return (
        <PopupConfirm
            open
            onClose={onClose}
            onConfirm={() =>
                save.send('put', `/control-room/settings/queues/${queue.id}`, { ...queue, is_active: false })
            }
            variant="warning"
            title="Turn off this queue?"
            description={
                <>
                    <b className="font-semibold text-foreground">{queue.name}</b> stops
                    taking new alerts.
                    {queue.open_alert_count > 0
                        ? ` The ${queue.open_alert_count} open ${queue.open_alert_count === 1 ? 'alert stays' : 'alerts stay'} where ${queue.open_alert_count === 1 ? 'it is' : 'they are'}.`
                        : ''}
                </>
            }
            confirmLabel="Turn off queue"
            confirmDestructive
            processing={save.processing}
            error={save.error}
        />
    );
}

/* ------------------------------------------------------------------ */
/*  Maintenance window                                                 */
/* ------------------------------------------------------------------ */

/** NZ wall time "2026-11-07T22:00" → an instant with its offset, or null. */
function nzInstant(local: string): string | null {
    const offset = workerTimeOffsets(local)[0];
    return offset ? `${local}:00${offset}` : null;
}

export function MaintenanceWindowPopup({
    window: mw,
    signalSources,
    sites,
    onClose,
}: {
    window: MaintenanceWindowData | null;
    signalSources: SignalSourceOption[];
    sites: SiteOption[];
    onClose: () => void;
}) {
    const initial = {
        name: mw?.name ?? '',
        description: mw?.description ?? '',
        signal_source_id: mw?.signal_source_id?.toString() ?? '',
        site_id: mw?.site_id?.toString() ?? '',
        starts_at: mw ? toDatetimeLocal(mw.starts_at) : '',
        ends_at: mw ? toDatetimeLocal(mw.ends_at) : '',
    };
    const [form, setForm] = useState(initial);
    const [timeError, setTimeError] = useState<Errors>({});
    const save = useSave(onClose);
    const set = <K extends keyof typeof form>(key: K, value: (typeof form)[K]) =>
        setForm((current) => ({ ...current, [key]: value }));

    const submit = () => {
        const starts = nzInstant(form.starts_at);
        const ends = nzInstant(form.ends_at);
        const next: Errors = {};
        if (!starts) next.starts_at = 'Choose a date and a time.';
        if (!ends) next.ends_at = 'Choose a date and a time.';
        if (starts && ends && Date.parse(ends) <= Date.parse(starts))
            next.ends_at = 'The end must be after the start.';
        setTimeError(next);
        if (Object.keys(next).length) return;
        save.send(mw ? 'put' : 'post', mw ? `/control-room/settings/maintenance/${mw.id}` : '/control-room/settings/maintenance', {
            name: form.name,
            description: form.description || null,
            signal_source_id: idOrNull(form.signal_source_id),
            site_id: idOrNull(form.site_id),
            starts_at: starts,
            ends_at: ends,
        });
    };

    return (
        <PopupForm
            open
            onClose={onClose}
            size="md"
            icon={Wrench}
            title={mw ? 'Edit maintenance window' : 'Schedule maintenance'}
            description="During this time, signals from the chosen source or house don't raise alerts."
            error={save.error}
            submitLabel={mw ? 'Save window' : 'Schedule window'}
            processing={save.processing}
            submitDisabled={!form.name.trim()}
            onSubmit={submit}
            dirty={JSON.stringify(form) !== JSON.stringify(initial)}
            draftName={mw ? `changes to ${mw.name}` : 'a new maintenance window'}
        >
            <PopupSection columns={2}>
                <PopupField label="Name" htmlFor="mw-name" required wide error={save.errors.name}>
                    <Input
                        id="mw-name"
                        value={form.name}
                        onChange={(e) => set('name', e.target.value)}
                        placeholder="e.g. Door sensor firmware update"
                    />
                </PopupField>
                <PopupField label="Signal source" htmlFor="mw-signal-source" error={save.errors.signal_source_id}>
                    <SelectInput
                        value={form.signal_source_id || 'all'}
                        onChange={(v) => set('signal_source_id', v === 'all' ? '' : v)}
                        placeholder="All sources"
                        ariaLabel="Signal source"
                        options={[
                            { value: 'all', label: 'All sources' },
                            ...signalSources.map((s) => ({ value: String(s.id), label: s.name })),
                        ]}
                    />
                </PopupField>
                <PopupField label="House" htmlFor="mw-site" error={save.errors.site_id}>
                    <SelectInput
                        value={form.site_id || 'all'}
                        onChange={(v) => set('site_id', v === 'all' ? '' : v)}
                        placeholder="All houses"
                        ariaLabel="House"
                        options={[
                            { value: 'all', label: 'All houses' },
                            ...sites.map((s) => ({ value: String(s.id), label: s.name })),
                        ]}
                    />
                </PopupField>
                <div className="sm:col-span-full">
                    <DateTimeField
                        id="mw-starts"
                        label="Starts"
                        value={form.starts_at}
                        onChange={(value) => set('starts_at', value)}
                        error={timeError.starts_at ?? save.errors.starts_at}
                        clearable={false}
                    />
                </div>
                <div className="sm:col-span-full">
                    <DateTimeField
                        id="mw-ends"
                        label="Ends"
                        value={form.ends_at}
                        onChange={(value) => set('ends_at', value)}
                        error={timeError.ends_at ?? save.errors.ends_at}
                        clearable={false}
                    />
                </div>
                <PopupField label="Notes" htmlFor="mw-desc" wide hint="Optional — who is doing the work and why." error={save.errors.description}>
                    <Textarea
                        id="mw-desc"
                        rows={2}
                        value={form.description}
                        onChange={(e) => set('description', e.target.value)}
                    />
                </PopupField>
            </PopupSection>
        </PopupForm>
    );
}

export function CancelWindowConfirm({ window: mw, onClose }: { window: MaintenanceWindowData; onClose: () => void }) {
    const save = useSave(onClose);
    return (
        <PopupConfirm
            open
            onClose={onClose}
            onConfirm={() => save.send('post', `/control-room/settings/maintenance/${mw.id}/cancel`)}
            variant="warning"
            icon={CalendarClock}
            title="Cancel this maintenance window?"
            description={
                <>
                    <b className="font-semibold text-foreground">{mw.name}</b> stops. Signals
                    {mw.signal_source_name ? ` from ${mw.signal_source_name}` : ''} raise
                    alerts as normal again.
                </>
            }
            cancelLabel="Keep window"
            confirmLabel="Cancel window"
            processing={save.processing}
            error={save.error}
        />
    );
}

/* ------------------------------------------------------------------ */
/*  Delivery recovery                                                  */
/* ------------------------------------------------------------------ */

export function RetryDeliveryConfirm({ row, onClose }: { row: SignalOutboxRow; onClose: () => void }) {
    const save = useSave(onClose);
    return (
        <PopupConfirm
            open
            onClose={onClose}
            onConfirm={() => save.send('post', `/control-room/settings/signal-outbox/${row.id}/retry`)}
            icon={RefreshCw}
            title="Try this delivery again?"
            description="It goes back in line to be delivered. Check this list again in a few minutes."
            confirmLabel="Try again"
            processing={save.processing}
            error={save.error}
        >
            <PopupFacts
                rows={[
                    ['Signal', row.signal ? `${plainLabel(row.signal.signal_type)} · #${row.signal.id}` : `Delivery #${row.id}`],
                    ['Tries so far', String(row.attempts)],
                    ['Last try', row.last_attempt_at ? formatDateTime(row.last_attempt_at) : 'Not tried yet'],
                    ['What went wrong', row.last_error ?? 'Not recorded'],
                ]}
            />
        </PopupConfirm>
    );
}

/* ------------------------------------------------------------------ */
/*  Options                                                            */
/* ------------------------------------------------------------------ */

export function OptionPopup({
    group,
    groupTitle,
    onClose,
}: {
    group: OptionGroup;
    groupTitle: string;
    onClose: () => void;
}) {
    const [label, setLabel] = useState('');
    const [value, setValue] = useState('');
    const [valueTouched, setValueTouched] = useState(false);
    const [color, setColor] = useState('');
    const [description, setDescription] = useState('');
    const save = useSave(onClose);
    const slug = (text: string) => text.toLowerCase().replace(/[^a-z0-9_]+/g, '_').replace(/^_+|_+$/g, '');

    return (
        <PopupForm
            open
            onClose={onClose}
            size="md"
            icon={ListPlus}
            title="Add option"
            context={{ title: groupTitle, tag: 'Option list', note: 'The list you pressed Add on.' }}
            error={save.error}
            submitLabel="Add option"
            processing={save.processing}
            submitDisabled={!label.trim() || !value.trim()}
            onSubmit={() =>
                save.send('post', '/control-room/settings/options', {
                    group,
                    value: value.trim(),
                    label: label.trim(),
                    color: color.trim() || null,
                    description: description.trim() || null,
                })
            }
            dirty={Boolean(label || value || color || description)}
            draftName={`a new ${groupTitle.toLowerCase()} option`}
        >
            <PopupSection columns={2}>
                <PopupField label="Name" htmlFor="option-label" required error={save.errors.label}>
                    <Input
                        id="option-label"
                        value={label}
                        onChange={(e) => {
                            setLabel(e.target.value);
                            if (!valueTouched) setValue(slug(e.target.value));
                        }}
                        placeholder="e.g. Welfare check"
                    />
                </PopupField>
                <PopupField
                    label="Short code"
                    htmlFor="option-value"
                    required
                    hint="Lower-case letters, numbers and _. Used in reports."
                    error={save.errors.value}
                >
                    <Input
                        id="option-value"
                        value={value}
                        onChange={(e) => {
                            setValueTouched(true);
                            setValue(e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, '_'));
                        }}
                        placeholder="e.g. welfare_check"
                    />
                </PopupField>
                <PopupField label="Colour" htmlFor="option-color" hint="Optional — a hex colour such as #2f6fdf." error={save.errors.color}>
                    <span className="flex items-center gap-2">
                        <Input
                            id="option-color"
                            value={color}
                            onChange={(e) => setColor(e.target.value)}
                            className="flex-1"
                        />
                        {color ? (
                            <span
                                aria-hidden="true"
                                className="size-6 shrink-0 rounded-full border border-border"
                                style={{ backgroundColor: color }}
                            />
                        ) : null}
                    </span>
                </PopupField>
                <PopupField label="Description" htmlFor="option-description" hint="Optional." error={save.errors.description}>
                    <Input
                        id="option-description"
                        value={description}
                        onChange={(e) => setDescription(e.target.value)}
                    />
                </PopupField>
            </PopupSection>
        </PopupForm>
    );
}

export function DeleteOptionConfirm({
    option,
    groupTitle,
    onClose,
}: {
    option: ConfigOption;
    groupTitle: string;
    onClose: () => void;
}) {
    const save = useSave(onClose);
    return (
        <PopupConfirm
            open
            onClose={onClose}
            onConfirm={() => save.send('delete', `/control-room/settings/options/${option.id}`)}
            variant="destructive"
            icon={Trash2}
            title="Delete this option?"
            description={
                <>
                    <b className="font-semibold text-foreground">{option.label}</b> is
                    removed from {groupTitle.toLowerCase()}. Alerts that already use it keep
                    their value. This can&apos;t be undone — turn it off instead to hide it.
                </>
            }
            confirmLabel="Delete option"
            processing={save.processing}
            error={save.error}
        />
    );
}
