/**
 * The Devices bulk bar's governed actions (S&D rebuild C6; plan: "/command-batches/{id} → Bulk bar on
 * Devices runs the action with one confirm; results land in the device logbooks", drawn as pop-up 26's
 * bar plus the kit confirm that states the effect).
 *
 * "Run action" lists what the ticked devices support, only for people who may start a command batch
 * (trackers never: Fleet owns them). The one confirm says how many devices and which, then posts to
 * today's command-batch routes, so identity confirmation, approval, the IT change, the typed count and
 * every server check stay as they are. Devices that are ready are sent straight away; the rest wait
 * for their approver. Each device's logbook records whether it was asked or left out.
 */
import { DateTimeField, validLocalDateTime } from '@/components/fleet-assets/maintenance/date-time-field';
import { VehicleSearchSelect } from '@/components/fleet-assets/vehicle-workspace/search-select';
import { PopupCallout, PopupConfirm, PopupFacts, PopupField } from '@/components/popup';
import { nzInstant } from '@/components/security-devices/alert-popups';
import { confirmIdentity, UNCONFIRMED_WORDS } from '@/components/security-devices/safety-request';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import axios from 'axios';
import { LoaderCircle, Zap } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

const OPTIONS_URL = '/security-devices/devices/bulk-actions';
const BATCHES_URL = '/security-devices/command-batches';
const JSON_HEADERS = { headers: { Accept: 'application/json' } };

export type BulkParameter = {
    name: string;
    label: string;
    type: 'integer' | 'string' | 'date_time';
    min: number | null;
    max: number | null;
    options: string[];
    option_labels: Record<string, string>;
};

export type BulkActionDevice = {
    id: number;
    name: string;
    site: string;
    /** Can take part right now, by the batch engine's own checks. */
    ready: boolean;
    reason: string | null;
    changes: { id: number; label: string }[];
};

export type BulkAction = {
    key: string;
    workspace: string;
    label: string;
    risk: 'low' | 'medium' | 'high' | 'critical';
    impact: string;
    expected: string;
    needs_approval: boolean;
    needs_change: boolean;
    /** Identity confirmation, the combined impact and the typed count (the batch engine's rule). */
    elevated: boolean;
    phrase: string;
    parameters: BulkParameter[];
    ready: number;
    devices: BulkActionDevice[];
};

export type BulkOptions = {
    actions: BulkAction[];
    left_out: { id: number; name: string; reason: string }[];
    step_up_current: boolean;
};

export type BulkResult = {
    label: string;
    sent: number;
    waitingApproval: number;
    waitingOther: number;
    leftOut: { name: string; reason: string | null }[];
    /** Asked, but the send wasn't confirmed. */
    sendUnconfirmed: boolean;
};

type BatchReply = {
    id: number;
    created: boolean;
    summary: { included: number; excluded: number; ready: number; awaitingApproval: number };
    targets: { device: { id: number; name: string }; included: boolean; status: string | null; reason: string | null }[];
};

type Load = { state: 'idle' } | { state: 'loading' } | { state: 'failed'; message: string } | { state: 'ready'; options: BulkOptions };

function plural(count: number, one: string, many = `${one}s`): string {
    return `${count} ${count === 1 ? one : many}`;
}

function firstError(error: unknown, fallback: string): string {
    if (axios.isAxiosError(error)) {
        const data = error.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined;
        const first = data?.errors ? Object.values(data.errors)[0]?.[0] : null;
        if (first) return first;
        if (error.response?.status === 403) return 'You can’t run this here.';
        if (error.response?.status === 404) return 'One of these devices can’t be found any more. Refresh the page and tick them again.';
    }
    return fallback;
}

