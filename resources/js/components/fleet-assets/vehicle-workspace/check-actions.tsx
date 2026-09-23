import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { formatDateOnly } from '@/lib/datetime';
import { FileCheck2, FileText, Loader2, Upload, Wrench } from 'lucide-react';
import { useRef, useState } from 'react';
import { DraftGuard } from './checks-kit';
import { vehicleShort, versionLabel } from './checks-model';
import type { CheckRun, VehicleChecks } from './checks-types';
import { PersonPicker } from './choice-picker';
import { isJsonObject, useVehicleRecordCommand } from './record-command';
import { VehicleSearchSelect } from './search-select';
import type { VehicleProfile, VehicleWorkspace } from './types';
import {
    fieldProps,
    StudioNotice,
    WizardField,
    WizardSuccess,
    WorkspaceWizard,
} from './wizard-kit';
import { todayInAuckland } from './workspace-model';

const REVIEW_STEP = {
    key: 'review',
    label: 'Review',
    blurb: 'Confirm the resulting record',
    icon: FileCheck2,
};

const railSub = (vehicle: VehicleProfile) =>
    [vehicle.registration_number, vehicle.site?.name]
        .filter(Boolean)
        .join(' · ') || vehicle.name;

/** "Manage requirement": the vehicle's checklist, next check date and owner. */
export function ManageRequirementWizard({
    workspace,
    checks,
    onClose,
    onSaved,
}: {
    workspace: VehicleWorkspace;
    checks: VehicleChecks;
    onClose: () => void;
    onSaved: () => void;
}) {
    const { vehicle } = workspace;
    const { requirement, templates } = checks;
    const [initial] = useState(() => ({
        template: requirement.template_id
            ? String(requirement.template_id)
            : '',
        due: requirement.due_on ?? '',
        owner: requirement.owner?.id ?? null,
    }));
    const [form, setForm] = useState(initial);
    const [step, setStep] = useState(0);
    const [localErrors, setLocalErrors] = useState<Record<string, string>>({});
    const [savedText, setSavedText] = useState<string | null>(null);
    const command = useVehicleRecordCommand(isJsonObject);
    const errors: Record<string, string> = {
        ...command.errors,
        ...localErrors,
    };
    const serverField: Record<string, string> = {
        template: 'template_id',
        due: 'due_on',
        owner: 'owner_user_id',
    };
    const error = (field: string) =>
        errors[field] ?? errors[serverField[field]];
    const chosen = templates.find(
        (template) => String(template.id) === form.template,
    );
    const owner = workspace.people.find((person) => person.id === form.owner);
    const update = <K extends keyof typeof form>(
        key: K,
        value: (typeof form)[K],
    ) => {
        setForm((old) => ({ ...old, [key]: value }));
        setLocalErrors({});
        command.clearError(serverField[key as string]);
    };

    const validateStep = (at: number) => {
        const found: Record<string, string> = {};
        if (at === 0) {
            if (!form.template)
                found.template = 'Choose the checklist template.';
            if (!form.due) found.due = 'Choose the next check date.';
            else if (form.due < todayInAuckland())
                found.due = 'Choose today or a later date.';
            if (!form.owner)
                found.owner = 'Choose who is responsible for the check.';
        }
        setLocalErrors(found);
        return Object.keys(found).length === 0;
    };

    const submit = async () => {
        if (!command.uncertain && !validateStep(0)) {
            setStep(0);
            return;
        }
        const result = await command.submit(
            `/fleet-assets/vehicles/${vehicle.id}/check-requirement`,
            {
                template_id: Number(form.template),
                due_on: form.due,
                owner_user_id: form.owner,
                expected_version: requirement.lock_version,
            },
            { method: 'PUT' },
        );
        if (!result) return;
        setSavedText(
            `New checks use ${chosen?.name ?? 'the chosen checklist'} · ${versionLabel(chosen?.version)}. The next check is due ${formatDateOnly(form.due)}.`,
        );
        onSaved();
    };

    return (
        <WorkspaceWizard
            title="Plan check requirement"
            description={vehicleShort(vehicle)}
            railIcon={Wrench}
            railSub={railSub(vehicle)}
            steps={[
                {
                    key: 'requirement',
                    label: 'Current requirement',
                    blurb: 'Choose the reusable template for this vehicle requirement',
                    icon: Wrench,
                },
                REVIEW_STEP,
            ]}
            step={step}
            setStep={setStep}
            pct={Math.round(
                ([!!form.template, !!form.due, !!form.owner].filter(Boolean)
                    .length /
                    3) *
                    100,
            )}
            context={{ name: vehicle.name, detail: railSub(vehicle) }}
            command={command}
            dirty={JSON.stringify(form) !== JSON.stringify(initial)}
            saved={savedText !== null}
            submitLabel="Save check plan"
            onValidateStep={validateStep}
            onSubmit={submit}
            onClose={onClose}
            onReload={() => {
                onSaved();
                onClose();
            }}
            errorKey={JSON.stringify(errors)}
            success={
                <WizardSuccess
                    title="Check requirement saved"
                    blurb={savedText ?? ''}
                    onClose={onClose}
                />
            }
        >
            {step === 0 && (
                <div className="space-y-5">
                    <p className="text-subtle">
                        Choose the reusable template for this vehicle
                        requirement. New checks use its latest published
                        version.
                    </p>
                    <WizardField
                        id="requirement-template"
                        label="Checklist template"
                        error={error('template')}
                    >
                        <VehicleSearchSelect
                            id="requirement-template"
                            label="Checklist template"
                            value={form.template}
                            invalid={!!error('template')}
                            options={templates.map((template) => ({
                                value: String(template.id),
                                label: template.name,
                                description: `${versionLabel(template.version)} · ${template.assignment_label}`,
                            }))}
                            onChange={(value) => update('template', value)}
                        />
                    </WizardField>
                    <WizardField
                        id="requirement-due"
                        label="Next check date"
                        error={error('due')}
                    >
                        <DatePicker
                            id="requirement-due"
                            label="Next check date"
                            value={form.due}
                            invalid={!!error('due')}
                            onChange={(value) => update('due', value)}
                        />
                    </WizardField>
                    <WizardField
                        id="requirement-owner"
                        label="Responsible role"
                        error={error('owner')}
                    >
                        <PersonPicker
                            id="requirement-owner"
                            label="Responsible role"
                            value={form.owner}
                            people={workspace.people}
                            invalid={!!error('owner')}
                            onChange={(value) => update('owner', value)}
                        />
                    </WizardField>
                </div>
            )}
            {step === 1 && (
                <div className="grid gap-4">
                    <ReviewCard
                        icon={FileText}
                        title="Current requirement"
                        onEdit={() => setStep(0)}
                    >
                        <ReviewRow
                            label="Checklist template"
                            value={
                                chosen
                                    ? `${chosen.name} · ${versionLabel(chosen.version)}`
                                    : undefined
                            }
                        />
                        <ReviewRow
                            label="Next check date"
                            value={
                                form.due ? formatDateOnly(form.due) : undefined
                            }
                        />
                        <ReviewRow
                            label="Responsible role"
                            value={owner?.name ?? requirement.owner?.name}
                        />
                    </ReviewCard>
                </div>
            )}
        </WorkspaceWizard>
    );
}

