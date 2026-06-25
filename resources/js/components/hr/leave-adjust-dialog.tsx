/* Adjust / opening-balance workflow — built on the shared Add-Client wizard
 * shell (resources/js/components/wizard) so it matches every other HR modal. */
import { router } from '@inertiajs/react';
import {
    ClipboardCheck,
    Minus,
    Plus,
    Scale,
    SlidersHorizontal,
    UserRound,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

import {
    Field,
    InfoCard,
    ReviewCard,
    ReviewRow,
    Segmented,
    SelectInput,
    StepHead,
    useWizard,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from './wizard';

type Mode = 'credit' | 'debit' | 'set_opening';

const STEPS: WizardStep[] = [
    {
        key: 'who',
        label: 'Person & type',
        blurb: 'Whose balance',
        icon: UserRound,
    },
    {
        key: 'amount',
        label: 'Adjustment',
        blurb: 'Credit, debit or opening',
        icon: Scale,
    },
    {
        key: 'review',
        label: 'Reason & review',
        blurb: 'Note & confirm',
        icon: ClipboardCheck,
    },
];

const MODE_LABEL: Record<Mode, string> = {
    credit: 'Credit +',
    debit: 'Debit −',
    set_opening: 'Set opening',
};

function typeLabel(type: string): string {
    return type.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

export function LeaveAdjustDialog({
    open,
    onClose,
    people,
    leaveTypes,
    year,
    currentBalanceFor,
    preset,
}: {
    open: boolean;
    onClose: () => void;
    people: Array<{ id: number; name: string }>;
    leaveTypes: string[];
    year: number;
    /** Returns the staff member's current remaining hours for a type, or null. */
    currentBalanceFor?: (userId: number, leaveType: string) => number | null;
    preset?: { user_id?: string; leave_type?: string };
}) {
    const wiz = useWizard(STEPS.length);
    const [submitting, setSubmitting] = useState(false);
    const [done, setDone] = useState(false);
    const [form, setForm] = useState({
        user_id: preset?.user_id ?? '',
        leave_type: preset?.leave_type ?? 'annual',
        mode: 'credit' as Mode,
        hours: '8',
        reason: '',
    });
    const set = <K extends keyof typeof form>(k: K, v: (typeof form)[K]) =>
        setForm((f) => ({ ...f, [k]: v }));

    const personName =
        people.find((p) => String(p.id) === form.user_id)?.name ?? '';
    const hoursNum = Number(form.hours) || 0;
    const current =
        form.user_id && currentBalanceFor
            ? currentBalanceFor(Number(form.user_id), form.leave_type)
            : null;
    const projected =
        current == null
            ? null
            : form.mode === 'set_opening'
              ? hoursNum
              : form.mode === 'credit'
                ? current + hoursNum
                : current - hoursNum;

    const step0Valid = form.user_id !== '' && form.leave_type !== '';
    const step1Valid =
        form.hours !== '' &&
        hoursNum >= 0 &&
        (form.mode === 'set_opening' || hoursNum > 0);
    const canContinue =
        wiz.index === 0 ? step0Valid : wiz.index === 1 ? step1Valid : true;

    const close = () => {
        onClose();
        // Reset shortly after the dialog animates out.
        window.setTimeout(() => {
            wiz.reset();
            setDone(false);
            setForm({
                user_id: preset?.user_id ?? '',
                leave_type: preset?.leave_type ?? 'annual',
                mode: 'credit',
                hours: '8',
                reason: '',
            });
        }, 150);
    };

    const submit = () => {
        if (!step0Valid || !step1Valid) return;
        setSubmitting(true);
        router.post(
            '/hr/leave/balances/adjust',
            {
                user_id: form.user_id,
                leave_type: form.leave_type,
                year,
                mode: form.mode,
                hours: hoursNum,
                reason: form.reason,
            },
            {
                preserveScroll: true,
                onSuccess: () => setDone(true),
                onError: () => toast.error('Could not apply the adjustment.'),
                onFinish: () => setSubmitting(false),
            },
        );
    };

    const modeOptions = useMemo(
        () =>
            (Object.keys(MODE_LABEL) as Mode[]).map((m) => ({
                value: m,
                label: MODE_LABEL[m],
                icon:
                    m === 'credit'
                        ? Plus
                        : m === 'debit'
                          ? Minus
                          : SlidersHorizontal,
            })),
        [],
    );

    return (
        <WizardShell
            open={open}
            onClose={close}
            title="Adjust leave balance"
            description="Credit, debit or set an opening leave balance for a staff member."
            railIcon={Scale}
            railTitle="Adjust balance"
            railSub={`Leave year ${year}`}
            steps={STEPS}
            stepIndex={wiz.index}
            onStepClick={(i) => {
                if (
                    i <= wiz.index ||
                    (i === 1 && step0Valid) ||
                    (step0Valid && step1Valid)
                )
                    wiz.goTo(i);
            }}
            pct={wiz.progress}
            maxWidth="min(94vw, 760px)"
            maxHeight="min(86vh, 620px)"
            success={
                done ? (
                    <WizardSuccessPane
                        title="Balance adjusted"
                        blurb={
                            <>
                                A ledger entry was recorded for{' '}
                                <strong>{personName}</strong> —{' '}
                                {typeLabel(form.leave_type)}. The audit trail
                                stays complete.
                            </>
                        }
                        actions={<Button onClick={close}>Done</Button>}
                    />
                ) : undefined
            }
            footerStart={
                wiz.index > 0 ? (
                    <Button variant="ghost" onClick={wiz.back}>
                        Back
                    </Button>
                ) : null
            }
            footerEnd={
                wiz.isLast ? (
                    <Button onClick={submit} disabled={submitting}>
                        {submitting ? 'Applying…' : 'Apply adjustment'}
                    </Button>
                ) : (
                    <Button onClick={wiz.next} disabled={!canContinue}>
                        Continue
                    </Button>
                )
            }
        >
            {wiz.index === 0 ? (
                <WizardStepPane>
                    <StepHead
                        icon={UserRound}
                        title="Person & leave type"
                        blurb="Pick the staff member and which entitlement to adjust."
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Staff member" required>
                            <SelectInput
                                value={form.user_id}
                                onChange={(v) => set('user_id', v)}
                                placeholder="Select a staff member…"
                                ariaLabel="Staff member"
                                options={people.map((p) => ({
                                    value: String(p.id),
                                    label: p.name,
                                }))}
                            />
                        </Field>
                        <Field label="Leave type" required>
                            <SelectInput
                                value={form.leave_type}
                                onChange={(v) => set('leave_type', v)}
                                placeholder="Select a leave type…"
                                ariaLabel="Leave type"
                                options={leaveTypes.map((t) => ({
                                    value: t,
                                    label: typeLabel(t),
                                }))}
                            />
                        </Field>
                        {current != null ? (
                            <InfoCard icon={Scale}>
                                {personName} currently has{' '}
                                <strong>{current}h</strong> of{' '}
                                {typeLabel(form.leave_type)} remaining.
                            </InfoCard>
                        ) : null}
                    </div>
                </WizardStepPane>
            ) : null}

            {wiz.index === 1 ? (
                <WizardStepPane>
                    <StepHead
                        icon={Scale}
                        title="Adjustment"
                        blurb="Credit hours, debit hours, or set the opening balance."
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Type of adjustment" span>
                            <Segmented<Mode>
                                value={form.mode}
                                onChange={(v) => set('mode', v)}
                                options={modeOptions}
                            />
                        </Field>
                        <Field
                            label="Hours"
                            required
                            hint={
                                form.mode === 'set_opening'
                                    ? '(new opening balance)'
                                    : undefined
                            }
                        >
                            <Input
                                type="number"
                                min="0"
                                step="0.5"
                                value={form.hours}
                                onChange={(e) => set('hours', e.target.value)}
                            />
                        </Field>
                        {projected != null ? (
                            <Field label="Resulting balance">
                                <div className="flex h-9 items-center gap-2 rounded-md border border-border px-3 text-sm font-semibold">
                                    <span className="text-muted-foreground tabular-nums">
                                        {current}h
                                    </span>
                                    <span className="text-muted-foreground">
                                        →
                                    </span>
                                    <span
                                        className={cn(
                                            'tabular-nums',
                                            projected < 0 &&
                                                'text-status-critical',
                                        )}
                                    >
                                        {projected}h
                                    </span>
                                </div>
                            </Field>
                        ) : null}
                    </div>
                </WizardStepPane>
            ) : null}

            {wiz.index === 2 ? (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title="Reason & review"
                        blurb="Add a note for the audit trail, then apply."
                    />
                    <div className="grid gap-4">
                        <Field
                            label="Reason"
                            hint="(recorded on the ledger entry)"
                        >
                            <Textarea
                                rows={2}
                                value={form.reason}
                                onChange={(e) => set('reason', e.target.value)}
                                placeholder="e.g. Opening balance migrated from PayHero"
                            />
                        </Field>
                        <ReviewCard icon={Scale} title="Adjustment summary">
                            <ReviewRow
                                label="Staff member"
                                value={personName}
                            />
                            <ReviewRow
                                label="Leave type"
                                value={typeLabel(form.leave_type)}
                            />
                            <ReviewRow
                                label="Adjustment"
                                value={`${MODE_LABEL[form.mode]} · ${hoursNum}h`}
                            />
                            {projected != null ? (
                                <ReviewRow
                                    label="Balance"
                                    value={`${current}h → ${projected}h`}
                                />
                            ) : null}
                            <ReviewRow
                                label="Reason"
                                value={form.reason || undefined}
                            />
                        </ReviewCard>
                    </div>
                </WizardStepPane>
            ) : null}
        </WizardShell>
    );
}

export default LeaveAdjustDialog;
