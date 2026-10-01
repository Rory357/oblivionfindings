/* The worker's own medication competency assessment, waiting for their
 * acknowledgement (eMAR P11 v5 `dialogs-elig.tsx` Acknowledge). An assessment
 * counts only once the assessed person acknowledges it from their own login.
 * Shown on Meds today for now; Staff eligibility › My eligibility (P11
 * chunk 6) becomes its permanent home. */
import { SettingsModal } from '@/components/settings/settings-modal';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { formatDateOnly } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import { AlertTriangle, ClipboardCheck } from 'lucide-react';
import { useState } from 'react';

export type PendingAssessment = {
    id: number;
    assessed_on: string | null;
    assessor: string | null;
    ends_on: string | null;
    passed_areas: number;
    not_passed: string[];
    not_assessed: string[];
    restriction: string | null;
    to_work_on: string | null;
    /** Can they already record doses as given (another assessment or an exemption)? */
    can_give_now: boolean;
};

const day = (d: string | null) => (d ? formatDateOnly(d) : '—');

export function PendingAssessmentNotice({
    assessment,
}: {
    assessment: PendingAssessment | null | undefined;
}) {
    const [open, setOpen] = useState(false);
    if (!assessment) return null;
    return (
        <>
            <div
                role="status"
                className="flex flex-wrap items-start gap-3 rounded-lg border border-status-warning/30 bg-status-warning-bg p-3 text-sm"
            >
                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-status-warning" />
                <div className="min-w-0 flex-1">
                    <p className="font-medium text-status-warning">
                        Your new medication assessment is waiting for you
                    </p>
                    <p className="mt-0.5 text-xs text-foreground/80">
                        {assessment.assessor ?? 'Your assessor'} recorded it on{' '}
                        {day(assessment.assessed_on)}. It counts once you
                        acknowledge it
                        {assessment.can_give_now
                            ? '.'
                            : ' — until then you can’t record doses as given.'}
                    </p>
                </div>
                <Button
                    size="sm"
                    className="frontline-tap ml-auto shrink-0"
                    onClick={() => setOpen(true)}
                >
                    Read and acknowledge
                </Button>
            </div>
            {open ? (
                <AcknowledgeAssessment
                    assessment={assessment}
                    onClose={() => setOpen(false)}
                />
            ) : null}
        </>
    );
}

export function AcknowledgeAssessment({
    assessment,
    onClose,
}: {
    assessment: PendingAssessment;
    onClose: () => void;
}) {
    const [on, setOn] = useState(false);
    const [err, setErr] = useState(false);
    const [saving, setSaving] = useState(false);
    const [problem, setProblem] = useState('');
    const acknowledge = () => {
        if (!on) {
            setErr(true);
            document.getElementById('ack-tick')?.focus();
            return;
        }
        router.post(
            `/emar/competency/${assessment.id}/acknowledge`,
            {},
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onSuccess: () => onClose(),
                onError: () =>
                    setProblem(
                        'Couldn’t acknowledge it — nothing was changed. Try again.',
                    ),
                onFinish: () => setSaving(false),
            },
        );
    };
    return (
        <SettingsModal
            title="Acknowledge your assessment"
            description={`Recorded by ${assessment.assessor ?? 'your assessor'} on ${day(assessment.assessed_on)}. Only you can acknowledge it.`}
            onClose={onClose}
            footer={
                <>
                    {/* Support workers tap these on shift: 44 px targets (Rory, P11). */}
                    <Button
                        variant="outline"
                        className="frontline-tap"
                        onClick={onClose}
                    >
                        Cancel
                    </Button>
                    <Button
                        className="frontline-tap"
                        onClick={acknowledge}
                        disabled={saving}
                    >
                        Acknowledge
                    </Button>
                </>
            }
        >
            <ReviewCard icon={ClipboardCheck} title="Your result">
                <ReviewRow
                    label="Result"
                    value={
                        <b>Passed · {assessment.passed_areas} of 12 areas</b>
                    }
                />
                <ReviewRow label="Ends" value={day(assessment.ends_on)} />
                <ReviewRow
                    label="Not passed"
                    value={assessment.not_passed.join(', ') || 'None'}
                />
                <ReviewRow
                    label="Not assessed"
                    value={assessment.not_assessed.join(', ') || 'None'}
                />
                <ReviewRow
                    label="Restriction"
                    value={assessment.restriction ?? 'None'}
                />
                {assessment.to_work_on ? (
                    <ReviewRow
                        label="What to work on"
                        value={assessment.to_work_on}
                    />
                ) : null}
            </ReviewCard>
            <div className="flex items-start justify-between gap-4 rounded-xl border p-3">
                <div>
                    <label
                        htmlFor="ack-tick"
                        className="text-[13px] font-semibold"
                    >
                        I’ve read my assessment and I understand what I can and
                        can’t do <span className="text-status-critical">*</span>
                    </label>
                    {err ? (
                        <p
                            className="mt-1 text-xs text-status-critical"
                            role="alert"
                        >
                            Turn this on to acknowledge.
                        </p>
                    ) : null}
                </div>
                <span className="inline-flex items-center gap-3">
                    <Switch
                        id="ack-tick"
                        className="frontline-hit"
                        checked={on}
                        aria-invalid={err || undefined}
                        onCheckedChange={(v) => {
                            setOn(v);
                            setErr(false);
                        }}
                    />
                    <span className="text-subtle w-7" aria-hidden="true">
                        {on ? 'On' : 'Off'}
                    </span>
                </span>
            </div>
            {problem ? (
                <p className="text-sm text-status-critical" role="alert">
                    {problem}
                </p>
            ) : null}
            <p className="text-caption">
                Recorded in the audit log with your name and the time.
            </p>
        </SettingsModal>
    );
}
