import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    FileDropzone,
    StagedFileCard,
    formatFileSize,
} from '@/components/ui/file-dropzone';
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
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import axios from 'axios';
import { Check, Package, ShieldCheck } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    metadataValid,
    safeSoftwareText,
    sameMetadata,
    softwareBase,
    softwareWriteReply,
    type PackageMetadata,
    type SoftwarePackage,
} from './_contracts';

const steps = [
    {
        key: 'package',
        label: 'Installer',
        blurb: 'Application and installer file',
        icon: Package,
    },
    {
        key: 'identity',
        label: 'Verification',
        blurb: 'Expected installer identity',
        icon: ShieldCheck,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Check before uploading',
        icon: Check,
    },
];
const empty: PackageMetadata = {
    name: '',
    publisher: '',
    product_code: '',
    product_version: '',
    architecture: 'amd64',
    signer_sha256: '',
};
type UploadAttempt = {
    key: string;
    metadata: PackageMetadata;
    file: File;
    reason: string;
};
export function SoftwareUploadDialog({
    actorId,
    maxBytes,
    onClose,
    onUploaded,
}: {
    actorId: number;
    maxBytes: number;
    onClose: () => void;
    onUploaded: (row: SoftwarePackage) => void;
}) {
    const [metadata, setMetadata] = useState<PackageMetadata>(empty),
        [file, setFile] = useState<File | null>(null),
        [reason, setReason] = useState('');
    const [step, setStep] = useState(0),
        [busy, setBusy] = useState(false),
        [progress, setProgress] = useState<number | null>(null),
        [notice, setNotice] = useState('');
    const [attempt, setAttempt] = useState<UploadAttempt | null>(null),
        [retry, setRetry] = useState(false),
        [saved, setSaved] = useState<SoftwarePackage | null>(null);
    const active = useRef<AbortController | null>(null);
    const leave = useSettingsLeaveConfirmation(
        !saved &&
            (file !== null ||
                reason !== '' ||
                Object.values(metadata).some(
                    (value, index) => value !== Object.values(empty)[index],
                )),
    );
    useEffect(() => () => active.current?.abort(), []);
    function choose(files: File[]) {
        const picked = files[0];
        if (
            !picked ||
            files.length !== 1 ||
            !/\.msi$/i.test(picked.name) ||
            picked.size <= 8 ||
            picked.size > maxBytes
        ) {
            setNotice(
                'Choose one Windows MSI installer up to ' +
                    formatFileSize(maxBytes) +
                    '.',
            );
            return;
        }
        setFile(picked);
        setNotice('');
    }
    function valid(target: number) {
        if (
            !file ||
            !safeSoftwareText(metadata.name, 200) ||
            !safeSoftwareText(metadata.publisher, 200) ||
            !metadata.product_version.trim()
        ) {
            setNotice(
                'Add the application name, publisher, version and one installer file.',
            );
            setStep(0);
            return false;
        }
        if (
            target >= 2 &&
            (!metadataValid(metadata) ||
                !safeSoftwareText(reason.trim(), 500, 10))
        ) {
            setNotice(
                'Check the ProductCode, three-part version and signer SHA-256, and give a reason of 10–500 characters.',
            );
            setStep(1);
            return false;
        }
        setNotice('');
        return true;
    }
    function accept(value: unknown, submitted: UploadAttempt) {
        const row = softwareWriteReply(value, actorId);
        if (
            !sameMetadata(row, submitted.metadata) ||
            row.size_bytes !== submitted.file.size
        )
            throw Error('Upload changed');
        setSaved(row);
        setNotice('');
        setRetry(false);
        onUploaded(row);
    }
    async function upload() {
        if (
            busy ||
            saved ||
            (attempt && !retry) ||
            (!attempt && !valid(2)) ||
            (!attempt && !file)
        )
            return;
        const submitted = attempt ?? {
            key: crypto.randomUUID(),
            metadata: { ...metadata },
            file: file!,
            reason: reason.trim(),
        };
        setAttempt(submitted);
        setRetry(false);
        setBusy(true);
        setProgress(0);
        setNotice('');
        const call = new AbortController();
        active.current = call;
        const body = new FormData();
        body.set('expected_actor_id', String(actorId));
        body.set('request_key', submitted.key);
        body.set('reason', submitted.reason);
        for (const [key, value] of Object.entries(submitted.metadata))
            body.set(key, value);
        body.set('file', submitted.file);
        try {
            const response = await axios.post<unknown>(softwareBase, body, {
                signal: call.signal,
                timeout: 300000,
                headers: { Accept: 'application/json' },
                onUploadProgress: (event) => {
                    if (!call.signal.aborted && event.total)
                        setProgress(
                            Math.min(
                                100,
                                Math.floor((event.loaded / event.total) * 100),
                            ),
                        );
                },
            });
            if (!call.signal.aborted) accept(response.data, submitted);
        } catch (error) {
            if (!call.signal.aborted) {
                if (
                    axios.isAxiosError(error) &&
                    error.response?.status === 422
                ) {
                    setAttempt(null);
                    setStep(0);
                    setNotice(
                        'The installer or its details were not accepted. Check the file and expected identity before uploading again.',
                    );
                } else
                    setNotice(
                        'The upload result could not be confirmed. Check the saved upload before trying again. Its reviewed details are kept unchanged.',
                    );
            }
        } finally {
            if (!call.signal.aborted) {
                setBusy(false);
                setProgress(null);
            }
        }
    }
    async function recover() {
        if (busy || !attempt) return;
        const call = new AbortController();
        active.current = call;
        setBusy(true);
        setRetry(false);
        setNotice('Checking the saved upload…');
        try {
            const response = await axios.get<unknown>(
                softwareBase + '/uploads/' + attempt.key,
                {
                    params: { expected_actor_id: actorId },
                    signal: call.signal,
                    timeout: 15000,
                    headers: { Accept: 'application/json' },
                },
            );
            if (!call.signal.aborted) accept(response.data, attempt);
        } catch (error) {
            if (!call.signal.aborted) {
                if (
                    axios.isAxiosError(error) &&
                    error.response?.status === 404
                ) {
                    setRetry(true);
                    setNotice(
                        'No saved upload was found yet. You can retry the same file and reviewed details.',
                    );
                } else
                    setNotice(
                        'The saved upload could not be checked. Keep this window open and check again when the connection is available.',
                    );
            }
        } finally {
            if (!call.signal.aborted) setBusy(false);
        }
    }
    const locked = busy || attempt !== null;
    const field = (
        key: keyof PackageMetadata,
        label: string,
        help?: string,
    ) => (
        <div className="space-y-2">
            <Label htmlFor={'software-upload-' + key}>{label}</Label>
            <Input
                id={'software-upload-' + key}
                value={metadata[key]}
                disabled={locked}
                maxLength={
                    key === 'signer_sha256'
                        ? 64
                        : key === 'product_code'
                          ? 38
                          : 200
                }
                onChange={(event) =>
                    setMetadata({ ...metadata, [key]: event.target.value })
                }
            />
            {help && (
                <p className="text-caption text-muted-foreground">{help}</p>
            )}
        </div>
    );
    return (
        <>
            <WizardShell
                open
                title="Add software release"
                description="Upload an installer for independent review before it can be used on computers."
                onClose={() => {
                    if (!busy) leave.request(onClose);
                }}
                railIcon={Package}
                railTitle="Approved software"
                railSub="Installer review"
                steps={steps}
                stepIndex={step}
                pct={saved ? 100 : step === 2 ? 100 : step === 1 ? 65 : 30}
                onStepClick={(index) => {
                    if (!locked && (index < step || valid(index)))
                        setStep(index);
                }}
                footerStart={
                    <Button
                        variant="outline"
                        disabled={busy}
                        onClick={() => leave.request(onClose)}
                    >
                        Cancel
                    </Button>
                }
                footerEnd={
                    attempt && !saved ? (
                        <>
                            <Button
                                variant="outline"
                                disabled={busy}
                                onClick={() => void recover()}
                            >
                                Check saved upload
                            </Button>
                            {retry && (
                                <Button
                                    disabled={busy}
                                    onClick={() => void upload()}
                                >
                                    Retry same upload
                                </Button>
                            )}
                        </>
                    ) : step < 2 ? (
                        <Button
                            disabled={busy}
                            onClick={() => {
                                if (valid(step + 1)) setStep(step + 1);
                            }}
                        >
                            Continue
                        </Button>
                    ) : (
                        <Button disabled={busy} onClick={() => void upload()}>
                            {busy ? 'Uploading…' : 'Upload draft'}
                        </Button>
                    )
                }
                success={
                    saved ? (
                        <WizardSuccessPane
                            title="Software release uploaded"
                            blurb="The installer is saved as a draft. Submit it for another authorised person to review before deployment."
                            actions={<Button onClick={onClose}>Done</Button>}
                        />
                    ) : undefined
                }
            >
                <div className="space-y-5">
                    {notice && (
                        <Alert>
                            <AlertDescription role="status">
                                {notice}
                            </AlertDescription>
                        </Alert>
                    )}
                    {progress !== null && (
                        <p className="text-subtle" role="status">
                            {progress < 100
                                ? 'Uploading installer · ' + progress + '%'
                                : 'Upload sent · waiting for confirmation'}
                        </p>
                    )}
                    <WizardStepPane>
                        {step === 0 && (
                            <div className="space-y-5">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    {field('name', 'Application name')}
                                    {field('publisher', 'Publisher')}
                                    {field(
                                        'product_version',
                                        'Version',
                                        'Use the installer’s three-part product version, such as 4.2.1.',
                                    )}
                                    <div className="space-y-2">
                                        <Label htmlFor="software-upload-architecture">
                                            Windows architecture
                                        </Label>
                                        <Select
                                            value={metadata.architecture}
                                            disabled={locked}
                                            onValueChange={(value) =>
                                                setMetadata({
                                                    ...metadata,
                                                    architecture:
                                                        value as PackageMetadata['architecture'],
                                                })
                                            }
                                        >
                                            <SelectTrigger id="software-upload-architecture">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="amd64">
                                                    Windows x64
                                                </SelectItem>
                                                <SelectItem value="arm64">
                                                    Windows ARM64
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>
                                <fieldset disabled={locked}>
                                    {file ? (
                                        <StagedFileCard
                                            file={file}
                                            onRemove={() => {
                                                if (!locked) setFile(null);
                                            }}
                                        >
                                            <p className="text-caption">
                                                Installer for this exact release
                                            </p>
                                        </StagedFileCard>
                                    ) : (
                                        <FileDropzone
                                            id="software-installer"
                                            title="Choose a Windows installer"
                                            hint={
                                                'One .msi file · maximum ' +
                                                formatFileSize(maxBytes)
                                            }
                                            accept=".msi"
                                            multiple={false}
                                            disabled={locked}
                                            onFiles={choose}
                                        />
                                    )}
                                </fieldset>
                                <p className="text-subtle">
                                    This workflow supports machine-wide MSI
                                    installations and vendor-supported major
                                    upgrades. A new installer or version needs
                                    its own reviewed release.
                                </p>
                            </div>
                        )}
                        {step === 1 && (
                            <div className="space-y-5">
                                {field(
                                    'product_code',
                                    'MSI ProductCode',
                                    'Copy the installer’s ProductCode, including braces and uppercase letters.',
                                )}
                                {field(
                                    'signer_sha256',
                                    'Expected signing certificate SHA-256',
                                    'Use the 64-character lowercase fingerprint of the vendor certificate you have verified.',
                                )}
                                <p className="text-subtle">
                                    Oblivion checks these values against the
                                    installer on the computer before
                                    installation. Uploading does not verify that
                                    the application is suitable for your
                                    organisation.
                                </p>
                                <div className="space-y-2">
                                    <Label htmlFor="software-upload-reason">
                                        Reason and review notes
                                    </Label>
                                    <Textarea
                                        id="software-upload-reason"
                                        value={reason}
                                        maxLength={500}
                                        disabled={locked}
                                        onChange={(event) =>
                                            setReason(event.target.value)
                                        }
                                        placeholder="Why is this version needed, and what testing has been completed?"
                                    />
                                </div>
                            </div>
                        )}
                        {step === 2 && (
                            <div className="space-y-4">
                                <ReviewCard
                                    icon={Package}
                                    title={metadata.name}
                                    onEdit={
                                        locked ? undefined : () => setStep(0)
                                    }
                                >
                                    <ReviewRow
                                        label="Publisher"
                                        value={metadata.publisher}
                                    />
                                    <ReviewRow
                                        label="Version"
                                        value={metadata.product_version}
                                    />
                                    <ReviewRow
                                        label="Windows"
                                        value={
                                            metadata.architecture === 'amd64'
                                                ? 'x64'
                                                : 'ARM64'
                                        }
                                    />
                                    <ReviewRow
                                        label="Installer"
                                        value={
                                            file
                                                ? file.name +
                                                  ' · ' +
                                                  formatFileSize(file.size)
                                                : 'Missing'
                                        }
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={ShieldCheck}
                                    title="Installer identity"
                                    onEdit={
                                        locked ? undefined : () => setStep(1)
                                    }
                                >
                                    <ReviewRow
                                        label="ProductCode"
                                        value={
                                            <span className="[overflow-wrap:anywhere]">
                                                {metadata.product_code}
                                            </span>
                                        }
                                    />
                                    <ReviewRow
                                        label="Signer SHA-256"
                                        value={
                                            <span className="[overflow-wrap:anywhere]">
                                                {metadata.signer_sha256}
                                            </span>
                                        }
                                    />
                                    <ReviewRow
                                        label="Reason"
                                        value={reason.trim()}
                                    />
                                </ReviewCard>
                            </div>
                        )}
                    </WizardStepPane>
                </div>
            </WizardShell>
            {leave.confirmation}
        </>
    );
}
