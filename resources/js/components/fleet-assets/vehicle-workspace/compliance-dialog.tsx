import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { formatDateOnly } from '@/lib/datetime';
import { CalendarDays, ClipboardCheck, ShieldCheck } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { uploadSummary, useEvidenceUpload } from './evidence-upload';
import { isJsonObject, useVehicleRecordCommand } from './record-command';
import {
    applicabilityNames,
    outcomeNames,
    type Applicability,
    type ComplianceOutcome,
    type ComplianceRecord,
    type VehicleProfile,
} from './types';
import {
    fieldProps,
    StagedFilesField,
    WizardField,
    WizardSuccess,
    WorkspaceWizard,
} from './wizard-kit';

type Form = {
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

const STEPS = [
    {
        key: 'applicability',
        label: 'Applicability',
        blurb: 'Confirm what applies to this vehicle',
        icon: ShieldCheck,
    },
    {
        key: 'evidence',
        label: 'Evidence & next action',
        blurb: 'Keep the original certificate or licence',
        icon: CalendarDays,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm the new record',
        icon: ClipboardCheck,
    },
];

export function ComplianceDialog({
    vehicle,
    record,
    initialStep = 0,
    onClose,
    onSaved,
}: {
    vehicle: VehicleProfile;
    record: ComplianceRecord;
    initialStep?: number;
    onClose: () => void;
    onSaved: () => void;
}) {
    const current = record.current;
    const [initial] = useState<Form>(() => ({
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
    const [files, setFiles] = useState<File[]>([]);
    const [step, setStep] = useState(initialStep);
    const [localErrors, setLocalErrors] = useState<Record<string, string>>({});
    const [savedText, setSavedText] = useState<string | null>(null);
    const command = useVehicleRecordCommand(isJsonObject);
    const uploads = useEvidenceUpload(vehicle.id);
    const errors = { ...command.errors, ...localErrors };
    const label = record.label;
    const applicable = form.applicability === 'applicable';
    const notApplicable = form.applicability === 'not_applicable';
    const assessed = applicable && form.outcome !== 'needs_assessment';
    const needsCoverage =
        applicable && ['recorded', 'passed'].includes(form.outcome);
    const dirty =
        JSON.stringify(form) !== JSON.stringify(initial) || files.length > 0;
    const pct = useMemo(() => {
        const checks = [
            form.applicability !== 'unknown',
            !notApplicable || !!form.applicability_basis.trim(),
            !assessed || !!form.evidence_reference.trim(),
            !needsCoverage ||
                (record.kind === 'ruc'
                    ? !!form.ruc_start_km && !!form.ruc_end_km
                    : !!form.expires_on),
        ];
        return Math.round(
            (checks.filter(Boolean).length / checks.length) * 100,
        );
    }, [form, notApplicable, assessed, needsCoverage, record.kind]);

    // Server validation lands on the step that owns the first failing field.
    useEffect(() => {
        const field = Object.keys(command.errors)[0];
        if (field)
            setStep(
                ['applicability', 'applicability_basis'].includes(field)
                    ? 0
                    : 1,
            );
    }, [command.errors]);

    const update = <K extends keyof Form>(key: K, value: Form[K]) => {
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

    const validateStep = (at: number): boolean => {
        const found: Record<string, string> = {};
        if (at === 0 && notApplicable && !form.applicability_basis.trim())
            found.applicability_basis = `Record why ${label} does not apply to this vehicle.`;
        if (at === 1 && !notApplicable) {
            if (assessed && !form.evidence_reference.trim())
                found.evidence_reference =
                    'Record the certificate, licence or receipt reference.';
            if (needsCoverage && record.kind !== 'ruc' && !form.expires_on)
                found.expires_on = 'Record the next due / expiry date.';
            if (needsCoverage && record.kind === 'ruc') {
                if (form.ruc_start_km === '' || Number(form.ruc_start_km) < 0)
                    found.ruc_start_km =
                        'Enter the licence start in kilometres.';
                if (
                    form.ruc_end_km === '' ||
                    Number(form.ruc_end_km) <= Number(form.ruc_start_km)
                )
                    found.ruc_end_km =
                        'The licence end must be higher than its start.';
            }
            if (
                form.effective_on &&
                form.expires_on &&
                form.expires_on < form.effective_on
            )
                found.expires_on =
                    'The next due / expiry date can’t be before the effective date.';
        }
        setLocalErrors(found);
        return Object.keys(found).length === 0;
    };

    const submit = async () => {
        if (!command.uncertain) {
            for (const at of [0, 1]) {
                if (!validateStep(at)) {
                    setStep(at);
                    return;
                }
            }
        }
        const result = await command.submit(
            `/fleet-assets/vehicles/${vehicle.id}/compliance/${record.kind}`,
            {
                applicability: form.applicability,
                applicability_basis: form.applicability_basis || null,
                outcome: notApplicable ? 'recorded' : form.outcome,
                evidence_reference: notApplicable
                    ? null
                    : form.evidence_reference || null,
                effective_on: notApplicable ? null : form.effective_on || null,
                expires_on: notApplicable ? null : form.expires_on || null,
                ruc_start_km:
                    notApplicable || form.ruc_start_km === ''
                        ? null
                        : Number(form.ruc_start_km),
                ruc_end_km:
                    notApplicable || form.ruc_end_km === ''
                        ? null
                        : Number(form.ruc_end_km),
                reason: form.reason || null,
                expected_current_version_id: current?.id ?? null,
            },
        );
        if (!result) return;
        const version = isJsonObject(result.version) ? result.version : null;
        const outcome =
            files.length && version
                ? await uploads.upload(files, {
                      category: `${label} evidence`,
                      reason: `${label} evidence for version ${String(version.version)}`,
                      sourceType: 'compliance_version',
                      sourceId: Number(version.id),
                  })
                : null;
        setSavedText(
            `${label} is recorded as version ${String(version?.version ?? '')}.${uploadSummary(outcome, files.length)}`,
        );
        onSaved();
    };

    return (
        <WorkspaceWizard
            title={`Update ${label} evidence`}
            description={`${vehicle.name}: a new version keeps earlier evidence.`}
            railIcon={ShieldCheck}
            railSub={vehicle.registration_number ?? vehicle.asset_tag ?? ''}
            steps={STEPS}
            step={step}
            setStep={setStep}
            pct={pct}
            context={{
                name: vehicle.name,
                detail: [vehicle.registration_number, vehicle.site?.name]
                    .filter(Boolean)
                    .join(' · '),
            }}
            command={command}
            dirty={dirty}
            saved={savedText !== null}
            submitLabel="Record evidence"
            onValidateStep={validateStep}
            onSubmit={submit}
            onClose={onClose}
            onReload={onClose}
            errorKey={JSON.stringify(errors)}
            success={
                <WizardSuccess
                    title={`${label} evidence recorded`}
                    blurb={`${savedText ?? ''} Readiness has been rechecked. Any repair or retest stays with Maintenance.`}
                    onClose={onClose}
                />
            }
        >
            {step === 0 && (
                <div className="space-y-5">
                    <p className="text-subtle">
                        Record what applies to this vehicle from its own source.
                        This does not decide legal applicability for you.
                    </p>
                    <WizardField
                        id="applicability"
                        label="Applicability"
                        error={errors.applicability}
                    >
                        <Select
                            value={form.applicability}
                            onValueChange={(value) =>
                                update('applicability', value as Applicability)
                            }
                        >
                            <SelectTrigger
                                {...fieldProps(
                                    'applicability',
                                    errors.applicability,
                                )}
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {(
                                    Object.keys(
                                        applicabilityNames,
                                    ) as Applicability[]
                                ).map((value) => (
                                    <SelectItem key={value} value={value}>
                                        {applicabilityNames[value]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </WizardField>
                    <WizardField
                        id="applicability_basis"
                        label="Basis / source"
                        optional={!notApplicable}
                        error={errors.applicability_basis}
                        hint={
                            notApplicable
                                ? 'Required: record why this does not apply, for example the vehicle class or the source you checked.'
                                : undefined
                        }
                    >
                        <Textarea
                            {...fieldProps(
                                'applicability_basis',
                                errors.applicability_basis,
                            )}
                            rows={3}
                            maxLength={5000}
                            value={form.applicability_basis}
                            onChange={(event) =>
                                update(
                                    'applicability_basis',
                                    event.target.value,
                                )
                            }
                        />
                    </WizardField>
                </div>
            )}
            {step === 1 &&
                (notApplicable ? (
                    <div className="rounded-lg border bg-muted/40 p-4">
                        <p className="font-medium">
                            No additional evidence required
                        </p>
                        <p className="text-subtle mt-1">
                            {label} is marked not applicable. Continue to review
                            its recorded basis; earlier evidence is kept.
                        </p>
                    </div>
                ) : (
                    <div className="space-y-5">
                        <p className="text-subtle">
                            Keep the original certificate or licence. A failed
                            outcome keeps the vehicle from use until it is
                            resolved.
                        </p>
                        <div className="vehicle-wizard-fields">
                            <WizardField
                                id="outcome"
                                label="Evidence outcome"
                                error={errors.outcome}
                            >
                                <Select
                                    value={form.outcome}
                                    disabled={form.applicability === 'unknown'}
                                    onValueChange={(value) =>
                                        update(
                                            'outcome',
                                            value as ComplianceOutcome,
                                        )
                                    }
                                >
                                    <SelectTrigger
                                        {...fieldProps(
                                            'outcome',
                                            errors.outcome,
                                        )}
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {(
                                            Object.keys(
                                                outcomeNames,
                                            ) as ComplianceOutcome[]
                                        ).map((value) => (
                                            <SelectItem
                                                key={value}
                                                value={value}
                                            >
                                                {outcomeNames[value]}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </WizardField>
                            <WizardField
                                id="evidence_reference"
                                label="Evidence reference"
                                optional={!assessed}
                                error={errors.evidence_reference}
                            >
                                <Input
                                    {...fieldProps(
                                        'evidence_reference',
                                        errors.evidence_reference,
                                    )}
                                    maxLength={255}
                                    value={form.evidence_reference}
                                    onChange={(event) =>
                                        update(
                                            'evidence_reference',
                                            event.target.value,
                                        )
                                    }
                                />
                            </WizardField>
                            {record.kind === 'ruc' ? (
                                <>
                                    <WizardField
                                        id="ruc_start_km"
                                        label="Licence start odometer (km)"
                                        optional={!needsCoverage}
                                        error={errors.ruc_start_km}
                                    >
                                        <Input
                                            {...fieldProps(
                                                'ruc_start_km',
                                                errors.ruc_start_km,
                                            )}
                                            type="number"
                                            inputMode="decimal"
                                            min="0"
                                            step="0.1"
                                            value={form.ruc_start_km}
                                            onChange={(event) =>
                                                update(
                                                    'ruc_start_km',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </WizardField>
                                    <WizardField
                                        id="ruc_end_km"
                                        label="Licence end odometer (km)"
                                        optional={!needsCoverage}
                                        error={errors.ruc_end_km}
                                    >
                                        <Input
                                            {...fieldProps(
                                                'ruc_end_km',
                                                errors.ruc_end_km,
                                            )}
                                            type="number"
                                            inputMode="decimal"
                                            min="0"
                                            step="0.1"
                                            value={form.ruc_end_km}
                                            onChange={(event) =>
                                                update(
                                                    'ruc_end_km',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </WizardField>
                                </>
                            ) : (
                                <WizardField
                                    id="expires_on"
                                    label="Next due / expiry date"
                                    optional={!needsCoverage}
                                    error={errors.expires_on}
                                >
                                    <DatePicker
                                        id="expires_on"
                                        label="Next due / expiry date"
                                        value={form.expires_on}
                                        onChange={(value) =>
                                            update('expires_on', value)
                                        }
                                        invalid={!!errors.expires_on}
                                        describedBy={
                                            errors.expires_on
                                                ? 'expires_on-error'
                                                : undefined
                                        }
                                    />
                                </WizardField>
                            )}
                            <WizardField
                                id="effective_on"
                                label="Effective from"
                                optional
                                error={errors.effective_on}
                            >
                                <DatePicker
                                    id="effective_on"
                                    label="Effective from"
                                    value={form.effective_on}
                                    onChange={(value) =>
                                        update('effective_on', value)
                                    }
                                    invalid={!!errors.effective_on}
                                />
                            </WizardField>
                        </div>
                        <StagedFilesField
                            label="Certificate / licence documents"
                            files={files}
                            onChange={setFiles}
                        />
                        <WizardField
                            id="reason"
                            label="Notes"
                            optional
                            error={errors.reason}
                        >
                            <Textarea
                                {...fieldProps('reason', errors.reason)}
                                rows={2}
                                maxLength={5000}
                                value={form.reason}
                                onChange={(event) =>
                                    update('reason', event.target.value)
                                }
                            />
                        </WizardField>
                    </div>
                ))}
            {step === 2 && (
                <div className="grid gap-4">
                    <ReviewCard
                        icon={ShieldCheck}
                        title="Applicability"
                        onEdit={() => setStep(0)}
                    >
                        <ReviewRow
                            label="Applicability"
                            value={applicabilityNames[form.applicability]}
                        />
                        <ReviewRow
                            label="Basis / source"
                            value={form.applicability_basis || undefined}
                        />
                    </ReviewCard>
                    {!notApplicable && (
                        <ReviewCard
                            icon={CalendarDays}
                            title="Evidence & next action"
                            onEdit={() => setStep(1)}
                        >
                            <ReviewRow
                                label="Outcome"
                                value={outcomeNames[form.outcome]}
                            />
                            <ReviewRow
                                label="Reference"
                                value={form.evidence_reference || undefined}
                            />
                            {record.kind === 'ruc' ? (
                                <ReviewRow
                                    label="Licence range"
                                    value={
                                        form.ruc_start_km && form.ruc_end_km
                                            ? `${Number(form.ruc_start_km).toLocaleString('en-NZ')} – ${Number(form.ruc_end_km).toLocaleString('en-NZ')} km`
                                            : undefined
                                    }
                                />
                            ) : (
                                <ReviewRow
                                    label="Next due / expiry"
                                    value={
                                        form.expires_on
                                            ? formatDateOnly(form.expires_on)
                                            : undefined
                                    }
                                />
                            )}
                            <ReviewRow
                                label="Files"
                                value={
                                    files.length
                                        ? files
                                              .map((file) => file.name)
                                              .join(', ')
                                        : 'No files attached'
                                }
                            />
                        </ReviewCard>
                    )}
                    <p className="text-caption">
                        Saving records a new version and rechecks readiness.
                        Earlier versions stay in the history; any repair or
                        retest stays with Maintenance.
                    </p>
                </div>
            )}
        </WorkspaceWizard>
    );
}
