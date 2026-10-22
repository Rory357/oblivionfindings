import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { EmptyState } from '@/components/ui/empty-state';
import { FileDropzone, formatFileSize } from '@/components/ui/file-dropzone';
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
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import axios from 'axios';
import {
    FileCode,
    ListChecks,
    ShieldCheck,
    ShieldOff,
    Upload,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    responseStatus,
    scriptBase,
    scriptStatusLabel,
    type ScriptParameter,
    type ScriptRecord,
    type ScriptReview,
} from './_contracts';
import {
    architectureLabel,
    outputSensitivityLabel,
    ScriptFacts,
    timeoutLabel,
} from './_dialogs';
import { ReasonField } from './_reason-field';
import {
    prepareScriptUpload,
    readScriptFile,
    scriptExitCodes,
    scriptLines,
    scriptRuntime,
    scriptUploadIssues,
    scriptUploadReply,
    type PreparedScriptUpload,
    type RuntimeSelection,
    type ScriptFile,
    type ScriptMetadata,
} from './_upload-contracts';

const emptyMetadata: ScriptMetadata = {
    name: '',
    publisher_label: '',
    interpreter: 'powershell7',
    interpreter_version: '',
    wrapper_sha256: '',
    architecture: 'amd64',
    parameter_schema: [],
    execution_policy: {
        runtime_seconds: 60,
        output_bytes: 4096,
        output_retention_hours: 24,
        network_mode: 'reviewed_network',
        timeout_mode: 'report_only',
        success_exit_codes: [0],
        expected_observation_schema: null,
    },
};
const emptyReview: ScriptReview = {
    effect: 'read_only',
    child_processes: [],
    network_destinations: [],
    output_sensitivity: 'operational',
    compensation: '',
    restart_behavior: 'none',
    no_detached_work: true,
    no_dynamic_code: true,
};
const steps = [
    {
        key: 'files',
        label: 'Files',
        blurb: 'Script and runtime files',
        icon: FileCode,
    },
    {
        key: 'inputs',
        label: 'Inputs and limits',
        blurb: 'What it accepts and how long it runs',
        icon: ListChecks,
    },
    {
        key: 'effects',
        label: 'What it does',
        blurb: 'Effects and recovery plan',
        icon: ShieldCheck,
    },
    {
        key: 'upload',
        label: 'Check and upload',
        blurb: 'Save a draft for review',
        icon: Upload,
    },
];
/** The fields that make up the completeness meter. */
const essentials = [
    'script-name',
    'script-publisher',
    'script-file',
    'script-runtime',
    'script-compensation',
    'script-acknowledged',
    'script-upload-reason',
];
/** Statuses where the catalogue refused the request, so nothing was saved. */
const rejected = [400, 409, 413, 415, 422];
function Choice({
    id,
    label,
    value,
    values,
    onChange,
    disabled,
}: {
    id: string;
    label: string;
    value: string;
    values: [string, string][];
    onChange: (value: string) => void;
    disabled?: boolean;
}) {
    return (
        <div className="space-y-2">
            <Label htmlFor={id}>{label}</Label>
            <Select value={value} onValueChange={onChange} disabled={disabled}>
                <SelectTrigger id={id}>
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {values.map(([key, text]) => (
                        <SelectItem key={key} value={key}>
                            {text}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}
function NumberField({
    id,
    label,
    value,
    onChange,
    min,
    max,
    disabled,
    error,
}: {
    id: string;
    label: string;
    value: number;
    onChange: (value: number) => void;
    min: number;
    max: number;
    disabled: boolean;
    error?: string;
}) {
    return (
        <div className="space-y-2">
            <Label htmlFor={id}>{label}</Label>
            <Input
                id={id}
                type="number"
                step={1}
                min={min}
                max={max}
                disabled={disabled}
                aria-invalid={error ? true : undefined}
                aria-describedby={error ? id + '-error' : undefined}
                value={Number.isFinite(value) ? value : ''}
                onChange={(e) =>
                    onChange(
                        e.target.value === '' ? NaN : Number(e.target.value),
                    )
                }
            />
            <InputError id={id + '-error'} message={error} />
        </div>
    );
}
export function ScriptUploadDialog({
    actorId,
    open,
    onClose,
    onUploaded,
    onDirtyChange,
    onAddAnother,
}: {
    actorId: number;
    open: boolean;
    onClose: () => void;
    onUploaded: (row: ScriptRecord) => void;
    onDirtyChange: (dirty: boolean) => void;
    /** Starts a separate, empty upload after this one is saved. */
    onAddAnother?: () => void;
}) {
    const [metadata, setMetadata] = useState<ScriptMetadata>(() =>
        structuredClone(emptyMetadata),
    );
    const [review, setReview] = useState<ScriptReview>(() =>
        structuredClone(emptyReview),
    );
    const [file, setFile] = useState<ScriptFile | null>(null),
        [runtime, setRuntime] = useState<RuntimeSelection | null>(null);
    const [step, setStep] = useState(0),
        [reason, setReason] = useState(''),
        [children, setChildren] = useState(''),
        [destinations, setDestinations] = useState('');
    const [exitCodes, setExitCodes] = useState('0'),
        [acknowledged, setAcknowledged] = useState(false);
    const [busy, setBusy] = useState(false),
        [notice, setNotice] = useState(''),
        [attempt, setAttempt] = useState<PreparedScriptUpload | null>(null),
        [saved, setSaved] = useState<ScriptRecord | null>(null),
        [denied, setDenied] = useState(false);
    // Field messages appear once an upload has been tried, then follow edits.
    const [checked, setChecked] = useState(false),
        [focusField, setFocusField] = useState<string | null>(null);
    const active = useRef<AbortController | null>(null),
        alive = useRef(true),
        busyNow = useRef(false),
        changed = useRef(false);
    // A request whose outcome is unknown keeps the form locked so a retry
    // sends exactly the same upload; a refused one hands the form back.
    const disabled = busy || !!attempt || !!saved || denied;
    const dirty =
        !saved &&
        (!!file || !!runtime || !!attempt || changed.current || reason !== '');
    const issues = scriptUploadIssues({
        metadata,
        review,
        file,
        runtime,
        exitCodes,
        children,
        destinations,
        acknowledged,
        reason,
    });
    const issueFor = (field: string) =>
        checked ? issues.find((x) => x.field === field)?.message : undefined;
    const pct = saved
        ? 100
        : Math.round(
              (100 *
                  essentials.filter(
                      (field) => !issues.some((x) => x.field === field),
                  ).length) /
                  essentials.length,
          );
    useEffect(() => {
        onDirtyChange(dirty);
    }, [dirty, onDirtyChange]);
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
            active.current?.abort();
        };
    }, []);
    useEffect(() => {
        if (!focusField) return;
        const timer = setTimeout(() => {
            document.getElementById(focusField)?.focus();
            setFocusField(null);
        }, 0);
        return () => clearTimeout(timer);
    }, [focusField, step]);
    const update = (patch: Partial<ScriptMetadata>) => {
        changed.current = true;
        setMetadata((value) => ({ ...value, ...patch }));
    };
    const policy = (patch: Partial<ScriptMetadata['execution_policy']>) =>
        update({
            execution_policy: { ...metadata.execution_policy, ...patch },
        });
    const updateReview = (patch: Partial<ScriptReview>) => {
        changed.current = true;
        setReview((value) => ({ ...value, ...patch }));
    };
    async function choose(files: File[], kind: 'script' | 'runtime') {
        if (busyNow.current || attempt || saved || denied) return;
        changed.current = true;
        setAcknowledged(false);
        if (kind === 'script') setFile(null);
        else setRuntime(null);
        if (files.length !== 1) {
            setNotice('Choose one file at a time.');
            return;
        }
        busyNow.current = true;
        setBusy(true);
        setNotice('');
        try {
            const selected = await readScriptFile(files[0], kind);
            const selectedRuntime =
                kind === 'runtime' ? scriptRuntime(selected) : null;
            if (!alive.current) return;
            if (selectedRuntime) {
                setRuntime(selectedRuntime);
                update({
                    interpreter_version: selectedRuntime.version,
                    architecture: selectedRuntime.architecture,
                    wrapper_sha256: selectedRuntime.wrapper,
                });
            } else setFile(selected);
        } catch (error) {
            if (alive.current)
                setNotice(
                    error instanceof Error
                        ? error.message
                        : 'This file could not be read.',
                );
        } finally {
            busyNow.current = false;
            if (alive.current) setBusy(false);
        }
    }
    function prepare(): PreparedScriptUpload {
        if (issues.length > 0) {
            setChecked(true);
            setStep(issues[0].step);
            setFocusField(issues[0].field);
            throw Error(
                issues.length === 1
                    ? 'One detail needs fixing before upload. It’s marked below.'
                    : `${issues.length} details need fixing before upload. The first is marked below; the others are marked in their sections.`,
            );
        }
        // Every new preparation gets a new request key, so an edited upload
        // can never be confused with one that was refused or is still unknown.
        return prepareScriptUpload(
            actorId,
            crypto.randomUUID(),
            reason.trim(),
            {
                ...metadata,
                parameter_schema: metadata.parameter_schema.map((p) =>
                    p.type === 'enum'
                        ? {
                              ...p,
                              constraints: {
                                  values: scriptLines(
                                      p.constraints.values.join('\n'),
                                  ),
                              },
                          }
                        : p,
                ),
                execution_policy: {
                    ...metadata.execution_policy,
                    success_exit_codes: scriptExitCodes(exitCodes),
                },
            },
            {
                ...review,
                child_processes: scriptLines(children),
                network_destinations: scriptLines(destinations),
            },
            file!,
            runtime!,
        );
    }
    function accept(data: unknown, request: PreparedScriptUpload) {
        const row = scriptUploadReply(data, request);
        setSaved(row);
        setAttempt(null);
        setFile(null);
        setRuntime(null);
        setDenied(false);
        setNotice('');
        onUploaded(row);
    }
    async function send(retained?: PreparedScriptUpload) {
        if (busyNow.current || saved || denied) return;
        let request: PreparedScriptUpload;
        try {
            request = retained ?? attempt ?? prepare();
        } catch (error) {
            setNotice(
                error instanceof Error
                    ? error.message
                    : 'Check the upload details.',
            );
            return;
        }
        setAttempt(request);
        busyNow.current = true;
        setBusy(true);
        setNotice('');
        const controller = new AbortController();
        active.current = controller;
        try {
            const response = await axios.post<unknown>(
                scriptBase,
                request.body,
                {
                    signal: controller.signal,
                    timeout: 60000,
                    headers: { Accept: 'application/json' },
                },
            );
            if (alive.current && !controller.signal.aborted)
                accept(response.data, request);
        } catch (error) {
            if (alive.current && !controller.signal.aborted) {
                const status = responseStatus(error);
                if (status !== null && rejected.includes(status)) {
                    // A definite refusal: nothing was saved, so the form is
                    // editable again and the next upload gets a new key.
                    setAttempt(null);
                    setNotice(
                        'The catalogue didn’t accept this upload, so nothing was saved. Check the details and upload again.',
                    );
                } else if (status === 503) {
                    setNotice(
                        'The catalogue is busy saving another change, so this upload wasn’t saved yet. Retry the same upload in a moment.',
                    );
                } else {
                    setDenied(
                        status !== null &&
                            [401, 403, 404, 419].includes(status),
                    );
                    setNotice(
                        'The upload outcome could not be confirmed. Check its saved outcome or retry this same request.',
                    );
                }
            }
        } finally {
            busyNow.current = false;
            if (alive.current) setBusy(false);
        }
    }
    async function recover() {
        if (!attempt || busyNow.current || saved) return;
        const request = attempt,
            controller = new AbortController();
        active.current = controller;
        busyNow.current = true;
        setBusy(true);
        try {
            const response = await axios.get<unknown>(
                scriptBase +
                    '/uploads/' +
                    request.body.request_key +
                    '?expected_actor_id=' +
                    actorId,
                {
                    signal: controller.signal,
                    timeout: 30000,
                    headers: { Accept: 'application/json' },
                },
            );
            if (alive.current && !controller.signal.aborted)
                accept(response.data, request);
        } catch (error) {
            if (alive.current && !controller.signal.aborted) {
                const status = responseStatus(error);
                if (status === 404) {
                    // No upload was saved under this request's key.
                    setAttempt(null);
                    setNotice(
                        'No saved upload was found for this request, so nothing was saved. Check the details and upload again.',
                    );
                } else if (status === 503) {
                    setNotice(
                        'The catalogue is busy saving another change. Check the saved upload again in a moment.',
                    );
                } else {
                    setDenied(
                        (previous) =>
                            previous ||
                            (status !== null &&
                                [401, 403, 419].includes(status)),
                    );
                    setNotice(
                        'No matching saved outcome could be confirmed. Keep this request for checking; retrying uses the same files and request ID.',
                    );
                }
            }
        } finally {
            busyNow.current = false;
            if (alive.current) setBusy(false);
        }
    }
    const parameter = (index: number, value: ScriptParameter) =>
        update({
            parameter_schema: metadata.parameter_schema.map((p, i) =>
                i === index ? value : p,
            ),
        });
    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title="Add script"
            description="Upload an exact script version for independent review. Uploading does not run it."
            railIcon={FileCode}
            railTitle={
                denied ? 'Access changed' : metadata.name || 'Add script'
            }
            railSub={saved ? 'Upload saved' : 'Draft for independent review'}
            steps={steps}
            stepIndex={step}
            onStepClick={setStep}
            headerLabel={steps[step].label}
            sequential={false}
            pct={pct}
            pctLabel="Details complete"
            footerStart={
                <Button variant="outline" onClick={onClose}>
                    Close
                </Button>
            }
            footerEnd={
                !saved &&
                (step < 3 ? (
                    <Button onClick={() => setStep(step + 1)}>
                        Next section
                    </Button>
                ) : (
                    <Button disabled={disabled} onClick={() => void send()}>
                        Upload draft
                    </Button>
                ))
            }
            success={
                saved ? (
                    <WizardSuccessPane
                        title="Script draft saved"
                        blurb={`Your upload was recorded. Current status: ${scriptStatusLabel[saved.status]}. Open it from the catalogue when you’re ready to submit it for review. It has not been run.`}
                        actions={
                            <>
                                {onAddAnother && (
                                    <Button
                                        variant="outline"
                                        onClick={onAddAnother}
                                    >
                                        Add another script
                                    </Button>
                                )}
                                <Button onClick={onClose}>Done</Button>
                            </>
                        }
                    />
                ) : undefined
            }
        >
            {notice && (
                <Alert role="status">
                    <AlertDescription>{notice}</AlertDescription>
                </Alert>
            )}
            {denied ? (
                <EmptyState
                    variant="compact"
                    icon={ShieldOff}
                    title="Upload details hidden"
                    description="Your access has changed, so private upload details are hidden. You can still check whether this upload was saved."
                />
            ) : (
                <WizardStepPane key={step}>
                    <div className="space-y-4">
                        {step === 0 && (
                            <>
                                <div className="space-y-2">
                                    <Label htmlFor="script-name">
                                        Script name
                                    </Label>
                                    <Input
                                        id="script-name"
                                        value={metadata.name}
                                        maxLength={200}
                                        disabled={disabled}
                                        aria-invalid={
                                            issueFor('script-name')
                                                ? true
                                                : undefined
                                        }
                                        onChange={(e) =>
                                            update({ name: e.target.value })
                                        }
                                    />
                                    <InputError
                                        message={issueFor('script-name')}
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="script-publisher">
                                        Publisher or author
                                    </Label>
                                    <Input
                                        id="script-publisher"
                                        value={metadata.publisher_label}
                                        maxLength={200}
                                        disabled={disabled}
                                        aria-invalid={
                                            issueFor('script-publisher')
                                                ? true
                                                : undefined
                                        }
                                        onChange={(e) =>
                                            update({
                                                publisher_label: e.target.value,
                                            })
                                        }
                                    />
                                    <InputError
                                        message={issueFor('script-publisher')}
                                    />
                                </div>
                                <Label id="script-file-label">
                                    PowerShell script
                                </Label>
                                <FileDropzone
                                    id="script-file"
                                    aria-labelledby="script-file-label"
                                    aria-invalid={
                                        issueFor('script-file')
                                            ? true
                                            : undefined
                                    }
                                    accept=".ps1"
                                    multiple={false}
                                    disabled={disabled}
                                    onFiles={(files) =>
                                        void choose(files, 'script')
                                    }
                                    title="Choose script"
                                    hint="One UTF-8 .ps1 file, up to 256 KB"
                                />
                                <InputError message={issueFor('script-file')} />
                                {file && (
                                    <p className="text-subtle [overflow-wrap:anywhere]">
                                        {file.name} ·{' '}
                                        {formatFileSize(file.size)}
                                    </p>
                                )}
                                <Label id="script-runtime-label">
                                    Runtime manifest
                                </Label>
                                <FileDropzone
                                    id="script-runtime"
                                    aria-labelledby="script-runtime-label"
                                    aria-invalid={
                                        issueFor('script-runtime')
                                            ? true
                                            : undefined
                                    }
                                    accept=".json"
                                    multiple={false}
                                    disabled={disabled}
                                    onFiles={(files) =>
                                        void choose(files, 'runtime')
                                    }
                                    title="Choose runtime manifest"
                                    hint="The reviewed PowerShell bundle manifest, up to 1 MB"
                                />
                                <InputError
                                    message={issueFor('script-runtime')}
                                />
                                {runtime && (
                                    <p className="text-subtle [overflow-wrap:anywhere]">
                                        {runtime.name} · PowerShell{' '}
                                        {runtime.version} ·{' '}
                                        {architectureLabel(
                                            runtime.architecture,
                                        )}
                                    </p>
                                )}
                                <p className="text-caption text-muted-foreground">
                                    The manifest identifies the runtime files.
                                    Selecting it does not install or approve
                                    that runtime.
                                </p>
                            </>
                        )}
                        {step === 1 && (
                            <>
                                <h3 className="text-section-title">Inputs</h3>
                                {metadata.parameter_schema.map((p, i) => (
                                    <div
                                        className="space-y-3 rounded-md border p-3"
                                        key={i}
                                    >
                                        <div className="space-y-2">
                                            <Label
                                                htmlFor={'parameter-name-' + i}
                                            >
                                                Input {i + 1} name
                                            </Label>
                                            <Input
                                                id={'parameter-name-' + i}
                                                disabled={disabled}
                                                maxLength={32}
                                                value={p.name}
                                                aria-invalid={
                                                    issueFor(
                                                        'parameter-name-' + i,
                                                    )
                                                        ? true
                                                        : undefined
                                                }
                                                onChange={(e) =>
                                                    parameter(i, {
                                                        ...p,
                                                        name: e.target.value,
                                                    })
                                                }
                                            />
                                            <InputError
                                                message={issueFor(
                                                    'parameter-name-' + i,
                                                )}
                                            />
                                        </div>
                                        <Choice
                                            id={'parameter-type-' + i}
                                            label="Input type"
                                            value={p.type}
                                            disabled={disabled}
                                            values={[
                                                ['boolean', 'Yes or no'],
                                                ['integer', 'Whole number'],
                                                ['string', 'Text'],
                                                ['enum', 'Choice'],
                                            ]}
                                            onChange={(value) => {
                                                const common = {
                                                    name: p.name,
                                                    required: p.required,
                                                };
                                                parameter(
                                                    i,
                                                    value === 'boolean'
                                                        ? {
                                                              ...common,
                                                              type: 'boolean',
                                                              constraints: {},
                                                          }
                                                        : value === 'integer'
                                                          ? {
                                                                ...common,
                                                                type: 'integer',
                                                                constraints: {
                                                                    minimum: 0,
                                                                    maximum: 100,
                                                                },
                                                            }
                                                          : value === 'string'
                                                            ? {
                                                                  ...common,
                                                                  type: 'string',
                                                                  constraints: {
                                                                      min_length: 0,
                                                                      max_length: 200,
                                                                  },
                                                              }
                                                            : {
                                                                  ...common,
                                                                  type: 'enum',
                                                                  constraints: {
                                                                      values: [],
                                                                  },
                                                              },
                                                );
                                            }}
                                        />
                                        <Label className="flex items-center gap-2">
                                            <Checkbox
                                                checked={p.required}
                                                disabled={disabled}
                                                onCheckedChange={(value) =>
                                                    parameter(i, {
                                                        ...p,
                                                        required:
                                                            value === true,
                                                    })
                                                }
                                            />
                                            Required input
                                        </Label>
                                        {p.type === 'integer' && (
                                            <>
                                                <NumberField
                                                    id={'parameter-min-' + i}
                                                    label="Minimum"
                                                    value={
                                                        p.constraints.minimum
                                                    }
                                                    min={
                                                        -Number.MAX_SAFE_INTEGER
                                                    }
                                                    max={
                                                        Number.MAX_SAFE_INTEGER
                                                    }
                                                    disabled={disabled}
                                                    error={issueFor(
                                                        'parameter-min-' + i,
                                                    )}
                                                    onChange={(value) =>
                                                        parameter(i, {
                                                            ...p,
                                                            constraints: {
                                                                ...p.constraints,
                                                                minimum: value,
                                                            },
                                                        })
                                                    }
                                                />
                                                <NumberField
                                                    id={'parameter-max-' + i}
                                                    label="Maximum"
                                                    value={
                                                        p.constraints.maximum
                                                    }
                                                    min={
                                                        -Number.MAX_SAFE_INTEGER
                                                    }
                                                    max={
                                                        Number.MAX_SAFE_INTEGER
                                                    }
                                                    disabled={disabled}
                                                    error={issueFor(
                                                        'parameter-max-' + i,
                                                    )}
                                                    onChange={(value) =>
                                                        parameter(i, {
                                                            ...p,
                                                            constraints: {
                                                                ...p.constraints,
                                                                maximum: value,
                                                            },
                                                        })
                                                    }
                                                />
                                            </>
                                        )}
                                        {p.type === 'string' && (
                                            <>
                                                <NumberField
                                                    id={'parameter-min-' + i}
                                                    label="Minimum characters"
                                                    value={
                                                        p.constraints.min_length
                                                    }
                                                    min={0}
                                                    max={1024}
                                                    disabled={disabled}
                                                    error={issueFor(
                                                        'parameter-min-' + i,
                                                    )}
                                                    onChange={(value) =>
                                                        parameter(i, {
                                                            ...p,
                                                            constraints: {
                                                                ...p.constraints,
                                                                min_length:
                                                                    value,
                                                            },
                                                        })
                                                    }
                                                />
                                                <NumberField
                                                    id={'parameter-max-' + i}
                                                    label="Maximum characters"
                                                    value={
                                                        p.constraints.max_length
                                                    }
                                                    min={0}
                                                    max={1024}
                                                    disabled={disabled}
                                                    error={issueFor(
                                                        'parameter-max-' + i,
                                                    )}
                                                    onChange={(value) =>
                                                        parameter(i, {
                                                            ...p,
                                                            constraints: {
                                                                ...p.constraints,
                                                                max_length:
                                                                    value,
                                                            },
                                                        })
                                                    }
                                                />
                                            </>
                                        )}
                                        {p.type === 'enum' && (
                                            <div className="space-y-2">
                                                <Label
                                                    htmlFor={
                                                        'parameter-options-' + i
                                                    }
                                                >
                                                    Choices (one per line)
                                                </Label>
                                                <Textarea
                                                    id={
                                                        'parameter-options-' + i
                                                    }
                                                    disabled={disabled}
                                                    aria-invalid={
                                                        issueFor(
                                                            'parameter-options-' +
                                                                i,
                                                        )
                                                            ? true
                                                            : undefined
                                                    }
                                                    value={p.constraints.values.join(
                                                        '\n',
                                                    )}
                                                    onChange={(e) =>
                                                        parameter(i, {
                                                            ...p,
                                                            constraints: {
                                                                values: e.target.value.split(
                                                                    '\n',
                                                                ),
                                                            },
                                                        })
                                                    }
                                                />
                                                <InputError
                                                    message={issueFor(
                                                        'parameter-options-' +
                                                            i,
                                                    )}
                                                />
                                            </div>
                                        )}
                                        <Button
                                            variant="outline"
                                            disabled={disabled}
                                            onClick={() =>
                                                update({
                                                    parameter_schema:
                                                        metadata.parameter_schema.filter(
                                                            (_, n) => i !== n,
                                                        ),
                                                })
                                            }
                                        >
                                            Remove input {i + 1}
                                        </Button>
                                    </div>
                                ))}
                                <Button
                                    variant="outline"
                                    disabled={
                                        disabled ||
                                        metadata.parameter_schema.length >= 16
                                    }
                                    onClick={() =>
                                        update({
                                            parameter_schema: [
                                                ...metadata.parameter_schema,
                                                {
                                                    name: '',
                                                    type: 'boolean',
                                                    required: false,
                                                    constraints: {},
                                                },
                                            ],
                                        })
                                    }
                                >
                                    Add input
                                </Button>
                                <h3 className="text-section-title">
                                    Limits when it runs
                                </h3>
                                <NumberField
                                    id="script-time"
                                    label="Time limit (seconds)"
                                    value={
                                        metadata.execution_policy
                                            .runtime_seconds
                                    }
                                    min={1}
                                    max={900}
                                    disabled={disabled}
                                    error={issueFor('script-time')}
                                    onChange={(value) =>
                                        policy({ runtime_seconds: value })
                                    }
                                />
                                <NumberField
                                    id="script-output"
                                    label="Most output to keep (bytes)"
                                    value={
                                        metadata.execution_policy.output_bytes
                                    }
                                    min={0}
                                    max={65536}
                                    disabled={disabled}
                                    error={issueFor('script-output')}
                                    onChange={(value) =>
                                        policy({ output_bytes: value })
                                    }
                                />
                                <NumberField
                                    id="script-retention"
                                    label="Keep output for (hours)"
                                    value={
                                        metadata.execution_policy
                                            .output_retention_hours
                                    }
                                    min={1}
                                    max={168}
                                    disabled={disabled}
                                    error={issueFor('script-retention')}
                                    onChange={(value) =>
                                        policy({
                                            output_retention_hours: value,
                                        })
                                    }
                                />
                                <Choice
                                    id="script-timeout"
                                    label="If it runs too long"
                                    value={
                                        metadata.execution_policy.timeout_mode
                                    }
                                    disabled={disabled}
                                    values={[
                                        [
                                            'report_only',
                                            timeoutLabel.report_only,
                                        ],
                                        [
                                            'terminate_owned_job',
                                            timeoutLabel.terminate_owned_job,
                                        ],
                                    ]}
                                    onChange={(value) =>
                                        policy({
                                            timeout_mode: value as
                                                | 'report_only'
                                                | 'terminate_owned_job',
                                        })
                                    }
                                />
                                <div className="space-y-2">
                                    <Label htmlFor="script-exit-codes">
                                        Success exit codes (comma separated)
                                    </Label>
                                    <Input
                                        id="script-exit-codes"
                                        value={exitCodes}
                                        disabled={disabled}
                                        aria-invalid={
                                            issueFor('script-exit-codes')
                                                ? true
                                                : undefined
                                        }
                                        onChange={(e) => {
                                            changed.current = true;
                                            setExitCodes(e.target.value);
                                        }}
                                    />
                                    <InputError
                                        message={issueFor('script-exit-codes')}
                                    />
                                </div>
                                <p className="text-caption text-muted-foreground">
                                    A success exit code describes how the script
                                    finished. It does not prove the computer
                                    reached the intended state.
                                </p>
                            </>
                        )}
                        {step === 2 && (
                            <>
                                <Choice
                                    id="script-effect"
                                    label="Effect on the computer"
                                    value={review.effect}
                                    disabled={disabled}
                                    values={[
                                        ['read_only', 'Read only'],
                                        ['mutating', 'Changes the computer'],
                                    ]}
                                    onChange={(value) =>
                                        updateReview({
                                            effect: value as ScriptReview['effect'],
                                            restart_behavior: 'none',
                                        })
                                    }
                                />
                                {review.effect === 'mutating' && (
                                    <Alert>
                                        <AlertDescription>
                                            Scripts that change the computer can
                                            be saved as drafts, but cannot be
                                            approved until an independent
                                            outcome check is available.
                                        </AlertDescription>
                                    </Alert>
                                )}
                                <div className="space-y-2">
                                    <Label htmlFor="script-children">
                                        Programs it starts (one per line; leave
                                        empty if none)
                                    </Label>
                                    <Textarea
                                        id="script-children"
                                        value={children}
                                        disabled={disabled}
                                        aria-invalid={
                                            issueFor('script-children')
                                                ? true
                                                : undefined
                                        }
                                        onChange={(e) => {
                                            changed.current = true;
                                            setChildren(e.target.value);
                                        }}
                                    />
                                    <InputError
                                        message={issueFor('script-children')}
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="script-network">
                                        Network destinations (one per line;
                                        leave empty if none)
                                    </Label>
                                    <Textarea
                                        id="script-network"
                                        value={destinations}
                                        disabled={disabled}
                                        aria-invalid={
                                            issueFor('script-network')
                                                ? true
                                                : undefined
                                        }
                                        onChange={(e) => {
                                            changed.current = true;
                                            setDestinations(e.target.value);
                                        }}
                                    />
                                    <InputError
                                        message={issueFor('script-network')}
                                    />
                                </div>
                                <p className="text-caption text-muted-foreground">
                                    These are declarations for review. They do
                                    not enforce a network block.
                                </p>
                                <Choice
                                    id="script-sensitive"
                                    label="Output sensitivity"
                                    value={review.output_sensitivity}
                                    disabled={disabled}
                                    values={(
                                        [
                                            'none',
                                            'operational',
                                            'sensitive',
                                        ] as const
                                    ).map((key): [string, string] => [
                                        key,
                                        outputSensitivityLabel[key],
                                    ])}
                                    onChange={(value) =>
                                        updateReview({
                                            output_sensitivity:
                                                value as ScriptReview['output_sensitivity'],
                                        })
                                    }
                                />
                                <ReasonField
                                    id="script-compensation"
                                    label="Recovery plan"
                                    noun="recovery plan"
                                    min={1}
                                    max={1000}
                                    value={review.compensation}
                                    disabled={disabled}
                                    error={issueFor('script-compensation')}
                                    onChange={(compensation) =>
                                        updateReview({ compensation })
                                    }
                                />
                                <Choice
                                    id="script-restart"
                                    label="Restart behaviour"
                                    value={review.restart_behavior}
                                    disabled={
                                        disabled ||
                                        review.effect === 'read_only'
                                    }
                                    values={[
                                        ['none', 'No restart'],
                                        [
                                            'may_request_restart',
                                            'May request a restart',
                                        ],
                                    ]}
                                    onChange={(value) =>
                                        updateReview({
                                            restart_behavior:
                                                value as ScriptReview['restart_behavior'],
                                        })
                                    }
                                />
                                <div className="space-y-2">
                                    <Label className="flex items-start gap-2">
                                        <Checkbox
                                            id="script-acknowledged"
                                            checked={acknowledged}
                                            disabled={disabled}
                                            aria-invalid={
                                                issueFor('script-acknowledged')
                                                    ? true
                                                    : undefined
                                            }
                                            onCheckedChange={(value) => {
                                                changed.current = true;
                                                setAcknowledged(value === true);
                                            }}
                                        />
                                        This script leaves no background work
                                        running and loads no other code.
                                    </Label>
                                    <InputError
                                        message={issueFor(
                                            'script-acknowledged',
                                        )}
                                    />
                                </div>
                            </>
                        )}
                        {step === 3 && (
                            <>
                                <ScriptFacts
                                    entries={[
                                        [
                                            'Script',
                                            metadata.name || 'Not named',
                                        ],
                                        [
                                            'Publisher',
                                            metadata.publisher_label ||
                                                'Not supplied',
                                        ],
                                        [
                                            'Selected file',
                                            file
                                                ? `${file.name} · ${formatFileSize(file.size)}`
                                                : 'Not selected',
                                        ],
                                        [
                                            'Runtime',
                                            runtime
                                                ? `PowerShell ${runtime.version} · ${architectureLabel(runtime.architecture)}`
                                                : 'Not selected',
                                        ],
                                        [
                                            'Inputs',
                                            String(
                                                metadata.parameter_schema
                                                    .length,
                                            ),
                                        ],
                                        [
                                            'Effect',
                                            review.effect === 'read_only'
                                                ? 'Read only'
                                                : 'Changes the computer',
                                        ],
                                        [
                                            'Time limit',
                                            `${metadata.execution_policy.runtime_seconds} seconds`,
                                        ],
                                        [
                                            'Output limit',
                                            `${metadata.execution_policy.output_bytes} bytes`,
                                        ],
                                        [
                                            'Output kept for',
                                            `${metadata.execution_policy.output_retention_hours} hours`,
                                        ],
                                    ]}
                                />
                                {file && (
                                    <details className="rounded-md border p-3">
                                        <summary className="frontline-focus cursor-pointer">
                                            Read selected script
                                        </summary>
                                        <pre
                                            tabIndex={0}
                                            aria-label="Selected script content"
                                            className="text-caption mt-3 max-h-64 overflow-auto [overflow-wrap:anywhere] whitespace-pre-wrap"
                                        >
                                            {file.text}
                                        </pre>
                                    </details>
                                )}
                                <ReasonField
                                    id="script-upload-reason"
                                    label="Reason for this upload"
                                    value={reason}
                                    disabled={disabled}
                                    error={issueFor('script-upload-reason')}
                                    onChange={setReason}
                                />
                                <p className="text-caption text-muted-foreground">
                                    Uploading saves this exact version as a
                                    draft. Another authorised person must review
                                    it. Scripts can’t run yet.
                                </p>
                            </>
                        )}
                    </div>
                </WizardStepPane>
            )}
            {attempt && !saved && (
                <div className="mt-4 flex flex-wrap gap-2">
                    <Button
                        variant="outline"
                        disabled={busy}
                        onClick={() => void recover()}
                    >
                        Check saved upload
                    </Button>
                    <Button
                        variant="outline"
                        disabled={busy || denied}
                        onClick={() => void send(attempt)}
                    >
                        Retry same upload
                    </Button>
                </div>
            )}
        </WizardShell>
    );
}
