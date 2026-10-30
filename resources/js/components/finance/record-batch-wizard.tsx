import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    isSavedResponse,
    useVehicleRecordCommand,
} from '@/components/fleet-assets/vehicle-workspace/record-command';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { WizardShell, type WizardStep } from '@/components/wizard/shell';
import { CheckCircle2, ClipboardCheck } from 'lucide-react';
import {
    forwardRef,
    useImperativeHandle,
    useRef,
    useState,
    type ReactNode,
} from 'react';

export type BatchRecord = {
    id: number;
    label: string;
    detail: ReactNode;
    href: string;
    url: string;
    method?: 'POST' | 'PUT';
    body: Record<string, unknown>;
};
type RowHandle = { run: (note: string) => Promise<void> };
const steps: WizardStep[] = [
    {
        key: 'records',
        label: 'Review each record',
        blurb: 'Check context and evidence',
        icon: ClipboardCheck,
    },
    {
        key: 'confirm',
        label: 'Confirm action',
        blurb: 'Record the decision',
        icon: ClipboardCheck,
    },
    {
        key: 'results',
        label: 'Results',
        blurb: 'Saved and blocked records',
        icon: CheckCircle2,
    },
];

/** Each row retains its own command identity and canonical endpoint, including after a lost response. */
export function RecordBatchWizard({
    title,
    description,
    records: incoming,
    action,
    requireNote = false,
    onClose,
}: {
    title: string;
    description: string;
    records: BatchRecord[];
    action: string;
    requireNote?: boolean;
    onClose: () => void;
}) {
    const [records] = useState(() => incoming.slice(0, 25));
    const [step, setStep] = useState(0);
    const [reviewed, setReviewed] = useState<Set<number>>(new Set());
    const [note, setNote] = useState('');
    const [error, setError] = useState('');
    const [running, setRunning] = useState(false);
    const [discard, setDiscard] = useState(false);
    const handles = useRef(new Map<number, RowHandle>());
    const close = () => {
        if (!running) {
            if (step === 1) setDiscard(true);
            else onClose();
        }
    };
    const run = async () => {
        if (running) return;
        if (requireNote && !note.trim()) {
            setError('Add a decision note for these records.');
            return;
        }
        setError('');
        setRunning(true);
        setStep(2);
        for (const record of records)
            await handles.current.get(record.id)?.run(note.trim());
        setRunning(false);
    };
    return (
        <>
            <WizardShell
                open
                onClose={close}
                title={title}
                description={description}
                railIcon={ClipboardCheck}
                railTitle={title}
                railSub={`${records.length} selected records`}
                steps={steps}
                stepIndex={step}
                onStepClick={(next) => {
                    if (!running && step < 2 && next === 0) setStep(0);
                }}
                pct={step === 2 ? 100 : (step + 1) * 33}
                footerStart={
                    <Button
                        className="min-h-[44px] sm:min-h-9"
                        variant="outline"
                        disabled={running}
                        onClick={close}
                    >
                        {step === 2 ? 'Return to queue' : 'Cancel'}
                    </Button>
                }
                footerEnd={
                    step === 0 ? (
                        <Button
                            className="min-h-[44px] sm:min-h-9"
                            disabled={
                                reviewed.size !== records.length ||
                                !records.length
                            }
                            onClick={() => setStep(1)}
                        >
                            Continue
                        </Button>
                    ) : step === 1 ? (
                        <>
                            <Button
                                className="min-h-[44px] sm:min-h-9"
                                variant="outline"
                                onClick={() => setStep(0)}
                            >
                                Back
                            </Button>
                            <Button
                                className="min-h-[44px] sm:min-h-9"
                                onClick={() => void run()}
                            >
                                {action}
                            </Button>
                        </>
                    ) : (
                        <span role="status" className="text-sm">
                            {running
                                ? 'Checking and saving each record…'
                                : 'Each result is listed below'}
                        </span>
                    )
                }
            >
                <h2 className="text-section-title">{steps[step].label}</h2>
                <p className="my-3 text-sm text-muted-foreground">
                    {step === 0
                        ? 'Open the supporting records and review each selection before continuing.'
                        : step === 1
                          ? description
                          : 'Successful records remain saved. Retry an unconfirmed result here with the same submission. Open a blocked record to review its latest state before making a new submission.'}
                </p>
                {step === 1 && (
                    <div className="mb-5 space-y-2">
                        <label
                            htmlFor="batch-note"
                            className="text-sm font-medium"
                        >
                            Decision note {requireNote ? '' : '(optional)'}
                        </label>
                        <Textarea
                            id="batch-note"
                            value={note}
                            onChange={(e) => setNote(e.target.value)}
                            maxLength={2000}
                            aria-invalid={!!error}
                        />
                        {error && (
                            <p
                                role="alert"
                                className="text-sm text-destructive"
                            >
                                {error}
                            </p>
                        )}
                    </div>
                )}
                <div className="space-y-3">
                    {records.map((record) => (
                        <BatchRow
                            key={record.id}
                            ref={(handle) => {
                                if (handle)
                                    handles.current.set(record.id, handle);
                                else handles.current.delete(record.id);
                            }}
                            record={record}
                            results={step === 2}
                            running={running}
                            onBusy={setRunning}
                        >
                            {step === 0 && (
                                <label className="mt-3 flex min-h-[44px] items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={reviewed.has(record.id)}
                                        onChange={(e) =>
                                            setReviewed((current) => {
                                                const next = new Set(current);
                                                if (e.target.checked)
                                                    next.add(record.id);
                                                else next.delete(record.id);
                                                return next;
                                            })
                                        }
                                    />
                                    I reviewed {record.label}
                                </label>
                            )}
                        </BatchRow>
                    ))}
                </div>
            </WizardShell>
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={onClose}
                title="Discard this draft?"
                description="The selected records have not been changed. Your decision note will be removed."
                confirmText="Discard draft"
                cancelText="Keep editing"
            />
        </>
    );
}

