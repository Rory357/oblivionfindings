import { ConfirmDialog } from '@/components/confirm-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { formatFileSize } from '@/components/ui/file-dropzone';
import { WizardShell, WizardStepPane } from '@/components/wizard/shell';
import { formatDateTime } from '@/lib/datetime';
import axios from 'axios';
import { FileCode, ListChecks, ShieldCheck, ShieldOff } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import {
    responseStatus,
    scriptBase,
    scriptBusyMessage,
    scriptMaterial,
    scriptReply,
    scriptStatusLabel,
    scriptText,
    type ScriptAction,
    type ScriptManifest,
    type ScriptMaterial,
    type ScriptRecord,
    type ScriptReview,
} from './_contracts';
import { ReasonField } from './_reason-field';

const actions: Record<
    ScriptAction,
    {
        label: string;
        status: ScriptRecord['status'];
        confirmTitle: string;
        confirmText: string;
        effect: string;
        destructive: boolean;
    }
> = {
    submit: {
        label: 'Submit for review',
        status: 'pending_review',
        confirmTitle: 'Submit this script for review?',
        confirmText: 'Submit for review',
        effect: 'Another authorised person will check this exact version before it can be approved. You can’t edit it after submitting — to change it, withdraw it and upload a new version. Nothing runs on any computer.',
        destructive: false,
    },
    review: {
        label: 'Approve this version',
        status: 'published',
        confirmTitle: 'Approve this script version?',
        confirmText: 'Approve version',
        effect: 'This exact version becomes an approved script, recorded with you as its reviewer. Each computer’s script policy and staff consent still apply, and scripts can’t run yet.',
        destructive: false,
    },
    withdraw: {
        label: 'Withdraw this version',
        status: 'withdrawn',
        confirmTitle: 'Withdraw this script version?',
        confirmText: 'Withdraw version',
        effect: 'This version can no longer be approved or used on any computer. This can’t be undone — to use the script again, upload it as a new version.',
        destructive: true,
    },
};
export const architectureLabel = (value: ScriptManifest['architecture']) =>
    value === 'amd64' ? 'x64' : 'ARM64';
export const outputSensitivityLabel: Record<
    ScriptReview['output_sensitivity'],
    string
> = {
    none: 'No sensitive output',
    operational: 'Operational information',
    sensitive: 'Sensitive information',
};
export const timeoutLabel: Record<
    ScriptManifest['execution_policy']['timeout_mode'],
    string
> = {
    report_only: 'Report that it ran too long and let it finish',
    terminate_owned_job: 'Ask the computer to stop the script',
};
const identityExpired =
    'Your identity check has expired — it lasts 15 minutes. Confirm your identity again to continue.';
