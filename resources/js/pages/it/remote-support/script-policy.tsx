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
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import { formatDateTime } from '@/lib/datetime';
import { responseStatus } from '@/pages/security-devices/scripts/_contracts';
import { ReasonField } from '@/pages/security-devices/scripts/_reason-field';
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
const deniedCodes = [401, 403, 404, 419];
const busyMessage =
    'Another change to this computer’s settings is being saved. Try again in a moment.';
const unreadable =
    'Browser recovery information could not be read. Check it again before saving.';
/**
 * How a saved draft widens script access compared with the current policy,
 * or null when it doesn't. Turning scripts on, or dropping staff consent,
 * loosens security and is confirmed separately before saving.
 */
export function scriptPolicyLoosening(
    current: ScriptPolicy,
    draft: ScriptPolicy,
): 'unattended' | 'enabled' | null {
    if (!draft.enabled) return null;
    if (
        draft.consent_mode === 'unattended' &&
        (!current.enabled || current.consent_mode !== 'unattended')
    )
        return 'unattended';
    return current.enabled ? null : 'enabled';
}
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
                error: unreadable,
            };
        }
    });
    const [reference, setReference] = useState<ScriptPolicyReference | null>(
        journal.references[0] ?? null,
    );
    const [dismiss, setDismiss] = useState(false);
    const [clearUnreadable, setClearUnreadable] = useState(false);
    const [confirmLoosen, setConfirmLoosen] = useState(false);
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
    const live = (next: AbortController) =>
        alive.current && !next.signal.aborted;
    /** Genuine loss of access: hide the computer and every private entry. */
    const deny = (message: string) => {
        setDenied(true);
        setData(null);
        setComplete(null);
        setNotice(message);
    };
    /** A load that failed: busy is retryable, refusals hide private context. */
    const failedLoad = (error: unknown, message: string) => {
        const status = responseStatus(error);
        if (status === 503) setNotice(busyMessage);
        else if (deniedCodes.includes(status ?? 0))
            deny(
                'Current access could not be confirmed. Check access before continuing.',
            );
        else setNotice(message);
    };
    /** Drop a browser reminder for a save that definitely did not land. */
    const release = (ref: ScriptPolicyReference | null) => {
        if (!ref) return;
        try {
            setJournal({
                references: releaseScriptPolicyReference(ref),
                error: '',
            });
        } catch {
            setJournal((previous) => ({ ...previous, error: unreadable }));
        }
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
                setJournal((previous) => ({ ...previous, error: unreadable }));
            }
        } catch (error) {
            if (alive.current && !next.signal.aborted) {
                const status = responseStatus(error);
                if (status === 503) setNotice(busyMessage);
                else if (deniedCodes.includes(status ?? 0))
                    deny(
                        'Current access could not be confirmed. Check access before continuing.',
                    );
                else setNotice('Script policy could not be loaded. Try again.');
            }
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
    const hidden = denied || !data;
    // Only real, unsent edits in an open dialog guard navigation. A recovery
    // reminder for a save already sent stays in this browser and is checked
    // from the Script policy button, so it never blocks leaving the page.
    const edited = Boolean(
        open &&
        !hidden &&
        !reference &&
        !complete &&
        draft &&
        data &&
        (reason !== '' ||
            draft.enabled !== data.policy.enabled ||
            draft.consent_mode !== data.policy.consent_mode),
    );
    const leave = useSettingsLeaveConfirmation(
        edited,
        'Discard this script policy change?',
        'Your unsaved settings and reason for this computer will be discarded. Nothing has been saved.',
        () => {
            setReason('');
            setDraft(data?.policy ?? null);
            setStep(0);
        },
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
    const loosening =
        data && draft ? scriptPolicyLoosening(data.policy, draft) : null;
    const acceptSaved = (
        dto: ScriptPolicyReply,
        ref: ScriptPolicyReference,
    ) => {
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
    /**
     * The server refused a save, or has no record of it, so nothing was
     * saved: forget the request (a new save gets a new key), reload the
     * current settings and say what to do next.
     */
    async function notSaved(
        why: 'stale' | 'refused' | 'missing',
        next: AbortController,
        wanted: ScriptPolicyAttempt | null,
    ) {
        pendingRef.current = null;
        setPending(null);
        setReference(null);
        setStep(0);
        try {
            const response = await axios.get(base + query, {
                signal: next.signal,
                timeout: 15000,
                headers: { Accept: 'application/json' },
            });
            const dto = scriptPolicyReply(response.data, actorId, device.id);
            if (!live(next)) return;
            setData(dto);
            setDenied(false);
            setComplete(null);
            // Keep the person's own edits from this visit (a stale version is
            // then flagged for review); a reminder restored after a reload has
            // none, so it starts from the current settings.
            setDraft((previous) =>
                why === 'missing' && !wanted
                    ? dto.policy
                    : (previous ?? dto.policy),
            );
            if (why === 'stale')
                setNotice(
                    'The policy changed since you opened it, so your change was not saved. Review the current settings, then save again.',
                );
            else if (why === 'missing')
                setNotice(
                    'This change was not saved. Review the current settings and try again.',
                );
            else if (dto.can_manage && !dto.identity_confirmed)
                setNotice(
                    'Your identity check has expired — it lasts 15 minutes. Confirm your identity again, then save. Your change was not saved.',
                );
            else if (
                wanted?.enabled &&
                wanted.consent_mode === 'unattended' &&
                !dto.can_unattended
            )
                setNotice(
                    'You don’t have permission to allow unattended scripts, so your change was not saved. Require staff consent or turn scripts off.',
                );
            else if (!dto.assignment_matches)
                setNotice(
                    'This computer’s assignment changed, so your change no longer applies. Review the current settings.',
                );
            else
                setNotice(
                    'This change isn’t available with your current access, so it was not saved.',
                );
        } catch (error) {
            if (!live(next)) return;
            deny(
                deniedCodes.includes(responseStatus(error) ?? 0)
                    ? 'Current access could not be confirmed. Check access before continuing.'
                    : 'Your change was not saved, and current access could not be checked. Check access before continuing.',
            );
        }
    }
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
        let sent = false,
            ref: ScriptPolicyReference | null = null;
        try {
            ref = await scriptPolicyReferenceFor(attempt, device.id);
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
            if (alive.current && !next.signal.aborted) acceptSaved(dto, ref);
        } catch (error) {
            if (!live(next)) return;
            if (!sent) {
                pendingRef.current = null;
                setPending(null);
                setNotice(
                    'Recovery information could not be retained. No save was sent. Check the browser recovery information before trying again.',
                );
                return;
            }
            const status = responseStatus(error);
            if (status === 503) {
                // Nothing was written; the same request can be sent again.
                setNotice(busyMessage);
            } else if (status === 401 || status === 419) {
                deny(
                    'Current access could not be confirmed. Check access before continuing.',
                );
            } else if (status === 400 || status === 422) {
                release(ref);
                pendingRef.current = null;
                setPending(null);
                setReference(null);
                setStep(0);
                setNotice(
                    'This change wasn’t accepted, so nothing was saved. Check the reason and try again.',
                );
            } else if (status === 409 || status === 403 || status === 404) {
                release(ref);
                await notSaved(
                    status === 409 ? 'stale' : 'refused',
                    next,
                    attempt,
                );
            } else
                setNotice(
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
            if (alive.current && !next.signal.aborted) acceptSaved(dto, chosen);
        } catch (error) {
            if (!live(next)) return;
            const status = responseStatus(error);
            if (status === 404) {
                // No saved record for this request: it never landed (or no
                // longer applies to this computer). Confirm access first.
                release(chosen);
                await notSaved('missing', next, pendingRef.current);
            } else
                failedLoad(
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
                failedLoad(
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
    function submit() {
        if (step === 0) setStep(1);
        else if (loosening) setConfirmLoosen(true);
        else void save();
    }
    const stale = Boolean(
        data && draft && draft.version !== data.policy.version,
    );
    const loosenText =
        loosening === 'unattended'
            ? 'This change lets approved scripts run on this computer without anyone at the computer allowing each one.'
            : 'This change lets approved scripts run on this computer, with staff at the computer allowing each one.';
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
                            variant={
                                step === 1 && loosening
                                    ? 'destructive'
                                    : 'default'
                            }
                            disabled={busy || !valid}
                            onClick={submit}
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
                                    <ReasonField
                                        id="script-policy-reason"
                                        label="Reason for this change"
                                        value={reason}
                                        disabled={busy || !allowed}
                                        onChange={setReason}
                                    />
                                </div>
                            ) : (
                                draft && (
                                    <div className="space-y-4">
                                        {loosening && (
                                            <Alert variant="warning">
                                                <AlertDescription>
                                                    {loosenText} You’ll be asked
                                                    to confirm before it’s
                                                    saved.
                                                </AlertDescription>
                                            </Alert>
                                        )}
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
                                    </div>
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
                open={confirmLoosen && loosening !== null}
                onClose={() => {
                    if (!busy) setConfirmLoosen(false);
                }}
                onConfirm={() => {
                    void save().finally(() => {
                        if (alive.current) setConfirmLoosen(false);
                    });
                }}
                title={
                    loosening === 'unattended'
                        ? `Allow unattended scripts on ${device.name}?`
                        : `Allow approved scripts on ${device.name}?`
                }
                description={
                    <>
                        <p>{loosenText}</p>
                        <p>
                            {loosening === 'unattended'
                                ? 'Only people with permission to run unattended scripts can start them. '
                                : ''}
                            Scripts can’t run yet — this takes effect when
                            script running becomes available.
                        </p>
                    </>
                }
                confirmText={
                    busy
                        ? 'Saving…'
                        : loosening === 'unattended'
                          ? 'Allow unattended scripts'
                          : 'Allow scripts'
                }
                variant="destructive"
                processing={busy}
            />
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
