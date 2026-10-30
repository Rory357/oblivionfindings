import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { formatFileSize } from '@/components/ui/file-dropzone';
import { Label } from '@/components/ui/label';
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
import axios from 'axios';
import { Check, Package, ShieldCheck } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    safeSoftwareText,
    sameMetadata,
    softwareBase,
    softwareStatusLabel,
    softwareWriteReply,
    type SoftwarePackage,
} from './_contracts';

export function SoftwarePackageDetails({
    item,
    expanded = false,
}: {
    item: SoftwarePackage;
    expanded?: boolean;
}) {
    return (
        <div className="min-w-0 space-y-4 [overflow-wrap:anywhere]">
            <dl className="grid gap-3 sm:grid-cols-2">
                {[
                    ['Version', item.product_version],
                    ['Publisher', item.publisher],
                    [
                        'Windows architecture',
                        item.architecture === 'amd64' ? 'x64' : 'ARM64',
                    ],
                    ['Installer size', formatFileSize(item.size_bytes)],
                    [
                        'Uploaded by',
                        item.uploaded_by?.name ?? 'Former staff member',
                    ],
                    [
                        'Uploaded',
                        item.uploaded_at
                            ? formatDateTime(item.uploaded_at)
                            : 'Not recorded',
                    ],
                    [
                        'Reviewed by',
                        item.reviewed_by?.name ?? 'Awaiting independent review',
                    ],
                    [
                        'Review date',
                        item.reviewed_at
                            ? formatDateTime(item.reviewed_at)
                            : 'Not yet reviewed',
                    ],
                ].map(([label, value]) => (
                    <div key={label}>
                        <dt className="text-caption text-muted-foreground">
                            {label}
                        </dt>
                        <dd className="text-subtle text-foreground">{value}</dd>
                    </div>
                ))}
            </dl>
            <details className="rounded-lg border p-3" open={expanded}>
                <summary className="frontline-focus cursor-pointer font-medium">
                    Installer identity
                </summary>
                <dl className="text-caption mt-3 space-y-3">
                    {[
                        ['ProductCode', item.product_code],
                        [
                            'Expected signing certificate SHA-256',
                            item.signer_sha256,
                        ],
                        ['Installer SHA-256', item.sha256],
                    ].map(([label, value]) => (
                        <div key={label}>
                            <dt className="text-muted-foreground">{label}</dt>
                            <dd className="mt-1 font-mono">{value}</dd>
                        </div>
                    ))}
                </dl>
            </details>
        </div>
    );
}
type Action = 'submit' | 'review' | 'withdraw';
const actionText: Record<
    Action,
    {
        title: string;
        verb: string;
        next: string;
        status: SoftwarePackage['status'];
    }