function newKey(): string {
    const random = typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.round(Math.random() * 1e9)}`;
    return `bulk_devices_${random}`.replace(/[^A-Za-z0-9:_-]/g, '').slice(0, 120);
}

/** The governed actions for these devices, read when asked. */
export function useBulkOptions(ids: number[]) {
    const idsKey = useMemo(() => [...ids].sort((a, b) => a - b).join(','), [ids]);
    const [load, setLoad] = useState<Load>({ state: 'idle' });
    const loadedFor = useRef<string | null>(null);

    useEffect(() => {
        if (loadedFor.current !== idsKey) setLoad({ state: 'idle' });
    }, [idsKey]);

    const fetchOptions = useCallback(
        async (force = false): Promise<BulkOptions | null> => {
            if (!force && loadedFor.current === idsKey && load.state === 'ready') return load.options;
            const list = idsKey === '' ? [] : idsKey.split(',').map(Number);
            loadedFor.current = idsKey;
            setLoad({ state: 'loading' });
            try {
                const { data } = await axios.get<BulkOptions>(OPTIONS_URL, { params: { ids: list }, ...JSON_HEADERS });
                if (loadedFor.current === idsKey) setLoad({ state: 'ready', options: data });
                return data;
            } catch (error) {
                if (loadedFor.current === idsKey) setLoad({ state: 'failed', message: firstError(error, 'Couldn’t check what these devices can do.') });
                return null;
            }
        },
        [idsKey, load],
    );

    return { load, fetchOptions };
}

/** "Run action" in the Devices selection bar (pop-up 26). */
export function BulkActionsMenu({ ids, onChoose }: { ids: number[]; onChoose: (action: BulkAction, options: BulkOptions) => void }) {
    const { load, fetchOptions } = useBulkOptions(ids);
    const leftOut = load.state === 'ready' ? load.options.left_out : [];

    return (
        <DropdownMenu
            onOpenChange={(open) => {
                if (open && load.state !== 'loading') void fetchOptions();
            }}
        >
            <DropdownMenuTrigger asChild>
                <Button type="button" variant="outline">
                    <Zap className="size-4" />
                    Run action
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-80">
                <DropdownMenuLabel>Run on the ticked devices</DropdownMenuLabel>
                {load.state === 'idle' || load.state === 'loading' ? (
                    <DropdownMenuItem disabled>
                        <LoaderCircle aria-hidden="true" className="size-4 animate-spin motion-reduce:animate-none" />
                        Checking what these can do…
                    </DropdownMenuItem>
                ) : load.state === 'failed' ? (
                    <DropdownMenuItem
                        onSelect={(event) => {
                            event.preventDefault();
                            void fetchOptions(true);
                        }}
                    >
                        <span className="grid gap-0.5">
                            <span>{load.message}</span>
                            <span className="text-caption">Try again</span>
                        </span>
                    </DropdownMenuItem>
                ) : load.options.actions.length === 0 ? (
                    <DropdownMenuItem disabled>None of these share an action</DropdownMenuItem>
                ) : (
                    load.options.actions.map((action) => (
                        <DropdownMenuItem key={`${action.workspace}:${action.key}`} disabled={action.ready === 0} onSelect={() => onChoose(action, load.options)}>
                            <span className="grid min-w-0 gap-0.5">
                                <span className="truncate">{action.label}</span>
                                <span className="text-caption">
                                    {action.ready === 0
                                        ? `${plural(action.devices.length, 'device')} · none can right now`
                                        : action.ready < action.devices.length
                                          ? `${action.ready} of ${action.devices.length} devices can right now`
                                          : plural(action.devices.length, 'device')}
                                    {action.needs_approval ? ' · needs approval' : ''}
                                </span>
                            </span>
                        </DropdownMenuItem>
                    ))
                )}
                {leftOut.length > 0 ? (
                    <>
                        <DropdownMenuSeparator />
                        <p className="text-caption px-2 py-1.5">
                            Left out: {leftOut.slice(0, 3).map((device) => device.name).join(', ')}
                            {leftOut.length > 3 ? ` and ${leftOut.length - 3} more` : ''}. {leftOut[0].reason}
                        </p>
                    </>
                ) : null}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function parameterValue(parameter: BulkParameter): string {
    return parameter.options[0] ?? (parameter.type === 'integer' && parameter.min !== null ? String(parameter.min) : '');
}

function optionLabel(parameter: BulkParameter, option: string): string {
    const label = parameter.option_labels[option];
    if (label) return label;
    const words = option.replaceAll('_', ' ');
    return words.charAt(0).toUpperCase() + words.slice(1);
}

/**
 * Back from "Confirm it’s you": the ticked devices and the action to reopen, read once and taken out
 * of the address so a refresh doesn't reopen it. The page still reads the options fresh.
 */
export function takeBulkResume(): { ids: number[]; action: string | null } | null {
    if (typeof window === 'undefined') return null;
    const url = new URL(window.location.href);
    const raw = url.searchParams.get('bulk');
    if (raw === null) return null;
    const ids = [...new Set(raw.split(',').map(Number).filter((id) => Number.isInteger(id) && id > 0))].slice(0, 100);
    const action = url.searchParams.get('bulk_action');
    url.searchParams.delete('bulk');
    url.searchParams.delete('bulk_action');
    window.history.replaceState(window.history.state, '', `${url.pathname}${url.search}${url.hash}`);
    if (ids.length < 2) return null;
    return { ids, action: action !== null && /^[a-z0-9_.]{1,120}$/.test(action) ? action : null };
}

/** Where "Confirm it’s you" sends people, and how they come back to the same ticked devices. */
export function confirmIdentityHref(action: BulkAction, ids: number[]): string {
    const query = new URLSearchParams({ workspace: action.workspace, return: 'devices', ids: ids.join(','), action: action.key });
    return `${BATCHES_URL}/confirm-identity?${query.toString()}`;
}

/** The one confirm: how many devices and which, what happens, then the batch engine's own checks. */
export function BulkActionConfirm({
    open,
    action,
    ticked,
    stepUpCurrent,
    onClose,
    onDone,
}: {
    open: boolean;
    action: BulkAction | null;
    /** Every ticked device, so "Confirm it’s you" comes back to the same selection. */
    ticked: number[];
    stepUpCurrent: boolean;
    onClose: () => void;
    onDone: (result: BulkResult) => void;
}) {
    const [values, setValues] = useState<Record<string, string>>({});
    const [changes, setChanges] = useState<Record<number, string>>({});
    const [why, setWhy] = useState('');
    const [acknowledged, setAcknowledged] = useState(false);
    const [typed, setTyped] = useState('');
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | undefined>(undefined);
    const [tried, setTried] = useState(false);
    // One key per opening, reused on a retry: a lost answer never starts a second batch.
    const key = useMemo(() => (open && action ? newKey() : ''), [open, action]);

    useEffect(() => {
        if (!open || !action) return;
        setValues(Object.fromEntries(action.parameters.map((parameter) => [parameter.name, parameterValue(parameter)])));
        setChanges(
            Object.fromEntries(
                action.devices.filter((device) => device.ready && device.changes[0]).map((device) => [device.id, String(device.changes[0].id)]),
            ),
        );
        setWhy('');
        setAcknowledged(false);
        setTyped('');
        setProcessing(false);
        setError(undefined);
        setTried(false);
    }, [open, action]);

    if (!action) return null;

    const ready = action.devices.filter((device) => device.ready);
    const notReady = action.devices.filter((device) => !device.ready);
    const sites = new Set(ready.map((device) => device.site));
    const blockedByIdentity = action.elevated && !stepUpCurrent;

    const parameterError = (parameter: BulkParameter): string | undefined => {
        const value = (values[parameter.name] ?? '').trim();
        if (value === '') return `Fill in ${parameter.label.toLowerCase()}.`;
        if (parameter.type === 'integer') {
            const number = Number(value);
            if (!Number.isInteger(number)) return 'Use a whole number.';
            if (parameter.min !== null && number < parameter.min) return `At least ${parameter.min}.`;
            if (parameter.max !== null && number > parameter.max) return `At most ${parameter.max}.`;
        }
        if (parameter.type === 'string' && parameter.max !== null && value.length > parameter.max) return `Up to ${parameter.max} characters.`;
        if (parameter.type === 'date_time') {
            if (!validLocalDateTime(value)) return 'Choose a date and a time.';
            const instant = nzInstant(value);
            if ('error' in instant) return instant.error;
        }
        return undefined;
    };
    const problems = {
        parameters: action.parameters.some((parameter) => parameterError(parameter) !== undefined),
        changes: action.needs_change && ready.some((device) => !changes[device.id]),
        why: why.trim().length < 10 ? 'Say why in at least 10 characters.' : why.trim().length > 1000 ? 'Keep it under 1,000 characters.' : undefined,
        acknowledged: action.elevated && !acknowledged,
        typed: action.elevated && typed.trim() !== action.phrase ? `Type ${action.phrase} exactly.` : undefined,
    };
    const invalid = problems.parameters || problems.changes || !!problems.why || problems.acknowledged || !!problems.typed;

    const submit = async () => {
        setTried(true);
        if (processing || blockedByIdentity || ready.length === 0 || invalid) return;
        const parameters = Object.fromEntries(
            action.parameters.map((parameter) => {
                const value = (values[parameter.name] ?? '').trim();
                if (parameter.type === 'integer') return [parameter.name, Number(value)];
                if (parameter.type === 'date_time') {
                    const instant = nzInstant(value);
                    return [parameter.name, 'iso' in instant ? instant.iso : value];
                }
                return [parameter.name, value];
            }),
        );
        setProcessing(true);
        setError(undefined);
        try {
            const { data } = await axios.post<BatchReply>(
                BATCHES_URL,
                {
                    workspace: action.workspace,
                    device_ids: action.devices.map((device) => device.id),
                    capability: action.key,
                    parameters,
                    reason: why.trim(),
                    idempotency_key: key,
                    it_change_ids: action.needs_change
                        ? Object.fromEntries(ready.filter((device) => changes[device.id]).map((device) => [device.id, Number(changes[device.id])]))
                        : {},
                    impact_acknowledged: action.elevated ? acknowledged : false,
                    confirmation_text: action.elevated ? typed.trim() : null,
                },
                JSON_HEADERS,
            );
            let sent = 0;
            let sendUnconfirmed = false;
            if (data.summary.ready > 0) {
                try {
                    const reply = await axios.post<{ processed: number; skipped: number }>(`${BATCHES_URL}/${data.id}/dispatch`, {}, JSON_HEADERS);
                    sent = reply.data.processed;
                } catch {
                    sendUnconfirmed = true;
                }
            }
            onDone({
                label: action.label,
                sent,
                waitingApproval: data.summary.awaitingApproval,
                waitingOther: Math.max(0, data.summary.included - data.summary.ready - data.summary.awaitingApproval),
                leftOut: data.targets.filter((target) => !target.included).map((target) => ({ name: target.device.name, reason: target.reason })),
                sendUnconfirmed,
            });
        } catch (failure) {
            setError(axios.isAxiosError(failure) && !failure.response ? UNCONFIRMED_WORDS : firstError(failure, 'That didn’t work. Nothing was run.'));
        } finally {
            setProcessing(false);
        }
    };

    return (
        <PopupConfirm
            open={open}
            onClose={onClose}
            onConfirm={() => void submit()}
            variant={action.elevated ? 'warning' : 'plain'}
            icon={Zap}
            title={`Run “${action.label}” on ${plural(action.devices.length, 'device')}?`}
            description={action.impact}
            error={error}
            confirmLabel={action.needs_approval ? `Ask to run on ${plural(ready.length, 'device')}` : `Run on ${plural(ready.length, 'device')}`}
            processing={processing}
            processingLabel="Running…"
            confirmDisabled={blockedByIdentity || ready.length === 0}
            testId="sd-bulk-action"
        >
            <ul aria-label="Devices" className="grid max-h-48 gap-0 overflow-y-auto rounded-[12px] border border-border text-sm scrollbar-pretty">
                {action.devices.map((device, index) => (
                    <li key={device.id} className={cn('flex items-start justify-between gap-3 px-3.5 py-2', index > 0 && 'border-t border-border')}>
                        <span className="grid min-w-0 gap-0.5">
                            <span className="truncate font-medium text-foreground">{device.name}</span>
                            <span className="text-caption">{device.ready ? device.site : device.reason}</span>
                        </span>
                        {device.ready ? null : <StatusBadge variant="neutral">Left out</StatusBadge>}
                    </li>
                ))}
            </ul>
            <PopupFacts
                rows={[
                    ['Then', action.needs_approval ? 'Each one waits for an approver on its device page, then it’s sent' : 'Sent straight away to each device that can take it'],
                    ['Expected', action.expected],
                    ...(notReady.length > 0 ? ([['Left out', `${plural(notReady.length, 'device')}, noted in ${notReady.length === 1 ? 'its' : 'their'} logbook`]] as const) : []),
                    ...(sites.size > 1 ? ([['Sites', `${sites.size} Sites`]] as const) : []),
                ]}
            />
            {action.parameters.map((parameter) => {
                const id = `sd-bulk-parameter-${parameter.name}`;
                const value = values[parameter.name] ?? '';
                const set = (next: string) => setValues((current) => ({ ...current, [parameter.name]: next }));
                const fieldError = tried ? parameterError(parameter) : undefined;
                if (parameter.type === 'date_time')
                    return <DateTimeField key={parameter.name} id={id} label={parameter.label} value={value} onChange={set} error={fieldError} clearable={false} />;
                return (
                    <PopupField key={parameter.name} label={parameter.label} htmlFor={id} required error={fieldError}>
                        {parameter.options.length > 0 ? (
                            <VehicleSearchSelect
                                id={id}
                                label={parameter.label}
                                value={value}
                                options={parameter.options.map((option) => ({ value: option, label: optionLabel(parameter, option) }))}
                                onChange={set}
                            />
                        ) : (
                            <Input
                                id={id}
                                type={parameter.type === 'integer' ? 'number' : 'text'}
                                inputMode={parameter.type === 'integer' ? 'numeric' : undefined}
                                min={parameter.type === 'integer' ? (parameter.min ?? undefined) : undefined}
                                max={parameter.type === 'integer' ? (parameter.max ?? undefined) : undefined}
                                maxLength={parameter.type === 'string' ? (parameter.max ?? undefined) : undefined}
                                value={value}
                                onChange={(event) => set(event.target.value)}
                            />
                        )}
                    </PopupField>
                );
            })}
            {action.needs_change
                ? ready.map((device) => (
                      <PopupField
                          key={device.id}
                          label={`IT change for ${device.name}`}
                          htmlFor={`sd-bulk-change-${device.id}`}
                          required
                          error={tried && !changes[device.id] ? 'Choose its approved IT change.' : undefined}
                      >
                          <VehicleSearchSelect
                              id={`sd-bulk-change-${device.id}`}
                              label={`IT change for ${device.name}`}
                              value={changes[device.id] ?? ''}
                              options={device.changes.map((change) => ({ value: String(change.id), label: change.label }))}
                              onChange={(next) => setChanges((current) => ({ ...current, [device.id]: next }))}
                          />
                      </PopupField>
                  ))
                : null}
            <PopupField label="Why" htmlFor="sd-bulk-why" required hint="Kept with the request for each device" error={tried ? problems.why : undefined}>
                <Textarea id="sd-bulk-why" rows={3} maxLength={1000} value={why} onChange={(event) => setWhy(event.target.value)} />
            </PopupField>
            {action.elevated ? (
                <>
                    {blockedByIdentity ? (
                        <PopupCallout tone="warning">
                            <p>Confirm it’s you first. You’ll come back here with the same devices ticked.</p>
                            <Button type="button" variant="outline" size="sm" className="mt-2" onClick={() => confirmIdentity(confirmIdentityHref(action, ticked))}>
                                Confirm it’s you
                            </Button>
                        </PopupCallout>
                    ) : null}
                    <div className="flex items-start gap-2.5">
                        <Checkbox
                            id="sd-bulk-acknowledged"
                            checked={acknowledged}
                            disabled={blockedByIdentity}
                            onCheckedChange={(value) => setAcknowledged(value === true)}
                            className="mt-0.5"
                        />
                        <Label htmlFor="sd-bulk-acknowledged" className="leading-snug font-normal">
                            I’ve checked these devices, their Sites and what’s left out
                        </Label>
                    </div>
                    <PopupField label={`Type ${action.phrase} to confirm`} htmlFor="sd-bulk-typed" required error={tried ? problems.typed : undefined}>
                        <Input id="sd-bulk-typed" autoComplete="off" value={typed} disabled={blockedByIdentity} onChange={(event) => setTyped(event.target.value)} />
                    </PopupField>
                </>
            ) : null}
        </PopupConfirm>
    );
}

/** The result as toasts' words (the details are in each device's logbook). */
export function bulkResultLines(result: BulkResult): { done: string | null; warning: string | null } {
    const parts: string[] = [];
    if (result.sent > 0) parts.push(`sent to ${plural(result.sent, 'device')}`);
    if (result.waitingApproval > 0) parts.push(`${plural(result.waitingApproval, 'device')} ${result.waitingApproval === 1 ? 'waits' : 'wait'} for an approver`);
    if (result.waitingOther > 0) parts.push(`${plural(result.waitingOther, 'device')} still ${result.waitingOther === 1 ? 'needs' : 'need'} a check before ${result.waitingOther === 1 ? 'it’s' : 'they’re'} sent`);
    const done = parts.length > 0 ? `${result.label}: ${parts.join(' · ')}. Each device’s logbook has the result.` : null;
    const warnings: string[] = [];
    if (result.sendUnconfirmed) warnings.push('Sending wasn’t confirmed. Check each device before you try again; nothing will be sent twice.');
    if (result.leftOut.length > 0) warnings.push(`Left out: ${result.leftOut.map((device) => (device.reason ? `${device.name} (${device.reason.replace(/\.$/, '')})` : device.name)).join(', ')}.`);
    return { done, warning: warnings.length > 0 ? warnings.join(' ') : null };
}
