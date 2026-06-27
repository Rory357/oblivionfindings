/* eslint-disable no-restricted-syntax -- This dialog is built on the shared HR
 * wizard kit (components/hr/wizard → components/wizard/*), which intentionally
 * uses styled native controls for the rail/footer. Every colour is a semantic
 * design token (docs/DESIGN_TOKENS.md). Mirrors the offer + band wizards. */
import { router } from '@inertiajs/react';
import {
    Banknote,
    CheckCircle2,
    ClipboardCheck,
    HandCoins,
    HeartPulse,
    PiggyBank,
    Shield,
    UserRound,
} from 'lucide-react';
import { FormEvent, useMemo, useState } from 'react';
import { toast } from 'sonner';

import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Button } from '@/components/ui/button';

import {
    Field,
    InfoCard,
    ReviewCard,
    ReviewRow,
    Ring,
    Segmented,
    SelectInput,
    StepHead,
    SubHead,
    TilePicker,
    useWizard,
    WizardShell,
    WizardStepPane,
    type IconType,
    type WizardStep,
} from './wizard';

/* ------------------------------------------------------------------ */
/*  Types (mirror the host page's Props)                               */
/* ------------------------------------------------------------------ */

export interface BenefitPlanOption {
    id: number;
    name: string;
    type: string;
    /** Optional — default employer contribution if the controller exposes it. */
    employer_contribution_rate?: string | number | null;
}

export interface BenefitEmployeeOption {
    id: number;
    user: { id: number; name: string } | null;
    position_title: string | null;
}

/** Shape of the enrollment being edited (subset of the host Enrollment type). */
export interface BenefitEnrollmentEdit {
    id: number;
    status: string;
    employee_contribution_rate: string;
    employer_contribution_rate: string;
    opt_out_date: string | null;
    notes: string | null;
    employee_profile: { id: number; user: { id: number; name: string } };
    benefit_plan: BenefitPlanOption;
}

const PLAN_TYPE_LABELS: Record<string, string> = {
    kiwisaver: 'KiwiSaver',
    health_insurance: 'Health Insurance',
    life_insurance: 'Life Insurance',
    other: 'Other',
};

const PLAN_TYPE_ICON: Record<string, IconType> = {
    kiwisaver: PiggyBank,
    health_insurance: HeartPulse,
    life_insurance: Shield,
    other: HandCoins,
};

/** KiwiSaver employee contribution presets (statutory NZ options). */
const KIWISAVER_EMPLOYEE_RATES = ['3', '4', '6', '8', '10'];

/** KiwiSaver minimum compulsory employer contribution (3% of gross pay). */
const KIWISAVER_EMPLOYER_MIN = 3;

const STATUS_OPTIONS = [
    { value: 'active', label: 'Active' },
    { value: 'opted_out', label: 'Opted out' },
    { value: 'suspended', label: 'Suspended' },
    { value: 'terminated', label: 'Terminated' },
] as const;

const todayIso = () => new Date().toISOString().slice(0, 10);

const nzd = new Intl.NumberFormat('en-NZ', {
    style: 'currency',
    currency: 'NZD',
    maximumFractionDigits: 0,
});
const nzd2 = new Intl.NumberFormat('en-NZ', {
    style: 'currency',
    currency: 'NZD',
    maximumFractionDigits: 2,
});

const num = (v: string) => {
    const n = parseFloat(v);
    return Number.isNaN(n) ? NaN : n;
};

/** Opt-out reason is folded into notes (no dedicated column) with this tag so it
 *  round-trips visibly. See backendNeeded for the optional first-class column. */
const REASON_TAG = 'Opt-out reason:';

interface FormState {
    employee_profile_id: string;
    benefit_plan_id: string;
    enrollment_date: string;
    employee_contribution_rate: string;
    employer_contribution_rate: string;
    notes: string;
    // edit-only
    status: string;
    opt_out_date: string;
    opt_out_reason: string;
}

