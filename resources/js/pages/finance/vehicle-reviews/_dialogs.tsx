import { formatMoney } from '@/components/finance';
import { DocumentPreview } from '@/components/finance/document-preview';
import {
    isSavedResponse,
    useVehicleRecordCommand,
} from '@/components/fleet-assets/vehicle-workspace/record-command';
import { WorkspaceWizard } from '@/components/fleet-assets/vehicle-workspace/wizard-kit';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { TilePicker } from '@/components/wizard/primitives';
import { WizardSuccessPane } from '@/components/wizard/shell';
import { formatDateTime } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import {
    Car,
    CheckCircle2,
    FileText,
    History,
    Undo2,
    UserRound,
    XCircle,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

export type ReviewFile = {
    mime?: string | null;
    id: number;
    name: string | null;
    url: string | null;
    waiting: boolean;
};

export type ReviewRequest = {
    id: number;
    reference: string | null;
    type_label: string;
    status:
        | 'preparing'
        | 'submitted'
        | 'changes_requested'
        | 'resolved'
        | 'declined';
    status_label: string;
    tone: 'success' | 'warning' | 'neutral';
    vehicle: {
        id: number;
        name: string;
        registration: string | null;
        site: string | null;
        url: string | null;
    };
    amount: number | null;
    note: string | null;
    source: string | null;
    requested_by: string | null;
    requested_at: string | null;
    decided_by: string | null;
    decided_at: string | null;
    decision_note: string | null;
    lock_version: number;
    evidence_token?: string;
    assigned_to_user_id?: number | null;
    assigned_to?: string | null;
    due_on?: string | null;
    response_note?: string | null;
    can_assign?: boolean;
    bill_url?: string | null;
    decision_evidence?: Array<{
        id: number;
        original_name: string;
        sha256: string;
    }> | null;
    files: ReviewFile[];
    history: Array<{
        id: number;
        label: string;
        actor: string | null;
        note: string | null;
        occurred_at: string | null;
    }>;
    history_next_before: number | null;
    own_request: boolean;
    can_decide: boolean;
};

export const vehicleLabel = (request: ReviewRequest) =>
    [request.vehicle.name, request.vehicle.registration, request.vehicle.site]
        .filter(Boolean)
        .join(' · ');
const steps = [
    {
        key: 'request',
        label: 'Request',
        blurb: 'Context and ownership',
        icon: Car,
    },
    {
        key: 'evidence',
        label: 'Evidence',
        blurb: 'Files and history',
        icon: FileText,
    },
    {
        key: 'decision',
        label: 'Decision',
        blurb: 'Outcome and next step',
        icon: CheckCircle2,
    },
];
export function ReviewRequestDialog({
    request,
    onClose,
}: {
    request: ReviewRequest | null;
    onClose: () => void;
}) {
    return request ? (
        <ReviewRequestBody
            key={request.id}
            request={request}
            onClose={onClose}
        />
    ) : null;
}
function ReviewRequestBody({
    request,
    onClose,
}: {
    request: ReviewRequest;
    onClose: () => void;
}) {
    const [reviewed, setReviewed] = useState({
        version: request.lock_version,
        token: request.evidence_token,
    });
    const [step, setStep] = useState(0);
    const [decision, setDecision] = useState('');
    const [note, setNote] = useState('');
    const [saved, setSaved] = useState(false);
    const [error, setError] = useState('');
    const [preview, setPreview] = useState<ReviewFile | null>(null);
    const [assigning, setAssigning] = useState(false);
    const command = useVehicleRecordCommand(isSavedResponse);
    const [history, setHistory] = useState(request.history);
    const [historyBefore, setHistoryBefore] = useState(
        request.history_next_before,
    );
    const [historyLoading, setHistoryLoading] = useState(false);
    const [historyError, setHistoryError] = useState('');
    const historyRequest = useRef<AbortController | null>(null);
    useEffect(() => () => historyRequest.current?.abort(), []);
    const loadOlderHistory = async () => {
        if (!historyBefore || historyRequest.current) return;
        const controller = new AbortController();
        historyRequest.current = controller;
        setHistoryLoading(true);
        setHistoryError('');
        try {
            const response = await fetch(
                `/finance/vehicle-reviews/${request.id}/history?before=${historyBefore}`,
                {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    signal: controller.signal,
                },
            );
            if (!response.ok) throw new Error('History unavailable');
            const page: Pick<ReviewRequest, 'history' | 'history_next_before'> =
                await response.json();
            setHistory((current) => [
                ...page.history.filter(
                    (entry) =>
                        !current.some((existing) => existing.id === entry.id),
                ),
                ...current,
            ]);
            setHistoryBefore(page.history_next_before);
        } catch {
            if (!controller.signal.aborted)
                setHistoryError(
                    'Older history could not be loaded. Try again.',
                );
        } finally {
            if (!controller.signal.aborted) {
                setHistoryLoading(false);
                historyRequest.current = null;
            }
        }
    };

    const submit = async () => {
        if (!request.can_decide) {
            onClose();
            return;
        }
        if (!command.uncertain && (!decision || !note.trim())) {
            setError('Choose a decision and record the reason and next step.');
            setStep(2);
            return;
        }
        const result = await command.submit(
            '/finance/vehicle-reviews/' + request.id + '/decision',
            {
                decision,
                note: note.trim(),
                expected_version: reviewed.version,
                evidence_token: reviewed.token,
            },
        );
        if (result?.saved) setSaved(true);
    };
    const close = () => {
        onClose();
        if (saved) router.reload();
    };
    return (
        <>
            <WorkspaceWizard
                title="Review Finance request"
                description="Review the evidence and record Finance’s response. The requester sees the outcome on the source profile."
                railIcon={Car}
                railSub={request.reference ?? 'Finance review'}
                steps={steps}
                step={step}
                setStep={setStep}
                pct={decision && note.trim() ? 100 : 0}
                context={{
                    name: request.type_label,
                    detail: vehicleLabel(request),
                }}
                command={{
                    ...command,
                    message:
                        command.message ||
                        error ||
                        Object.values(command.errors).join(' '),
                }}
                dirty={!!decision || !!note}
                saved={saved}
                submitLabel={
                    request.can_decide ? 'Record decision' : 'Close review'
                }
                onValidateStep={() => true}
                onSubmit={submit}
                onClose={close}
                onReload={() =>
                    router.reload({
                        onSuccess: (page) => {
                            const props = page.props as unknown as {
                                requests: ReviewRequest[];
                                focus: ReviewRequest | null;
                            };
                            const current =
                                props.requests.find(
                                    (item) => item.id === request.id,
                                ) ?? props.focus;
                            if (current?.id === request.id) {
                                setReviewed({
                                    version: current.lock_version,
                                    token: current.evidence_token,
                                });
                                command.reset();
                                setError('');
                                setStep(0);
                            }
                        },
                    })
                }
                errorKey={JSON.stringify(command.errors)}
                success={
                    <WizardSuccessPane
                        title="Decision recorded"
                        blurb="The outcome and reviewed evidence are retained in the request history. The requester’s notification is queued."
                        actions={
                            <Button onClick={close}>Back to reviews</Button>
                        }
                    />
                }
            >
                {step === 0 && (
                    <div className="space-y-4">
                        <p><strong>Review requested:</strong> {request.note ?? 'No note recorded.'}</p>
                        {request.vehicle.url && (
                            <Button variant="outline" asChild>
                                <a href={request.vehicle.url}>Open source profile</a>
                            </Button>
                        )}
                        <p>
                            <strong>Source:</strong> {request.source}{' '}
                            {request.bill_url && (
                                <a
                                    className="text-primary underline"
                                    href={request.bill_url}
                                >
                                    Open bill, journal and payments
                                </a>
                            )}
                        </p>
                        <p>
                            <strong>Amount:</strong>{' '}
                            {request.amount === null
                                ? 'Not supplied'
                                : formatMoney(request.amount)}
                        </p>
                        <p>
                            <strong>Requester:</strong>{' '}
                            {request.requested_by ?? 'Not recorded'}
                        </p>
                        <p>
                            <strong>Reviewer:</strong>{' '}
                            {request.assigned_to ?? 'Unassigned'} ·{' '}
                            <strong>Due:</strong> {request.due_on ?? 'Not set'}
                        </p>
                        {request.can_assign && (
                            <Button
                                variant="outline"
                                onClick={() => setAssigning(true)}
                            >
                                <UserRound className="size-4" />
                                Assign reviewer / due date
                            </Button>
                        )}
                        <p>{request.status_label}</p>
                        {request.response_note && (
                            <p>
                                <strong>Requester’s response:</strong>{' '}
                                {request.response_note}
                            </p>
                        )}
                        {request.decision_note && (
                            <p>
                                <strong>Previous decision:</strong>{' '}
                                {request.decision_note}
                            </p>
                        )}
                    </div>
                )}
                {step === 1 && (
                    <div className="space-y-4">
                        {!request.files.length && (
                            <p>No files were attached.</p>
                        )}
                        {request.files.map((file) => (
                            <div
                                key={file.id}
                                className="flex flex-wrap items-center gap-3 border-b py-3"
                            >
                                <FileText className="size-4" />
                                <span>{file.name ?? 'File'}</span>
                                {file.url ? (
                                    <>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setPreview(file)}
                                        >
                                            Preview
                                        </Button>
                                        <a
                                            className="text-primary underline"
                                            href={file.url}
                                        >
                                            Download
                                        </a>
                                    </>
                                ) : (
                                    <span>
                                        {file.waiting
                                            ? 'Waiting for virus check'
                                            : 'File unavailable'}
                                    </span>
                                )}
                            </div>
                        ))}
                        {preview?.url && (
                            <section aria-label="File preview">
                                <div className="flex items-center justify-between">
                                    <strong>{preview.name}</strong>
                                    <Button
                                        variant="ghost"
                                        onClick={() => setPreview(null)}
                                    >
                                        Close preview
                                    </Button>
                                </div>
                                <DocumentPreview
                                    url={preview.url + '?inline=1'}
                                    name={preview.name ?? 'Evidence'}
                                    mime={preview.mime}
                                />
                                <a
                                    className="text-primary underline"
                                    href={preview.url + '?inline=1'}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    Open preview in a new tab
                                </a>
                            </section>
                        )}
                        {request.decision_evidence?.length ? (
                            <p>
                                {request.decision_evidence.length} file
                                identities and checksums retained with the
                                latest decision.
                            </p>
                        ) : null}
                        <section aria-label="History">
                            <h3 className="flex items-center gap-2 font-semibold">
                                <History className="size-4" />
                                History
                            </h3>
                            <ol className="space-y-3">
                                {history.map((entry) => (
                                    <li key={entry.id}>
                                        <strong>{entry.label}</strong> ·{' '}
                                        {entry.actor ?? 'System'}
                                        {entry.occurred_at
                                            ? ' · ' +
                                              formatDateTime(entry.occurred_at)
                                            : ''}
                                        <p>{entry.note}</p>
                                    </li>
                                ))}
                            </ol>
                            {historyError && <p role="alert">{historyError}</p>}
                            {historyBefore && (
                                <Button
                                    variant="outline"
                                    disabled={historyLoading}
                                    onClick={loadOlderHistory}
                                >
                                    {historyLoading
                                        ? 'Loading…'
                                        : 'Load older history'}
                                </Button>
                            )}
                        </section>
                    </div>
                )}
                {step === 2 &&
                    (request.can_decide ? (
                        <div className="space-y-5">
                            <TilePicker
                                value={decision}
                                onChange={(value) => {
                                    setDecision(value);
                                    setError('');
                                }}
                                options={[
                                    {
                                        key: 'resolved',
                                        label: 'Resolve',
                                        description:
                                            'Record how Finance has addressed it.',
                                        icon: CheckCircle2,
                                    },
                                    {
                                        key: 'changes_requested',
                                        label: 'Return for correction',
                                        description:
                                            'Ask the requester to correct or explain the evidence.',
                                        icon: Undo2,
                                    },
                                    {
                                        key: 'declined',
                                        label: 'Decline',
                                        description:
                                            'Explain why and record the next step.',
                                        icon: XCircle,
                                    },
                                ]}
                            />
                            <div>
                                <Label htmlFor="finance-decision-note">
                                    Decision note *
                                </Label>
                                <Textarea
                                    id="finance-decision-note"
                                    value={note}
                                    maxLength={2000}
                                    rows={5}
                                    aria-invalid={
                                        !!error || !!command.errors.note
                                    }
                                    onChange={(event) => {
                                        setNote(event.target.value);
                                        setError('');
                                    }}
                                />
                            </div>
                            <p className="text-sm text-muted-foreground">
                                This records Finance’s response. Spend approval,
                                journal posting and payment remain separate
                                actions.
                            </p>
                        </div>
                    ) : (
                        <p>
                            {request.own_request
                                ? 'Another Finance reviewer must decide your request.'
                                : request.status_label}
                            {request.decision_note
                                ? ' · ' + request.decision_note
                                : ''}
                        </p>
                    ))}
            </WorkspaceWizard>
            {assigning && (
                <AssignmentWizard
                    request={request}
                    onClose={() => setAssigning(false)}
                />
            )}
        </>
    );
}
function AssignmentWizard({
    request,
    onClose,
}: {
    request: ReviewRequest;
    onClose: () => void;
}) {
    const command = useVehicleRecordCommand(isSavedResponse);
    const [assignee, setAssignee] = useState(
        request.assigned_to_user_id ? String(request.assigned_to_user_id) : '',
    );
    const [due, setDue] = useState(request.due_on ?? '');
    const [search, setSearch] = useState('');
    const [choices, setChoices] = useState<Array<{ id: number; name: string }>>(
        [],
    );
    const [next, setNext] = useState<number | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [saved, setSaved] = useState(false);
    const load = async (after = 0) => {
        setLoading(true);
        setError('');
        try {
            const response = await fetch(
                '/finance/vehicle-reviews/' +
                    request.id +
                    '/reviewers?search=' +
                    encodeURIComponent(search) +
                    '&after=' +
                    after,
                { headers: { Accept: 'application/json' } },
            );
            if (!response.ok) throw new Error();
            const data = (await response.json()) as {
                reviewers: typeof choices;
                next_after: number | null;
            };
            setChoices((previous) =>
                after ? [...previous, ...data.reviewers] : data.reviewers,
            );
            setNext(data.next_after);
        } catch {
            setError('Reviewers could not be loaded. Try again.');
        } finally {
            setLoading(false);
        }
    };
    const submit = async () => {
        const result = await command.submit(
            '/finance/vehicle-reviews/' + request.id + '/assignment',
            {
                assigned_to_user_id: assignee ? Number(assignee) : null,
                due_on: due || null,
                expected_version: request.lock_version,
            },
        );
        if (result?.saved) setSaved(true);
    };
    const close = () => {
        onClose();
        if (saved) router.reload();
    };
    return (
        <WorkspaceWizard
            title="Assign review"
            description="Choose who will review this request and when it is due."
            railIcon={UserRound}
            railSub={request.reference ?? 'Finance review'}
            steps={[
                {
                    key: 'assignment',
                    label: 'Reviewer and due date',
                    blurb: 'Both optional',
                    icon: UserRound,
                },
            ]}
            step={0}
            setStep={() => {}}
            pct={100}
            context={{
                name: request.type_label,
                detail: vehicleLabel(request),
            }}
            command={command}
            dirty={
                assignee !== String(request.assigned_to_user_id ?? '') ||
                due !== (request.due_on ?? '')
            }
            saved={saved}
            submitLabel="Save assignment"
            onValidateStep={() => true}
            onSubmit={submit}
            onClose={close}
            onReload={() => router.reload({ onSuccess: command.reset })}
            errorKey={JSON.stringify(command.errors)}
            success={
                <WizardSuccessPane
                    title="Assignment saved"
                    blurb="Any due reminders use the date you chose."
                    actions={<Button onClick={close}>Back to review</Button>}
                />
            }
        >
            <div className="space-y-4">
                <Label htmlFor="reviewer-search">Find reviewer</Label>
                <Input
                    id="reviewer-search"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                />
                <Button
                    variant="outline"
                    disabled={loading}
                    onClick={() => load()}
                >
                    Find eligible reviewers
                </Button>
                {error && <p role="alert">{error}</p>}
                <TilePicker
                    value={assignee}
                    onChange={setAssignee}
                    options={[
                        {
                            key: '',
                            label: 'Unassigned',
                            description:
                                'The Finance queue remains responsible.',
                            icon: UserRound,
                        },
                        ...(request.assigned_to_user_id &&
                        !choices.some(
                            (choice) =>
                                choice.id === request.assigned_to_user_id,
                        )
                            ? [
                                  {
                                      key: String(request.assigned_to_user_id),
                                      label:
                                          request.assigned_to ??
                                          'Current reviewer',
                                      description: 'Current assignment',
                                      icon: UserRound,
                                  },
                              ]
                            : []),
                        ...choices.map((choice) => ({
                            key: String(choice.id),
                            label: choice.name,
                            description: 'Eligible reviewer',
                            icon: UserRound,
                        })),
                    ]}
                />
                {next !== null && (
                    <Button
                        variant="outline"
                        disabled={loading}
                        onClick={() => load(next)}
                    >
                        More reviewers
                    </Button>
                )}
                <Label htmlFor="review-due">Due date (optional)</Label>
                <Input
                    id="review-due"
                    type="date"
                    value={due}
                    onChange={(event) => setDue(event.target.value)}
                />
            </div>
        </WorkspaceWizard>
    );
}
