import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import { usePage } from '@inertiajs/react';
import axios from 'axios';
import {
    Activity,
    Cable,
    Check,
    Database,
    Globe,
    KeyRound,
    Loader2,
    Network,
    ShieldCheck,
    ShieldX,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    confirmedNativeMonitor,
    initialNativeDraft,
    nativeDraftErrors,
    nativeErrorStep,
    nativeKinds,
    nativePayload,
    type NativeMonitorAcknowledgement,
    type NativeMonitorAttempt,
    type NativeMonitorDraft,
} from './native-monitor-wizard-data';

export type NativeMonitorManagement = {
    actor_id?: number;
    can_manage: boolean;
    create_url: string | null;
    kinds: Array<{ value: string; label: string }>;
    profiles: Array<{ id: number; name: string }>;
    devices: Array<{
        id: number;
        name: string;
        site: { id: number; name: string };
    }>;
};

export type NativeMonitorTarget = {
    id: number;
    name: string;
    kind: string;
    enabled: boolean;
    affects_availability: boolean;
    profile: { id: number; name: string } | null;
    device: { id: number; name: string; site?: { id: number; name: string } };
    actions: {
        actor_id?: number;
        review_token?: string | null;
        can_manage: boolean;
        update_url: string | null;
        deactivate_url: string | null;
    };
};

type CommitState =
    | 'idle'
    | 'busy'
    | 'uncertain'
    | 'denied'
    | 'conflict'
    | 'saved';
function useNativeMonitorCommit() {
    const [state, setState] = useState<CommitState>('idle'),
        [notice, setNotice] = useState(''),
        [errors, setErrors] = useState<Record<string, string>>({}),
        [saved, setSaved] = useState<NativeMonitorAcknowledgement | null>(null);
    const attempt = useRef<NativeMonitorAttempt | null>(null),
        call = useRef<AbortController | null>(null),
        live = useRef(true),
        sending = useRef(false),
        hadSave = useRef(false);
    useEffect(() => {
        live.current = true;
        return () => {
            live.current = false;
            call.current?.abort();
        };
    }, []);
    async function run(next: NativeMonitorAttempt, recovering = false) {
        if (sending.current || !live.current) return;
        sending.current = true;
        const controller = new AbortController();
        call.current = controller;
        attempt.current = next;
        setState('busy');
        setNotice('');
        setErrors({});
        try {
            const options = {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
                timeout: 30000,
            };
            const response = recovering
                ? await axios.post<unknown>(
                      `/security-devices/monitoring/native-monitors/requests/${next.uuid}/recover`,
                      {
                          expected_actor_id: next.actor,
                          expected_site_id: next.site,
                          expected_device_id: next.device,
                      },
                      options,
                  )
                : next.operation === 'update'
                  ? await axios.patch<unknown>(
                        `/security-devices/monitoring/native-monitors/${next.target}`,
                        next.payload,
                        options,
                    )
                  : await axios.post<unknown>(
                        next.operation === 'create'
                            ? '/security-devices/monitoring/native-monitors'
                            : `/security-devices/monitoring/native-monitors/${next.target}/deactivate`,
                        next.payload,
                        options,
                    );
            if (!live.current || controller.signal.aborted) return;
            const confirmed = confirmedNativeMonitor(response.data, next);
            hadSave.current = true;
            setSaved(confirmed);
            setState('saved');
        } catch (error) {
            if (!live.current || controller.signal.aborted) return;
            const status = axios.isAxiosError(error)
                ? error.response?.status
                : undefined;
            if (!recovering && status === 422) {
                const values = axios.isAxiosError(error)
                    ? error.response?.data?.errors
                    : undefined;
                const nextErrors: Record<string, string> = {};
                if (values && typeof values === 'object')
                    for (const [key, value] of Object.entries(values)) {
                        const message = Array.isArray(value) ? value[0] : value;
                        if (typeof message === 'string')
                            nextErrors[key.split('.')[0]] = message.slice(
                                0,
                                500,
                            );
                    }
                setErrors(nextErrors);
                setNotice(
                    'The monitor was not saved. Review the highlighted entries.',
                );
                setState('idle');
            } else if (!recovering && [401, 403, 404].includes(status ?? 0)) {
                setNotice(
                    'Your current access or reviewed Site context cannot apply this change. Your draft is still here. Reopen Monitoring before another save.',
                );
                setState('denied');
            } else if (!recovering && status === 409) {
                setNotice(
                    'This monitor or request changed after review. Your entries are still here. Reopen current Monitoring before applying another change.',
                );
                setState('conflict');
            } else {
                setNotice(
                    recovering
                        ? 'The saved request is not available yet in your current scope. It may still be finishing. Review Monitoring before another save.'
                        : 'The save could not be confirmed. It may have finished. Check this saved request before doing anything again.',
                );
                setState('uncertain');
            }
        } finally {
            sending.current = false;
            if (live.current && !controller.signal.aborted) call.current = null;
        }
    }
    return {
        state,
        notice,
        errors,
        setErrors,
        saved,
        hadSave,
        send: (next: NativeMonitorAttempt) => run(next),
        recover: () => {
            if (attempt.current) return run(attempt.current, true);
        },
        reset: () => {
            attempt.current = null;
            setSaved(null);
            setState('idle');
            setErrors({});
            setNotice('');
        },
    };
}
function useNativeActor() {
    const { auth } = usePage<{
        auth?: {
            user?: { id: number } | null;
            can?: {
                securityDevices?: {
                    viewAny?: boolean;
                    monitoringManage?: boolean;
                };
            };
        };
    }>().props;
    return {
        id: auth?.user?.id ?? 0,
        manage: auth?.can?.securityDevices?.monitoringManage === true,
        identity: JSON.stringify([auth?.user?.id, auth?.can]),
    };
}
const kindDetails: Record<
    string,
    { label: string; note: string; icon: typeof Activity }
