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
import axios from 'axios';
import { Check, Settings2, ShieldCheck } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

type Policy = {
    version: number;
    enabled: boolean;
    consent_mode: 'attended' | 'unattended';
};
export type ServicePolicyReply = {
    actor_id: number;
    device_id: number;
    policy: Policy;
    catalogue_digest: string;
    catalogue: {
        name: string;
        label: string;
        operations: ('start' | 'stop' | 'restart')[];
    }[];
    available: boolean;
    can_unattended: boolean;
};
type Scope = { actorId: number; device: { id: number; name: string } };
function object(value: unknown): Record<string, unknown> {
    if (!value || typeof value !== 'object' || Array.isArray(value))
        throw Error('Policy unavailable');
    return value as Record<string, unknown>;
}
export function servicePolicyReply(
    value: unknown,
    actor: number,
    device: number,
): ServicePolicyReply {
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
        typeof reply.can_unattended !== 'boolean' ||
        typeof reply.catalogue_digest !== 'string' ||
        !/^[a-f0-9]{64}$/.test(reply.catalogue_digest) ||
        !Array.isArray(reply.catalogue) ||
        reply.catalogue.length > 100
    )
        throw Error('Policy context changed');
    const names = new Set<string>();
    for (const item of reply.catalogue) {
        const entry = object(item);
        if (
            typeof entry.name !== 'string' ||
            !/^[A-Za-z0-9][A-Za-z0-9_. -]{0,199}$/.test(entry.name) ||
            entry.name !== entry.name.trim() ||
            names.has(entry.name.toLowerCase()) ||
            typeof entry.label !== 'string' ||
            !entry.label.trim() ||
            Array.from(entry.label).length > 200 ||
            /[\p{Cc}\p{Cf}]/u.test(entry.label) ||
            !Array.isArray(entry.operations) ||
            entry.operations.length < 1 ||
            entry.operations.length > 3 ||
            new Set(entry.operations).size !== entry.operations.length ||
            entry.operations.some(
                (operation) =>
                    typeof operation !== 'string' ||
                    !['start', 'stop', 'restart'].includes(operation),
            )
        )
            throw Error('Service catalogue unavailable');
        names.add(entry.name.toLowerCase());
    }
    return value as ServicePolicyReply;
}
const steps = [
    {
        key: 'access',
        label: 'Access',
        blurb: 'Consent for service actions',
        icon: ShieldCheck,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Check the policy change',
        icon: Check,
    },
];
export function ServiceActionPolicyEditor(props: Scope) {
    const [open, setOpen] = useState(false);
    return (
        <>
            <Button variant="outline" onClick={() => setOpen(true)}>
                <Settings2 aria-hidden="true" />
                Service action policy
            </Button>
            {open && (
                <ServiceActionPolicyForm
                    {...props}
                    onClose={() => setOpen(false)}
                />
            )}
        </>
    );
}
function ServiceActionPolicyForm({
    actorId,
    device,
    onClose,
}: Scope & { onClose: () => void }) {
    const [saved, setSaved] = useState<ServicePolicyReply | null>(null);
    const [draft, setDraft] = useState<Policy | null>(null);
    const [reason, setReason] = useState('');
    const [step, setStep] = useState(0);
    const [busy, setBusy] = useState(false);
    const [uncertain, setUncertain] = useState(false);
    const [success, setSuccess] = useState(false);
    const [notice, setNotice] = useState('Loading service action policy…');
    const active = useRef<AbortController | null>(null);
    const url = '/it/remote-support/devices/' + device.id + '/action-policy';
    const dirty =
        !success &&
        draft !== null &&
        (draft.enabled !== saved?.policy.enabled ||
            draft.consent_mode !== saved?.policy.consent_mode ||
            reason !== '');
    const leave = useSettingsLeaveConfirmation(dirty);
    const load = useCallback(async () => {
        active.current?.abort();
        const controller = new AbortController();
        active.current = controller;
        setBusy(true);
        setUncertain(true);
        setNotice('Loading saved service action policy…');
        try {
            const response = await axios.get<unknown>(url, {
                params: { expected_actor_id: actorId },
                signal: controller.signal,
                timeout: 15000,
                headers: { Accept: 'application/json' },
            });
            const data = servicePolicyReply(response.data, actorId, device.id);
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
            reason.trim().length < 10 ||
            reason.trim().length > 500 ||
            /[\p{Cc}\p{Cf}]/u.test(reason)
        ) {
            setNotice(
                'Give a reason of 10–500 characters for this policy change.',
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
                'Your permissions do not allow unattended service actions. Choose staff consent or disable actions.',
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
                    expected_catalogue_digest: before.catalogue_digest,
                    enabled: draft.enabled,
                    consent_mode: draft.consent_mode,
                    reason: reason.trim(),
                },
                {
                    signal: controller.signal,
                    timeout: 15000,
                    headers: { Accept: 'application/json' },
                },
            );
            const data = servicePolicyReply(response.data, actorId, device.id);
            if (
                data.policy.version !== before.policy.version + 1 ||
                data.catalogue_digest !== before.catalogue_digest ||
                data.policy.enabled !== draft.enabled ||
                data.policy.consent_mode !== draft.consent_mode
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
            ? 'Authorised unattended actions'
            : 'Staff allow each action on the computer';
    return (
        <>
            <WizardShell
                open
                onClose={() => {
                    if (!busy) leave.request(onClose);
                }}
                title="Service action policy"
                description={
                    'Configure approved Windows service actions for ' +
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
                            disabled={busy || !draft}
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
                            title="Service action policy saved"
                            blurb="Future requests must follow this policy and pass the current permissions and approval checks. Saving a policy does not run an action."
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
                                        Service actions are not available in
                                        this deployment yet. You can prepare the
                                        consent policy here.
                                    </p>
                                )}
                                <div className="flex items-center justify-between gap-4">
                                    <Label htmlFor="service-actions-enabled">
                                        Allow approved service actions
                                    </Label>
                                    <Switch
                                        id="service-actions-enabled"
                                        checked={draft.enabled}
                                        disabled={busy || uncertain}
                                        onCheckedChange={(enabled) =>
                                            setDraft({ ...draft, enabled })
                                        }
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="service-action-consent">
                                        Staff consent
                                    </Label>
                                    <Select
                                        value={draft.consent_mode}
                                        disabled={busy || uncertain}
                                        onValueChange={(value) =>
                                            setDraft({
                                                ...draft,
                                                consent_mode:
                                                    value as Policy['consent_mode'],
                                            })
                                        }
                                    >
                                        <SelectTrigger id="service-action-consent">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="attended">
                                                Ask staff for each action
                                            </SelectItem>
                                            <SelectItem
                                                value="unattended"
                                                disabled={!saved.can_unattended}
                                            >
                                                Authorised unattended actions
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <p className="text-subtle">
                                        {draft.consent_mode === 'attended'
                                            ? 'The Windows helper shows the technician, reason, service and exact action before staff choose Allow or Decline.'
                                            : 'Approved actions can run without a staff prompt. Technicians need the separate unattended permission.'}
                                    </p>
                                </div>
                                <div className="space-y-2">
                                    <h3 className="text-section-title">
                                        Approved services
                                    </h3>
                                    {saved.catalogue.length ? (
                                        <ul className="divide-y rounded-lg border">
                                            {saved.catalogue.map((item) => (
                                                <li
                                                    className="space-y-1 p-3"
                                                    key={item.name}
                                                >
                                                    <p className="font-medium">
                                                        {item.label}
                                                    </p>
                                                    <p className="text-subtle">
                                                        {item.name} ·{' '}
                                                        {item.operations.join(
                                                            ', ',
                                                        )}
                                                    </p>
                                                </li>
                                            ))}
                                        </ul>
                                    ) : (
                                        <p className="text-subtle">
                                            No services have been approved for
                                            this deployment. Actions remain
                                            unavailable until services are
                                            configured.
                                        </p>
                                    )}
                                    <p className="text-subtle">
                                        Each action uses the device’s existing
                                        request, independent approval and audit
                                        history. Screen access is configured
                                        separately.
                                    </p>
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="service-action-reason">
                                        Reason for this change
                                    </Label>
                                    <Textarea
                                        id="service-action-reason"
                                        value={reason}
                                        maxLength={500}
                                        disabled={busy || uncertain}
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
                                title="Service action policy"
                                onEdit={() => setStep(0)}
                            >
                                <ReviewRow
                                    label="Computer"
                                    value={device.name}
                                />
                                <ReviewRow
                                    label="Service actions"
                                    value={
                                        draft.enabled
                                            ? 'Allowed, subject to approval'
                                            : 'Disabled'
                                    }
                                />
                                <ReviewRow label="Consent" value={consent} />
                                <ReviewRow
                                    label="Approved services"
                                    value={
                                        saved.catalogue
                                            .map(
                                                (entry) =>
                                                    entry.label +
                                                    ' (' +
                                                    entry.operations.join(
                                                        ', ',
                                                    ) +
                                                    ')',
                                            )
                                            .join('; ') || 'None configured'
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