const STEPS: readonly WizardStep[] = [
    { key: 'who', label: 'Employee & plan', blurb: 'Who & which benefit', icon: UserRound },
    { key: 'contrib', label: 'Contributions', blurb: 'Rates & cost', icon: Banknote },
    { key: 'review', label: 'Review', blurb: 'Confirm & save', icon: ClipboardCheck },
];

export function BenefitsEnrollDialog({
    open,
    onClose,
    plans,
    employees,
    edit = null,
    /** Optional map of employee_profile_id → annual salary (NZD) for cost preview.
     *  Absent → the contribution step previews by rate only. */
    annualSalaryByProfileId,
}: {
    open: boolean;
    onClose: () => void;
    plans: BenefitPlanOption[];
    employees: BenefitEmployeeOption[];
    edit?: BenefitEnrollmentEdit | null;
    annualSalaryByProfileId?: Record<number, number | string | null | undefined>;
}) {
    const isEdit = edit !== null;
    const wiz = useWizard(STEPS.length);
    const [saving, setSaving] = useState(false);

    const initial = useMemo<FormState>(() => {
        if (edit) {
            const reasonFromNotes = (edit.notes ?? '')
                .split('\n')
                .find((l) => l.trim().startsWith(REASON_TAG));
            return {
                employee_profile_id: String(edit.employee_profile.id),
                benefit_plan_id: String(edit.benefit_plan.id),
                enrollment_date: todayIso(),
                employee_contribution_rate: edit.employee_contribution_rate ?? '',
                employer_contribution_rate: edit.employer_contribution_rate ?? '',
                notes: edit.notes ?? '',
                status: edit.status,
                opt_out_date: edit.opt_out_date ?? '',
                opt_out_reason: reasonFromNotes
                    ? reasonFromNotes.replace(REASON_TAG, '').trim()
                    : '',
            };
        }
        return {
            employee_profile_id: '',
            benefit_plan_id: '',
            enrollment_date: todayIso(),
            employee_contribution_rate: '',
            employer_contribution_rate: '',
            notes: '',
            status: 'active',
            opt_out_date: '',
            opt_out_reason: '',
        };
    }, [edit]);

    const [form, setForm] = useState<FormState>(initial);
    const [serverErrors, setServerErrors] = useState<Record<string, string>>({});

    // Re-seed the form whenever the dialog is (re)opened for a new target.
    const [seedKey, setSeedKey] = useState<string>('');
    const thisKey = `${open ? 1 : 0}:${edit?.id ?? 'new'}`;
    if (open && thisKey !== seedKey) {
        setSeedKey(thisKey);
        setForm(initial);
        setServerErrors({});
        wiz.reset();
    }

    const set = <K extends keyof FormState>(key: K, value: FormState[K]) =>
        setForm((prev) => ({ ...prev, [key]: value }));

    const selectedPlan = useMemo(
        () => plans.find((p) => String(p.id) === form.benefit_plan_id) ?? edit?.benefit_plan ?? null,
        [plans, form.benefit_plan_id, edit],
    );
    const planType = selectedPlan?.type ?? '';
    const isKiwiSaver = planType === 'kiwisaver';

    const selectedEmployeeName = useMemo(() => {
        if (edit) return edit.employee_profile.user?.name ?? `Profile #${edit.employee_profile.id}`;
        const e = employees.find((x) => String(x.id) === form.employee_profile_id);
        return e?.user?.name ?? '';
    }, [edit, employees, form.employee_profile_id]);

    const empRate = num(form.employee_contribution_rate);
    const erRate = num(form.employer_contribution_rate);

    // 3% employer-minimum guard — applies to KiwiSaver only (statutory floor).
    const employerBelowMin =
        isKiwiSaver && !Number.isNaN(erRate) && erRate < KIWISAVER_EMPLOYER_MIN;

    // Optional cost preview from a passed-in annual salary.
    const annualSalaryRaw = form.employee_profile_id
        ? annualSalaryByProfileId?.[Number(form.employee_profile_id)]
        : undefined;
    const annualSalary =
        annualSalaryRaw == null ? NaN : typeof annualSalaryRaw === 'number' ? annualSalaryRaw : parseFloat(annualSalaryRaw);
    const hasSalary = !Number.isNaN(annualSalary) && annualSalary > 0;

    const cost = useMemo(() => {
        const ee = Number.isNaN(empRate) ? 0 : empRate;
        const er = Number.isNaN(erRate) ? 0 : erRate;
        if (!hasSalary) return null;
        const eeAnnual = (annualSalary * ee) / 100;
        const erAnnual = (annualSalary * er) / 100;
        return {
            eeAnnual,
            erAnnual,
            eeMonthly: eeAnnual / 12,
            erMonthly: erAnnual / 12,
            totalAnnual: eeAnnual + erAnnual,
            totalMonthly: (eeAnnual + erAnnual) / 12,
        };
    }, [annualSalary, hasSalary, empRate, erRate]);

    const optedOut = isEdit && form.status === 'opted_out';

    /* ----- validation / completeness ----- */
    const stepValid = (i: number) => {
        if (i === 0) {
            return (
                form.employee_profile_id !== '' &&
                form.benefit_plan_id !== '' &&
                form.enrollment_date !== ''
            );
        }
        if (i === 1) {
            const eeOk = !Number.isNaN(empRate) && empRate >= 0 && empRate <= 100;
            const erOk =
                form.employer_contribution_rate === '' ||
                (!Number.isNaN(erRate) && erRate >= 0 && erRate <= 100 && !employerBelowMin);
            // Opt-out requires a date when the status is opted_out (on edit).
            const optOk = !optedOut || form.opt_out_date !== '';
            return eeOk && erOk && optOk;
        }
        return true;
    };

    const formValid = stepValid(0) && stepValid(1);

    const completeness = useMemo(() => {
        const checks = [
            form.employee_profile_id !== '',
            form.benefit_plan_id !== '',
            !Number.isNaN(empRate) && empRate >= 0,
            form.employer_contribution_rate === '' || (!Number.isNaN(erRate) && !employerBelowMin),
            isEdit ? form.status !== '' : true,
        ];
        return Math.round((checks.filter(Boolean).length / checks.length) * 100);
    }, [form, empRate, erRate, employerBelowMin, isEdit]);

    const err = (field: string) => serverErrors[field];

    const close = () => {
        setSaving(false);
        onClose();
    };

    /* ----- submit ----- */
    const buildNotes = () => {
        // Strip any prior reason line, then re-append the current one (edit only).
        const base = (form.notes ?? '')
            .split('\n')
            .filter((l) => !l.trim().startsWith(REASON_TAG))
            .join('\n')
            .trim();
        if (optedOut && form.opt_out_reason.trim()) {
            return [base, `${REASON_TAG} ${form.opt_out_reason.trim()}`].filter(Boolean).join('\n');
        }
        return base;
    };

    const submit = (e?: FormEvent) => {
        e?.preventDefault();
        if (!formValid) return;
        setSaving(true);
        setServerErrors({});

        const opts = {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(isEdit ? 'Enrollment updated.' : 'Employee enrolled in benefit plan.');
                close();
            },
            onError: (errors: Record<string, string>) => {
                setServerErrors(errors);
                // Jump to the step owning the first error.
                if (
                    errors.employee_profile_id ||
                    errors.benefit_plan_id ||
                    errors.enrollment_date
                ) {
                    wiz.goTo(0);
                } else if (
                    errors.employee_contribution_rate ||
                    errors.employer_contribution_rate ||
                    errors.opt_out_date ||
                    errors.status
                ) {
                    wiz.goTo(1);
                }
                toast.error('Could not save. Check the highlighted fields.');
            },
            onFinish: () => setSaving(false),
        };

        if (isEdit && edit) {
            // The update route validates employer_contribution_rate as
            // `sometimes|numeric` (no `nullable`), so a literal null is rejected.
            // Only send the key when it has a value; a cleared field is simply
            // omitted and the stored value is left untouched.
            const editPayload: Record<string, string | null> = {
                status: form.status,
                employee_contribution_rate: form.employee_contribution_rate,
                opt_out_date: optedOut ? form.opt_out_date || null : null,
                notes: buildNotes() || null,
            };
            if (form.employer_contribution_rate !== '') {
                editPayload.employer_contribution_rate = form.employer_contribution_rate;
            }
            router.put(
                `/hr/compensation/benefits/enrollments/${edit.id}`,
                editPayload,
                opts,
            );
        } else {
            router.post(
                '/hr/compensation/benefits/enroll',
                {
                    employee_profile_id: form.employee_profile_id,
                    benefit_plan_id: form.benefit_plan_id,
                    enrollment_date: form.enrollment_date,
                    employee_contribution_rate: form.employee_contribution_rate,
                    employer_contribution_rate: form.employer_contribution_rate || null,
                    notes: form.notes || null,
                },
                opts,
            );
        }
    };

    /* ----- rail live cost preview ----- */
    const railExtra = (
        <div className="rounded-lg border border-border bg-card/60 p-3">
            <div className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                Live cost
            </div>
            {cost ? (
                <div className="space-y-1">
                    <div className="flex items-baseline justify-between">
                        <span className="text-[11px] text-muted-foreground">Total / yr</span>
                        <span className="text-sm font-bold text-foreground">
                            {nzd.format(cost.totalAnnual)}
                        </span>
                    </div>
                    <div className="flex items-baseline justify-between">
                        <span className="text-[11px] text-muted-foreground">Total / mo</span>
                        <span className="text-[13px] font-semibold text-foreground">
                            {nzd.format(cost.totalMonthly)}
                        </span>
                    </div>
                </div>
            ) : (
                <p className="text-[11px] text-muted-foreground">
                    {hasSalary
                        ? 'Enter contribution rates to preview cost.'
                        : 'Salary not available — preview shows rates only.'}
                </p>
            )}
        </div>
    );

    return (
        <WizardShell
            open={open}
            onClose={close}
            title={isEdit ? 'Update enrollment' : 'Enroll employee in benefit plan'}
            description="Guided benefit enrollment — choose a plan, set contribution rates, and review."
            railIcon={HandCoins}
            railTitle={isEdit ? 'Update enrollment' : 'Enroll employee'}
            railSub="Benefits"
            steps={STEPS}
            stepIndex={wiz.index}
            onStepClick={(i) => {
                const forwardOk = Array.from({ length: i }, (_, s) => stepValid(s)).every(Boolean);
                if (i <= wiz.index || forwardOk) wiz.goTo(i);
            }}
            pct={completeness}
            railExtra={railExtra}
            footerStart={
                <Button type="button" variant="ghost" onClick={close}>
                    Cancel
                </Button>
            }
            footerEnd={
                <>
                    {!wiz.isFirst ? (
                        <Button type="button" variant="outline" onClick={wiz.back}>
                            Back
                        </Button>
                    ) : null}
                    {!wiz.isLast ? (
                        <Button
                            type="button"
                            onClick={wiz.next}
                            disabled={!stepValid(wiz.index)}
                        >
                            Continue
                        </Button>
                    ) : (
                        <Button
                            type="button"
                            onClick={() => submit()}
                            disabled={saving || !formValid}
                        >
                            {saving
                                ? 'Saving…'
                                : isEdit
                                  ? 'Update enrollment'
                                  : 'Enroll employee'}
                        </Button>
                    )}
                </>
            }
        >
            {/* Step 1 — employee & plan */}
            {wiz.index === 0 ? (
                <WizardStepPane>
                    <StepHead
                        icon={UserRound}
                        title="Employee & plan"
                        blurb={
                            isEdit
                                ? 'The employee and plan are fixed for an existing enrollment.'
                                : 'Choose the employee and the benefit plan to enroll them in.'
                        }
                    />
                    <div className="grid grid-cols-1 gap-4">
                        <Field
                            label="Employee"
                            required
                            error={err('employee_profile_id')}
                        >
                            {isEdit ? (
                                <div className="flex items-center gap-2 rounded-md border border-border bg-muted/40 px-3 py-2 text-sm">
                                    <UserRound className="h-4 w-4 text-muted-foreground" />
                                    <span className="font-medium">{selectedEmployeeName}</span>
                                </div>
                            ) : (
                                <SelectInput
                                    value={form.employee_profile_id}
                                    onChange={(v) => set('employee_profile_id', v)}
                                    placeholder="Select an employee"
                                    ariaLabel="Employee"
                                    options={employees.map((e) => ({
                                        value: String(e.id),
                                        label: e.user?.name
                                            ? e.position_title
                                                ? `${e.user.name} — ${e.position_title}`
                                                : e.user.name
                                            : `Profile #${e.id}`,
                                    }))}
                                />
                            )}
                        </Field>

                        <Field
                            label="Benefit plan"
                            required
                            error={err('benefit_plan_id')}
                        >
                            {isEdit ? (
                                <div className="flex items-center gap-2 rounded-md border border-border bg-muted/40 px-3 py-2 text-sm">
                                    {(() => {
                                        const Icon = PLAN_TYPE_ICON[planType] ?? HandCoins;
                                        return <Icon className="h-4 w-4 text-muted-foreground" />;
                                    })()}
                                    <span className="font-medium">{selectedPlan?.name}</span>
                                    <span className="text-xs text-muted-foreground">
                                        {PLAN_TYPE_LABELS[planType] ?? planType}
                                    </span>
                                </div>
                            ) : plans.length ? (
                                <TilePicker
                                    value={form.benefit_plan_id}
                                    onChange={(v) => {
                                        set('benefit_plan_id', v);
                                        // Prefill employer rate from the plan default when known.
                                        const p = plans.find((pl) => String(pl.id) === v);
                                        if (
                                            p?.employer_contribution_rate != null &&
                                            form.employer_contribution_rate === ''
                                        ) {
                                            set(
                                                'employer_contribution_rate',
                                                String(p.employer_contribution_rate),
                                            );
                                        }
                                    }}
                                    options={plans.map((p) => ({
                                        key: String(p.id),
                                        label: p.name,
                                        description: PLAN_TYPE_LABELS[p.type] ?? p.type,
                                        icon: PLAN_TYPE_ICON[p.type] ?? HandCoins,
                                        meta:
                                            p.employer_contribution_rate != null
                                                ? `Employer default ${p.employer_contribution_rate}%`
                                                : undefined,
                                    }))}
                                />
                            ) : (
                                <InfoCard icon={Shield} tone="warn">
                                    No active benefit plans available. Create a plan first.
                                </InfoCard>
                            )}
                        </Field>

                        {!isEdit ? (
                            <Field
                                label="Enrollment date"
                                required
                                error={err('enrollment_date')}
                            >
                                <Input
                                    type="date"
                                    value={form.enrollment_date}
                                    onChange={(e) => set('enrollment_date', e.target.value)}
                                />
                            </Field>
                        ) : null}
                    </div>
                </WizardStepPane>
            ) : null}

            {/* Step 2 — contributions */}
            {wiz.index === 1 ? (
                <WizardStepPane>
                    <StepHead
                        icon={Banknote}
                        title="Contributions"
                        blurb={
                            isKiwiSaver
                                ? 'Set the KiwiSaver employee and employer contribution rates.'
                                : 'Set the employee and employer contribution rates for this benefit.'
                        }
                    />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        {isKiwiSaver ? (
                            <div className="sm:col-span-2">
                                <SubHead icon={PiggyBank}>KiwiSaver employee rate</SubHead>
                                <div className="mt-2 flex flex-wrap gap-1.5">
                                    {KIWISAVER_EMPLOYEE_RATES.map((r) => {
                                        const active = form.employee_contribution_rate === r;
                                        return (
                                            <button
                                                key={r}
                                                type="button"
                                                aria-pressed={active}
                                                onClick={() =>
                                                    set('employee_contribution_rate', r)
                                                }
                                                className={
                                                    'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-[13px] font-medium transition-colors ' +
                                                    (active
                                                        ? 'border-primary bg-primary/10 text-primary'
                                                        : 'border-border bg-card text-foreground hover:border-primary/50')
                                                }
                                            >
                                                {active ? (
                                                    <CheckCircle2 className="h-3.5 w-3.5" />
                                                ) : null}
                                                {r}%
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>
                        ) : null}

                        <Field
                            label="Employee rate (%)"
                            required
                            error={err('employee_contribution_rate')}
                        >
                            <Input
                                type="number"
                                step="0.01"
                                min="0"
                                max="100"
                                value={form.employee_contribution_rate}
                                onChange={(e) =>
                                    set('employee_contribution_rate', e.target.value)
                                }
                            />
                        </Field>

                        <Field
                            label="Employer rate (%)"
                            hint={isKiwiSaver ? 'min 3% statutory' : 'optional'}
                            error={
                                err('employer_contribution_rate') ??
                                (employerBelowMin
                                    ? `KiwiSaver employer contribution must be at least ${KIWISAVER_EMPLOYER_MIN}%.`
                                    : undefined)
                            }
                        >
                            <Input
                                type="number"
                                step="0.01"
                                min="0"
                                max="100"
                                value={form.employer_contribution_rate}
                                onChange={(e) =>
                                    set('employer_contribution_rate', e.target.value)
                                }
                            />
                        </Field>

                        {/* Live cost preview (only if a salary was provided). */}
                        {cost ? (
                            <div className="sm:col-span-2 rounded-xl border border-border bg-muted/30 p-4">
                                <div className="mb-3 flex items-center justify-between">
                                    <div className="flex items-center gap-2 text-sm font-bold">
                                        <Banknote className="h-4 w-4 text-primary" />
                                        Estimated cost
                                    </div>
                                    <span className="text-[11px] text-muted-foreground">
                                        on {nzd.format(annualSalary)} salary
                                    </span>
                                </div>
                                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                                    <div>
                                        <div className="text-[11px] text-muted-foreground">
                                            Employee / mo
                                        </div>
                                        <div className="text-base font-bold">
                                            {nzd2.format(cost.eeMonthly)}
                                        </div>
                                        <div className="text-[11px] text-muted-foreground">
                                            {nzd.format(cost.eeAnnual)} / yr
                                        </div>
                                    </div>
                                    <div>
                                        <div className="text-[11px] text-muted-foreground">
                                            Employer / mo
                                        </div>
                                        <div className="text-base font-bold">
                                            {nzd2.format(cost.erMonthly)}
                                        </div>
                                        <div className="text-[11px] text-muted-foreground">
                                            {nzd.format(cost.erAnnual)} / yr
                                        </div>
                                    </div>
                                    <div className="col-span-2 sm:col-span-1">
                                        <div className="text-[11px] text-muted-foreground">
                                            Combined / mo
                                        </div>
                                        <div className="text-base font-bold text-primary">
                                            {nzd2.format(cost.totalMonthly)}
                                        </div>
                                        <div className="text-[11px] text-muted-foreground">
                                            {nzd.format(cost.totalAnnual)} / yr
                                        </div>
                                    </div>
                                </div>
                            </div>
                        ) : (
                            <InfoCard icon={Banknote} tone="info">
                                Cost preview shows once an annual salary is available for this
                                employee. Rates are still saved.
                            </InfoCard>
                        )}

                        {/* Edit-only: status + opt-out path. */}
                        {isEdit ? (
                            <div className="sm:col-span-2 mt-1 space-y-4 border-t border-border pt-4">
                                <SubHead icon={Shield}>Enrollment status</SubHead>
                                <Field label="Status" error={err('status')}>
                                    <Segmented
                                        value={form.status}
                                        onChange={(v) => set('status', v)}
                                        options={STATUS_OPTIONS.map((s) => ({
                                            value: s.value,
                                            label: s.label,
                                        }))}
                                    />
                                </Field>
                                {optedOut ? (
                                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <Field
                                            label="Opt-out date"
                                            required
                                            error={err('opt_out_date')}
                                        >
                                            <Input
                                                type="date"
                                                value={form.opt_out_date}
                                                onChange={(e) =>
                                                    set('opt_out_date', e.target.value)
                                                }
                                            />
                                        </Field>
                                        <Field label="Opt-out reason" hint="optional" span>
                                            <Textarea
                                                rows={2}
                                                value={form.opt_out_reason}
                                                onChange={(e) =>
                                                    set('opt_out_reason', e.target.value)
                                                }
                                                placeholder="e.g. Joined a different scheme; financial hardship…"
                                            />
                                        </Field>
                                    </div>
                                ) : null}
                            </div>
                        ) : null}

                        <Field label="Notes" hint="optional" span error={err('notes')}>
                            <Textarea
                                rows={3}
                                value={form.notes}
                                onChange={(e) => set('notes', e.target.value)}
                                placeholder="Any notes about this enrollment…"
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            ) : null}

            {/* Step 3 — review */}
            {wiz.index === 2 ? (
                <WizardStepPane>
                    <div className="mb-5 flex items-center gap-4 rounded-xl border border-border bg-muted/30 p-4">
                        <Ring pct={completeness} />
                        <div>
                            <h2 className="text-lg font-bold">
                                {isEdit ? 'Review the changes' : 'Review the enrollment'}
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                Confirm the details below, then {isEdit ? 'update' : 'enroll'}.
                            </p>
                        </div>
                    </div>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <ReviewCard
                            icon={UserRound}
                            title="Employee & plan"
                            onEdit={() => wiz.goTo(0)}
                        >
                            <ReviewRow label="Employee" value={selectedEmployeeName} />
                            <ReviewRow label="Plan" value={selectedPlan?.name} />
                            <ReviewRow
                                label="Type"
                                value={PLAN_TYPE_LABELS[planType] ?? planType}
                            />
                            {!isEdit ? (
                                <ReviewRow label="Enrolled" value={form.enrollment_date} />
                            ) : (
                                <ReviewRow
                                    label="Status"
                                    value={
                                        STATUS_OPTIONS.find((s) => s.value === form.status)
                                            ?.label ?? form.status
                                    }
                                />
                            )}
                        </ReviewCard>

                        <ReviewCard
                            icon={Banknote}
                            title="Contributions"
                            onEdit={() => wiz.goTo(1)}
                        >
                            <ReviewRow
                                label="Employee"
                                value={
                                    form.employee_contribution_rate
                                        ? `${form.employee_contribution_rate}%`
                                        : undefined
                                }
                            />
                            <ReviewRow
                                label="Employer"
                                value={
                                    form.employer_contribution_rate
                                        ? `${form.employer_contribution_rate}%`
                                        : undefined
                                }
                            />
                            {cost ? (
                                <ReviewRow
                                    label="Cost / mo"
                                    value={nzd2.format(cost.totalMonthly)}
                                />
                            ) : null}
                            {optedOut ? (
                                <ReviewRow
                                    label="Opted out"
                                    value={form.opt_out_date || undefined}
                                />
                            ) : null}
                        </ReviewCard>

                        {optedOut && form.opt_out_reason.trim() ? (
                            <ReviewCard icon={Shield} title="Opt-out reason" span>
                                <p className="text-[13px] text-foreground">
                                    {form.opt_out_reason}
                                </p>
                            </ReviewCard>
                        ) : null}

                        {employerBelowMin ? (
                            <InfoCard icon={Shield} tone="crit">
                                Employer contribution is below the {KIWISAVER_EMPLOYER_MIN}%
                                KiwiSaver minimum — adjust it before saving.
                            </InfoCard>
                        ) : null}

                        {form.notes.trim() &&
                        !form.notes.trim().startsWith(REASON_TAG) ? (
                            <ReviewCard icon={ClipboardCheck} title="Notes" span>
                                <p className="whitespace-pre-line text-[13px] text-foreground">
                                    {form.notes
                                        .split('\n')
                                        .filter((l) => !l.trim().startsWith(REASON_TAG))
                                        .join('\n')
                                        .trim()}
                                </p>
                            </ReviewCard>
                        ) : null}
                    </div>
                </WizardStepPane>
            ) : null}
        </WizardShell>
    );
}

export default BenefitsEnrollDialog;
