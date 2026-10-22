import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { WizardShell, WizardStepPane } from '@/components/wizard/shell';
import { formatDateTime } from '@/lib/datetime';
import axios from 'axios';
import { FileCode, ListChecks, ShieldCheck } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import {
    scriptBase,
    scriptMaterial,
    scriptReply,
    scriptStatusLabel,
    scriptText,
    type ScriptAction,
    type ScriptMaterial,
    type ScriptRecord,
} from './_contracts';

const actions: Record<
    ScriptAction,
    { label: string; status: ScriptRecord['status'] }
> = {
    submit: { label: 'Submit for review', status: 'pending_review' },
    review: { label: 'Approve this version', status: 'published' },
    withdraw: { label: 'Withdraw this version', status: 'withdrawn' },
};
export function ScriptFacts({ entries }: { entries: [string, ReactNode][] }) {
    return (
        <dl className="grid min-w-0 gap-3 sm:grid-cols-2">
            {entries.map(([label, value]) => (
                <div className="min-w-0 [overflow-wrap:anywhere]" key={label}>
                    <dt className="text-caption text-muted-foreground">
                        {label}
                    </dt>
                    <dd className="text-subtle">{value}</dd>
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
    async function readMaterial() {
        if (busyNow.current || pending || !current.can_read_material) return;
        const controller = new AbortController();
        active.current = controller;
        busyNow.current = true;
        setBusy(true);
        setNotice('');
        setMaterial(null);
        try {
            const fresh = await axios.get<unknown>(
                scriptBase + '/' + item.uuid + query,
                {
                    signal: controller.signal,
                    timeout: 30000,
                    headers: { Accept: 'application/json' },
                },
            );
            const row = scriptReply(fresh.data, actorId, item).script;
            if (controller.signal.aborted || !alive.current) return;
            setCurrent(row);
            onChanged(row);
            if (!row.can_read_material)
                throw Error('Identity needs confirmation');
            const response = await axios.get<unknown>(
                scriptBase + '/' + item.uuid + '/material' + query,
                {
                    signal: controller.signal,
                    timeout: 30000,
                    headers: { Accept: 'application/json' },
                },
            );
            const verified = await scriptMaterial(response.data, actorId, row);
            if (!controller.signal.aborted && alive.current) {
                setMaterial(verified);
                setDenied(false);
            }
        } catch (error) {
            if (!controller.signal.aborted && alive.current) {
                setMaterial(null);
                setDenied(
                    axios.isAxiosError(error) &&
                        [401, 403, 404].includes(error.response?.status ?? 0),
                );
                setNotice(
                    'The script could not be read and verified. Refresh your access or confirm your identity before trying again.',
                );
            }
        } finally {
            busyNow.current = false;
            if (alive.current) setBusy(false);
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
        const controller = new AbortController();
        active.current = controller;
        busyNow.current = true;
        setBusy(true);
        setPending(request);
        setNotice('');
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
            if (!controller.signal.aborted && alive.current)
                accept(response.data, request);
        } catch (error) {
            if (!controller.signal.aborted && alive.current) {
                setMaterial(null);
                setDenied(
                    axios.isAxiosError(error) &&
                        [401, 403, 404].includes(error.response?.status ?? 0),
                );
                setNotice(
                    'The outcome could not be confirmed. Check the saved outcome before making another change.',
                );
            }
        } finally {
            busyNow.current = false;
            if (alive.current) setBusy(false);
        }
    }
    async function recover() {
        if (!pending || busyNow.current) return;
        const request = pending,
            controller = new AbortController();
        active.current = controller;
        busyNow.current = true;
        setBusy(true);
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
            if (!controller.signal.aborted && alive.current)
                accept(response.data, request);
        } catch (error) {
            if (!controller.signal.aborted && alive.current) {
                setMaterial(null);
                setDenied(
                    (previous) =>
                        previous ||
                        (axios.isAxiosError(error) &&
                            [401, 403, 404].includes(
                                error.response?.status ?? 0,
                            )),
                );
                setNotice(
                    'No matching saved outcome is available yet. Keep this request for checking or retry the same request; do not create a replacement.',
                );
            }
        } finally {
            busyNow.current = false;
            if (alive.current) setBusy(false);
        }
    }
    function decide(action: ScriptAction) {
        const allowed =
            current[
                ('can_' + action) as
                    | 'can_submit'
                    | 'can_review'
                    | 'can_withdraw'
            ];
        if (
            busyNow.current ||
            pending ||
            denied ||
            !allowed ||
            !scriptText(reason.trim(), 500, 10) ||
            (action === 'review' && !material)
        )
            return;
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
    return (
        <>
            <WizardShell
                open={open}
                onClose={onClose}
                title="Script review"
                description="Read this exact version and its declared effects before changing its catalogue status."
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
                pct={material ? 100 : 0}
                pctLabel="Code verification"
                footerStart={
                    <Button variant="outline" onClick={onClose}>
                        Close
                    </Button>
                }
                footerEnd={
                    <Button
                        variant="outline"
                        onClick={() => setStep((step + 1) % 3)}
                    >
                        {step === 2 ? 'Back to script' : 'Next section'}
                    </Button>
                }
            >
                {notice && (
                    <Alert role="status">
                        <AlertDescription>{notice}</AlertDescription>
                    </Alert>
                )}
                {denied ? (
                    <p className="text-subtle">
                        This record is no longer available with your current
                        access. Refresh the catalogue.
                    </p>
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
                                            `PowerShell ${current.manifest.interpreter_version} · ${current.manifest.architecture === 'amd64' ? 'x64' : 'ARM64'}`,
                                        ],
                                        [
                                            'Version',
                                            String(current.script_version),
                                        ],
                                        [
                                            'Script size',
                                            `${current.manifest.size_bytes} bytes`,
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
                                        Version identities
                                    </summary>
                                    <div className="mt-3">
                                        <ScriptFacts
                                            entries={[
                                                [
                                                    'Script SHA-256',
                                                    current.manifest.sha256,
                                                ],
                                                [
                                                    'Runtime manifest SHA-256',
                                                    current.manifest
                                                        .runtime_manifest_sha256,
                                                ],
                                                [
                                                    'Wrapper SHA-256',
                                                    current.manifest
                                                        .wrapper_sha256,
                                                ],
                                                [
                                                    'Review content SHA-256',
                                                    current.content_hash,
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
                                    Execution limits
                                </h3>
                                <ScriptFacts
                                    entries={[
                                        [
                                            'Time limit',
                                            `${policy.runtime_seconds} seconds`,
                                        ],
                                        [
                                            'On timeout',
                                            policy.timeout_mode ===
                                            'report_only'
                                                ? 'Report the timeout; continue observing owned work'
                                                : 'Request termination of the owned job',
                                        ],
                                        [
                                            'Retained output',
                                            `${policy.output_bytes} bytes across both streams`,
                                        ],
                                        [
                                            'Output retention',
                                            `${policy.output_retention_hours} hours`,
                                        ],
                                        [
                                            'Network use',
                                            'Reviewed destinations; no enforced network block',
                                        ],
                                        [
                                            'Completion exit codes',
                                            policy.success_exit_codes.join(
                                                ', ',
                                            ),
                                        ],
                                        [
                                            'Independent outcome schema',
                                            policy.expected_observation_schema ??
                                                'None',
                                        ],
                                    ]}
                                />
                                <h3 className="text-section-title">
                                    Declared effects
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
                                            'Child processes',
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
                                            review.output_sensitivity,
                                        ],
                                        ['Compensation', review.compensation],
                                        [
                                            'Restart behaviour',
                                            review.restart_behavior === 'none'
                                                ? 'No restart'
                                                : 'May request a restart',
                                        ],
                                        ['Detached work', 'Not allowed'],
                                        [
                                            'Unpublished dynamic code',
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
                                <div className="space-y-2">
                                    <Label htmlFor="script-decision-reason">
                                        Reason for this decision
                                    </Label>
                                    <Textarea
                                        id="script-decision-reason"
                                        value={reason}
                                        disabled={!!pending || busy}
                                        onChange={(e) =>
                                            setReason(e.target.value)
                                        }
                                        maxLength={500}
                                    />
                                </div>
                                <p className="text-caption text-muted-foreground">
                                    Approval records this version in the
                                    catalogue. Computer permissions and consent
                                    still apply; script execution is not
                                    available yet.
                                </p>
                                {current.can_review && !material && (
                                    <p className="text-subtle">
                                        Read and verify the exact script before
                                        approving it.
                                    </p>
                                )}
                                <div className="flex flex-wrap gap-2">
                                    {(['submit', 'review', 'withdraw'] as const)
                                        .filter(
                                            (a) =>
                                                current[
                                                    ('can_' + a) as
                                                        | 'can_submit'
                                                        | 'can_review'
                                                        | 'can_withdraw'
                                                ],
                                        )
                                        .map((a) => (
                                            <Button
                                                key={a}
                                                variant={
                                                    a === 'withdraw'
                                                        ? 'destructive'
                                                        : 'default'
                                                }
                                                disabled={
                                                    busy ||
                                                    !!pending ||
                                                    !scriptText(
                                                        reason.trim(),
                                                        500,
                                                        10,
                                                    ) ||
                                                    (a === 'review' &&
                                                        !material)
                                                }
                                                onClick={() => decide(a)}
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
        </>
    );
}