/** "Add amendment": an attributable note beside a submitted check. */
export function AmendmentWizard({
    vehicle,
    run,
    onClose,
    onSaved,
}: {
    vehicle: VehicleProfile;
    run: CheckRun;
    onClose: () => void;
    onSaved: () => void;
}) {
    const [note, setNote] = useState('');
    const [step, setStep] = useState(0);
    const [localError, setLocalError] = useState('');
    const [saved, setSaved] = useState(false);
    const command = useVehicleRecordCommand(isJsonObject);
    const error = localError || command.errors.note;

    const validateStep = (at: number) => {
        if (at === 0 && !note.trim()) {
            setLocalError('Record the amendment and its reason.');
            return false;
        }
        setLocalError('');
        return true;
    };

    const submit = async () => {
        if (!command.uncertain && !validateStep(0)) {
            setStep(0);
            return;
        }
        const result = await command.submit(
            `/fleet-assets/vehicles/${vehicle.id}/checks/${run.id}/amendments`,
            { note: note.trim() },
        );
        if (!result) return;
        setSaved(true);
        onSaved();
    };

    return (
        <WorkspaceWizard
            title={`Add amendment · ${run.reference}`}
            description={vehicleShort(vehicle)}
            railIcon={Wrench}
            railSub={railSub(vehicle)}
            steps={[
                {
                    key: 'amendment',
                    label: 'Attributable amendment',
                    blurb: `Original check ${run.reference} remains immutable`,
                    icon: Wrench,
                },
                REVIEW_STEP,
            ]}
            step={step}
            setStep={setStep}
            pct={note.trim() ? 100 : 0}
            context={{ name: vehicle.name, detail: railSub(vehicle) }}
            command={command}
            dirty={!!note.trim()}
            saved={saved}
            submitLabel="Record amendment"
            onValidateStep={validateStep}
            onSubmit={submit}
            onClose={onClose}
            onReload={() => {
                onSaved();
                onClose();
            }}
            errorKey={error ? JSON.stringify({ note: error }) : ''}
            success={
                <WizardSuccess
                    title="Amendment recorded"
                    blurb={`The amendment is kept with ${run.reference} under your name. The original answers, files and outcome are unchanged.`}
                    onClose={onClose}
                />
            }
        >
            {step === 0 && (
                <div className="space-y-5">
                    <p className="text-subtle">
                        Original check {run.reference} remains immutable. A
                        retest creates another run.
                    </p>
                    <WizardField
                        id="amendment-note"
                        label="Amendment and reason"
                        error={error}
                    >
                        <Textarea
                            {...fieldProps('amendment-note', error)}
                            rows={4}
                            maxLength={2000}
                            value={note}
                            onChange={(event) => {
                                setNote(event.target.value);
                                setLocalError('');
                                command.clearError('note');
                            }}
                        />
                    </WizardField>
                </div>
            )}
            {step === 1 && (
                <div className="grid gap-4">
                    <ReviewCard
                        icon={FileText}
                        title="Attributable amendment"
                        onEdit={() => setStep(0)}
                    >
                        <ReviewRow
                            label="Amendment and reason"
                            value={note.trim() || undefined}
                        />
                    </ReviewCard>
                </div>
            )}
        </WorkspaceWizard>
    );
}

