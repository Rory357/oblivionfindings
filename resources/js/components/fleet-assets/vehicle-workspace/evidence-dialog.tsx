import { ConfirmDialog } from '@/components/confirm-dialog';
import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { formatDateOnly } from '@/lib/datetime';
import {
    CalendarDays,
    ClipboardCheck,
    FileCheck2,
    Loader2,
    ShieldCheck,
} from 'lucide-react';
import { useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { useVehicleRecordCommand } from './record-command';
import {
    applicabilityNames,
    outcomeNames,
    requirementNames,
    type Applicability,
    type ComplianceOutcome,
    type VehicleCompliance,
    type VehicleIdentity,
    type VehicleReadiness,
} from './types';
import './workspace.css';

type EvidenceResult = {
    version: { id: number; version: number; record_id: number };
    readiness: VehicleReadiness;
};
function isEvidenceResult(value: unknown): value is EvidenceResult {
    if (
        !value ||
        typeof value !== 'object' ||
        !('version' in value) ||
        !('readiness' in value)
    )
        return false;
    const version = value.version;
    const readiness = value.readiness;
    return (
        !!version &&
        typeof version === 'object' &&
        'id' in version &&
        typeof version.id === 'number' &&
        'version' in version &&
        typeof version.version === 'number' &&
        !!readiness &&
        typeof readiness === 'object' &&
        'can_proceed' in readiness &&
        typeof readiness.can_proceed === 'boolean'
    );
}
type EvidenceForm = {
    applicability: Applicability;
    applicability_basis: string;
    outcome: ComplianceOutcome;
    evidence_reference: string;
    effective_on: string;
    expires_on: string;
    ruc_start_km: string;
    ruc_end_km: string;
    reason: string;
};
const steps = [
    {
        key: 'assessment',
        label: 'Requirement & assessment',
        blurb: 'Applicability and recorded outcome',
        icon: ShieldCheck,
    },
    {
        key: 'evidence',
        label: 'Dates & evidence',
        blurb: 'Coverage and the original source',
        icon: CalendarDays,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm the new evidence version',
        icon: ClipboardCheck,
    },
] as const;
const assessmentFields = ['applicability', 'applicability_basis', 'outcome'];

export function VehicleEvidenceDialog(props: {
    open: boolean;
    vehicle: VehicleIdentity;
    record: VehicleCompliance;
    initialStep?: 0 | 1;
    onClose: () => void;
    onSaved: (result: EvidenceResult) => void;
    onReload: () => void;
}) {
    return props.open ? <EvidenceBody {...props} /> : null;
}

function EvidenceBody({
    vehicle,
    record,
    initialStep = 0,
    onClose,
    onSaved,
    onReload,
}: Omit<Parameters<typeof VehicleEvidenceDialog>[0], 'open'> & {
    open: boolean;
}) {
    const current = record.current;
    const [initial] = useState<EvidenceForm>(() => ({
        applicability: current?.applicability ?? 'unknown',
        applicability_basis: current?.applicability_basis ?? '',
        outcome: current?.outcome ?? 'needs_assessment',
        evidence_reference: current?.evidence_reference ?? '',
        effective_on: current?.effective_on ?? '',
        expires_on: current?.expires_on ?? '',
        ruc_start_km: current?.ruc_start_km?.toString() ?? '',
        ruc_end_km: current?.ruc_end_km?.toString() ?? '',
        reason: '',
    }));
    const [form, setForm] = useState(initial);
    const [step, setStep] = useState<number>(initialStep);
    const [discard, setDiscard] = useState(false);
    const [saved, setSaved] = useState<EvidenceResult | null>(null);
    const [localErrors, setLocalErrors] = useState<Record<string, string>>({});
    const command = useVehicleRecordCommand(isEvidenceResult);
    const errors = { ...command.errors, ...localErrors };
    const scope = useId();
    const fields = useRef<HTMLFieldSetElement>(null);
    const name = requirementNames[record.kind];
    const dirty = JSON.stringify(form) !== JSON.stringify(initial);
    const assessed =
        form.applicability === 'applicable' &&
        form.outcome !== 'needs_assessment';
    const coverageRequired = assessed && form.outcome !== 'failed';
    const requiredValues = [
        form.applicability !== 'unknown',
        form.applicability === 'not_applicable'
            ? !!form.applicability_basis.trim()
            : form.outcome !== 'needs_assessment',
        form.applicability !== 'applicable' || !!form.evidence_reference.trim(),
        !coverageRequired ||
            (record.kind === 'ruc'
                ? !!form.ruc_end_km && !!form.ruc_start_km
                : !!form.expires_on),
    ];
    const pct = Math.round(
        (requiredValues.filter(Boolean).length / requiredValues.length) * 100,
    );

    useEffect(() => {
        const field = Object.keys(command.errors)[0];
        if (field) setStep(assessmentFields.includes(field) ? 0 : 1);
    }, [command.errors]);
    useEffect(() => {
        if (
            Object.keys(command.errors).length ||
            Object.keys(localErrors).length
        ) {
            fields.current
                ?.querySelector<HTMLElement>('[aria-invalid="true"]')
                ?.focus();
        }
    }, [command.errors, localErrors, step]);

    const update = <K extends keyof EvidenceForm>(
        key: K,
        value: EvidenceForm[K],
    ) => {
        setForm((old) => ({
            ...old,
            [key]: value,
            ...(key === 'applicability' && value === 'unknown'
                ? { outcome: 'needs_assessment' as const }
                : {}),
        }));
        setLocalErrors((old) => {
            const next = { ...old };
            delete next[key];
            return next;
        });
        command.clearError(key);
    };
    const fieldId = (key: string) => `${scope}-${key}`;
    const field = (
        key: keyof EvidenceForm,
        label: string,
        control: ReactNode,
        hint?: string,
    ) => (
        <div className="space-y-2">
            <Label htmlFor={fieldId(key)}>{label}</Label>
            {control}
            {hint && (
                <p className="text-xs leading-relaxed text-muted-foreground">
                    {hint}
                </p>
            )}
            {errors[key] && (
                <p
                    id={`${fieldId(key)}-error`}
                    className="text-xs text-status-critical"
                    role="alert"
                >
                    {errors[key]}
                </p>
            )}
        </div>
    );
    const inputProps = (key: keyof EvidenceForm) => ({
        id: fieldId(key),
        'aria-invalid': !!errors[key],
        'aria-describedby': errors[key] ? `${fieldId(key)}-error` : undefined,
    });
    const validate = (all = false) => {
        const found: Record<string, string> = {};
        if (step === 0 || all) {
            if (
                form.applicability === 'not_applicable' &&
                !form.applicability_basis.trim()
            )
                found.applicability_basis =
                    'Record the basis for Not applicable.';
        }
        if (step === 1 || all) {
            if (assessed && !form.evidence_reference.trim())
                found.evidence_reference =
                    'Record the reference shown on your evidence.';
            if (coverageRequired && record.kind !== 'ruc' && !form.expires_on)
                found.expires_on = 'Choose the expiry or next due date.';
            if (coverageRequired && record.kind === 'ruc') {
                if (!form.ruc_start_km || Number(form.ruc_start_km) < 0)
                    found.ruc_start_km =
                        'Enter the licence start in kilometres.';
                if (
                    !form.ruc_end_km ||
                    Number(form.ruc_end_km) <= Number(form.ruc_start_km)
                )
                    found.ruc_end_km = 'The licence end must exceed its start.';
            }
            if (
                form.effective_on &&
                form.expires_on &&
                form.expires_on < form.effective_on
            )
                found.expires_on =
                    'Expiry cannot be before the effective date.';
        }
        setLocalErrors(found);
        const first = Object.keys(found)[0];
        if (first) setStep(assessmentFields.includes(first) ? 0 : 1);
        return !first;
    };
    const close = () => {
        if (command.processing) return;
        if (!saved && (dirty || command.uncertain)) setDiscard(true);
        else onClose();
    };
    const submit = async () => {
        if (!command.uncertain && !validate(true)) return;
        const result = await command.submit(
            `/fleet-assets/vehicles/${vehicle.id}/compliance/${record.kind}`,
            {
                ...form,
                expected_current_version_id: current?.id ?? null,
                source_reference: current?.source_reference ?? null,
                asset_document_id: current?.asset_document_id ?? null,
                effective_on: form.effective_on || null,
                expires_on: form.expires_on || null,
                ruc_start_km:
                    form.ruc_start_km === '' ? null : Number(form.ruc_start_km),
                ruc_end_km:
                    form.ruc_end_km === '' ? null : Number(form.ruc_end_km),
            },
        );
        if (result) {
            setSaved(result);
            onSaved(result);
        }
    };
    return (
        <>
            <WizardShell
                open
                onClose={close}
                title={`Update ${name} evidence`}
                description={`${vehicle.name} · A new source version retains earlier evidence.`}
                railIcon={ShieldCheck}
                railTitle={`Update ${name}`}
                railSub={vehicle.registration_number ?? vehicle.asset_tag}
                steps={steps}
                stepIndex={step}
                onStepClick={(next) => {
                    if (!command.locked) setStep(next);
                }}
                pct={pct}
                footerStart={
                    <Button
                        variant="outline"
                        disabled={command.processing}
                        onClick={close}
                    >
                        Cancel
                    </Button>
                }
                footerEnd={
                    <>
                        {step > 0 && (
                            <Button
                                variant="outline"
                                disabled={command.locked}
                                onClick={() => setStep(step - 1)}
                            >
                                Back
                            </Button>
                        )}
                        {command.requiresReload ? (
                            <Button onClick={onReload}>
                                Review latest record
                            </Button>
                        ) : step < 2 && !command.uncertain ? (
                            <Button
                                disabled={command.processing}
                                onClick={() => {
                                    if (validate()) setStep(step + 1);
                                }}
                            >
                                Continue
                            </Button>
                        ) : (
                            <Button
                                disabled={command.processing}
                                onClick={submit}
                            >
                                {command.processing && (
                                    <Loader2 className="size-4 animate-spin" />
                                )}
                                {command.uncertain
                                    ? 'Retry this submission'
                                    : 'Save evidence'}
                            </Button>
                        )}
                    </>
                }
                success={
                    saved && (
                        <WizardSuccessPane
                            title={`${name} evidence saved`}
                            blurb={`Version ${saved.version.version} is recorded. ${saved.readiness.can_proceed ? 'The vehicle currently meets its recorded readiness checks.' : 'Readiness still has items to resolve.'}`}
                            actions={
                                <Button onClick={onClose}>
                                    Return to vehicle
                                </Button>
                            }
                        />
                    )
                }
            >
                <div className="mb-6 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-primary/25 bg-primary/5 px-4 py-3 text-sm">
                    <strong>{vehicle.name}</strong>
                    <span className="text-xs text-muted-foreground">
                        {vehicle.asset_tag}
                        {vehicle.site_name ? ` · ${vehicle.site_name}` : ''}
                    </span>
                </div>
                {command.message && (
                    <div
                        role="alert"
                        className="mb-5 rounded-lg border border-status-warning/30 bg-status-warning-bg p-3 text-sm text-status-warning"
                    >
                        {command.message}
                    </div>
                )}
                <fieldset
                    ref={fields}
                    disabled={command.locked}
                    className="min-w-0"
                >
                    <WizardStepPane key={step}>
                        <h2 className="mb-2 text-lg font-semibold">
                            {steps[step].label}
                        </h2>
                        <p className="mb-6 text-sm text-muted-foreground">
                            {step === 0
                                ? 'Record the requirement for this vehicle from its approved source.'
                                : step === 1
                                  ? 'Enter dates from the evidence. An unknown requirement can be saved for assessment.'
                                  : 'This saves a new version and reassesses vehicle readiness.'}
                        </p>
                        {step === 0 && (
                            <div className="space-y-5">
                                {field(
                                    'applicability',
                                    'Applicability',
                                    <Select
                                        value={form.applicability}
                                        onValueChange={(value) =>
                                            update(
                                                'applicability',
                                                value as Applicability,
                                            )
                                        }
                                    >
                                        <SelectTrigger
                                            {...inputProps('applicability')}
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {Object.entries(
                                                applicabilityNames,
                                            ).map(([value, label]) => (
                                                <SelectItem
                                                    key={value}
                                                    value={value}
                                                >
                                                    {label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>,
                                )}
                                {form.applicability === 'not_applicable'
                                    ? field(
                                          'applicability_basis',
                                          'Basis for Not applicable',
                                          <Textarea
                                              {...inputProps(
                                                  'applicability_basis',
                                              )}
                                              value={form.applicability_basis}
                                              onChange={(event) =>
                                                  update(
                                                      'applicability_basis',
                                                      event.target.value,
                                                  )
                                              }
                                              rows={4}
                                              maxLength={5000}
                                          />,
                                      )
                                    : field(
                                          'outcome',
                                          'Recorded outcome',
                                          <Select
                                              value={form.outcome}
                                              disabled={
                                                  form.applicability ===
                                                  'unknown'
                                              }
                                              onValueChange={(value) =>
                                                  update(
                                                      'outcome',
                                                      value as ComplianceOutcome,
                                                  )
                                              }
                                          >
                                              <SelectTrigger
                                                  {...inputProps('outcome')}
                                              >
                                                  <SelectValue />
                                              </SelectTrigger>
                                              <SelectContent>
                                                  {Object.entries(
                                                      outcomeNames,
                                                  ).map(([value, label]) => (
                                                      <SelectItem
                                                          key={value}
                                                          value={value}
                                                      >
                                                          {label}
                                                      </SelectItem>
                                                  ))}
                                              </SelectContent>
                                          </Select>,
                                      )}
                            </div>
                        )}
                        {step === 1 && (
                            <div className="space-y-5">
                                <div className="vehicle-wizard-fields">
                                    {field(
                                        'effective_on',
                                        'Effective date',
                                        <>
                                            <DatePicker
                                                id={fieldId('effective_on')}
                                                label="Effective date"
                                                value={form.effective_on}
                                                onChange={(value) =>
                                                    update(
                                                        'effective_on',
                                                        value,
                                                    )
                                                }
                                                invalid={!!errors.effective_on}
                                            />
                                            {form.effective_on && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        update(
                                                            'effective_on',
                                                            '',
                                                        )
                                                    }
                                                >
                                                    Clear effective date
                                                </Button>
                                            )}
                                        </>,
                                    )}
                                    {field(
                                        'expires_on',
                                        record.kind === 'ruc'
                                            ? 'Expiry date (if recorded)'
                                            : 'Expiry / next due date',
                                        <>
                                            <DatePicker
                                                id={fieldId('expires_on')}
                                                label="Expiry date"
                                                value={form.expires_on}
                                                onChange={(value) =>
                                                    update('expires_on', value)
                                                }
                                                invalid={!!errors.expires_on}
                                                describedBy={
                                                    errors.expires_on
                                                        ? `${fieldId('expires_on')}-error`
                                                        : undefined
                                                }
                                            />
                                            {form.expires_on && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        update('expires_on', '')
                                                    }
                                                >
                                                    Clear expiry date
                                                </Button>
                                            )}
                                        </>,
                                    )}
                                    {record.kind === 'ruc' && (
                                        <>
                                            {field(
                                                'ruc_start_km',
                                                'Licence start (km)',
                                                <Input
                                                    {...inputProps(
                                                        'ruc_start_km',
                                                    )}
                                                    inputMode="decimal"
                                                    type="number"
                                                    min="0"
                                                    step="0.1"
                                                    value={form.ruc_start_km}
                                                    onChange={(event) =>
                                                        update(
                                                            'ruc_start_km',
                                                            event.target.value,
                                                        )
                                                    }
                                                />,
                                            )}
                                            {field(
                                                'ruc_end_km',
                                                'Licence end (km)',
                                                <Input
                                                    {...inputProps(
                                                        'ruc_end_km',
                                                    )}
                                                    inputMode="decimal"
                                                    type="number"
                                                    min="0"
                                                    step="0.1"
                                                    value={form.ruc_end_km}
                                                    onChange={(event) =>
                                                        update(
                                                            'ruc_end_km',
                                                            event.target.value,
                                                        )
                                                    }
                                                />,
                                            )}
                                        </>
                                    )}
                                </div>
                                {field(
                                    'evidence_reference',
                                    'Evidence reference',
                                    <Input
                                        {...inputProps('evidence_reference')}
                                        value={form.evidence_reference}
                                        maxLength={255}
                                        onChange={(event) =>
                                            update(
                                                'evidence_reference',
                                                event.target.value,
                                            )
                                        }
                                    />,
                                    'Use the certificate, licence or source reference. Dates alone do not establish an outcome.',
                                )}
                                {field(
                                    'reason',
                                    'Reason / supporting details',
                                    <Textarea
                                        {...inputProps('reason')}
                                        value={form.reason}
                                        maxLength={5000}
                                        rows={3}
                                        onChange={(event) =>
                                            update('reason', event.target.value)
                                        }
                                    />,
                                )}
                            </div>
                        )}
                        {step === 2 && (
                            <div className="grid gap-4">
                                <ReviewCard
                                    icon={ShieldCheck}
                                    title="Requirement"
                                    onEdit={() => setStep(0)}
                                >
                                    <ReviewRow
                                        label="Requirement"
                                        value={name}
                                    />
                                    <ReviewRow
                                        label="Applicability"
                                        value={
                                            applicabilityNames[
                                                form.applicability
                                            ]
                                        }
                                    />
                                    <ReviewRow
                                        label={
                                            form.applicability ===
                                            'not_applicable'
                                                ? 'Recorded basis'
                                                : 'Outcome'
                                        }
                                        value={
                                            form.applicability ===
                                            'not_applicable'
                                                ? form.applicability_basis
                                                : outcomeNames[form.outcome]
                                        }
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={FileCheck2}
                                    title="Dates & evidence"
                                    onEdit={() => setStep(1)}
                                >
                                    <ReviewRow
                                        label="Effective"
                                        value={formatDateOnly(
                                            form.effective_on,
                                        )}
                                    />
                                    <ReviewRow
                                        label="Expiry / due"
                                        value={formatDateOnly(form.expires_on)}
                                    />
                                    {record.kind === 'ruc' && (
                                        <ReviewRow
                                            label="Licence coverage"
                                            value={
                                                form.ruc_start_km &&
                                                form.ruc_end_km
                                                    ? `${form.ruc_start_km}–${form.ruc_end_km} km`
                                                    : 'Not recorded'
                                            }
                                        />
                                    )}
                                    <ReviewRow
                                        label="Reference"
                                        value={form.evidence_reference}
                                    />
                                    <ReviewRow
                                        label="Supporting details"
                                        value={form.reason}
                                    />
                                </ReviewCard>
                            </div>
                        )}
                    </WizardStepPane>
                </fieldset>
            </WizardShell>
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                title={
                    command.uncertain
                        ? 'Leave an unconfirmed save?'
                        : 'Discard this draft?'
                }
                description={
                    command.uncertain
                        ? 'The request may already be saved. Review the latest vehicle record before entering it again.'
                        : 'Your unsaved changes will be discarded. Existing evidence will be kept.'
                }
                confirmText={
                    command.uncertain ? 'Review latest record' : 'Discard draft'
                }
                onConfirm={() => {
                    setDiscard(false);
                    if (command.uncertain) onReload();
                    else onClose();
                }}
            />
        </>
    );
}