> = {
    icmp: {
        label: 'ICMP availability',
        note: 'Check whether an approved address responds.',
        icon: Activity,
    },
    tcp: {
        label: 'TCP port',
        note: 'Check an approved network port.',
        icon: Cable,
    },
    dns: {
        label: 'DNS answer',
        note: 'Check an approved DNS query and answers.',
        icon: Network,
    },
    http: {
        label: 'HTTP / HTTPS',
        note: 'Check an approved web address and status.',
        icon: Globe,
    },
    tls: {
        label: 'TLS certificate',
        note: 'Check the approved certificate expiry.',
        icon: ShieldCheck,
    },
    snmp: {
        label: 'SNMPv3 inventory and health',
        note: 'Use an existing approved Site credential.',
        icon: Database,
    },
    ssh_inventory: {
        label: 'Read-only SSH inventory',
        note: 'Use a pinned host and approved inventory profile.',
        icon: KeyRound,
    },
    winrm_inventory: {
        label: 'Read-only WinRM inventory',
        note: 'Read an approved Windows inventory profile.',
        icon: Database,
    },
};
const steps = [
    {
        key: 'kind',
        label: 'Check type',
        blurb: 'Choose the supported check',
        icon: Activity,
    },
    {
        key: 'context',
        label: 'Device and policy',
        blurb: 'Site, name and availability',
        icon: Network,
    },
    {
        key: 'definition',
        label: 'Connection',
        blurb: 'Approved target and settings',
        icon: Cable,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm this configuration',
        icon: Check,
    },
];
export function NativeMonitorDialog({
    open,
    onOpenChange,
    management,
    monitor,
    onSaved,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    management: NativeMonitorManagement;
    monitor: NativeMonitorTarget | null;
    onSaved: () => void;
}) {
    const actor = useNativeActor();
    if (
        open &&
        (!actor.manage ||
            management.actor_id !== actor.id ||
            (monitor && monitor.actions.actor_id !== actor.id))
    )
        return <NativeContextUnavailable onClose={() => onOpenChange(false)} />;
    return open ? (
        <NativeMonitorWizardBody
            key={JSON.stringify([actor.identity, management, monitor])}
            actor={actor}
            management={management}
            monitor={monitor}
            onClose={() => onOpenChange(false)}
            onSaved={onSaved}
        />
    ) : null;
}
function NativeMonitorWizardBody({
    actor,
    management,
    monitor,
    onClose,
    onSaved,
}: {
    actor: ReturnType<typeof useNativeActor>;
    management: NativeMonitorManagement;
    monitor: NativeMonitorTarget | null;
    onClose: () => void;
    onSaved: () => void;
}) {
    const editing = monitor !== null,
        initial = initialNativeDraft(management, monitor);
    const [draft, setDraft] = useState(initial),
        [step, setStep] = useState(0);
    const commit = useNativeMonitorCommit(),
        busy = commit.state === 'busy',
        blocked = commit.state !== 'idle';
    const permitted =
        actor.id > 0 &&
        actor.manage &&
        management.can_manage &&
        (!monitor
            ? management.create_url ===
              '/security-devices/monitoring/native-monitors'
            : monitor.actions.can_manage &&
              monitor.actions.update_url ===
                  `/security-devices/monitoring/native-monitors/${monitor.id}`);
    const site =
        monitor?.device.site ??
        management.devices.find((item) => item.id === Number(draft.deviceId))
            ?.site;
    const validReviewToken =
        !monitor ||
        (typeof monitor.actions.review_token === 'string' &&
            /^[0-9a-f]{64}$/.test(monitor.actions.review_token));
    const dirty =
        !commit.saved &&
        (JSON.stringify(draft) !== JSON.stringify(initial) ||
            busy ||
            commit.state === 'uncertain');
    const closeSaved = () => {
        if (commit.hadSave.current) {
            commit.hadSave.current = false;
            onSaved();
        }
        onClose();
    };
    const leave = useSettingsLeaveConfirmation(
        dirty,
        'Discard this monitor draft?',
        'Unsaved monitor settings will be discarded. A save already sent may still finish. Check its saved request before another save. Cancel to keep editing.',
    );
    const close = () => {
        if (!busy) leave.request(closeSaved);
    };
    const change = <K extends keyof NativeMonitorDraft>(
        key: K,
        value: NativeMonitorDraft[K],
    ) => {
        if (blocked || !permitted) return;
        setDraft((before) => ({ ...before, [key]: value }));
        commit.setErrors((before) => {
            const next = { ...before };
            const names: Partial<Record<keyof NativeMonitorDraft, string>> = {
                deviceId: 'device_id',
                profileId: 'profile_id',
                dnsName: 'dns_name',
                dnsType: 'dns_type',
                expectedAnswers: 'expected_answers',
                expectedStatus: 'expected_status',
                warnDays: 'warn_days',
                credentialReference: 'credential_reference',
                inventoryProfile: 'inventory_profile',
                hostKeyFingerprint: 'host_key_fingerprint',
                affectsAvailability: 'affects_availability',
            };
            const field = names[key] ?? String(key);
            delete next[field];
            return next;
        });
    };
    const {
        deviceId,
        profileId,
        kind,
        name,
        target,
        port,
        dnsName,
        dnsType,
        expectedAnswers,
        expectedStatus,
        warnDays,
        credentialReference,
        inventoryProfile,
        hostKeyFingerprint,
        affectsAvailability,
    } = draft;
    const setDeviceId = (v: string) => change('deviceId', v),
        setProfileId = (v: string) => change('profileId', v),
        setName = (v: string) => change('name', v),
        setTarget = (v: string) => change('target', v),
        setPort = (v: string) => change('port', v),
        setDnsName = (v: string) => change('dnsName', v),
        setDnsType = (v: string) => change('dnsType', v),
        setExpectedAnswers = (v: string) => change('expectedAnswers', v),
        setExpectedStatus = (v: string) => change('expectedStatus', v),
        setWarnDays = (v: string) => change('warnDays', v),
        setCredentialReference = (v: string) =>
            change('credentialReference', v),
        setInventoryProfile = (v: string) => change('inventoryProfile', v),
        setHostKeyFingerprint = (v: string) => change('hostKeyFingerprint', v),
        setAffectsAvailability = (v: boolean) =>
            change('affectsAvailability', v);
    const needsPort = ['tcp', 'dns', 'tls', 'snmp', 'ssh_inventory'].includes(
            kind,
        ),
        needsCredential = ['snmp', 'ssh_inventory', 'winrm_inventory'].includes(
            kind,
        );
    const errorFor = (key: string) =>
        commit.errors[key] ? (
            <p role="alert" className="text-sm text-destructive">
                {commit.errors[key]}
            </p>
        ) : null;
    useEffect(() => {
        const key = Object.keys(commit.errors)[0];
        if (key) setStep(nativeErrorStep(key));
    }, [commit.errors]);
    const check = (through: number) => {
        const errors = nativeDraftErrors(draft, management, monitor, through);
        if (through >= 1 && !site)
            errors.expected_site_id =
                'The current Site context is unavailable. Reopen Monitoring.';
        commit.setErrors(errors);
        if (Object.keys(errors).length) {
            setStep(nativeErrorStep(Object.keys(errors)[0]));
            return false;
        }
        return true;
    };
    const save = () => {
        if (blocked || !permitted || !validReviewToken || !check(2) || !site)
            return;
        const uuid = crypto.randomUUID();
        const payload = {
            ...nativePayload(draft, monitor),
            request_uuid: uuid,
            expected_actor_id: actor.id,
            expected_site_id: site.id,
            expected_device_id: Number(deviceId),
            ...(monitor ? { review_token: monitor.actions.review_token } : {}),
        };
        void commit.send({
            uuid,
            actor: actor.id,
            site: site.id,
            device: Number(deviceId),
            operation: editing ? 'update' : 'create',
            target: monitor?.id ?? null,
            payload,
            expected: {
                name: draft.name.trim(),
                kind,
                profile: Number(profileId),
                availability: affectsAvailability,
                enabled: monitor?.enabled ?? true,
            },
        });
    };
    const label =
        management.kinds.find((item) => item.value === kind)?.label ??
        kindDetails[kind]?.label ??
        'Unsupported check';
    const profile = management.profiles.find(
        (item) => item.id === Number(profileId),
    );
    const device = management.devices.find(
        (item) => item.id === Number(deviceId),
    );
    const draftErrors = {
        ...nativeDraftErrors(draft, management, monitor),
        ...commit.errors,
    };
    const ready = [
        !draftErrors.kind,
        !Object.keys(draftErrors).some((key) => nativeErrorStep(key) === 1) &&
            Boolean(site),
        !Object.keys(draftErrors).some((key) => nativeErrorStep(key) === 2),
    ];
    const pct = Math.round((ready.filter(Boolean).length / ready.length) * 100);
    return (
        <>
            <WizardShell
                open
                onClose={close}
                title={
                    editing ? `Update ${monitor.name}` : 'Add direct monitor'
                }
                description="Configure an approved device check. A saved configuration does not prove the connection works."
                railIcon={Activity}
                railTitle={editing ? 'Update monitor' : 'Create monitor'}
                railSub={
                    monitor ? `Monitor #${monitor.id}` : 'Approved direct check'
                }
                steps={steps}
                stepIndex={step}
                onStepClick={(next) => {
                    if (!busy) setStep(next);
                }}
                pct={pct}
                pctLabel="Required sections completed"
                maxWidth="min(94vw, 1100px)"
                footerStart={
                    <>
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={close}
                        >
                            Cancel
                        </Button>
                        {step > 0 ? (
                            <Button
                                variant="outline"
                                disabled={busy}
                                onClick={() => setStep(step - 1)}
                            >
                                Back
                            </Button>
                        ) : null}
                    </>
                }
                footerEnd={
                    step < 3 ? (
                        <Button
                            disabled={busy || !permitted}
                            onClick={() => {
                                if (check(step)) setStep(step + 1);
                            }}
                        >
                            Continue
                        </Button>
                    ) : (
                        <Button
                            disabled={
                                busy ||
                                blocked ||
                                !permitted ||
                                !validReviewToken
                            }
                            onClick={save}
                        >
                            {busy ? (
                                <Loader2 className="size-4 animate-spin" />
                            ) : null}
                            {busy
                                ? 'Saving…'
                                : editing
                                  ? 'Apply monitor update'
                                  : 'Add direct monitor'}
                        </Button>
                    )
                }
                success={
                    commit.saved ? (
                        <WizardSuccessPane
                            title="Monitor configuration saved"
                            blurb={
                                <>
                                    {commit.saved.monitor.name} · Monitor #
                                    {commit.saved.monitor.id}.{' '}
                                    {commit.saved.receipt.current_matches_saved
                                        ? 'Observations are still needed to establish health.'
                                        : 'The saved request is confirmed, but this monitor changed later. Review its current settings.'}
                                </>
                            }
                            actions={
                                <>
                                    <Button onClick={closeSaved}>Done</Button>
                                    {!editing ? (
                                        <Button
                                            variant="outline"
                                            onClick={() => {
                                                setDraft(
                                                    initialNativeDraft(
                                                        management,
                                                        null,
                                                    ),
                                                );
                                                setStep(0);
                                                commit.reset();
                                            }}
                                        >
                                            Create another
                                        </Button>
                                    ) : null}
                                </>
                            }
                        />
                    ) : undefined
                }
            >
                <WizardStepPane>
                    {!permitted ? (
                        <p
                            role="alert"
                            className="mb-4 text-sm text-destructive"
                        >
                            Monitor management access is required. Reopen
                            Monitoring with your current approved access.
                        </p>
                    ) : null}
                    {editing && !validReviewToken ? (
                        <p
                            role="alert"
                            className="mb-4 text-sm text-destructive"
                        >
                            The current monitor review is unavailable. Reopen
                            Monitoring before saving.
                        </p>
                    ) : null}
                    {dirty && !leave.historyProtectionAvailable ? (
                        <p
                            role="note"
                            className="mb-4 text-sm text-muted-foreground"
                        >
                            Use Cancel to leave this draft safely. This browser
                            cannot ask before you use Back or Forward.
                        </p>
                    ) : null}
                    {commit.notice ? (
                        <p
                            role="alert"
                            className="mb-4 rounded-lg border p-3 text-sm"
                        >
                            {commit.notice}
                        </p>
                    ) : null}
                    {commit.state === 'uncertain' ? (
                        <Button
                            variant="outline"
                            className="mb-4"
                            onClick={() => void commit.recover()}
                        >
                            Check saved request
                        </Button>
                    ) : null}
                    {step === 0 ? (
                        <div className="space-y-4">
                            <p className="text-sm text-muted-foreground">
                                {editing
                                    ? 'This recorded check type is fixed. Connection fields left blank keep their hidden saved values.'
                                    : 'Choose a supported check. Changing type clears its type-specific connection entries.'}
                            </p>
                            <div className="grid gap-3 sm:grid-cols-2">
                                {(editing
                                    ? [{ value: kind, label }]
                                    : management.kinds
                                )
                                    .filter((option) =>
                                        nativeKinds.includes(
                                            option.value as (typeof nativeKinds)[number],
                                        ),
                                    )
                                    .map((option) => {
                                        const info = kindDetails[option.value],
                                            Icon = info.icon;
                                        return (
                                            <Button
                                                key={option.value}
                                                variant={
                                                    kind === option.value
                                                        ? 'secondary'
                                                        : 'outline'
                                                }
                                                className="h-auto min-h-28 flex-col items-start gap-2 text-left whitespace-normal"
                                                disabled={
                                                    editing ||
                                                    blocked ||
                                                    !permitted
                                                }
                                                aria-pressed={
                                                    kind === option.value
                                                }
                                                onClick={() => {
                                                    if (kind !== option.value)
                                                        setDraft((before) => ({
                                                            ...initialNativeDraft(
                                                                management,
                                                                null,
                                                            ),
                                                            deviceId:
                                                                before.deviceId,
                                                            profileId:
                                                                before.profileId,
                                                            name: before.name,
                                                            affectsAvailability:
                                                                before.affectsAvailability,
                                                            kind: option.value,
                                                        }));
                                                }}
                                            >
                                                <Icon className="size-5" />
                                                <span className="font-semibold">
                                                    {option.label}
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    {info.note}
                                                </span>
                                            </Button>
                                        );
                                    })}
                            </div>
                            {errorFor('kind')}
                        </div>
                    ) : null}
                    {step === 1 ? (
                        <fieldset
                            disabled={blocked || !permitted}
                            className="space-y-4"
                        >
                            <div className="grid gap-4 sm:grid-cols-2">
                                {!editing ? (
                                    <div className="space-y-2">
                                        <Label htmlFor="native-monitor-device">
                                            Device
                                        </Label>
                                        <Select
                                            value={deviceId}
                                            onValueChange={setDeviceId}
                                        >
                                            <SelectTrigger id="native-monitor-device">
                                                <SelectValue placeholder="Select a Site Device" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {management.devices.map(
                                                    (device) => (
                                                        <SelectItem
                                                            key={device.id}
                                                            value={device.id.toString()}
                                                        >
                                                            {device.name} ·{' '}
                                                            {device.site.name}
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                ) : (
                                    <div className="rounded-lg border p-3 text-sm">
                                        <strong>{monitor.device.name}</strong>
                                        <p className="text-muted-foreground">
                                            {monitor.kind.replaceAll('_', ' ')}
                                        </p>
                                    </div>
                                )}
                                <div className="space-y-2">
                                    <Label htmlFor="native-monitor-profile">
                                        Policy profile
                                    </Label>
                                    <Select
                                        value={profileId}
                                        onValueChange={setProfileId}
                                    >
                                        <SelectTrigger id="native-monitor-profile">
                                            <SelectValue placeholder="Select a profile" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {management.profiles.map(
                                                (profile) => (
                                                    <SelectItem
                                                        key={profile.id}
                                                        value={profile.id.toString()}
                                                    >
                                                        {profile.name}
                                                    </SelectItem>
                                                ),
                                            )}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="native-monitor-name">
                                    Monitor name
                                </Label>
                                <Input
                                    id="native-monitor-name"
                                    value={name}
                                    onChange={(event) =>
                                        setName(event.target.value)
                                    }
                                    maxLength={128}
                                    required
                                />
                            </div>
                            <label className="flex items-start gap-3 rounded-lg border p-3 text-sm">
                                <Checkbox
                                    checked={affectsAvailability}
                                    onCheckedChange={(checked) =>
                                        setAffectsAvailability(Boolean(checked))
                                    }
                                />
                                <span>
                                    <strong>Affects Device availability</strong>
                                    <span className="mt-1 block text-xs text-muted-foreground">
                                        Use only for checks that should change
                                        this device's availability state.
                                    </span>
                                </span>
                            </label>
                            <div className="space-y-1">
                                {[
                                    'device_id',
                                    'profile_id',
                                    'name',
                                    'expected_site_id',
                                ].map((key) => (
                                    <div key={key}>{errorFor(key)}</div>
                                ))}
                            </div>
                        </fieldset>
                    ) : null}
                    {step === 2 ? (
                        <fieldset
                            disabled={blocked || !permitted}
                            className="space-y-4"
                        >
                            <p className="text-sm text-muted-foreground">
                                {editing
                                    ? 'Leave replacement fields blank to retain their hidden saved values. Only entries you supply will be changed.'
                                    : 'The approved Site connection and credential rules are checked when saving.'}
                            </p>
                            <div className="space-y-2">
                                <Label htmlFor="native-monitor-target">
                                    {editing
                                        ? 'Replacement target (optional)'
                                        : 'Approved target'}
                                </Label>
                                <Input
                                    id="native-monitor-target"
                                    value={target}
                                    onChange={(event) =>
                                        setTarget(event.target.value)
                                    }
                                    required={!editing}
                                    autoComplete="off"
                                />
                                <p className="text-xs text-muted-foreground">
                                    {editing
                                        ? 'Leave blank to keep the existing hidden target.'
                                        : 'Targets are stored for execution but never projected into the Monitoring workspace or audit metadata.'}
                                </p>
                            </div>
                            {needsPort ? (
                                <div className="space-y-2">
                                    <Label htmlFor="native-monitor-port">
                                        Port {editing ? '(optional)' : ''}
                                    </Label>
                                    <Input
                                        id="native-monitor-port"
                                        type="number"
                                        min={1}
                                        max={65535}
                                        value={port}
                                        onChange={(event) =>
                                            setPort(event.target.value)
                                        }
                                        required={!editing && kind === 'tcp'}
                                    />
                                </div>
                            ) : null}
                            {kind === 'dns' ? (
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label htmlFor="native-monitor-dns-name">
                                            DNS query name
                                        </Label>
                                        <Input
                                            id="native-monitor-dns-name"
                                            value={dnsName}
                                            onChange={(event) =>
                                                setDnsName(event.target.value)
                                            }
                                            required={!editing}
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="native-monitor-dns-type">
                                            Record type
                                        </Label>
                                        <Select
                                            value={dnsType}
                                            onValueChange={setDnsType}
                                        >
                                            <SelectTrigger id="native-monitor-dns-type">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {[
                                                    'A',
                                                    'AAAA',
                                                    'CNAME',
                                                    'MX',
                                                    'TXT',
                                                ].map((value) => (
                                                    <SelectItem
                                                        key={value}
                                                        value={value}
                                                    >
                                                        {value}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="space-y-2 sm:col-span-2">
                                        <Label htmlFor="native-monitor-dns-answers">
                                            Expected answers (optional)
                                        </Label>
                                        <Textarea
                                            id="native-monitor-dns-answers"
                                            value={expectedAnswers}
                                            onChange={(event) =>
                                                setExpectedAnswers(
                                                    event.target.value,
                                                )
                                            }
                                            rows={2}
                                        />
                                    </div>
                                </div>
                            ) : null}
                            {kind === 'http' ? (
                                <div className="space-y-2">
                                    <Label htmlFor="native-monitor-http-status">
                                        Expected HTTP statuses
                                    </Label>
                                    <Input
                                        id="native-monitor-http-status"
                                        value={expectedStatus}
                                        onChange={(event) =>
                                            setExpectedStatus(
                                                event.target.value,
                                            )
                                        }
                                        placeholder="200, 204"
                                    />
                                </div>
                            ) : null}
                            {kind === 'tls' ? (
                                <div className="space-y-2">
                                    <Label htmlFor="native-monitor-warn-days">
                                        Certificate warning days
                                    </Label>
                                    <Input
                                        id="native-monitor-warn-days"
                                        type="number"
                                        min={1}
                                        max={365}
                                        value={warnDays}
                                        onChange={(event) =>
                                            setWarnDays(event.target.value)
                                        }
                                    />
                                </div>
                            ) : null}
                            {needsCredential ? (
                                <div className="space-y-2">
                                    <Label htmlFor="native-monitor-credential">
                                        Credential reference{' '}
                                        {editing
                                            ? '(blank preserves current)'
                                            : ''}
                                    </Label>
                                    <Input
                                        id="native-monitor-credential"
                                        value={credentialReference}
                                        onChange={(event) =>
                                            setCredentialReference(
                                                event.target.value,
                                            )
                                        }
                                        required={!editing}
                                        autoComplete="off"
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Use only an active Site reference.
                                        Secret material is leased at execution
                                        and never entered here.
                                    </p>
                                </div>
                            ) : null}
                            {['ssh_inventory', 'winrm_inventory'].includes(
                                kind,
                            ) ? (
                                <div className="space-y-2">
                                    <Label htmlFor="native-monitor-inventory-profile">
                                        Read-only inventory profile
                                    </Label>
                                    <Select
                                        value={inventoryProfile}
                                        onValueChange={setInventoryProfile}
                                    >
                                        <SelectTrigger id="native-monitor-inventory-profile">
                                            <SelectValue placeholder="Select an approved profile" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {kind === 'ssh_inventory' ? (
                                                <SelectItem value="linux.basic">
                                                    Linux basic
                                                </SelectItem>
                                            ) : (
                                                <SelectItem value="windows.basic">
                                                    Windows basic
                                                </SelectItem>
                                            )}
                                        </SelectContent>
                                    </Select>
                                </div>
                            ) : null}
                            {kind === 'ssh_inventory' ? (
                                <div className="space-y-2">
                                    <Label htmlFor="native-monitor-host-key">
                                        Pinned SSH host-key fingerprint
                                    </Label>
                                    <Input
                                        id="native-monitor-host-key"
                                        value={hostKeyFingerprint}
                                        onChange={(event) =>
                                            setHostKeyFingerprint(
                                                event.target.value,
                                            )
                                        }
                                        placeholder="SHA256:…"
                                        required={!editing}
                                        autoComplete="off"
                                    />
                                </div>
                            ) : null}
                            <div className="space-y-1">
                                {[
                                    'target',
                                    'port',
                                    'dns_name',
                                    'dns_type',
                                    'expected_answers',
                                    'expected_status',
                                    'warn_days',
                                    'credential_reference',
                                    'inventory_profile',
                                    'host_key_fingerprint',
                                ].map((key) => (
                                    <div key={key}>{errorFor(key)}</div>
                                ))}
                            </div>
                        </fieldset>
                    ) : null}
                    {step === 3 ? (
                        <div className="grid gap-5 sm:grid-cols-2">
                            <ReviewCard
                                icon={Network}
                                title="Device and policy"
                                onEdit={() => setStep(1)}
                            >
                                <ReviewRow
                                    label="Device"
                                    value={`${monitor?.device.name ?? device?.name ?? 'Not selected'} (#${deviceId})`}
                                />
                                <ReviewRow
                                    label="Site"
                                    value={site?.name ?? 'Unavailable'}
                                />
                                <ReviewRow label="Name" value={name.trim()} />
                                <ReviewRow
                                    label="Policy"
                                    value={profile?.name ?? 'Not selected'}
                                />
                                <ReviewRow label="Check type" value={label} />
                                <ReviewRow
                                    label="Affects availability"
                                    value={affectsAvailability ? 'Yes' : 'No'}
                                />
                            </ReviewCard>
                            <ReviewCard
                                icon={Cable}
                                title="Connection settings"
                                onEdit={() => setStep(2)}
                            >
                                <ReviewRow
                                    label="Target"
                                    value={
                                        target.trim() ||
                                        (editing
                                            ? 'Keep hidden saved target'
                                            : 'Not entered')
                                    }
                                />
                                {needsPort ? (
                                    <ReviewRow
                                        label="Port"
                                        value={
                                            port ||
                                            (editing
                                                ? 'Keep saved value'
                                                : 'Adapter default')
                                        }
                                    />
                                ) : null}
                                {kind === 'dns' ? (
                                    <>
                                        <ReviewRow
                                            label="DNS name"
                                            value={
                                                dnsName ||
                                                (editing
                                                    ? 'Keep saved value'
                                                    : 'Not entered')
                                            }
                                        />
                                        <ReviewRow
                                            label="DNS answer type"
                                            value={
                                                dnsType ||
                                                (editing
                                                    ? 'Keep saved value'
                                                    : 'Not entered')
                                            }
                                        />
                                        <ReviewRow
                                            label="Expected answers"
                                            value={
                                                expectedAnswers ||
                                                (editing
                                                    ? 'Keep saved values'
                                                    : 'No expected-answer restriction')
                                            }
                                        />
                                    </>
                                ) : null}
                                {kind === 'http' ? (
                                    <ReviewRow
                                        label="HTTP status codes"
                                        value={
                                            expectedStatus ||
                                            (editing
                                                ? 'Keep saved values'
                                                : 'Not entered')
                                        }
                                    />
                                ) : null}
                                {kind === 'tls' ? (
                                    <ReviewRow
                                        label="Certificate warning days"
                                        value={
                                            warnDays ||
                                            (editing
                                                ? 'Keep saved value'
                                                : 'Not entered')
                                        }
                                    />
                                ) : null}
                                {needsCredential ? (
                                    <ReviewRow
                                        label="Approved Site credential"
                                        value={
                                            credentialReference.trim()
                                                ? 'Selected; reference kept private'
                                                : editing
                                                  ? 'Keep saved reference'
                                                  : 'Not selected'
                                        }
                                    />
                                ) : null}
                                {['ssh_inventory', 'winrm_inventory'].includes(
                                    kind,
                                ) ? (
                                    <ReviewRow
                                        label="Read-only inventory profile"
                                        value={
                                            inventoryProfile ||
                                            (editing
                                                ? 'Keep saved profile'
                                                : 'Not selected')
                                        }
                                    />
                                ) : null}
                                {kind === 'ssh_inventory' ? (
                                    <ReviewRow
                                        label="Pinned host identity"
                                        value={
                                            hostKeyFingerprint.trim()
                                                ? 'Entered; fingerprint kept private'
                                                : editing
                                                  ? 'Keep saved fingerprint'
                                                  : 'Not entered'
                                        }
                                    />
                                ) : null}
                            </ReviewCard>
                            <p className="text-sm text-muted-foreground sm:col-span-2">
                                Saving changes this configuration only. Check
                                observations and collection readiness before
                                treating the device as healthy.{' '}
                                {editing && !monitor.enabled
                                    ? 'This check stays paused.'
                                    : ''}
                            </p>
                        </div>
                    ) : null}
                </WizardStepPane>
            </WizardShell>
            {leave.confirmation}
        </>
    );
}

function NativeContextUnavailable({ onClose }: { onClose: () => void }) {
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) onClose();
            }}
        >
            <DialogContent
                style={{
                    maxWidth: 'min(92vw, 480px)',
                    width: 'min(92vw, 480px)',
                }}
            >
                <DialogHeader>
                    <DialogTitle>Refresh monitor context</DialogTitle>
                    <DialogDescription>
                        The current editor context is unavailable or belongs to
                        a different signed-in session. Reopen Monitoring with
                        your approved access.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Close
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
const reasonLabels: Record<string, string> = {
    replaced: 'Replaced by an approved definition',
    obsolete: 'Obsolete check',
    coverage_removed: 'Coverage intentionally removed',
    device_retired: 'Device retired',
};
export function NativeMonitorDeactivateDialog({
    open,
    onOpenChange,
    monitor,
    onDeactivated,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    monitor: NativeMonitorTarget | null;
    onDeactivated: () => void;
}) {
    const actor = useNativeActor();
    if (!open || !monitor) return null;
    if (actor.id <= 0 || !actor.manage || monitor.actions.actor_id !== actor.id)
        return <NativeContextUnavailable onClose={() => onOpenChange(false)} />;
    return (
        <NativeMonitorDeactivateBody
            key={JSON.stringify([actor.identity, monitor])}
            actor={actor}
            monitor={monitor}
            onClose={() => onOpenChange(false)}
            onDeactivated={onDeactivated}
        />
    );
}
function NativeMonitorDeactivateBody({
    actor,
    monitor,
    onClose,
    onDeactivated,
}: {
    actor: ReturnType<typeof useNativeActor>;
    monitor: NativeMonitorTarget;
    onClose: () => void;
    onDeactivated: () => void;
}) {
    const [reasonCode, setReasonCode] = useState(''),
        commit = useNativeMonitorCommit(),
        busy = commit.state === 'busy';
    const site = monitor.device.site,
        permitted =
            actor.manage &&
            monitor.actions.can_manage &&
            monitor.enabled &&
            monitor.actions.deactivate_url ===
                `/security-devices/monitoring/native-monitors/${monitor.id}/deactivate` &&
            site &&
            typeof monitor.actions.review_token === 'string' &&
            /^[0-9a-f]{64}$/.test(monitor.actions.review_token);
    const dirty =
        !commit.saved &&
        (Boolean(reasonCode) || busy || commit.state === 'uncertain');
    const leave = useSettingsLeaveConfirmation(
        dirty,
        'Discard this deactivation draft?',
        'Your reason choice will be discarded. A request already sent may still finish. Check the saved request before another change. Cancel to keep reviewing.',
    );
    const done = () => {
            if (commit.hadSave.current) {
                commit.hadSave.current = false;
                onDeactivated();
            }
            onClose();
        },
        close = () => {
            if (!busy) leave.request(done);
        };
    const save = () => {
        if (
            !permitted ||
            commit.state !== 'idle' ||
            !reasonLabels[reasonCode] ||
            !site
        )
            return;
        const uuid = crypto.randomUUID();
        const payload = {
            request_uuid: uuid,
            expected_actor_id: actor.id,
            expected_site_id: site.id,
            expected_device_id: monitor.device.id,
            review_token: monitor.actions.review_token,
            reason_code: reasonCode,
        };
        void commit.send({
            uuid,
            actor: actor.id,
            site: site.id,
            device: monitor.device.id,
            target: monitor.id,
            operation: 'deactivate',
            payload,
            expected: {
                name: monitor.name,
                kind: monitor.kind,
                profile: monitor.profile?.id,
                availability: monitor.affects_availability,
                enabled: false,
            },
        });
    };
    return (
        <>
            <Dialog
                open
                onOpenChange={(open) => {
                    if (!open) close();
                }}
            >
                <DialogContent
                    className="max-h-[90vh] overflow-x-hidden overflow-y-auto"
                    style={{
                        maxWidth: 'min(92vw, 480px)',
                        width: 'min(92vw, 480px)',
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {commit.saved
                                ? 'Monitor deactivation confirmed'
                                : `Deactivate ${monitor.name}`}
                        </DialogTitle>
                        <DialogDescription>
                            {commit.saved
                                ? 'The request confirms the saved scheduling configuration, not a device observation.'
                                : 'New scheduling stops after confirmation. A check already in progress may still finish. Remove or replace active dependencies first.'}
                        </DialogDescription>
                    </DialogHeader>
                    {commit.saved ? (
                        <div className="space-y-4">
                            <ReviewCard
                                icon={ShieldCheck}
                                title="Scheduling disabled"
                            >
                                <ReviewRow
                                    label="Monitor"
                                    value={`${commit.saved.monitor.name} (#${commit.saved.monitor.id})`}
                                />
                                <ReviewRow
                                    label="Device"
                                    value={`${monitor.device.name} (#${monitor.device.id})`}
                                />
                                <ReviewRow
                                    label="Reason"
                                    value={reasonLabels[reasonCode]}
                                />
                                <ReviewRow
                                    label="Saved request"
                                    value={
                                        commit.saved.receipt
                                            .current_matches_saved
                                            ? 'Confirmed; current configuration matches'
                                            : 'Confirmed; current configuration changed later'
                                    }
                                />
                            </ReviewCard>
                            <DialogFooter>
                                <Button onClick={done}>Done</Button>
                            </DialogFooter>
                        </div>
                    ) : (
                        <div className="space-y-4">
                            <ReviewCard
                                icon={Activity}
                                title="Reviewed monitor"
                            >
                                <ReviewRow
                                    label="Monitor"
                                    value={`${monitor.name} (#${monitor.id})`}
                                />
                                <ReviewRow
                                    label="Device"
                                    value={`${monitor.device.name} (#${monitor.device.id})`}
                                />
                                <ReviewRow
                                    label="Site"
                                    value={site?.name ?? 'Unavailable'}
                                />
                            </ReviewCard>
                            {!permitted ? (
                                <p
                                    role="alert"
                                    className="text-sm text-destructive"
                                >
                                    A current approved monitor review is
                                    required. Reopen Monitoring before
                                    deactivation.
                                </p>
                            ) : null}
                            <fieldset
                                disabled={commit.state !== 'idle' || !permitted}
                                className="space-y-2"
                            >
                                <Label htmlFor="native-monitor-deactivate-reason">
                                    Operational reason
                                </Label>
                                <Select
                                    value={reasonCode}
                                    onValueChange={(value) => {
                                        if (commit.state === 'idle')
                                            setReasonCode(value);
                                    }}
                                >
                                    <SelectTrigger id="native-monitor-deactivate-reason">
                                        <SelectValue placeholder="Select a safe audit reason" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {Object.entries(reasonLabels).map(
                                            ([value, label]) => (
                                                <SelectItem
                                                    key={value}
                                                    value={value}
                                                >
                                                    {label}
                                                </SelectItem>
                                            ),
                                        )}
                                    </SelectContent>
                                </Select>
                                <p className="text-xs text-muted-foreground">
                                    Only the approved reason code enters the
                                    audit. Targets and credentials stay private.
                                </p>
                                {commit.errors.reason_code ||
                                commit.errors.monitor ? (
                                    <p
                                        role="alert"
                                        className="text-sm text-destructive"
                                    >
                                        {commit.errors.reason_code ??
                                            commit.errors.monitor}
                                    </p>
                                ) : null}
                            </fieldset>
                            {dirty && !leave.historyProtectionAvailable ? (
                                <p
                                    role="note"
                                    className="text-sm text-muted-foreground"
                                >
                                    Use Cancel to leave safely. This browser
                                    cannot ask before Back or Forward.
                                </p>
                            ) : null}
                            {commit.notice ? (
                                <p role="alert" className="text-sm">
                                    {commit.notice}
                                </p>
                            ) : null}
                            {commit.state === 'uncertain' ? (
                                <Button
                                    variant="outline"
                                    onClick={() => void commit.recover()}
                                >
                                    Check saved request
                                </Button>
                            ) : null}
                            <DialogFooter>
                                <Button
                                    variant="outline"
                                    disabled={busy}
                                    onClick={close}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    variant="destructive"
                                    disabled={
                                        busy ||
                                        commit.state !== 'idle' ||
                                        !permitted ||
                                        !reasonLabels[reasonCode]
                                    }
                                    onClick={save}
                                >
                                    {busy ? (
                                        <Loader2 className="size-4 animate-spin" />
                                    ) : (
                                        <ShieldX className="size-4" />
                                    )}
                                    {busy
                                        ? 'Deactivating…'
                                        : 'Deactivate monitor'}
                                </Button>
                            </DialogFooter>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
            {leave.confirmation}
        </>
    );
}