type UploadStatus =
    | 'staged'
    | 'uploading'
    | 'saved'
    | 'waiting'
    | 'blocked'
    | 'failed';

type UploadItem = { file: File; key: string; status: UploadStatus };

const ACCEPTED = ['image/png', 'image/jpeg', 'application/pdf'];
const MAX_BYTES = 10 * 1024 * 1024;

const STATUS: Record<UploadStatus, { label: string; tone: StatusVariant }> = {
    staged: { label: 'Selected · not saved', tone: 'neutral' },
    uploading: { label: 'Uploading', tone: 'neutral' },
    saved: { label: 'Saved', tone: 'success' },
    waiting: { label: 'Saved · waiting for virus check', tone: 'info' },
    blocked: { label: 'Not accepted', tone: 'critical' },
    failed: { label: 'Upload failed', tone: 'critical' },
};

/** "Upload evidence" for a submitted check: files kept as vehicle documents. */
export function CheckEvidenceDialog({
    vehicle,
    run,
    onClose,
    onSaved,
}: {
    vehicle: VehicleProfile;
    run: CheckRun;
    onClose: () => void;
    onSaved: () => void;
}) {
    const [items, setItems] = useState<UploadItem[]>([]);
    const [rejected, setRejected] = useState(false);
    const [discard, setDiscard] = useState(false);
    const busyRef = useRef(false);
    const [busy, setBusy] = useState(false);
    const command = useVehicleRecordCommand(isJsonObject);
    const pending = items.some((item) =>
        ['staged', 'failed', 'uploading'].includes(item.status),
    );
    const anySaved = items.some((item) =>
        ['saved', 'waiting'].includes(item.status),
    );
    const failed = items.some((item) => item.status === 'failed');

    const add = (files: File[]) => {
        const valid = files.filter(
            (file) =>
                ACCEPTED.includes(file.type) &&
                file.size > 0 &&
                file.size <= MAX_BYTES,
        );
        setRejected(valid.length !== files.length);
        setItems((current) =>
            [
                ...current,
                ...valid.map((file) => ({
                    file,
                    key: `check-evidence-${crypto.randomUUID()}`,
                    status: 'staged' as const,
                })),
            ].slice(0, 10),
        );
    };

    const setStatus = (key: string, status: UploadStatus) =>
        setItems((current) =>
            current.map((item) =>
                item.key === key ? { ...item, status } : item,
            ),
        );

    const upload = async () => {
        if (busyRef.current) return;
        busyRef.current = true;
        setBusy(true);
        for (const item of items) {
            if (!['staged', 'failed'].includes(item.status)) continue;
            setStatus(item.key, 'uploading');
            const body = new FormData();
            body.append('category', 'Check evidence');
            body.append('document_date', todayInAuckland());
            body.append('reason', `Added to check ${run.reference}.`);
            body.append('source_type', 'checklist_run');
            body.append('source_id', String(run.id));
            body.append('request_key', item.key);
            body.append('files[]', item.file);
            const result = await command.submit(
                `/fleet-assets/vehicles/${vehicle.id}/documents`,
                body,
            );
            if (!result || !Array.isArray(result.files)) {
                setStatus(item.key, 'failed');
                break;
            }
            const state = isJsonObject(result.files[0])
                ? String(result.files[0].state)
                : '';
            setStatus(
                item.key,
                state === 'available'
                    ? 'saved'
                    : ['quarantined', 'storage_failed'].includes(state)
                      ? 'blocked'
                      : 'waiting',
            );
        }
        busyRef.current = false;
        setBusy(false);
    };

    const finish = () => {
        if (anySaved) onSaved();
        onClose();
    };
    const requestClose = () => {
        if (busy) return;
        if (pending) setDiscard(true);
        else finish();
    };

    return (
        <>
            <Dialog open onOpenChange={(next) => !next && requestClose()}>
                <DialogContent className="vehicle-record-dialog vehicle-studio flex max-h-[90vh] w-[min(92vw,720px)] max-w-[min(92vw,720px)] flex-col gap-0 overflow-hidden bg-card p-0">
                    <DialogHeader className="vehicle-record-header">
                        <div className="vehicle-record-icon">
                            <Upload className="size-[21px]" aria-hidden />
                        </div>
                        <div>
                            <DialogTitle className="text-section-title">
                                Add evidence
                            </DialogTitle>
                            <DialogDescription className="mt-1.5">
                                {run.reference} · {vehicle.name} · Original
                                evidence
                            </DialogDescription>
                        </div>
                    </DialogHeader>
                    <div className="vehicle-record-body">
                        <div className="flow-stack">
                            <StudioNotice title="Kept with the original check">
                                Files are stored privately with {run.reference}{' '}
                                and open once they pass a virus check. They
                                don’t change the submitted answers or outcome.
                            </StudioNotice>
                            <div
                                className="upload-title"
                                id="check-evidence-label"
                            >
                                Evidence files
                            </div>
                            <FileDropzone
                                aria-labelledby="check-evidence-label"
                                onFiles={add}
                                disabled={busy}
                                accept="image/jpeg,image/png,application/pdf"
                                hint="JPEG, PNG or PDF · Up to 10 MiB per file · Check evidence"
                            />
                            {rejected && (
                                <StudioNotice
                                    title="File selection needs attention"
                                    tone="critical"
                                >
                                    Some files were rejected. Choose JPEG, PNG
                                    or PDF, up to 10 MiB per file. Your valid
                                    files are kept.
                                </StudioNotice>
                            )}
                            {command.message && (
                                <StudioNotice
                                    title="Evidence not saved"
                                    tone="critical"
                                >
                                    {command.errors['files.0'] ??
                                        command.errors.files ??
                                        command.message}
                                </StudioNotice>
                            )}
                            {items.map((item) => (
                                <div
                                    key={item.key}
                                    className={
                                        [
                                            'saved',
                                            'waiting',
                                            'blocked',
                                        ].includes(item.status)
                                            ? 'saved-evidence'
                                            : undefined
                                    }
                                    role="group"
                                    aria-label={item.file.name}
                                >
                                    <StagedFileCard
                                        file={item.file}
                                        onRemove={() =>
                                            !busy &&
                                            ['staged', 'failed'].includes(
                                                item.status,
                                            ) &&
                                            setItems((current) =>
                                                current.filter(
                                                    (entry) =>
                                                        entry.key !== item.key,
                                                ),
                                            )
                                        }
                                    >
                                        <div className="inline-actions">
                                            <StatusBadge
                                                variant={
                                                    STATUS[item.status].tone
                                                }
                                            >
                                                {STATUS[item.status].label}
                                            </StatusBadge>
                                        </div>
                                        {['saved', 'waiting'].includes(
                                            item.status,
                                        ) && (
                                            <small className="muted">
                                                Original selection retained as
                                                evidence. Saved evidence can’t
                                                be removed here.
                                            </small>
                                        )}
                                    </StagedFileCard>
                                </div>
                            ))}
                            {failed && (
                                <StudioNotice
                                    title="Some evidence needs retry"
                                    tone="warning"
                                >
                                    Confirmed files are kept. Retry only the
                                    files that failed.
                                </StudioNotice>
                            )}
                        </div>
                    </div>
                    <DialogFooter className="vehicle-record-footer">
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={requestClose}
                        >
                            Close
                        </Button>
                        <Button
                            disabled={
                                !pending || busy || command.requiresReload
                            }
                            onClick={upload}
                        >
                            {busy && (
                                <Loader2 className="size-4 animate-spin" />
                            )}
                            {busy
                                ? 'Uploading…'
                                : failed
                                  ? 'Retry failed files'
                                  : 'Save evidence'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            <DraftGuard
                open={discard}
                onKeep={() => setDiscard(false)}
                onDiscard={() => {
                    setDiscard(false);
                    finish();
                }}
            />
        </>
    );
}
