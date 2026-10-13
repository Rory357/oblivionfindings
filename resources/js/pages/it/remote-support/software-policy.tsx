import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
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
import { safeSoftwareText } from '@/pages/security-devices/software/_contracts';
import axios from 'axios';
import { Check, Settings2, ShieldCheck } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

type Policy = {
    version: number;
    enabled: boolean;
    consent_mode: 'attended' | 'unattended';
};
export type SoftwarePolicyReply = {
    actor_id: number;
    device_id: number;
    policy: Policy;
    available: boolean;
    can_manage: boolean;
    can_unattended: boolean;
};
export function softwarePolicyReply(
    value: unknown,
    actor: number,
    device: number,
): SoftwarePolicyReply {
    if (!value || typeof value !== 'object' || Array.isArray(value))
        throw Error('Software policy unavailable');
    const reply = value as SoftwarePolicyReply,
        policy = reply.policy;
    if (
        reply.actor_id !== actor ||
        reply.device_id !== device ||
        !policy ||
        !Number.isSafeInteger(policy.version) ||
        policy.version < 0 ||
        typeof policy.enabled !== 'boolean' ||
        !['attended', 'unattended'].includes(policy.consent_mode) ||
        [reply.available, reply.can_manage, reply.can_unattended].some(
            (flag) => typeof flag !== 'boolean',
        )
    )
        throw Error('Software policy context changed');
    return reply;
}
const steps = [
    {
        key: 'access',
        label: 'Access',
        blurb: 'Permission for software installation',
        icon: ShieldCheck,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Check this computer’s policy',
        icon: Check,
    },
];
type Scope = { actorId: number; device: { id: number; name: string } };
export function SoftwarePolicyEditor(props: Scope) {
    const [open, setOpen] = useState(false);
    return (
        <>
            <Button variant="outline" onClick={() => setOpen(true)}>
                <Settings2 aria-hidden="true" />
                Software policy
            </Button>
            {open && (
                <SoftwarePolicyForm {...props} onClose={() => setOpen(false)} />
            )}
        </>
    );
}
function SoftwarePolicyForm({
    actorId,
    device,
    onClose,
}: Scope & { onClose: () => void }) {
    const [saved, setSaved] = useState<SoftwarePolicyReply | null>(null),
        [draft, setDraft] = useState<Policy | null>(null);
    const [reason, setReason] = useState(''),
        [step, setStep] = useState(0),
        [busy, setBusy] = useState(false);
    const [uncertain, setUncertain] = useState(true),
        [success, setSuccess] = useState(false),
        [notice, setNotice] = useState('Loading software policy…');
    const active = useRef<AbortController | null>(null),
        url =
            '/security-devices/devices/' +
            device.id +
            '/endpoint/software-policy';
    const dirty =
        !success &&
        !!draft &&
        (draft.enabled !== saved?.policy.enabled ||
            draft.consent_mode !== saved?.policy.consent_mode ||
            reason !== '');
    const leave = useSettingsLeaveConfirmation(dirty);
    const load = useCallback(async () => {
        active.current?.abort();
        const call = new AbortController();
        active.current = call;
        setBusy(true);
        setUncertain(true);
        setNotice('Loading saved software policy…');
        try {
            const response = await axios.get<unknown>(url, {
                params: { expected_actor_id: actorId },
                signal: call.signal,
                timeout: 15000,
                headers: { Accept: 'application/json' },
            });
            const next = softwarePolicyReply(response.data, actorId, device.id);
            if (call.signal.aborted) return;
            setSaved(next);
            setDraft(next.policy);
            setReason('');
            setStep(0);
            setUncertain(false);
            setNotice('');
        } catch {
            if (!call.signal.aborted)
                setNotice(
                    'The policy could not be verified. Reload it before making changes.',
                );
        } finally {
            if (!call.signal.aborted) setBusy(false);
        }
    }, [actorId, device.id, url]);
    useEffect(() => {
        void load();
        return () => active.current?.abort();
    }, [load]);
    function valid() {
        if (
            !draft ||
            !saved?.can_manage ||
            !safeSoftwareText(reason.trim(), 500, 10)
        ) {
            setNotice(
                'Give a reason of 10–500 characters. You need permission to manage software policy.',
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
                'Your permissions do not allow unattended installation. Require staff consent or disable installation.',
            );
            setStep(0);
            return false;
        }
        setNotice('');
        return true;
    }
    async function save() {
        if (busy || uncertain || !saved || !draft || !valid()) return;
        const before = saved,
            reviewed = draft,
            call = new AbortController();
        active.current = call;
        setBusy(true);
        try {
            const response = await axios.put<unknown>(
                url,
                {
                    expected_actor_id: actorId,
                    expected_version: before.policy.version,
                    enabled: reviewed.enabled,
                    consent_mode: reviewed.consent_mode,
                    reason: reason.trim(),
                },
                {
                    signal: call.signal,
                    timeout: 15000,
                    headers: { Accept: 'application/json' },
                },
            );
            const next = softwarePolicyReply(response.data, actorId, device.id);
            if (
                next.policy.version !== before.policy.version + 1 ||
                next.policy.enabled !== reviewed.enabled ||
                next.policy.consent_mode !== reviewed.consent_mode
            )
                throw Error('Policy changed');
            if (call.signal.aborted) return;
            setSaved(next);
            setDraft(next.policy);
            setReason('');
            setSuccess(true);
        } catch {
            if (!call.signal.aborted) {
                setUncertain(true);
                setNotice(
                    'The save could not be confirmed. Reload the saved policy before making another change.',
                );
            }
        } finally {
            if (!call.signal.aborted) setBusy(false);
        }
    }
    const consent =
        draft?.consent_mode === 'unattended'
            ? 'Authorised unattended installation'
            : 'Staff allow each installation on the computer';
    return (
        <>
            <WizardShell
                open
                title="Software policy"
                description={
                    'Configure approved software installation for ' +
                    device.name +
                    '.'
                }
                onClose={() => {
                    if (!busy) leave.request(onClose);
                }}
                railIcon={ShieldCheck}
                railTitle={device.name}
                railSub="Computer management"
                steps={steps}
                stepIndex={step}
                pct={
                    draft && safeSoftwareText(reason.trim(), 500, 10) ? 100 : 50
                }
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
                    step === 0 ? (
                        <Button
                            disabled={busy || uncertain || !saved?.can_manage}
                            onClick={() => {
                                if (valid()) setStep(1);
                            }}
                        >
                            Review policy
                        </Button>
                    ) : (
                        <Button
                            disabled={busy || uncertain || !saved?.can_manage}
                            onClick={() => void save()}
                        >
                            {busy ? 'Saving…' : 'Save policy'}
                        </Button>
                    )
                }
                success={
                    success ? (
                        <WizardSuccessPane
                            title="Software policy saved"
                            blurb={
                                'The saved policy for ' +
                                device.name +
                                ' has been confirmed.'
                            }
                            actions={<Button onClick={onClose}>Done</Button>}
                        />
                    ) : undefined
                }
            >
                <div className="space-y-5">
                    <WizardStepPane>
                        {notice && (
                            <Alert>
                                <AlertDescription>{notice}</AlertDescription>
                            </Alert>
                        )}
                        {uncertain && (
                            <Button
                                variant="outline"
                                disabled={busy}
                                onClick={() => void load()}
                            >
                                {busy ? 'Loading…' : 'Reload saved policy'}
                            </Button>
                        )}
                        {saved && !saved.available && (
                            <p className="text-subtle">
                                Software deployment is not available yet. You
                                can prepare the policy for this computer.
                            </p>
                        )}
                        {draft && saved && step === 0 && (
                            <div className="space-y-5">
                                <div className="flex items-center justify-between gap-4">
                                    <div>
                                        <Label htmlFor="software-enabled">
                                            Allow approved software installation
                                        </Label>
                                        <p className="text-subtle">
                                            Each installation still needs its
                                            own reviewed request.
                                        </p>
                                    </div>
                                    <Switch
                                        id="software-enabled"
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
                                    <Label htmlFor="software-consent">
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
                                        <SelectTrigger id="software-consent">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="attended">
                                                Staff allow each installation
                                            </SelectItem>
                                            <SelectItem
                                                value="unattended"
                                                disabled={!saved.can_unattended}
                                            >
                                                Authorised unattended
                                                installation
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <p className="text-subtle">
                                        Only independently reviewed packages are
                                        eligible. Installation does not grant
                                        screen access, and any required restart
                                        is arranged separately.
                                    </p>
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="software-policy-reason">
                                        Reason for this change
                                    </Label>
                                    <Textarea
                                        id="software-policy-reason"
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
                        {draft && step === 1 && (
                            <ReviewCard
                                icon={ShieldCheck}
                                title="Software policy"
                                onEdit={() => setStep(0)}
                            >
                                <ReviewRow
                                    label="Computer"
                                    value={device.name}
                                />
                                <ReviewRow
                                    label="Installation"
                                    value={
                                        draft.enabled
                                            ? 'Allowed, subject to approval'
                                            : 'Disabled'
                                    }
                                />
                                <ReviewRow label="Consent" value={consent} />
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