const BatchRow = forwardRef<
    RowHandle,
    {
        record: BatchRecord;
        results: boolean;
        running: boolean;
        onBusy: (value: boolean) => void;
        children: ReactNode;
    }
>(function BatchRow({ record, results, running, onBusy, children }, ref) {
    const command = useVehicleRecordCommand(isSavedResponse);
    const [outcome, setOutcome] = useState<'pending' | 'attempted' | 'saved'>(
        'pending',
    );
    const submittedNote = useRef('');
    const run = async (note: string) => {
        submittedNote.current = note;
        const result = await command.submit(
            record.url,
            { ...record.body, note },
            { method: record.method },
        );
        setOutcome(result ? 'saved' : 'attempted');
    };
    useImperativeHandle(ref, () => ({ run }));
    return (
        <article className="rounded-lg border bg-card p-4">
            <div className="flex items-start justify-between gap-3">
                <strong>{record.label}</strong>
                <a
                    className="text-sm text-primary underline"
                    href={record.href}
                    target="_blank"
                    rel="noreferrer"
                >
                    Open record
                </a>
            </div>
            <div className="mt-2 text-sm text-muted-foreground">
                {record.detail}
            </div>
            {children}
            {results && (
                <div className="mt-3 space-y-2" role="status">
                    {outcome === 'saved' ? (
                        <span className="flex items-center gap-2 text-sm text-status-success">
                            <CheckCircle2 className="size-4" />
                            Saved
                        </span>
                    ) : command.processing ? (
                        'Saving…'
                    ) : outcome === 'pending' ? (
                        'Waiting for earlier records'
                    ) : (
                        <>
                            <strong className="block text-sm">
                                {command.uncertain
                                    ? 'Result unconfirmed'
                                    : 'Needs attention'}
                            </strong>
                            <p className="text-sm">
                                {Object.values(command.errors).join(' ') ||
                                    command.message}
                            </p>
                            {command.uncertain && (
                                <Button
                                    className="min-h-[44px] sm:min-h-9"
                                    variant="outline"
                                    disabled={running}
                                    onClick={async () => {
                                        onBusy(true);
                                        await run(submittedNote.current);
                                        onBusy(false);
                                    }}
                                >
                                    Retry this record
                                </Button>
                            )}
                        </>
                    )}
                </div>
            )}
        </article>
    );
});
