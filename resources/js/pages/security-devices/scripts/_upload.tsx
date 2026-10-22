import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { WizardShell, WizardStepPane } from '@/components/wizard/shell';
import axios from 'axios';
import { FileCode, ListChecks, ShieldCheck, Upload } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    scriptBase,
    scriptStatusLabel,
    type ScriptParameter,
    type ScriptRecord,
    type ScriptReview,
} from './_contracts';
import { ScriptFacts } from './_dialogs';
import {
    prepareScriptUpload,
    readScriptFile,
    scriptRuntime,
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
        blurb: 'Script and runtime identity',
        icon: FileCode,
    },
    {
        key: 'inputs',
        label: 'Inputs and limits',
        blurb: 'Bounded execution contract',
        icon: ListChecks,
    },
    {
        key: 'effects',
        label: 'Declared effects',
        blurb: 'Review and compensation',
        icon: ShieldCheck,
    },
    {
        key: 'upload',
        label: 'Check and upload',
        blurb: 'Save an immutable draft',
        icon: Upload,
    },
];
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
}: {
    id: string;
    label: string;
    value: number;
    onChange: (value: number) => void;
    min: number;
    max: number;
    disabled: boolean;
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
                value={Number.isFinite(value) ? value : ''}
                onChange={(e) =>
                    onChange(
                        e.target.value === '' ? NaN : Number(e.target.value),
                    )
                }
            />
        </div>
    );
}
const lines = (value: string) =>
    value
        .split(/\r?\n/)
        .map((x) => x.trim())
        .filter(Boolean);
