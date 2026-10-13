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
import { useEffect, useRef, useState } from 'react';

type Policy = {
    version: number;
    enabled: boolean;
    consent_mode: 'attended' | 'unattended';
    allow_control: boolean;
    max_minutes: number;
};
type Reply = { actor_id: number; device_id: number; policy: Policy };
const steps = [
    {
        key: 'access',
        label: 'Access',
        blurb: 'Consent and permissions',
        icon: ShieldCheck,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm the change',
        icon: Check,
    },
];

export function RemotePolicyEditor({
    actorId,
    device,
}: {
    actorId: number;
    device: { id: number; name: string };
}) {
    const [open, setOpen] = useState(false),
        [step, setStep] = useState(0),
        [busy, setBusy] = useState(false);
    const [saved, setSaved] = useState<Policy | null>(null),
        [draft, setDraft] = useState<Policy | null>(null);
    const [reason, setReason] = useState(''),
        [notice, setNotice] = useState(''),
        [uncertain, setUncertain] = useState(false),
        [success, setSuccess] = useState(false);
    const attempt = useRef<AbortController | null>(null);
    const dirty =
        open &&
        !success &&
        draft !== null &&
        (JSON.stringify(draft) !== JSON.stringify(saved) || reason !== '');
    const leave = useSettingsLeaveConfirmation(dirty);
    useEffect(() => () => attempt.current?.abort(), []);
    const url = '/it/remote-support/devices/' + device.id + '/policy';
    function update<K extends keyof Policy>(key: K, value: Policy[K]) {
        setDraft((current) =>
            current ? { ...current, [key]: value } : current,
        );
    }
    function close() {
        if (!busy) leave.request(() => setOpen(false));
    }
    function valid() {
        if (
            !draft ||
            !Number.isInteger(draft.max_minutes) ||
            draft.max_minutes < 5 ||
            draft.max_minutes > 120 ||
            reason.trim().length < 10 ||
            reason.trim().length > 500 ||
            /[\p{Cc}\p{Cf}]/u.test(reason)
        ) {
            setNotice(
                'Set a session limit from 5 to 120 minutes and give a reason of 10–500 characters.',
            );
            setStep(0);
            return false;
        }
        setNotice('');
        return true;
    }
    async function load() {
        if (busy) return;
        setBusy(true);
        setOpen(true);
        setNotice('Loading saved access policy…');
        setSuccess(false);
        const controller = new AbortController();
        attempt.current = controller;
        try {
            const { data } = await axios.get<Reply>(url, {
                signal: controller.signal,
                timeout: 20000,
                headers: { Accept: 'application/json' },
            });
            if (data.actor_id !== actorId || data.device_id !== device.id)
                throw Error('Context changed');
            if (controller.signal.aborted) return;
            setSaved(data.policy);
            setDraft(data.policy);
            setReason('');
            setStep(0);
            setUncertain(false);
            setNotice('');
        } catch {
            if (!controller.signal.aborted) {
                setUncertain(true);
                setNotice(
                    'The saved policy could not be verified. Reload it before making changes.',
                );
            }
        } finally {
            if (!controller.signal.aborted) setBusy(false);
        }
    }
    async function save() {
        if (busy || uncertain || !saved || !draft || !valid()) return;
        setBusy(true);
        const controller = new AbortController();
        attempt.current = controller;
        try {
            const { data } = await axios.put<Reply>(
                url,
                {
                    expected_actor_id: actorId,
                    expected_version: saved.version,
                    enabled: draft.enabled,
                    consent_mode: draft.consent_mode,
                    allow_control: draft.allow_control,
                    max_minutes: draft.max_minutes,
                    reason: reason.trim(),
                },
                {
                    signal: controller.signal,
                    timeout: 20000,
                    headers: { Accept: 'application/json' },
                },
            );
            if (
                data.actor_id !== actorId ||
                data.device_id !== device.id ||
                data.policy.version !== saved.version + 1
            )
                throw Error('Context changed');
            if (controller.signal.aborted) return;
            setSaved(data.policy);
            setDraft(data.policy);
            setReason('');
            setSuccess(true);
        } catch {
            if (!controller.signal.aborted) {
                setUncertain(true);
                setNotice(
                    'The save could not be confirmed. Reload the saved policy before trying again.',
                );
            }
        } finally {
            if (!controller.signal.aborted) setBusy(false);
        }
    }
    return (
        <>
            <Button
                variant="outline"
                onClick={() => void load()}
                disabled={busy}
            >
                <Settings2 aria-hidden="true" />
                Remote access policy
            </Button>
            <WizardShell
                open={open}
                onClose={close}
                title="Remote access policy"
                description={
                    'Configure remote support for ' + device.name + '.'
                }
                railIcon={ShieldCheck}
                railTitle={device.name}
                railSub="Computer access"
                steps={steps}
                stepIndex={step}
                onStepClick={(index) => {
                    if (!busy && !uncertain && draft) {
                        if (index === 0 || valid()) setStep(index);
                    }
                }}
                pct={draft && reason.trim().length >= 10 ? 100 : 50}
                footerStart={
                    <Button variant="outline" onClick={close} disabled={busy}>
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
                            title="Remote access policy saved"
                            blurb="Open requests and sessions for this computer have been invalidated. This does not start a connection."
                            actions={
                                <Button onClick={() => setOpen(false)}>
                                    Done
                                </Button>
                            }
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
                        {draft && step === 0 && (
                            <div className="space-y-5">
                                <p className="text-subtle text-muted-foreground">
                                    The Windows connection service is still
                                    being built. You can prepare this policy
                                    now; saving it does not make screen control
                                    available.
                                </p>
                                <div className="flex items-center justify-between gap-4">
                                    <Label htmlFor="remote-enabled">
                                        Allow remote support on this computer
                                    </Label>
                                    <Switch
                                        id="remote-enabled"
                                        checked={draft.enabled}
                                        onCheckedChange={(value) =>
                                            update('enabled', value)
                                        }
                                        disabled={busy || uncertain}
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="remote-consent">
                                        Connection approval
                                    </Label>
                                    <Select
                                        value={draft.consent_mode}
                                        onValueChange={(value) =>
                                            update(
                                                'consent_mode',
                                                value as Policy['consent_mode'],
                                            )
                                        }
                                        disabled={busy || uncertain}
                                    >
                                        <SelectTrigger id="remote-consent">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="attended">
                                                User must allow each connection
                                            </SelectItem>
                                            <SelectItem value="unattended">
                                                Approved unattended equipment
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <p className="text-caption text-muted-foreground">
                                        {draft.consent_mode === 'attended'
                                            ? 'Show the technician and requested access, with Allow and Decline.'
                                            : 'Only separately authorised technicians may connect without a user approving. Reserve this for explicitly approved servers or shared equipment.'}
                                    </p>
                                </div>
                                <div className="space-y-2">
                                    <div className="flex items-center justify-between gap-4">
                                        <Label htmlFor="remote-control">
                                            Permit mouse and keyboard control
                                        </Label>
                                        <Switch
                                            id="remote-control"
                                            checked={draft.allow_control}
                                            onCheckedChange={(value) =>
                                                update('allow_control', value)
                                            }
                                            disabled={busy || uncertain}
                                        />
                                    </div>
                                    <p className="text-caption text-muted-foreground">
                                        Technicians also need the separate
                                        control permission. Viewing does not
                                        grant control.
                                    </p>
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="remote-duration">
                                        Maximum session length (minutes)
                                    </Label>
                                    <Input
                                        id="remote-duration"
                                        type="number"
                                        min={5}
                                        max={120}
                                        value={draft.max_minutes}
                                        onChange={(event) =>
                                            update(
                                                'max_minutes',
                                                Number(event.target.value),
                                            )
                                        }
                                        disabled={busy || uncertain}
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="remote-reason">
                                        Reason for this policy change
                                    </Label>
                                    <Textarea
                                        id="remote-reason"
                                        value={reason}
                                        onChange={(event) =>
                                            setReason(event.target.value)
                                        }
                                        maxLength={500}
                                        disabled={busy || uncertain}
                                    />
                                    <p className="text-caption text-muted-foreground">
                                        Recorded in the access audit. Do not
                                        include passwords or care information.
                                    </p>
                                </div>
                                <p className="text-subtle text-muted-foreground">
                                    Every session must show a visible notice and
                                    Stop control. Video recording is off;
                                    emergency override and recording settings
                                    remain in development.
                                </p>
                            </div>
                        )}
                        {draft && step === 1 && (
                            <div className="space-y-5">
                                <ReviewCard
                                    icon={ShieldCheck}
                                    title={device.name}
                                >
                                    <ReviewRow
                                        label="Remote support"
                                        value={
                                            draft.enabled
                                                ? 'Allowed by policy'
                                                : 'Disabled'
                                        }
                                    />
                                    <ReviewRow
                                        label="Approval"
                                        value={
                                            draft.consent_mode === 'attended'
                                                ? 'User must allow each connection'
                                                : 'Approved unattended equipment'
                                        }
                                    />
                                    <ReviewRow
                                        label="Mouse and keyboard"
                                        value={
                                            draft.allow_control
                                                ? 'Allowed with separate technician permission'
                                                : 'View only'
                                        }
                                    />
                                    <ReviewRow
                                        label="Maximum length"
                                        value={draft.max_minutes + ' minutes'}
                                    />
                                    <ReviewRow label="Recording" value="Off" />
                                    <ReviewRow
                                        label="Reason"
                                        value={reason.trim()}
                                    />
                                </ReviewCard>
                                <Alert>
                                    <AlertDescription>
                                        Saving invalidates any open requests and
                                        sessions for this computer. Technicians
                                        will need to start a new request under
                                        the updated policy.
                                    </AlertDescription>
                                </Alert>
                            </div>
                        )}
                    </WizardStepPane>
                </div>
            </WizardShell>
            {leave.confirmation}
        </>
    );
}
