import { ConfirmDialog } from '@/components/confirm-dialog';
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
import { formatDateTime } from '@/lib/datetime';
import { usePage } from '@inertiajs/react';
import axios from 'axios';
import { Check, Settings2, ShieldCheck } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import {
    scriptPolicyReason,
    scriptPolicyRecoveredReply,
    scriptPolicyReferenceFor,
    scriptPolicyReply,
    type ScriptPolicy,
    type ScriptPolicyAttempt,
    type ScriptPolicyReference,
    type ScriptPolicyReply,
} from './script-policy-contract';
import {
    discardUnreadableScriptPolicyReferences,
    releaseScriptPolicyReference,
    retainScriptPolicyReference,
    SCRIPT_POLICY_REFERENCE_LIMIT,
    scriptPolicyReferences,
} from './script-policy-journal';

const steps = [
    {
        key: 'settings',
        label: 'Access',
        blurb: 'Script access and staff consent',
        icon: Settings2,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Check this policy change',
        icon: Check,
    },
];
type Props = { actorId: number; device: { id: number; name: string } };
export function ScriptPolicyEditor(props: Props) {
    const current = usePage<{
        auth?: { user?: { id?: unknown } | null } | null;
    }>().props.auth?.user?.id;
    if (!Number.isSafeInteger(current) || current !== props.actorId)
        return null;
    return (
        <ScriptPolicyWorkspace
            key={`${props.actorId}:${props.device.id}`}
            {...props}
        />
    );
}

