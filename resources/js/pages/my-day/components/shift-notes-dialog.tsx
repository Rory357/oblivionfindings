import { ConfirmDialog } from '@/components/confirm-dialog';
import HandoverPersonNotes from '@/components/handover-person-notes';
import HandoverWriteForm from '@/components/handover-write-form';
import { Button } from '@/components/ui/button';
import {
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { useHandoverDraftSave } from '@/hooks/use-handover-draft-save';
import { useHandoverEditor } from '@/hooks/use-handover-editor';
import { formatTime } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import { Check, FileText, Home, Loader2, User } from 'lucide-react';
import { useState } from 'react';

export type ShiftNotesDialogProps = {
    shiftId: number | null;
    alreadySubmitted?: boolean;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export default function ShiftNotesDialog(props: ShiftNotesDialogProps) {
    return props.open ? (
        <ShiftNotesBody key={props.shiftId} {...props} />
    ) : null;
}

function ShiftNotesBody({
    shiftId,
    alreadySubmitted,
    onOpenChange,
}: ShiftNotesDialogProps) {
    const { editor, setEditor, value, setValue, loading, error, retry } =
        useHandoverEditor(shiftId, true);
    const [step, setStep] = useState(0);
    const [discardOpen, setDiscardOpen] = useState(false);
    const [reloadOpen, setReloadOpen] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [saved, setSaved] = useState(false);
    const people = editor?.people ?? [];
    const steps = [
        ...people.map((person) => ({
            key: String(person.id),
            label: person.name,
            blurb: (() => {
                const note = editor?.worker_notes.people.find(
                    (note) => note.client_id === person.id,
                );
                return note?.not_supported
                    ? 'Not supported this shift'
                    : note &&
                        (note.notes || note.no_updates || note.follow_up_needed)
                      ? 'Draft saved'
                      : 'Not started';
            })(),
            icon: User,
        })),
        {
            key: 'site',
            label: 'Whole site',
            blurb: 'Shared practical matters',
            icon: Home,
        },
        {
            key: 'review',
            label: 'Review notes',
            blurb: 'Check and save your draft',
            icon: Check,
        },
    ];
    const readOnly =
        alreadySubmitted ||
        editor?.status === 'submitted' ||
        editor?.status === 'acknowledged';
    const draft = useHandoverDraftSave({
        shiftId,
        editor,
        value,
        loading,
        readOnly: !!readOnly,
        setEditor,
        setValue,
    });
    const { dirty } = draft;
    const unavailable = submitting || loading || !!error || !shiftId;
    const review = step === steps.length - 1;
    const refreshSummary = () =>
        router.reload({
            only: ['handover_draft', 'outgoing_handover'],
            preserveScroll: true,
        });
    const close = async () => {
        if (submitting) return;
        if (dirty && !saved) {
            setSubmitting(true);
            const kept = await draft.flush();
            setSubmitting(false);
            if (!kept) {
                setDiscardOpen(true);
                return;
            }
        }
        refreshSummary();
        onOpenChange(false);
    };
    const save = async () => {
        if (unavailable || readOnly || !value.worker_notes) return;
        setSubmitting(true);
        const kept = await draft.flush();
        if (kept) {
            setSaved(true);
            refreshSummary();
        }
        setSubmitting(false);
    };
    return (
        <>
            <WizardShell
                open
                onClose={close}
                title="Shift notes"
                description="Write a separate note for each person. Your private draft saves as you type; sending the handover is a separate action."
                railIcon={FileText}
                railTitle="Shift notes"
                railSub="One place for each person's notes"
                steps={steps}
                stepIndex={step}
                onStepClick={(next) => !unavailable && setStep(next)}
                pct={null}
                railExtra={
                    <p role="status" className="text-sm text-muted-foreground">
                        {readOnly
                            ? 'This handover has been sent and is read-only.'
                            : draft.saving
                              ? 'Saving private draft…'
                              : draft.error
                                ? 'Not saved — your answers are still here.'
                                : dirty
                                  ? 'Changes waiting to save…'
                                  : editor?.handover_id
                                    ? `Saved${editor.saved_at ? ' at ' + formatTime(editor.saved_at) : ''} · not sent yet.`
                                    : 'Private draft · saves as you type.'}
                    </p>
                }
                footerStart={
                    <>
                        <Button
                            variant="outline"
                            className="min-h-11"
                            onClick={close}
                            disabled={submitting}
                        >
                            Close
                        </Button>
                        {step > 0 && (
                            <Button
                                variant="ghost"
                                className="min-h-11"
                                onClick={() => setStep(step - 1)}
                                disabled={unavailable}
                            >
                                Back
                            </Button>
                        )}
                    </>
                }
                footerEnd={
                    review ? (
                        <Button
                            className="min-h-11"
                            onClick={save}
                            disabled={
                                unavailable ||
                                readOnly ||
                                (!editor?.handover_id && !dirty)
                            }
                        >
                            {submitting ? (
                                <Loader2 className="animate-spin" />
                            ) : (
                                <FileText />
                            )}
                            Save draft
                        </Button>
                    ) : (
                        <Button
                            className="min-h-11"
                            onClick={() => setStep(step + 1)}
                            disabled={unavailable}
                        >
                            Next: {steps[step + 1].label}
                        </Button>
                    )
                }
                success={
                    saved ? (
                        <WizardSuccessPane
                            title="Shift notes saved"
                            blurb="Your draft is saved. Review the handover and choose the incoming shift when you are ready to send it."
                            actions={
                                <>
                                    <Button
                                        variant="outline"
                                        className="min-h-11"
                                        onClick={() => onOpenChange(false)}
                                    >
                                        Back to My Day
                                    </Button>
                                    <Button
                                        className="min-h-11"
                                        onClick={() =>
                                            router.visit(
                                                editor?.review_url ??
                                                    '/operations/handovers',
                                            )
                                        }
                                    >
                                        Review and send
                                    </Button>
                                </>
                            }
                        />
                    ) : undefined
                }
            >
                <WizardStepPane key={step}>
                    {loading ? (
                        <p role="status">Loading people and saved notes…</p>
                    ) : error ? (
                        <div role="alert">
                            <p>{error}</p>
                            <Button variant="outline" onClick={retry}>
                                Retry
                            </Button>
                        </div>
                    ) : !shiftId ? (
                        <p>No current shift is available.</p>
                    ) : review ? (
                        <>
                            <h2 className="mb-2 text-lg font-semibold">
                                Check your shift notes
                            </h2>
                            <p className="mb-4 text-sm text-muted-foreground">
                                Only the people with a note or an explicit
                                support update are included. Saving keeps this
                                as a draft.
                            </p>
                            <HandoverPersonNotes
                                notes={
                                    value.worker_notes
                                        ? {
                                              ...value.worker_notes,
                                              people: value.worker_notes.people
                                                  .filter(
                                                      (p) =>
                                                          p.notes.trim() ||
                                                          p.no_updates ||
                                                          p.follow_up_needed ||
                                                          p.not_supported,
                                                  )
                                                  .map((p) => ({
                                                      ...p,
                                                      name: people.find(
                                                          (person) =>
                                                              person.id ===
                                                              p.client_id,
                                                      )?.name,
                                                  })),
                                          }
                                        : null
                                }
                            />
                        </>
                    ) : (
                        <HandoverWriteForm
                            value={value}
                            onChange={(next) => {
                                setValue(next);
                            }}
                            disabled={submitting || readOnly}
                            people={step < people.length ? [people[step]] : []}
                            showSharedNotes={step === people.length}
                        />
                    )}
                    {draft.error && (
                        <div
                            role="alert"
                            className="mt-4 rounded-lg border border-status-critical/30 bg-status-critical-bg p-4 text-sm text-status-critical"
                        >
                            <p>{draft.error}</p>
                            <div className="mt-3 flex flex-wrap gap-2">
                                <Button
                                    variant="outline"
                                    className="frontline-tap"
                                    disabled={draft.saving}
                                    onClick={() => void draft.retrySave()}
                                >
                                    Check saved draft and retry
                                </Button>
                                <Button
                                    variant="outline"
                                    className="frontline-tap"
                                    disabled={draft.saving}
                                    onClick={() => setReloadOpen(true)}
                                >
                                    Load saved draft
                                </Button>
                            </div>
                        </div>
                    )}
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                open={discardOpen}
                onClose={() => setDiscardOpen(false)}
                onConfirm={() => onOpenChange(false)}
                title="Discard unsaved changes?"
                description="Your previously saved draft will stay available. The changes you just made will be lost."
                confirmText="Discard changes"
            />
            <ConfirmDialog
                open={reloadOpen}
                onClose={() => setReloadOpen(false)}
                onConfirm={() => {
                    setReloadOpen(false);
                    retry();
                }}
                title="Load the saved draft?"
                description="This replaces your unsaved answers with the latest private draft. The saved draft is kept."
                confirmText="Load saved draft"
            />
        </>
    );
}
