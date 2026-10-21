import { ConfirmDialog } from '@/components/confirm-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { Field } from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { formatDateTime } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { ClipboardCheck, ListChecks } from 'lucide-react';
import { useRef, useState } from 'react';
import { checklistGateLabel, type ChecklistRecord } from './checklist-types';
import { label, referralHref, type ReferralDetail } from './types';

type Selection = {
    template: ChecklistRecord;
    item: ChecklistRecord['items'][number];
};
export function ChecklistPanel({ referral }: { referral: ReferralDetail }) {
    const [selection, setSelection] = useState<Selection | null>(null);
    if (!referral.checklists?.length) return null;
    return (
        <div className="space-y-5">
            {referral.checklists.map((template) => (
                <Card key={template.id}>
                    <CardHeader>
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <h2 className="text-section-title">
                                {template.label}
                            </h2>
                            <StatusBadge
                                variant={
                                    template.items.every(
                                        (item) => item.completed,
                                    )
                                        ? 'success'
                                        : 'info'
                                }
                            >
                                {
                                    template.items.filter(
                                        (item) => item.completed,
                                    ).length
                                }{' '}
                                of {template.items.length} completed
                            </StatusBadge>
                        </div>
                        <p className="text-subtle">
                            {checklistGateLabel(template.gate)} ·{' '}
                            {label(template.audience)}. Required steps also
                            apply at later gates.
                        </p>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <ul className="divide-y">
                            {template.items.map((item) => (
                                <li
                                    key={item.id}
                                    className="space-y-3 py-4 first:pt-0"
                                >
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div className="min-w-0 flex-1">
                                            <p className="text-subtle font-medium break-words">
                                                {item.title}
                                                {item.required
                                                    ? ' · Required'
                                                    : ' · Optional'}
                                            </p>
                                            {item.instruction && (
                                                <p className="text-subtle mt-1 break-words text-muted-foreground">
                                                    {item.instruction}
                                                </p>
                                            )}
                                        </div>
                                        <StatusBadge
                                            variant={
                                                item.completed
                                                    ? 'success'
                                                    : item.decision?.completed
                                                      ? 'warning'
                                                      : 'neutral'
                                            }
                                        >
                                            {item.completed
                                                ? 'Completed'
                                                : item.decision?.completed
                                                  ? 'Review changed requirements'
                                                  : 'To do'}
                                        </StatusBadge>
                                    </div>
                                    {item.decision && (
                                        <details>
                                            <summary className="text-subtle cursor-pointer rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
                                                Last recorded review ·{' '}
                                                {item.decision.recorded_by_name}
                                            </summary>
                                            <div className="text-subtle mt-2 space-y-2">
                                                <p>
                                                    {formatDateTime(
                                                        item.decision
                                                            .recorded_at,
                                                    )}{' '}
                                                    ·{' '}
                                                    {item.decision.completed
                                                        ? 'Recorded complete'
                                                        : 'Reopened'}
                                                </p>
                                                <p className="break-words whitespace-pre-wrap">
                                                    {item.decision.note}
                                                </p>
                                                {item.decision.item.title !==
                                                    item.title && (
                                                    <p>
                                                        Recorded step:{' '}
                                                        {
                                                            item.decision.item
                                                                .title
                                                        }
                                                    </p>
                                                )}
                                            </div>
                                        </details>
                                    )}
                                    {template.editable && (
                                        <Button
                                            variant="outline"
                                            aria-label={`${item.completed ? 'Reopen' : 'Record completion for'} ${item.title}`}
                                            onClick={() =>
                                                setSelection({ template, item })
                                            }
                                        >
                                            {item.completed
                                                ? 'Reopen item'
                                                : 'Record completion'}
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                        {!template.editable && (
                            <p className="text-subtle">
                                Updating requires an open referral, current
                                collection authority, follow-up management and
                                the checklist’s audience and responsible-role
                                permissions.
                            </p>
                        )}
                        <p className="text-subtle text-muted-foreground">
                            Checklist completion records preparation. Consent,
                            assessment, funding and service-start approvals
                            remain separate.
                        </p>
                    </CardContent>
                </Card>
            ))}
            {selection && (
                <ChecklistEditor
                    key={`${referral.reference}:${selection.template.id}:${selection.item.id}`}
                    referral={referral}
                    selection={selection}
                    onClose={() => setSelection(null)}
                />
            )}
        </div>
    );
}

function ChecklistEditor({
    referral,
    selection,
    onClose,
}: {
    referral: ReferralDetail;
    selection: Selection;
    onClose: () => void;
}) {
    const [version] = useState(referral.version);
    const [step, setStep] = useState(0);
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);
    const [saved, setSaved] = useState(false);
    const [uncertain, setUncertain] = useState(false);
    const [discard, setDiscard] = useState(false);
    const [error, setError] = useState('');
    const pending = useRef(false);
    const body = useRef<HTMLDivElement>(null);
    const complete = !selection.item.completed;
    const title = complete
        ? 'Record checklist completion'
        : 'Reopen checklist item';
    function close() {
        if (busy) return;
        if (saved) onClose();
        else setDiscard(true);
    }
    function go(step: number) {
        setStep(step);
        requestAnimationFrame(() => body.current?.scrollTo({ top: 0 }));
    }
    async function save() {
        if (pending.current || saved || uncertain || note.trim().length < 5)
            return;
        pending.current = true;
        setBusy(true);
        setError('');
        try {
            const response = await axios.patch(
                `${referralHref(referral.reference)}/checklists`,
                {
                    version,
                    template_id: selection.template.id,
                    item_id: selection.item.id,
                    completed: complete,
                    note,
                },
            );
            if (!response.data.saved) throw new Error('Save not confirmed');
            setSaved(true);
            router.reload({ only: ['referral'], preserveScroll: true });
        } catch (caught) {
            const status = axios.isAxiosError(caught)
                ? caught.response?.status
                : null;
            const errors = axios.isAxiosError(caught)
                ? caught.response?.data?.errors
                : null;
            setUncertain(status !== 422);
            setError(
                status === 422
                    ? errors
                        ? Object.values(errors as Record<string, string[]>)
                              .flat()
                              .join(' ')
                        : 'Review current collection authority and the checklist before saving.'
                    : 'Save was not confirmed. Close this draft and reload the referral to check the recorded checklist before trying again.',
            );
            if (status === 422) go(0);
        } finally {
            pending.current = false;
            setBusy(false);
        }
    }
    return (
        <>
            <WizardShell
                open
                onClose={close}
                title={title}
                description={referral.name}
                railIcon={ListChecks}
                railTitle="Preparation checklist"
                railSub={selection.template.label}
                steps={[
                    {
                        key: 'detail',
                        label: 'Record',
                        blurb: 'Explain the preparation',
                        icon: ListChecks,
                    },
                    {
                        key: 'review',
                        label: 'Review',
                        blurb: 'Check before recording',
                        icon: ClipboardCheck,
                    },
                ]}
                stepIndex={step}
                onStepClick={(value) => {
                    if (!busy && !saved) go(value);
                }}
                bodyRef={body}
                footerStart={
                    saved ? null : (
                        <Button
                            variant="ghost"
                            disabled={busy}
                            onClick={() => (step ? go(0) : close())}
                        >
                            {step ? 'Back' : 'Cancel'}
                        </Button>
                    )
                }
                footerEnd={
                    saved ? null : step === 0 ? (
                        <Button
                            disabled={
                                busy || uncertain || note.trim().length < 5
                            }
                            onClick={() => go(1)}
                        >
                            Continue
                        </Button>
                    ) : (
                        <Button
                            disabled={
                                busy || uncertain || note.trim().length < 5
                            }
                            onClick={() => void save()}
                        >
                            {busy
                                ? 'Saving…'
                                : complete
                                  ? 'Record completion'
                                  : 'Reopen item'}
                        </Button>
                    )
                }
            >
                {saved ? (
                    <WizardSuccessPane
                        title="Checklist updated"
                        blurb="Your review and the checklist wording are recorded in the permitted audience. Required preparation will be checked again at the next gate."
                        actions={<Button onClick={onClose}>Done</Button>}
                    />
                ) : (
                    <div className="space-y-5">
                        {error && (
                            <Alert variant="warning">
                                <AlertDescription>{error}</AlertDescription>
                            </Alert>
                        )}
                        {step === 0 ? (
                            <fieldset
                                disabled={busy || uncertain}
                                className="space-y-4"
                            >
                                <h2 className="text-section-title">
                                    {selection.item.title}
                                </h2>
                                <p className="text-subtle">
                                    {selection.item.instruction}
                                </p>
                                <p className="text-subtle">
                                    {complete
                                        ? 'Record only work that has actually been completed. This does not record a consent decision or approve funding.'
                                        : 'Reopening this item makes required preparation incomplete. Previous history is retained and service readiness may need review; active support is not silently stopped.'}
                                </p>
                                <Field
                                    label={
                                        complete
                                            ? 'What was completed?'
                                            : 'Why does this need more work?'
                                    }
                                >
                                    <Textarea
                                        value={note}
                                        maxLength={2000}
                                        onChange={(event) =>
                                            setNote(event.target.value)
                                        }
                                    />
                                </Field>
                            </fieldset>
                        ) : (
                            <ReviewCard
                                icon={ClipboardCheck}
                                title="Checklist review"
                            >
                                <ReviewRow
                                    label="Checklist"
                                    value={selection.template.label}
                                />
                                <ReviewRow
                                    label="Step"
                                    value={selection.item.title}
                                />
                                <ReviewRow
                                    label="Outcome"
                                    value={complete ? 'Completed' : 'Reopened'}
                                />
                                <ReviewRow
                                    label="Information audience"
                                    value={label(selection.template.audience)}
                                />
                                <ReviewRow label="Note" value={note} />
                            </ReviewCard>
                        )}
                    </div>
                )}
            </WizardShell>
            <ConfirmDialog
                variant="destructive"
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={onClose}
                title="Discard this review?"
                description="Unsaved notes will be cleared. If a save was not confirmed, check the recorded checklist before trying again."
                confirmText="Discard draft"
            />
        </>
    );
}