function ScriptPolicyWorkspace({ actorId, device }: Props) {
    const base = `/security-devices/devices/${device.id}/endpoint/script-policy`;
    const query = `?expected_actor_id=${actorId}`;
    const [data, setData] = useState<ScriptPolicyReply | null>(null);
    const [draft, setDraft] = useState<ScriptPolicy | null>(null);
    const [reason, setReason] = useState('');
    const [open, setOpen] = useState(false);
    const [step, setStep] = useState(0);
    const [busy, setBusy] = useState(false);
    const [denied, setDenied] = useState(false);
    const [notice, setNotice] = useState('');
    const [pending, setPending] = useState<ScriptPolicyAttempt | null>(null);
    const pendingRef = useRef<ScriptPolicyAttempt | null>(null);
    const [journal, setJournal] = useState(() => {
        try {
            return {
                references: scriptPolicyReferences(actorId, device.id),
                error: '',
            };
        } catch {
            return {
                references: [] as ScriptPolicyReference[],
                error: 'Browser recovery information could not be read. Check it again before saving.',
            };
        }
    });
    const [reference, setReference] = useState<ScriptPolicyReference | null>(
        journal.references[0] ?? null,
    );
    const [dismiss, setDismiss] = useState(false);
    const [clearUnreadable, setClearUnreadable] = useState(false);
    const [complete, setComplete] = useState<ScriptPolicyReply | null>(null);
    const call = useRef<AbortController | null>(null);
    const alive = useRef(true);
    const operation = () => {
        call.current?.abort();
        const next = new AbortController();
        call.current = next;
        setBusy(true);
        return next;
    };
    const failed = (error: unknown, message: string) => {
        const status = (error as { response?: { status?: number } })?.response
            ?.status;
        if (status && [401, 403, 404].includes(status)) {
            setDenied(true);
            setData(null);
            setComplete(null);
            setNotice(
                'Current access could not be confirmed. Check access before continuing.',
            );
        } else setNotice(message);
    };
    const load = useCallback(async () => {
        call.current?.abort();
        const next = new AbortController();
        call.current = next;
        setBusy(true);
        try {
            const response = await axios.get(base + query, {
                signal: next.signal,
                timeout: 15000,
                headers: { Accept: 'application/json' },
            });
            const dto = scriptPolicyReply(response.data, actorId, device.id);
            if (!alive.current || next.signal.aborted) return;
            setData(dto);
            setDraft((previous) => previous ?? dto.policy);
            setDenied(false);
            setNotice('');
            try {
                setJournal({
                    references: scriptPolicyReferences(actorId, device.id),
                    error: '',
                });
            } catch {
                setJournal((previous) => ({
                    ...previous,
                    error: 'Browser recovery information could not be read. Check it again before saving.',
                }));
            }
        } catch (error) {
            if (alive.current && !next.signal.aborted)
                failed(error, 'Script policy could not be loaded. Try again.');
        } finally {
            if (alive.current && !next.signal.aborted) setBusy(false);
        }
    }, [base, query, actorId, device.id]);
    useEffect(() => {
        alive.current = true;
        void load();
        return () => {
            alive.current = false;
            call.current?.abort();
        };
    }, [load]);
    const leave = useSettingsLeaveConfirmation(
        Boolean(reason || reference) && !complete,
        'Leave this script policy?',
        'Unsaved entries will be discarded. A save already sent may still finish. Check the current policy before making another change.',
    );
    const allowed = Boolean(
        data?.can_manage &&
        data.identity_confirmed &&
        !denied &&
        !journal.error,
    );
    const valid = Boolean(
        allowed &&
        journal.references.length < SCRIPT_POLICY_REFERENCE_LIMIT &&
        draft &&
        data &&
        draft.version === data.policy.version &&
        scriptPolicyReason(reason.trim()) &&
        (!draft.enabled ||
            draft.consent_mode !== 'unattended' ||
            data.can_unattended),
    );
    const confirm = (dto: ScriptPolicyReply, ref: ScriptPolicyReference) => {
        let warning = '';
        try {
            setJournal({
                references: releaseScriptPolicyReference(ref),
                error: '',
            });
        } catch {
            warning =
                'The change is confirmed, but its browser recovery reminder could not be cleared.';
        }
        pendingRef.current = null;
        setData(dto);
        setComplete(dto);
        setPending(null);
        setReference(null);
        setDraft(dto.policy);
        setReason('');
        setNotice(warning);
        setDenied(false);
    };
    async function save() {
        if (
            busy ||
            denied ||
            !allowed ||
            (reference && !pending) ||
            (!pending && !valid) ||
            !draft
        )
            return;
        const attempt =
            pendingRef.current ??
            Object.freeze({
                expected_actor_id: actorId,
                expected_version: draft.version,
                request_key: crypto.randomUUID(),
                enabled: draft.enabled,
                consent_mode: draft.consent_mode,
                reason: reason.trim(),
            });
        pendingRef.current = attempt;
        const next = operation();
        let sent = false;
        try {
            const ref = await scriptPolicyReferenceFor(attempt, device.id);
            if (!alive.current || next.signal.aborted) return;
            setJournal({
                references: retainScriptPolicyReference(ref),
                error: '',
            });
            setReference(ref);
            setPending(attempt);
            sent = true;
            const response = await axios.put(base, attempt, {
                signal: next.signal,
                timeout: 15000,
                headers: { Accept: 'application/json' },
            });
            const dto = scriptPolicyReply(
                response.data,
                actorId,
                device.id,
                attempt,
            );
            if (alive.current && !next.signal.aborted) confirm(dto, ref);
        } catch (error) {
            if (alive.current && !next.signal.aborted && !sent) {
                pendingRef.current = null;
                setPending(null);
                setNotice(
                    'Recovery information could not be retained. No save was sent. Check the browser recovery information before trying again.',
                );
            } else if (alive.current && !next.signal.aborted)
                failed(
                    error,
                    'The save could not be confirmed. Check the saved request or retry the same change.',
                );
        } finally {
            if (alive.current && !next.signal.aborted) setBusy(false);
        }
    }
    async function recover(chosen = reference) {
        if (!chosen || busy) return;
        setReference(chosen);
        if (pendingRef.current?.request_key !== chosen.request_key) {
            pendingRef.current = null;
            setPending(null);
        }
        const next = operation();
        try {
            const response = await axios.get(
                `${base}/requests/${chosen.request_key}${query}`,
                {
                    signal: next.signal,
                    timeout: 15000,
                    headers: { Accept: 'application/json' },
                },
            );
            const dto = await scriptPolicyRecoveredReply(
                response.data,
                actorId,
                device.id,
                chosen,
            );
            if (alive.current && !next.signal.aborted) confirm(dto, chosen);
        } catch (error) {
            if (alive.current && !next.signal.aborted)
                failed(
                    error,
                    'The saved request could not be confirmed. Keep this request and check again.',
                );
        } finally {
            if (alive.current && !next.signal.aborted) setBusy(false);
        }
    }
    async function reviewAnother() {
        if (busy) return;
        const next = operation();
        setData(null);
        setDraft(null);
        setReason('');
        setComplete(null);
        setStep(0);
        setReference(null);
        setPending(null);
        pendingRef.current = null;
        try {
            const response = await axios.get(base + query, {
                signal: next.signal,
                timeout: 15000,
                headers: { Accept: 'application/json' },
            });
            const dto = scriptPolicyReply(response.data, actorId, device.id);
            if (!alive.current || next.signal.aborted) return;
            setData(dto);
            setDraft(dto.policy);
            setDenied(false);
            setReference(null);
            setPending(null);
            pendingRef.current = null;
            setReason('');
            setComplete(null);
            setStep(0);
            setNotice(
                'Review these current settings for a separate change. Earlier unconfirmed saves have not been cancelled.',
            );
        } catch (error) {
            if (alive.current && !next.signal.aborted)
                failed(
                    error,
                    'Current settings could not be confirmed. Check again before making another change.',
                );
        } finally {
            if (alive.current && !next.signal.aborted) setBusy(false);
        }
    }
    function dismissReference() {
        if (!reference || busy) return;
        try {
            setJournal({
                references: releaseScriptPolicyReference(reference),
                error: '',
            });
            setDismiss(false);
            void reviewAnother();
        } catch {
            setNotice('The browser recovery reminder could not be cleared.');
            setDismiss(false);
        }
    }
    const close = () => {
        if (busy) return;
        // Closing an uncertain save keeps its frozen request for this workspace.
        if (reference || complete) {
            setOpen(false);
            return;
        }
        leave.request(() => {
            setOpen(false);
            setReason('');
            setDraft(null);
            setStep(0);
        });
    };
    const launch = () => {
        setOpen(true);
        setComplete(null);
        setData(null);
        if (reference) {
            void recover();
            return;
        }
        setData(null);
        setDraft(null);
        setStep(0);
        void load();
    };
    const stale = Boolean(
        data && draft && draft.version !== data.policy.version,
    );
    const hidden = denied || !data;
    if (!open && denied && !reference) return null;
    if (!open && !data)
        return notice ? (
            <Button
                variant="outline"
                onClick={() => void load()}
                disabled={busy}
            >
                Check script policy
            </Button>
        ) : null;
    return (
        <>
            <Button variant="outline" onClick={launch} disabled={busy}>
                {reference ? 'Check script policy save' : 'Script policy'}
            </Button>
            <WizardShell
                open={open}
                onClose={close}
                title="Script policy"
                description="Review script access and consent for this computer."
                railIcon={ShieldCheck}
                railTitle={hidden ? 'Script policy' : device.name}
                railSub="Computer management"
                steps={steps}
                stepIndex={step}
                pct={valid ? 100 : 50}
                onStepClick={(index) => {
                    if (!busy && !reference && !hidden) setStep(index);
                }}
                footerStart={
                    <Button variant="outline" onClick={close} disabled={busy}>
                        Close
                    </Button>
                }
                footerEnd={
                    !hidden && !reference && !complete && data?.can_manage ? (
                        <Button
                            disabled={busy || !valid}
                            onClick={() =>
                                step === 0 ? setStep(1) : void save()
                            }
                        >
                            {busy
                                ? 'Saving…'
                                : step === 0
                                  ? 'Review policy'
                                  : 'Save policy'}
                        </Button>
                    ) : undefined
                }
                success={
                    !hidden && complete ? (
                        <WizardSuccessPane
                            title="Policy change recorded"
                            blurb={`Your change was recorded at ${formatDateTime(complete.receipt?.recorded_at ?? null)}. ${complete.receipt && complete.policy.version > complete.receipt.policy.version ? 'A newer policy is now in place. ' : ''}Current script setting: ${complete.policy.enabled ? 'allowed subject to approval' : 'disabled'}. Script execution is still unavailable. ${notice}`}
                            actions={
                                <Button onClick={() => setOpen(false)}>
                                    Done
                                </Button>
                            }
                        />
                    ) : undefined
                }
            >
                <WizardStepPane>
                    {busy && (
                        <p role="status" className="text-subtle">
                            {reference
                                ? 'Confirming policy change…'
                                : 'Loading current policy…'}
                        </p>
                    )}
                    {notice && (
                        <Alert>
                            <AlertDescription>{notice}</AlertDescription>
                        </Alert>
                    )}
                    {journal.error && (
                        <Alert>
                            <AlertDescription>
                                {journal.error}{' '}
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        variant="outline"
                                        disabled={busy}
                                        onClick={() => void load()}
                                    >
                                        Check recovery information
                                    </Button>
                                    <Button
                                        variant="outline"
                                        disabled={busy}
                                        onClick={() => setClearUnreadable(true)}
                                    >
                                        Clear unreadable reminders
                                    </Button>
                                </div>
                            </AlertDescription>
                        </Alert>
                    )}
                    {hidden ? (
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={() => void load()}
                        >
                            Check current access
                        </Button>
                    ) : (
                        <>
                            <p className="text-subtle">
                                Script execution is not available yet. These
                                settings prepare this computer for reviewed
                                scripts; saving does not run a script.
                            </p>
                            {!data.assignment_matches && (
                                <Alert>
                                    <AlertDescription>
                                        This computer’s assignment changed. Its
                                        script settings are disabled until a new
                                        policy is reviewed and saved.
                                    </AlertDescription>
                                </Alert>
                            )}
                            {stale && !reference && (
                                <Alert>
                                    <AlertDescription>
                                        The policy has changed.{' '}
                                        <Button
                                            variant="outline"
                                            onClick={() => {
                                                setDraft(data.policy);
                                                setStep(0);
                                            }}
                                        >
                                            Use current policy
                                        </Button>
                                    </AlertDescription>
                                </Alert>
                            )}
                            {!data.identity_confirmed && data.can_manage && (
                                <Button asChild variant="outline">
                                    <a
                                        href={`${base}/confirm-identity${query}`}
                                    >
                                        Confirm your identity to change settings
                                    </a>
                                </Button>
                            )}
                            {reference ? (
                                <div className="space-y-3">
                                    <p className="text-subtle">
                                        Keep this request until its result is
                                        known. Reopening or reloading can
                                        recover its saved result. The original
                                        form stays only in memory.
                                    </p>
                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            disabled={busy}
                                            onClick={() => void recover()}
                                        >
                                            Check saved request
                                        </Button>
                                        <Button
                                            variant="outline"
                                            disabled={
                                                busy || !allowed || !pending
                                            }
                                            onClick={() => void save()}
                                        >
                                            Retry same save
                                        </Button>
                                    </div>
                                    {!pending && (
                                        <p className="text-subtle">
                                            This browser kept only a private
                                            recovery reference. It cannot resend
                                            the original form after a reload.
                                        </p>
                                    )}
                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            variant="outline"
                                            disabled={busy}
                                            onClick={() => void reviewAnother()}
                                        >
                                            Review a separate change
                                        </Button>
                                        <Button
                                            variant="outline"
                                            disabled={busy}
                                            onClick={() => setDismiss(true)}
                                        >
                                            Dismiss recovery reminder
                                        </Button>
                                    </div>
                                </div>
                            ) : draft && step === 0 ? (
                                <div className="space-y-5">
                                    {!data.can_manage && (
                                        <p className="text-subtle">
                                            You can view this policy. Changing
                                            it needs separate permission.
                                        </p>
                                    )}
                                    <div className="flex items-center justify-between gap-4">
                                        <Label htmlFor="script-policy-enabled">
                                            Allow reviewed scripts
                                        </Label>
                                        <Switch
                                            id="script-policy-enabled"
                                            checked={draft.enabled}
                                            disabled={busy || !allowed}
                                            onCheckedChange={(enabled) =>
                                                setDraft({ ...draft, enabled })
                                            }
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="script-policy-consent">
                                            Staff consent
                                        </Label>
                                        <Select
                                            value={draft.consent_mode}
                                            disabled={busy || !allowed}
                                            onValueChange={(value) =>
                                                setDraft({
                                                    ...draft,
                                                    consent_mode:
                                                        value as ScriptPolicy['consent_mode'],
                                                })
                                            }
                                        >
                                            <SelectTrigger id="script-policy-consent">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="attended">
                                                    Staff allow each script
                                                </SelectItem>
                                                <SelectItem
                                                    value="unattended"
                                                    disabled={
                                                        !data.can_unattended
                                                    }
                                                >
                                                    Authorised unattended
                                                    scripts
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="script-policy-reason">
                                            Reason for this change
                                        </Label>
                                        <Textarea
                                            id="script-policy-reason"
                                            value={reason}
                                            maxLength={500}
                                            disabled={busy || !allowed}
                                            onChange={(event) =>
                                                setReason(event.target.value)
                                            }
                                        />
                                        <p className="text-subtle">
                                            Use 10–500 characters on one line.
                                        </p>
                                    </div>
                                </div>
                            ) : (
                                draft && (
                                    <ReviewCard
                                        icon={ShieldCheck}
                                        title="Script access"
                                        onEdit={() => setStep(0)}
                                    >
                                        <ReviewRow
                                            label="Computer"
                                            value={device.name}
                                        />
                                        <ReviewRow
                                            label="Scripts"
                                            value={
                                                draft.enabled
                                                    ? 'Allowed, subject to approval and availability'
                                                    : 'Disabled'
                                            }
                                        />
                                        <ReviewRow
                                            label="Consent"
                                            value={
                                                draft.consent_mode ===
                                                'attended'
                                                    ? 'Staff allow each script'
                                                    : 'Authorised unattended scripts'
                                            }
                                        />
                                        <ReviewRow
                                            label="Reason"
                                            value={reason.trim()}
                                        />
                                    </ReviewCard>
                                )
                            )}
                            {!reference && journal.references.length > 0 && (
                                <div className="space-y-2">
                                    <p className="text-subtle">
                                        Earlier saves still need checking.
                                        Reviewing another change does not cancel
                                        them.
                                    </p>
                                    {journal.references.map((ref, index) => (
                                        <Button
                                            className="mr-2"
                                            variant="outline"
                                            key={ref.request_key}
                                            disabled={busy}
                                            onClick={() =>
                                                leave.request(() => {
                                                    setReason('');
                                                    setDraft(null);
                                                    void recover(ref);
                                                })
                                            }
                                        >
                                            Check earlier save {index + 1}
                                        </Button>
                                    ))}
                                    {journal.references.length >=
                                        SCRIPT_POLICY_REFERENCE_LIMIT && (
                                        <p className="text-subtle">
                                            Check or dismiss an earlier reminder
                                            before adding another save.
                                        </p>
                                    )}
                                </div>
                            )}
                        </>
                    )}
                </WizardStepPane>
            </WizardShell>
            {leave.confirmation}
            <ConfirmDialog
                open={dismiss}
                onClose={() => setDismiss(false)}
                onConfirm={dismissReference}
                title="Dismiss this recovery reminder?"
                description="This removes only the browser reminder. It does not cancel a save or delete its server record. You will review current settings before making another change."
                confirmText="Dismiss reminder"
            />
            <ConfirmDialog
                open={clearUnreadable}
                onClose={() => setClearUnreadable(false)}
                onConfirm={() => {
                    try {
                        discardUnreadableScriptPolicyReferences(
                            actorId,
                            device.id,
                        );
                        setJournal({ references: [], error: '' });
                        setClearUnreadable(false);
                        void reviewAnother();
                    } catch {
                        setNotice(
                            'Recovery information could not be cleared. Check it again.',
                        );
                        setClearUnreadable(false);
                    }
                }}
                title="Clear unreadable browser reminders?"
                description="This clears only recovery reminders for this account and computer. It does not cancel earlier saves or delete server records. Review current settings before making another change."
                confirmText="Clear browser reminders"
            />
        </>
    );
}