> = {
    submit: {
        title: 'Submit software for review',
        verb: 'Submit for review',
        next: 'Another authorised person must review this release before deployment.',
        status: 'pending_review',
    },
    review: {
        title: 'Review software release',
        verb: 'Approve for use',
        next: 'This exact installer becomes available for approved installation requests. Computer permissions and consent still apply.',
        status: 'published',
    },
    withdraw: {
        title: 'Withdraw software release',
        verb: 'Withdraw release',
        next: 'New installations will be blocked. This does not uninstall software already on computers or stop an installer that has started.',
        status: 'withdrawn',
    },
};
const steps = [
    {
        key: 'package',
        label: 'Release',
        blurb: 'Check the exact installer',
        icon: Package,
    },
    {
        key: 'decision',
        label: 'Decision',
        blurb: 'Record the review outcome',
        icon: Check,
    },
];
export function SoftwareTransitionDialog({
    actorId,
    item,
    action,
    onClose,
    onChanged,
}: {
    actorId: number;
    item: SoftwarePackage;
    action: Action;
    onClose: () => void;
    onChanged: (row: SoftwarePackage) => void;
}) {
    const [current, setCurrent] = useState(item),
        [reason, setReason] = useState(''),
        [step, setStep] = useState(0);
    const [busy, setBusy] = useState(false),
        [uncertain, setUncertain] = useState(false),
        [notice, setNotice] = useState(''),
        [success, setSuccess] = useState(false);
    const active = useRef<AbortController | null>(null),
        info = actionText[action];
    const leave = useSettingsLeaveConfirmation(!success && reason !== '');
    useEffect(() => () => active.current?.abort(), []);
    const allowed =
        current[
            ('can_' + action) as 'can_submit' | 'can_review' | 'can_withdraw'
        ] && !(action === 'review' && current.uploaded_by?.id === actorId);
    function match(value: unknown) {
        const next = softwareWriteReply(value, actorId);
        if (
            next.uuid !== item.uuid ||
            next.version !== item.version ||
            next.sha256 !== item.sha256 ||
            next.size_bytes !== item.size_bytes ||
            !sameMetadata(next, item)
        )
            throw Error('Release changed');
        return next;
    }
    async function save() {
        if (
            busy ||
            uncertain ||
            !allowed ||
            !safeSoftwareText(reason.trim(), 500, 10)
        )
            return;
        const before = current,
            call = new AbortController();
        active.current = call;
        setBusy(true);
        setNotice('');
        try {
            const reply = await axios.post<unknown>(
                softwareBase + '/' + item.uuid + '/' + action,
                {
                    expected_actor_id: actorId,
                    expected_version: before.status_version,
                    reason: reason.trim(),
                },
                {
                    signal: call.signal,
                    timeout: 30000,
                    headers: { Accept: 'application/json' },
                },
            );
            const next = match(reply.data);
            if (
                next.status_version !== before.status_version + 1 ||
                next.status !== info.status
            )
                throw Error('Decision changed');
            if (call.signal.aborted) return;
            setCurrent(next);
            setSuccess(true);
            setReason('');
            onChanged(next);
        } catch {
            if (!call.signal.aborted) {
                setUncertain(true);
                setNotice(
                    'The decision could not be confirmed. Check the saved release before making another change.',
                );
            }
        } finally {
            if (!call.signal.aborted) setBusy(false);
        }
    }
    async function recover() {
        if (busy) return;
        const call = new AbortController();
        active.current = call;
        setBusy(true);
        setNotice('Checking the saved release…');
        try {
            const reply = await axios.get<unknown>(
                softwareBase + '/' + item.uuid,
                {
                    params: { expected_actor_id: actorId },
                    signal: call.signal,
                    timeout: 15000,
                    headers: { Accept: 'application/json' },
                },
            );
            const next = match(reply.data);
            if (call.signal.aborted) return;
            setCurrent(next);
            setReason('');
            setStep(0);
            setUncertain(false);
            onChanged(next);
            if (next.status === info.status) {
                setSuccess(true);
                setNotice('');
            } else
                setNotice(
                    'Current saved status: ' +
                        softwareStatusLabel[next.status] +
                        '. Review it before submitting another decision.',
                );
        } catch {
            if (!call.signal.aborted)
                setNotice(
                    'The saved release could not be checked. Check again before making another change.',
                );
        } finally {
            if (!call.signal.aborted) setBusy(false);
        }
    }
    return (
        <>
            <WizardShell
                open
                title={info.title}
                description={item.name + ' · ' + item.product_version}
                onClose={() => {
                    if (!busy) leave.request(onClose);
                }}
                railIcon={ShieldCheck}
                railTitle="Approved software"
                railSub="Independent review"
                steps={steps}
                stepIndex={step}
                pct={
                    step === 1 && safeSoftwareText(reason.trim(), 500, 10)
                        ? 100
                        : 50
                }
                onStepClick={(index) => {
                    if (!busy && !uncertain) setStep(index);
                }}
                footerStart={
                    <Button
                        variant="outline"
                        disabled={busy}
                        onClick={() => leave.request(onClose)}
                    >
                        Close
                    </Button>
                }
                footerEnd={
                    uncertain ? (
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={() => void recover()}
                        >
                            Check saved release
                        </Button>
                    ) : step === 0 ? (
                        <Button
                            disabled={busy || !allowed}
                            onClick={() => setStep(1)}
                        >
                            Continue to decision
                        </Button>
                    ) : (
                        <Button
                            disabled={
                                busy ||
                                !allowed ||
                                !safeSoftwareText(reason.trim(), 500, 10)
                            }
                            onClick={() => void save()}
                        >
                            {busy ? 'Confirming…' : info.verb}
                        </Button>
                    )
                }
                success={
                    success ? (
                        <WizardSuccessPane
                            title={
                                'Release ' +
                                softwareStatusLabel[
                                    current.status
                                ].toLowerCase()
                            }
                            blurb={info.next}
                            actions={<Button onClick={onClose}>Done</Button>}
                        />
                    ) : undefined
                }
            >
                <div className="space-y-5">
                    <WizardStepPane>
                        {notice && (
                            <Alert>
                                <AlertDescription role="status">
                                    {notice}
                                </AlertDescription>
                            </Alert>
                        )}
                        {!allowed && !success && (
                            <p className="text-subtle">
                                This decision is not available with the current
                                release status and your permissions.
                            </p>
                        )}
                        {step === 0 && (
                            <div className="space-y-4">
                                <SoftwarePackageDetails
                                    item={current}
                                    expanded
                                />
                                <p className="text-subtle">
                                    {action === 'review'
                                        ? 'Check the vendor, exact installer identity, application testing and organisation suitability. Approval permits this installer to run with computer-management privileges.'
                                        : info.next}
                                </p>
                            </div>
                        )}
                        {step === 1 && (
                            <div className="space-y-4">
                                <ReviewCard
                                    icon={Package}
                                    title={item.name}
                                    onEdit={() => setStep(0)}
                                >
                                    <ReviewRow
                                        label="Version"
                                        value={item.product_version}
                                    />
                                    <ReviewRow
                                        label="Publisher"
                                        value={item.publisher}
                                    />
                                    <ReviewRow
                                        label="Decision"
                                        value={info.verb}
                                    />
                                </ReviewCard>
                                <p className="text-subtle">{info.next}</p>
                                <div className="space-y-2">
                                    <Label htmlFor="software-review-reason">
                                        Reason and review notes
                                    </Label>
                                    <Textarea
                                        id="software-review-reason"
                                        maxLength={500}
                                        value={reason}
                                        disabled={busy || uncertain || !allowed}
                                        onChange={(event) =>
                                            setReason(event.target.value)
                                        }
                                    />
                                </div>
                            </div>
                        )}
                    </WizardStepPane>
                </div>
            </WizardShell>
            {leave.confirmation}
        </>
    );
}