export function ScriptUploadDialog({
    actorId,
    open,
    onClose,
    onUploaded,
    onDirtyChange,
}: {
    actorId: number;
    open: boolean;
    onClose: () => void;
    onUploaded: (row: ScriptRecord) => void;
    onDirtyChange: (dirty: boolean) => void;
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
    const active = useRef<AbortController | null>(null),
        alive = useRef(true),
        busyNow = useRef(false),
        changed = useRef(false);
    const disabled = busy || !!attempt || !!saved || denied;
    const dirty =
        !saved &&
        (!!file || !!runtime || !!attempt || changed.current || reason !== '');
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
        if (!file || !runtime)
            throw Error('Choose both the script and its runtime manifest.');
        if (!acknowledged)
            throw Error(
                'Confirm the restrictions on detached work and dynamic code.',
            );
        const codes = exitCodes.split(',').map((x) => x.trim());
        if (codes.some((x) => !/^(0|[1-9][0-9]*)$/.test(x)))
            throw Error(
                'Enter whole-number success exit codes separated by commas.',
            );
        return prepareScriptUpload(
            actorId,
            crypto.randomUUID(),
            reason.trim(),
            {
                ...metadata,
                execution_policy: {
                    ...metadata.execution_policy,
                    success_exit_codes: codes.map(Number),
                },
            },
            {
                ...review,
                child_processes: lines(children),
                network_destinations: lines(destinations),
            },
            file,
            runtime,
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
                setDenied(
                    axios.isAxiosError(error) &&
                        [401, 403, 404].includes(error.response?.status ?? 0),
                );
                setNotice(
                    'The upload outcome could not be confirmed. Check its saved outcome or retry this same request.',
                );
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
                setDenied(
                    (previous) =>
                        previous ||
                        (axios.isAxiosError(error) &&
                            [401, 403].includes(error.response?.status ?? 0)),
                );
                setNotice(
                    'No matching saved outcome could be confirmed. Keep this request for checking; retrying uses the same files and request ID.',
                );
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
            pct={saved ? 100 : 0}
            pctLabel="Upload saved"
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
        >
            {notice && (
                <Alert role="status">
                    <AlertDescription>{notice}</AlertDescription>
                </Alert>
            )}
            {saved ? (
                <div className="space-y-4">
                    <h3 className="text-section-title">Upload saved</h3>
                    <p>
                        Your original upload was recorded. Current status:{' '}
                        {scriptStatusLabel[saved.status]}.
                    </p>
                    <p className="text-subtle">
                        Close this window and refresh the catalogue to review
                        the saved version. It has not been run.
                    </p>
                </div>
            ) : denied ? (
                <p className="text-subtle">
                    Your access has changed. Private upload details are hidden.
                    Refresh the catalogue before starting another upload.
                </p>
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
                                        onChange={(e) =>
                                            update({ name: e.target.value })
                                        }
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
                                        onChange={(e) =>
                                            update({
                                                publisher_label: e.target.value,
                                            })
                                        }
                                    />
                                </div>
                                <Label id="script-file-label">
                                    PowerShell script
                                </Label>
                                <FileDropzone
                                    id="script-file"
                                    aria-labelledby="script-file-label"
                                    accept=".ps1"
                                    multiple={false}
                                    disabled={disabled}
                                    onFiles={(files) =>
                                        void choose(files, 'script')
                                    }
                                    title="Choose script"
                                    hint="One UTF-8 .ps1 file, up to 256 KB"
                                />
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
                                    accept=".json"
                                    multiple={false}
                                    disabled={disabled}
                                    onFiles={(files) =>
                                        void choose(files, 'runtime')
                                    }
                                    title="Choose runtime manifest"
                                    hint="The reviewed PowerShell bundle manifest, up to 1 MB"
                                />
                                {runtime && (
                                    <p className="text-subtle [overflow-wrap:anywhere]">
                                        {runtime.name} · PowerShell{' '}
                                        {runtime.version} ·{' '}
                                        {runtime.architecture === 'amd64'
                                            ? 'x64'
                                            : 'ARM64'}
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
                                                onChange={(e) =>
                                                    parameter(i, {
                                                        ...p,
                                                        name: e.target.value,
                                                    })
                                                }
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
                                    Execution limits
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
                                    onChange={(value) =>
                                        policy({ runtime_seconds: value })
                                    }
                                />
                                <NumberField
                                    id="script-output"
                                    label="Maximum retained output (bytes)"
                                    value={
                                        metadata.execution_policy.output_bytes
                                    }
                                    min={0}
                                    max={65536}
                                    disabled={disabled}
                                    onChange={(value) =>
                                        policy({ output_bytes: value })
                                    }
                                />
                                <NumberField
                                    id="script-retention"
                                    label="Output retention (hours)"
                                    value={
                                        metadata.execution_policy
                                            .output_retention_hours
                                    }
                                    min={1}
                                    max={168}
                                    disabled={disabled}
                                    onChange={(value) =>
                                        policy({
                                            output_retention_hours: value,
                                        })
                                    }
                                />
                                <Choice
                                    id="script-timeout"
                                    label="On timeout"
                                    value={
                                        metadata.execution_policy.timeout_mode
                                    }
                                    disabled={disabled}
                                    values={[
                                        [
                                            'report_only',
                                            'Report timeout and continue observing',
                                        ],
                                        [
                                            'terminate_owned_job',
                                            'Request termination of owned work',
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
                                        onChange={(e) => {
                                            changed.current = true;
                                            setExitCodes(e.target.value);
                                        }}
                                    />
                                </div>
                                <p className="text-caption text-muted-foreground">
                                    A success exit code describes execution. It
                                    does not prove the computer reached the
                                    intended state.
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
                                            outcome check is implemented.
                                        </AlertDescription>
                                    </Alert>
                                )}
                                <div className="space-y-2">
                                    <Label htmlFor="script-children">
                                        Child processes (one per line; leave
                                        empty if none)
                                    </Label>
                                    <Textarea
                                        id="script-children"
                                        value={children}
                                        disabled={disabled}
                                        onChange={(e) => {
                                            changed.current = true;
                                            setChildren(e.target.value);
                                        }}
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
                                        onChange={(e) => {
                                            changed.current = true;
                                            setDestinations(e.target.value);
                                        }}
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
                                    values={[
                                        ['none', 'No sensitive output'],
                                        [
                                            'operational',
                                            'Operational information',
                                        ],
                                        ['sensitive', 'Sensitive information'],
                                    ]}
                                    onChange={(value) =>
                                        updateReview({
                                            output_sensitivity:
                                                value as ScriptReview['output_sensitivity'],
                                        })
                                    }
                                />
                                <div className="space-y-2">
                                    <Label htmlFor="script-compensation">
                                        Compensation or recovery plan
                                    </Label>
                                    <Textarea
                                        id="script-compensation"
                                        value={review.compensation}
                                        maxLength={1000}
                                        disabled={disabled}
                                        onChange={(e) =>
                                            updateReview({
                                                compensation: e.target.value,
                                            })
                                        }
                                    />
                                </div>
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
                                <Label className="flex items-start gap-2">
                                    <Checkbox
                                        checked={acknowledged}
                                        disabled={disabled}
                                        onCheckedChange={(value) => {
                                            changed.current = true;
                                            setAcknowledged(value === true);
                                        }}
                                    />
                                    This script creates no detached work and
                                    loads no unpublished dynamic code.
                                </Label>
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
                                                ? `PowerShell ${runtime.version} · ${runtime.architecture}`
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
                                            'Output retention',
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
                                <div className="space-y-2">
                                    <Label htmlFor="script-upload-reason">
                                        Reason for this upload
                                    </Label>
                                    <Textarea
                                        id="script-upload-reason"
                                        value={reason}
                                        maxLength={500}
                                        disabled={disabled}
                                        onChange={(e) =>
                                            setReason(e.target.value)
                                        }
                                    />
                                </div>
                                <p className="text-caption text-muted-foreground">
                                    Uploading saves a new immutable draft.
                                    Another authorised person must review it.
                                    Script execution is not available yet.
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
