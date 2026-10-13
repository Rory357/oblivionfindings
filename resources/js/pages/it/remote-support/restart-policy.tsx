import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import axios from 'axios';
import { Check, Settings2, ShieldCheck } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

type Policy = {
    version: number;
    enabled: boolean;
    consent_mode: 'attended' | 'unattended';
    minimum_warning_seconds: number;
};
export type RestartPolicyReply = {
    actor_id: number;
    device_id: number;
    policy: Policy;
    available: boolean;
    can_manage: boolean;
    can_unattended: boolean;
};
type Scope = { actorId: number; device: { id: number; name: string } };
function object(value: unknown): Record<string, unknown> {
    if (!value || typeof value !== 'object' || Array.isArray(value))
        throw Error('Policy unavailable');
    return value as Record<string, unknown>;
}
export function restartPolicyReply(
    value: unknown,
    actor: number,
    device: number,
): RestartPolicyReply {
    const reply = object(value),
        policy = object(reply.policy);
    if (
        reply.actor_id !== actor ||
        reply.device_id !== device ||
        !Number.isSafeInteger(policy.version) ||
        (policy.version as number) < 0 ||
        typeof policy.enabled !== 'boolean' ||
        typeof policy.consent_mode !== 'string' ||
        !['attended', 'unattended'].includes(policy.consent_mode) ||
        typeof reply.available !== 'boolean' ||
        typeof reply.can_manage !== 'boolean' ||
        typeof reply.can_unattended !== 'boolean' ||
        !Number.isSafeInteger(policy.minimum_warning_seconds) ||
        (policy.minimum_warning_seconds as number) < 60 ||
        (policy.minimum_warning_seconds as number) > 3600
    )
        throw Error('Restart policy context changed');
    return value as RestartPolicyReply;
}
const steps = [
    {
        key: 'access',
        label: 'Access',
        blurb: 'Consent and warning time',
        icon: ShieldCheck,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Check the policy change',
        icon: Check,
    },
];
export function RestartPolicyEditor(props: Scope) {
    const [open, setOpen] = useState(false);
    return (
        <>
            <Button variant="outline" onClick={() => setOpen(true)}>
                <Settings2 aria-hidden="true" />
                Restart policy
            </Button>
            {open && (
                <RestartPolicyForm {...props} onClose={() => setOpen(false)} />
            )}
        </>
    );
}
function RestartPolicyForm({
    actorId,
    device,
    onClose,
}: Scope & { onClose: () => void }) {
    const [saved, setSaved] = useState<RestartPolicyReply | null>(null);
    const [draft, setDraft] = useState<Policy | null>(null);
    const [reason, setReason] = useState('');
    const [step, setStep] = useState(0);
    const [busy, setBusy] = useState(false);
    const [uncertain, setUncertain] = useState(false);
    const [success, setSuccess] = useState(false);
    const [notice, setNotice] = useState('Loading restart policy…');
    const active = useRef<AbortController | null>(null);
    const url =
        '/security-devices/devices/' + device.id + '/endpoint/restart-policy';
    const dirty =
        !success &&
        draft !== null &&
        (draft.enabled !== saved?.policy.enabled ||
            draft.consent_mode !== saved?.policy.consent_mode ||
            draft.minimum_warning_seconds !==
                saved?.policy.minimum_warning_seconds ||
            reason !== '');
    const leave = useSettingsLeaveConfirmation(dirty);
    const load = useCallback(async () => {
        active.current?.abort();
        const controller = new AbortController();
        active.current = controller;
        setBusy(true);
        setUncertain(true);
        setNotice('Loading saved restart policy…');
        try {
            const response = await axios.get<unknown>(url, {
                params: { expected_actor_id: actorId },
                signal: controller.signal,
                timeout: 15000,
                headers: { Accept: 'application/json' },
            });
            const data = restartPolicyReply(response.data, actorId, device.id);
            if (controller.signal.aborted) return;
            setSaved(data);
            setDraft(data.policy);
            setReason('');
            setStep(0);
            setUncertain(false);
            setNotice('');
        } catch {
            if (!controller.signal.aborted)
                setNotice(
                    'The policy could not be verified. Reload it before making changes.',
                );
        } finally {
            if (!controller.signal.aborted) setBusy(false);
        }
    }, [actorId, device.id, url]);
    useEffect(() => {
        void load();
        return () => active.current?.abort();
    }, [load]);
    function valid() {
        if (
            !draft ||
            !saved ||
            !saved.can_manage ||
            !Number.isSafeInteger(draft.minimum_warning_seconds) ||
            draft.minimum_warning_seconds < 60 ||
            draft.minimum_warning_seconds > 3600 ||
            Array.from(reason.trim()).length < 10 ||
            Array.from(reason.trim()).length > 500 ||
            /[\p{Cc}\p{Cf}]/u.test(reason)
        ) {
            setNotice(
                'Choose a warning of 60–3600 seconds and give a reason of 10–500 characters. You need permission to manage restart policy.',
            );
            setStep(0);
            return false;
        }
        if (
            draft.enabled &&
            draft.consent_mode === 'unattended' &&
            !saved.can_unattended
        ) {
            setNotice(
                'Your permissions do not allow unattended scheduled restarts. Choose staff consent or disable actions.',
            );
            setStep(0);
            return false;
        }
        setNotice('');
        return true;
    }
    async function save() {
        if (busy || uncertain || !saved || !draft || !valid()) return;
        const before = saved;
        const controller = new AbortController();
        active.current = controller;
        setBusy(true);
        try {
            const response = await axios.put<unknown>(
                url,
                {
                    expected_actor_id: actorId,
                    expected_version: before.policy.version,
                    enabled: draft.enabled,
                    consent_mode: draft.consent_mode,
                    minimum_warning_seconds: draft.minimum_warning_seconds,
                    reason: reason.trim(),
                },
                {
                    signal: controller.signal,
                    timeout: 15000,
                    headers: { Accept: 'application/json' },
                },
            );
            const data = restartPolicyReply(response.data, actorId, device.id);
            if (
                data.policy.version !== before.policy.version + 1 ||
                data.policy.enabled !== draft.enabled ||
                data.policy.consent_mode !== draft.consent_mode ||
                data.policy.minimum_warning_seconds !==
                    draft.minimum_warning_seconds
            )
                throw Error('Policy changed');
            if (controller.signal.aborted) return;
            setSaved(data);
            setDraft(data.policy);
            setSuccess(true);
            setReason('');
        } catch {
            if (!controller.signal.aborted) {
                setUncertain(true);
                setNotice(
                    'The save could not be confirmed. Reload the saved policy before making another change.',
                );
            }
        } finally {
            if (!controller.signal.aborted) setBusy(false);
        }
    }
    const consent =
        draft?.consent_mode === 'unattended'
            ? 'Authorised unattended restarts'
            : 'Staff allow each scheduled restart';
    return (
        <>
            <WizardShell
                open
                onClose={() => {
                    if (!busy) leave.request(onClose);
                }}
                title="Restart policy"
                description={
                    'Configure scheduled Windows restarts for ' +
                    device.name +
                    '.'
                }
                railIcon={ShieldCheck}
                railTitle={device.name}
                railSub="Computer management"
                steps={steps}
                stepIndex={step}
                pct={draft && reason.trim().length >= 10 ? 100 : 50}
                onStepClick={(index) => {
                    if (
                        !busy &&
                        !uncertain &&
                        draft &&
                        (index === 0 || valid())
                    )
                        setStep(index);
                }}
                footerStart={
                    <Button
                        variant="outline"
                        disabled={busy}
                        onClick={() => leave.request(onClose)}
                    >
                        Cancel
                    </Button>
                }
                footerEnd={
                    uncertain ? (
                        <Button disabled={busy} onClick={() => void load()}>
                            Reload saved policy
                        </Button>
                    ) : step === 0 ? (
                        <Button
                            disabled={busy || !draft || !saved?.can_manage}
                            onClick={() => {
                                if (valid()) setStep(1);
                            }}
                        >
                            Continue
                        </Button>
                    ) : (
                        <Button disabled={busy} onClick={() => void save()}>
                            {busy ? 'Saving…' : 'Save policy'}
                        </Button>
                    )
                }
                success={
                    success ? (
                        <WizardSuccessPane
                            title="Restart policy saved"
                            blurb="Future requests must follow this policy and pass the current permissions and approval checks. Saving a policy does not restart the computer."
                            actions={<Button onClick={onClose}>Done</Button>}
                        />
                    ) : undefined
                }
            >
                <div className="space-y-5">
                    {notice && (
                        <Alert>
                            <AlertDescription role="status">
                                {notice}
                            </AlertDescription>
                        </Alert>
                    )}
                    <WizardStepPane key={step}>
                        {draft && saved && step === 0 && (
                            <div className="space-y-5">
                                {!saved.available && (
                                    <p className="text-subtle">
                                        Scheduled restarts are not available in
                                        this deployment yet. You can prepare the
                                        consent policy here.
                                    </p>
                                )}
                                <div className="flex items-center justify-between gap-4">
                                    <Label htmlFor="restart-policy-enabled">
                                        Allow approved scheduled restarts
                                    </Label>
                                    <Switch
                                        id="restart-policy-enabled"
                                        checked={draft.enabled}
                                        disabled={
                                            busy ||
                                            uncertain ||
                                            !saved.can_manage
                                        }
                                        onCheckedChange={(enabled) =>
                                            setDraft({ ...draft, enabled })
                                        }
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="restart-policy-consent">
                                        Staff consent
                                    </Label>
                                    <Select
                                        value={draft.consent_mode}
                                        disabled={
                                            busy ||
                                            uncertain ||
                                            !saved.can_manage
                                        }
                                        onValueChange={(value) =>
                                            setDraft({
                                                ...draft,
                                                consent_mode:
                                                    value as Policy['consent_mode'],
                                            })
                                        }
                                    >
                                        <SelectTrigger id="restart-policy-consent">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="attended">
                                                Ask staff for each restart
                                            </SelectItem>
                                            <SelectItem
                                                value="unattended"
                                                disabled={!saved.can_unattended}
                                            >
                                                Authorised unattended restarts
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <p className="text-subtle">
                                        {draft.consent_mode === 'attended'
                                            ? 'Obli shows the technician, reason and scheduled time. Staff must allow the restart and keep the warning visible.'
                                            : 'Approved restarts can run without staff approval. Technicians need the separate unattended permission. Obli shows a warning when a staff session is available.'}
                                    </p>
                                </div>
                                {!saved.can_manage && (
                                    <p className="text-subtle">
                                        You can read this policy, but your
                                        current permissions do not allow
                                        changes.
                                    </p>
                                )}
                                <div className="space-y-2">
                                    <Label htmlFor="restart-warning">
                                        Minimum visible warning (seconds)
                                    </Label>
                                    <Input
                                        id="restart-warning"
                                        type="number"
                                        min={60}
                                        max={3600}
                                        step={1}
                                        value={draft.minimum_warning_seconds}
                                        disabled={
                                            busy ||
                                            uncertain ||
                                            !saved.can_manage
                                        }
                                        onChange={(event) =>
                                            setDraft({
                                                ...draft,
                                                minimum_warning_seconds: Number(
                                                    event.target.value,
                                                ),
                                            })
                                        }
                                    />
                                    <p className="text-subtle">
                                        Between 1 and 60 minutes. Staff consent
                                        requires an uninterrupted visible
                                        warning before the scheduled restart.
                                    </p>
                                </div>
                                <p className="text-subtle">
                                    Each restart uses the computer's existing
                                    request, independent approval and audit
                                    history. To postpone it, cancel the original
                                    request and arrange a newly approved time.
                                    Screen access is configured separately.
                                </p>
                                <div className="space-y-2">
                                    <Label htmlFor="restart-policy-reason">
                                        Reason for this change
                                    </Label>
                                    <Textarea
                                        id="restart-policy-reason"
                                        value={reason}
                                        maxLength={500}
                                        disabled={
                                            busy ||
                                            uncertain ||
                                            !saved.can_manage
                                        }
                                        onChange={(event) =>
                                            setReason(event.target.value)
                                        }
                                    />
                                </div>
                            </div>
                        )}
                        {draft && saved && step === 1 && (
                            <ReviewCard
                                icon={ShieldCheck}
                                title="Restart policy"
                                onEdit={() => setStep(0)}
                            >
                                <ReviewRow
                                    label="Computer"
                                    value={device.name}
                                />
                                <ReviewRow
                                    label="Scheduled restarts"
                                    value={
                                        draft.enabled
                                            ? 'Allowed, subject to approval'
                                            : 'Disabled'
                                    }
                                />
                                <ReviewRow label="Consent" value={consent} />
                                <ReviewRow
                                    label="Minimum warning"
                                    value={
                                        draft.minimum_warning_seconds +
                                        ' seconds'
                                    }
                                />
                                <ReviewRow
                                    label="Reason"
                                    value={reason.trim()}
                                />
                            </ReviewCard>
                        )}
                    </WizardStepPane>
                </div>
            </WizardShell>
            {leave.confirmation}
        </>
    );
}