const deniedCodes = [401, 403, 404, 419];
export function ScriptFacts({
    entries,
    technical = false,
}: {
    entries: [string, ReactNode][];
    /** Hashes and other long identifiers: monospace, any-point wrapping. */
    technical?: boolean;
}) {
    return (
        <dl className="grid min-w-0 gap-3 sm:grid-cols-2">
            {entries.map(([label, value]) => (
                <div className="min-w-0 [overflow-wrap:anywhere]" key={label}>
                    <dt className="text-caption text-muted-foreground">
                        {label}
                    </dt>
                    <dd
                        className={
                            technical ? 'text-caption font-mono' : 'text-subtle'
                        }
                    >
                        {value}
                    </dd>
                </div>
            ))}
        </dl>
    );
}
type Pending = {
    action: ScriptAction;
    before: ScriptRecord;
    body: {
        expected_actor_id: number;
        request_key: string;
        expected_version: number;
        reason: string;
        reviewed_content_hash?: string;
    };
};
/** Why the current record is being reloaded after a refused or failed write. */
type Reload = 'changed' | 'refused' | 'not_saved' | 'read' | 'manual';
export function ScriptReviewDialog({
    actorId,
    item,
    open,
    onClose,
    onChanged,
    onPendingChange,
}: {
    actorId: number;
    item: ScriptRecord;
    open: boolean;
    onClose: () => void;
    onChanged: (row: ScriptRecord) => void;
    onPendingChange: (pending: boolean) => void;
}) {
    const [current, setCurrent] = useState(item),
        [step, setStep] = useState(0),
        [material, setMaterial] = useState<ScriptMaterial | null>(null);
    const [busy, setBusy] = useState(false),
        [pending, setPending] = useState<Pending | null>(null),
        [reason, setReason] = useState(''),
        [notice, setNotice] = useState(''),
        [denied, setDenied] = useState(false);
    const [confirming, setConfirming] = useState<ScriptAction | null>(null),
        [reloadNeeded, setReloadNeeded] = useState(false);
    const active = useRef<AbortController | null>(null),
        alive = useRef(true),
        busyNow = useRef(false);
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
            active.current?.abort();
        };
    }, []);
    useEffect(() => {
        onPendingChange(pending !== null || reason.trim() !== '');
    }, [pending, reason, onPendingChange]);
    useEffect(() => {
        if (!open) {
            if (!pending) active.current?.abort();
            setMaterial(null);
        }
    }, [open, pending]);
    const query = '?expected_actor_id=' + actorId;
    const confirmHref = scriptBase + '/confirm-identity' + query;
    const live = (controller: AbortController) =>
        !controller.signal.aborted && alive.current;
    function start() {
        const controller = new AbortController();
        active.current = controller;
        busyNow.current = true;
        setBusy(true);
        return controller;
    }
    function finish() {
        busyNow.current = false;
        if (alive.current) {
            setBusy(false);
            setConfirming(null);
        }
    }
    const read = (path: string, controller: AbortController) =>
        axios.get<unknown>(scriptBase + '/' + item.uuid + path + query, {
            signal: controller.signal,
            timeout: 30000,
            headers: { Accept: 'application/json' },
        });
    /** Genuine loss of access: hide every private detail held here. */
    function deny() {
        setMaterial(null);
        setReason('');
        setDenied(true);
        setReloadNeeded(false);
    }
    /** Load the current record after a write that did not save. */
    async function reload(
        why: Reload,
        controller: AbortController,
        action?: ScriptAction,
    ) {
        try {
            const fresh = await read('', controller);
            if (!live(controller)) return;
            const row = scriptReply(fresh.data, actorId, item).script;
            setCurrent(row);
            onChanged(row);
            setReloadNeeded(false);
            // The record still has the verified content hash, so the code read
            // stays valid while this person may still read it.
            if (!row.can_read_material) setMaterial(null);
            if (why === 'changed')
                setNotice(
                    'This script changed since you opened it, so your change was not saved. Its current details are shown — check them before deciding again.',
                );
            else if (why === 'not_saved')
                setNotice(
                    'Your change was not saved. The current details are shown — check them, then try again.',
                );
            // Submitting needs no identity check, so only a reviewer's refusal
            // can be an expired one.
            else if (
                !row.can_read_material &&
                why !== 'manual' &&
                action !== 'submit'
            )
                setNotice(
                    identityExpired +
                        (why === 'refused'
                            ? ' Your change was not saved.'
                            : ''),
                );
            else if (why === 'refused')
                setNotice(
                    'This decision isn’t available for this script with your current access, so your change was not saved.',
                );
            else if (why === 'read')
                setNotice(
                    'The script could not be read and verified. Try again in a moment.',
                );
            else setNotice('');
        } catch (error) {
            if (!live(controller)) return;
            if (deniedCodes.includes(responseStatus(error) ?? 0)) {
                deny();
                return;
            }
            setReloadNeeded(true);
            setNotice(
                (why === 'changed'
                    ? 'This script changed since you opened it, so your change was not saved. '
                    : why === 'read' || why === 'manual'
                      ? ''
                      : 'Your change was not saved. ') +
                    'The current details could not be loaded. Load them again before deciding.',
            );
        }
    }
    async function loadCurrent() {
        if (busyNow.current) return;
        const controller = start();
        try {
            await reload('manual', controller);
        } finally {
            finish();
        }
    }
    async function readMaterial() {
        if (busyNow.current || pending || !current.can_read_material) return;
        const controller = start();
        setNotice('');
        setMaterial(null);
        let stage: 'record' | 'material' = 'record';
        try {
            const fresh = await read('', controller);
            const row = scriptReply(fresh.data, actorId, item).script;
            if (!live(controller)) return;
            setCurrent(row);
            onChanged(row);
            if (!row.can_read_material) {
                setNotice(identityExpired);
                return;
            }
            stage = 'material';
            const response = await read('/material', controller);
            const verified = await scriptMaterial(response.data, actorId, row);
            if (live(controller)) {
                setMaterial(verified);
                setDenied(false);
            }
        } catch (error) {
            if (!live(controller)) return;
            setMaterial(null);
            const status = responseStatus(error);
            if (status === 503) setNotice(scriptBusyMessage);
            // The record was readable a moment ago, so a refusal here is
            // usually an identity check that has just expired.
            else if (status === 403 && stage === 'material')
                await reload('read', controller);
            else if (deniedCodes.includes(status ?? 0)) deny();
            else
                setNotice(
                    'The script could not be read and verified. Try again in a moment.',
                );
        } finally {
            finish();
        }
    }
    function accept(value: unknown, request: Pending) {
        const reply = scriptReply(
            value,
            actorId,
            request.before,
            request.body.request_key,
        );
        if (
            reply.receipt?.event !== request.action ||
            reply.receipt.status !== actions[request.action].status ||
            reply.receipt.status_version !== request.body.expected_version + 1
        )
            throw Error('Receipt does not match');
        setCurrent(reply.script);
        onChanged(reply.script);
        setPending(null);
        setReason('');
        setMaterial(null);
        setDenied(false);
        setNotice(
            `Your change was recorded. Current catalogue status: ${scriptStatusLabel[reply.script.status]}.`,
        );
    }
    async function send(request: Pending) {
        if (busyNow.current) return;
        const controller = start();
        setPending(request);
        setNotice('');
        setReloadNeeded(false);
        try {
            const response = await axios.post<unknown>(
                scriptBase + '/' + item.uuid + '/' + request.action,
                request.body,
                {
                    signal: controller.signal,
                    timeout: 30000,
                    headers: { Accept: 'application/json' },
                },
            );
            if (live(controller)) accept(response.data, request);
        } catch (error) {
            if (!live(controller)) return;
            const status = responseStatus(error);
            if (status === 503) {
                // Nothing was written; the same request can be sent again.
                setNotice(scriptBusyMessage);
            } else if (status === 401 || status === 419) {
                setPending(null);
                deny();
            } else if (status === 400 || status === 422) {
                setPending(null);
                setNotice(
                    'This decision wasn’t accepted, so nothing changed. Check the reason and try again.',
                );
            } else if (status === 409) {
                // A stale version: reload it so a new decision gets a new key.
                setPending(null);
                await reload('changed', controller);
            } else if (status === 403 || status === 404) {
                setPending(null);
                await reload('refused', controller, request.action);
            } else {
                setMaterial(null);
                setNotice(
                    'The outcome could not be confirmed. Check the saved outcome before making another change.',
                );
            }
        } finally {
            finish();
        }
    }
    async function recover() {
        if (!pending || busyNow.current) return;
        const request = pending,
            controller = start();
        try {
            const response = await axios.get<unknown>(
                scriptBase +
                    '/' +
                    item.uuid +
                    '/requests/' +
                    request.body.request_key +
                    query,
                {
                    signal: controller.signal,
                    timeout: 30000,
                    headers: { Accept: 'application/json' },
                },
            );
            if (live(controller)) accept(response.data, request);
        } catch (error) {
            if (!live(controller)) return;
            const status = responseStatus(error);
            if (status === 503) {
                setNotice(scriptBusyMessage);
                return;
            }
            if (status === 404 && !denied) {
                // No receipt for this request: it never landed. Check the
                // record is still visible before offering a fresh attempt.
                setPending(null);
                await reload('not_saved', controller);
                return;
            }
            setMaterial(null);
            setDenied(
                (previous) => previous || deniedCodes.includes(status ?? 0),
            );
            setNotice(
                'No matching saved outcome is available yet. Keep this request for checking or retry the same request; do not create a replacement.',
            );
        } finally {
            finish();
        }
    }
    const allowed = (action: ScriptAction) =>
        current[
            ('can_' + action) as 'can_submit' | 'can_review' | 'can_withdraw'
        ];
    const ready = (action: ScriptAction) =>
        !busy &&
        !pending &&
        !denied &&
        !reloadNeeded &&
        allowed(action) &&
        scriptText(reason.trim(), 500, 10) &&
        (action !== 'review' || !!material);
    function decide() {
        const action = confirming;
        if (!action || busyNow.current || !ready(action)) {
            setConfirming(null);
            return;
        }
        void send({
            action,
            before: current,
            body: {
                expected_actor_id: actorId,
                request_key: crypto.randomUUID(),
                expected_version: current.status_version,
                reason: reason.trim(),
                ...(action === 'review'
                    ? { reviewed_content_hash: current.content_hash }
                    : {}),
            },
        });
    }
    const policy = current.manifest.execution_policy,
        review = current.review;
    const choosing = confirming ? actions[confirming] : null;
    return (
        <>
            <WizardShell
                open={open}
                onClose={onClose}
                title="Script review"
                description="Read this exact version and what it does before changing its catalogue status."
                railIcon={FileCode}
                railTitle={
                    denied ? 'Unavailable script' : current.manifest.name
                }
                railSub={
                    denied
                        ? 'Access changed'
                        : scriptStatusLabel[current.status]
                }
                steps={[
                    {
                        key: 'source',
                        label: 'Script',
                        blurb: 'Exact code and runtime',
                        icon: FileCode,
                    },
                    {
                        key: 'behaviour',
                        label: 'Inputs and effects',
                        blurb: 'Limits and review notes',
                        icon: ListChecks,
                    },
                    {
                        key: 'decision',
                        label: 'Decision',
                        blurb: 'Reason and saved outcome',
                        icon: ShieldCheck,
                    },
                ]}
                stepIndex={step}
                onStepClick={setStep}
                headerLabel={['Script', 'Inputs and effects', 'Decision'][step]}
                sequential={false}
                pct={null}
                footerStart={
                    <Button variant="outline" onClick={onClose}>
                        Close
                    </Button>
                }
                footerEnd={
                    !denied && (
                        <Button
                            variant="outline"
                            onClick={() => setStep((step + 1) % 3)}
                        >
                            {step === 2 ? 'Back to script' : 'Next section'}
                        </Button>
                    )
                }
            >
                {notice && (
                    <Alert role="status">
                        <AlertDescription>
                            {notice}
                            {reloadNeeded && !denied && (
                                <Button
                                    variant="outline"
                                    disabled={busy}
                                    onClick={() => void loadCurrent()}
                                >
                                    Load current details
                                </Button>
                            )}
                        </AlertDescription>
                    </Alert>
                )}
                {denied ? (
                    <EmptyState
                        variant="compact"
                        icon={ShieldOff}
                        title="This script is no longer available to you"
                        description="Your access to this record has changed, so its details are hidden. Close this window and refresh the catalogue to see what you can open."
                    />
                ) : (
                    <WizardStepPane key={step}>
                        {step === 0 && (
                            <div className="space-y-4">
                                <ScriptFacts
                                    entries={[
                                        [
                                            'Publisher',
                                            current.manifest.publisher_label,
                                        ],
                                        [
                                            'Runtime',
                                            `PowerShell ${current.manifest.interpreter_version} · ${architectureLabel(current.manifest.architecture)}`,
                                        ],
                                        [
                                            'Version',
                                            String(current.script_version),
                                        ],
                                        [
                                            'Script size',
                                            formatFileSize(
                                                current.manifest.size_bytes,
                                            ),
                                        ],
                                    ]}
                                />
                                {!current.can_read_material ? (
                                    <Button variant="outline" asChild>
                                        <a href={confirmHref}>
                                            Confirm identity to read the script
                                        </a>
                                    </Button>
                                ) : (
                                    <Button
                                        variant="outline"
                                        disabled={busy || !!pending}
                                        onClick={() => void readMaterial()}
                                    >
                                        {busy
                                            ? 'Reading…'
                                            : material
                                              ? 'Read and verify again'
                                              : 'Read and verify script'}
                                    </Button>
                                )}
                                {material && (
                                    <div>
                                        <h3 className="text-section-title">
                                            Exact script content
                                        </h3>
                                        <pre
                                            tabIndex={0}
                                            aria-label="Verified script content"
                                            className="scrollbar-pretty text-caption max-h-80 overflow-auto rounded-md border bg-muted/30 p-3 [overflow-wrap:anywhere] whitespace-pre-wrap"
                                        >
                                            {material.artifact}
                                        </pre>
                                    </div>
                                )}
                                <details className="rounded-md border p-3">
                                    <summary className="frontline-focus cursor-pointer">
                                        Technical details
                                    </summary>
                                    <div className="mt-3">
                                        <ScriptFacts
                                            technical
                                            entries={[
                                                [
                                                    'Script file (SHA-256)',
                                                    current.manifest.sha256,
                                                ],
                                                [
                                                    'Runtime manifest (SHA-256)',
                                                    current.manifest
                                                        .runtime_manifest_sha256,
                                                ],
                                                [
                                                    'Script wrapper (SHA-256)',
                                                    current.manifest
                                                        .wrapper_sha256,
                                                ],
                                                [
                                                    'Reviewed content (SHA-256)',
                                                    current.content_hash,
                                                ],
                                                [
                                                    'Outcome check (SHA-256)',
                                                    policy.expected_observation_schema ??
                                                        'None',
                                                ],
                                            ]}
                                        />
                                    </div>
                                </details>
                            </div>
                        )}
                        {step === 1 && (
                            <div className="space-y-4">
                                <h3 className="text-section-title">Inputs</h3>
                                {current.manifest.parameter_schema.length ===
                                0 ? (
                                    <p className="text-subtle">
                                        This version accepts no inputs.
                                    </p>
                                ) : (
                                    <ul className="space-y-3">
                                        {current.manifest.parameter_schema.map(
                                            (p) => (
                                                <li
                                                    className="rounded-md border p-3 [overflow-wrap:anywhere]"
                                                    key={p.name}
                                                >
                                                    <p className="font-medium">
                                                        {p.name} ·{' '}
                                                        {p.required
                                                            ? 'Required'
                                                            : 'Optional'}
                                                    </p>
                                                    <p className="text-subtle">
                                                        {p.type === 'boolean'
                                                            ? 'Yes or no'
                                                            : p.type ===
                                                                'integer'
                                                              ? `Whole number from ${p.constraints.minimum} to ${p.constraints.maximum}`
                                                              : p.type ===
                                                                  'string'
                                                                ? `Text: ${p.constraints.min_length}–${p.constraints.max_length} characters`
                                                                : `Choose one: ${p.constraints.values.join(' · ')}`}
                                                    </p>
                                                </li>
                                            ),
                                        )}
                                    </ul>
                                )}
                                <h3 className="text-section-title">
                                    Limits when it runs
                                </h3>
                                <ScriptFacts
                                    entries={[
                                        [
                                            'Time limit',
                                            `${policy.runtime_seconds} seconds`,
                                        ],
                                        [
                                            'If it runs too long',
                                            timeoutLabel[policy.timeout_mode],
                                        ],
                                        [
                                            'Output kept',
                                            policy.output_bytes === 0
                                                ? 'None'
                                                : `Up to ${formatFileSize(policy.output_bytes)} (results and errors together)`,
                                        ],
                                        [
                                            'Output kept for',
                                            `${policy.output_retention_hours} hours`,
                                        ],
                                        [
                                            'Network use',
                                            'Only the destinations listed below. This is a declaration; no network block enforces it.',
                                        ],
                                        [
                                            'Success exit codes',
                                            policy.success_exit_codes.join(
                                                ', ',
                                            ),
                                        ],
                                        [
                                            'Independent outcome check',
                                            policy.expected_observation_schema
                                                ? 'Required'
                                                : 'Not required',
                                        ],
                                    ]}
                                />
                                <h3 className="text-section-title">
                                    What it does
                                </h3>
                                <ScriptFacts
                                    entries={[
                                        [
                                            'Effect',
                                            review.effect === 'read_only'
                                                ? 'Read only'
                                                : 'Changes the computer',
                                        ],
                                        [
                                            'Programs it starts',
                                            review.child_processes.join(
                                                ' · ',
                                            ) || 'None declared',
                                        ],
                                        [
                                            'Network destinations',
                                            review.network_destinations.join(
                                                ' · ',
                                            ) || 'None declared',
                                        ],
                                        [
                                            'Output sensitivity',
                                            outputSensitivityLabel[
                                                review.output_sensitivity
                                            ],
                                        ],
                                        ['Recovery plan', review.compensation],
                                        [
                                            'Restart behaviour',
                                            review.restart_behavior === 'none'
                                                ? 'No restart'
                                                : 'May request a restart',
                                        ],
                                        [
                                            'Background work left running',
                                            'Not allowed',
                                        ],
                                        [
                                            'Downloads or runs other code',
                                            'Not allowed',
                                        ],
                                    ]}
                                />
                            </div>
                        )}
                        {step === 2 && (
                            <div className="space-y-4">
                                <ScriptFacts
                                    entries={[
                                        [
                                            'Current status',
                                            scriptStatusLabel[current.status],
                                        ],
                                        [
                                            'Submitted',
                                            current.submitted_at
                                                ? formatDateTime(
                                                      current.submitted_at,
                                                  )
                                                : 'Not submitted',
                                        ],
                                        [
                                            'Approved',
                                            current.published_at
                                                ? formatDateTime(
                                                      current.published_at,
                                                  )
                                                : 'Not approved',
                                        ],
                                        [
                                            'Withdrawn',
                                            current.withdrawn_at
                                                ? formatDateTime(
                                                      current.withdrawn_at,
                                                  )
                                                : 'Not withdrawn',
                                        ],
                                    ]}
                                />
                                {current.publication_issue && (
                                    <Alert>
                                        <AlertDescription>
                                            {current.publication_issue ===
                                            'runtime_unavailable'
                                                ? 'This runtime has not been approved for this deployment.'
                                                : 'The independent outcome check required by this version is not available.'}
                                        </AlertDescription>
                                    </Alert>
                                )}
                                {current.uploaded_by_user_id === actorId &&
                                    current.status === 'pending_review' && (
                                        <p className="text-subtle">
                                            Another authorised person must
                                            review your upload.
                                        </p>
                                    )}
                                {!current.can_read_material && (
                                    <Button variant="outline" asChild>
                                        <a href={confirmHref}>
                                            Confirm identity for review actions
                                        </a>
                                    </Button>
                                )}
                                <ReasonField
                                    id="script-decision-reason"
                                    label="Reason for this decision"
                                    value={reason}
                                    disabled={!!pending || busy}
                                    onChange={setReason}
                                />
                                <p className="text-caption text-muted-foreground">
                                    Approval records this version in the
                                    catalogue. Computer permissions and consent
                                    still apply; scripts can’t run yet.
                                </p>
                                {current.can_review && !material && (
                                    <p className="text-subtle">
                                        Read and verify the exact script before
                                        approving it.
                                    </p>
                                )}
                                <div className="flex flex-wrap gap-2">
                                    {(['submit', 'review', 'withdraw'] as const)
                                        .filter(allowed)
                                        .map((a) => (
                                            <Button
                                                key={a}
                                                variant={
                                                    actions[a].destructive
                                                        ? 'destructive'
                                                        : 'default'
                                                }
                                                disabled={!ready(a)}
                                                onClick={() => setConfirming(a)}
                                            >
                                                {actions[a].label}
                                            </Button>
                                        ))}
                                </div>
                            </div>
                        )}
                    </WizardStepPane>
                )}
                {pending && (
                    <div className="mt-4 flex flex-wrap gap-2">
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={() => void recover()}
                        >
                            Check saved outcome
                        </Button>
                        <Button
                            variant="outline"
                            disabled={busy || denied}
                            onClick={() => void send(pending)}
                        >
                            Retry same request
                        </Button>
                    </div>
                )}
            </WizardShell>
            <ConfirmDialog
                open={choosing !== null}
                onClose={() => {
                    if (!busy) setConfirming(null);
                }}
                onConfirm={decide}
                title={choosing?.confirmTitle ?? ''}
                description={
                    <>
                        <p className="font-medium text-foreground">
                            {current.manifest.name} · version{' '}
                            {current.script_version}
                        </p>
                        <p>{choosing?.effect}</p>
                    </>
                }
                confirmText={busy ? 'Saving…' : (choosing?.confirmText ?? '')}
                variant={choosing?.destructive ? 'destructive' : 'default'}
                processing={busy}
            />
        </>
    );
}
