import { ConfirmDialog } from '@/components/confirm-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput } from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { ClipboardCheck, FileText, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import { ReferralDateTime } from './date-time';
import type { ReferralDocument } from './evidence-picker';
import { label, referralHref, type ReferralDetail } from './types';

const MAX_BYTES = 10485760;

export function EvidenceUploadDialog({
    referral,
    audience,
    previous,
    onClose,
}: {
    referral: ReferralDetail;
    audience: 'assessment' | 'funding';
    previous?: ReferralDocument;
    onClose: () => void;
}) {
    const [title, setTitle] = useState(previous?.title ?? '');
    const [category, setCategory] = useState(previous?.category ?? 'other');
    const [source, setSource] = useState('');
    const [reason, setReason] = useState('');
    const [expiry, setExpiry] = useState('');
    const [file, setFile] = useState<File | null>(null);
    const [step, setStep] = useState(0);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [conflict, setConflict] = useState(false);
    const [version, setVersion] = useState(referral.version);
    const [saved, setSaved] = useState<ReferralDocument | null>(null);
    const [discard, setDiscard] = useState(false);
    const pending = useRef(false);
    const body = useRef<HTMLDivElement>(null);
    const steps = [
        {
            key: 'document',
            label: 'Document',
            blurb: 'Private supporting evidence',
            icon: FileText,
        },
        {
            key: 'review',
            label: 'Review',
            blurb: 'Check before uploading',
            icon: ClipboardCheck,
        },
    ];
    const go = (index: number) => {
        setStep(index);
        requestAnimationFrame(() => {
            body.current?.scrollTo({ top: 0 });
            body.current?.querySelector<HTMLElement>('[data-heading]')?.focus();
        });
    };
    function valid() {
        if (
            !file ||
            file.size > MAX_BYTES ||
            title.trim().length < 3 ||
            source.trim().length < 3 ||
            reason.trim().length < 5
        ) {
            setError(
                'Choose a PDF, JPEG or PNG up to 10 MB, and record its title, source and reason.',
            );
            go(0);
            return false;
        }
        return true;
    }
    async function save() {
        if (pending.current || conflict || !valid()) return;
        pending.current = true;
        setBusy(true);
        setError('');
        const form = new FormData();
        Object.entries({
            version,
            title,
            audience,
            category,
            source,
            reason,
            expires_at: expiry,
            previous_version_id: previous?.id ?? '',
        }).forEach(([key, value]) => form.append(key, String(value)));
        form.append('file', file!);
        try {
            const response = await axios.post<{
                saved: boolean;
                document: ReferralDocument;
            }>(`${referralHref(referral.reference)}/documents`, form);
            if (!response.data.saved) throw new Error('Upload not confirmed');
            setSaved(response.data.document);
            router.reload({ only: ['referral'], preserveScroll: true });
        } catch (caught) {
            const response = axios.isAxiosError(caught)
                ? caught.response
                : undefined;
            setConflict(!response || response.status === 409);
            setError(
                response?.data?.errors
                    ? Object.values(
                          response.data.errors as Record<string, string[]>,
                      )
                          .flat()
                          .join(' ')
                    : (response?.data?.message ??
                          'Upload was not confirmed. Check the latest documents before uploading again.'),
            );
        } finally {
            pending.current = false;
            setBusy(false);
        }
    }
    return (
        <>
            <WizardShell
                open
                title={
                    previous
                        ? 'Upload a new document version'
                        : 'Add a private document'
                }
                description={`Supporting evidence for ${referral.name}`}
                railTitle="Documents"
                railIcon={FileText}
                railSub="Available means malware checks passed. Funding or assessment verification is a separate decision."
                steps={steps}
                stepIndex={step}
                onStepClick={(index) => {
                    if (!busy) go(index);
                }}
                bodyRef={body}
                onClose={() => {
                    if (!busy) {
                        if (saved) onClose();
                        else setDiscard(true);
                    }
                }}
                footerStart={
                    <Button
                        variant="ghost"
                        disabled={busy}
                        onClick={() => (step ? go(0) : setDiscard(true))}
                    >
                        {step ? 'Back' : 'Cancel'}
                    </Button>
                }
                footerEnd={
                    step ? (
                        <Button
                            disabled={busy || conflict}
                            onClick={() => void save()}
                        >
                            {busy ? 'Uploading…' : 'Upload document'}
                        </Button>
                    ) : (
                        <Button
                            onClick={() => {
                                if (valid()) {
                                    setError('');
                                    go(1);
                                }
                            }}
                        >
                            Continue
                        </Button>
                    )
                }
                success={
                    saved ? (
                        <WizardSuccessPane
                            title={
                                saved.state === 'available'
                                    ? 'Document available'
                                    : saved.state === 'rejected'
                                      ? 'Document rejected'
                                      : 'Document quarantined'
                            }
                            blurb={
                                saved.state === 'available'
                                    ? 'The private document passed malware checks and can now be linked as supporting evidence.'
                                    : saved.state === 'rejected'
                                      ? 'Malware was detected. The file is blocked; obtain a safe replacement.'
                                      : 'The scan could not be completed. The document is retained privately but cannot be downloaded or used for verification until a scan succeeds.'
                            }
                            actions={
                                <Button onClick={onClose}>
                                    View documents
                                </Button>
                            }
                        />
                    ) : undefined
                }
            >
                <div className="space-y-5">
                    <h2
                        data-heading
                        tabIndex={-1}
                        className="text-section-title"
                    >
                        {steps[step].label}
                    </h2>
                    {error && (
                        <Alert variant="warning">
                            <AlertDescription>
                                {error}
                                {conflict && (
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            router.reload({
                                                only: ['referral'],
                                                preserveScroll: true,
                                                onSuccess: (page) => {
                                                    setVersion(
                                                        (
                                                            page.props
                                                                .referral as ReferralDetail
                                                        ).version,
                                                    );
                                                    setConflict(false);
                                                    setError(
                                                        'Review the current Evidence section before retrying. If this upload is already present, close this draft.',
                                                    );
                                                },
                                            })
                                        }
                                    >
                                        Reload and keep draft
                                    </Button>
                                )}
                            </AlertDescription>
                        </Alert>
                    )}
                    {!step ? (
                        <>
                            <p className="text-subtle">
                                Access: {label(audience)}.{' '}
                                {previous
                                    ? 'The earlier file and its history are retained. A replacement requires fresh review of dependent decisions.'
                                    : 'Only authorized colleagues can see this document.'}
                            </p>
                            <Field label="Document title">
                                <Input
                                    value={title}
                                    onChange={(event) =>
                                        setTitle(event.target.value)
                                    }
                                    maxLength={200}
                                />
                            </Field>
                            <Field label="Category">
                                <SelectInput
                                    value={category}
                                    placeholder="Choose document category"
                                    onChange={setCategory}
                                    options={[
                                        'authority',
                                        'assessment',
                                        'application',
                                        'external_decision',
                                        'agreement',
                                        'identity',
                                        'other',
                                    ].map((value) => ({
                                        value,
                                        label: label(value),
                                    }))}
                                />
                            </Field>
                            <Field label="Provided by / source">
                                <Input
                                    value={source}
                                    onChange={(event) =>
                                        setSource(event.target.value)
                                    }
                                    maxLength={500}
                                />
                            </Field>
                            <Field label="Reason for collecting this document">
                                <Textarea
                                    value={reason}
                                    onChange={(event) =>
                                        setReason(event.target.value)
                                    }
                                    maxLength={2000}
                                />
                            </Field>
                            <ReferralDateTime
                                id="evidence-expiry"
                                label="Expiry, if known"
                                value={expiry}
                                onChange={setExpiry}
                            />
                            {expiry && (
                                <Button
                                    variant="ghost"
                                    onClick={() => setExpiry('')}
                                >
                                    Clear expiry
                                </Button>
                            )}
                            <div className="space-y-2">
                                <p
                                    id="evidence-file-label"
                                    className="text-sm font-medium"
                                >
                                    Document file
                                </p>
                                {file ? (
                                    <StagedFileCard
                                        file={file}
                                        onRemove={() => setFile(null)}
                                    >
                                        <p className="text-caption text-muted-foreground">
                                            {file.size > MAX_BYTES
                                                ? 'This file is larger than 10 MB. Choose a smaller copy.'
                                                : 'Staged — uploads after you review and confirm.'}
                                        </p>
                                    </StagedFileCard>
                                ) : (
                                    <FileDropzone
                                        id="evidence-file"
                                        aria-labelledby="evidence-file-label"
                                        accept="application/pdf,image/jpeg,image/png"
                                        multiple={false}
                                        disabled={busy}
                                        title="Drag the document here"
                                        hint="PDF, JPEG or PNG · one file · maximum 10 MB"
                                        onFiles={(files) =>
                                            setFile(files[0] ?? null)
                                        }
                                    />
                                )}
                            </div>
                        </>
                    ) : (
                        <ReviewCard icon={Upload} title="Upload review">
                            <ReviewRow label="Title" value={title} />
                            <ReviewRow label="Access" value={label(audience)} />
                            <ReviewRow
                                label="Category"
                                value={label(category)}
                            />
                            <ReviewRow label="Source" value={source} />
                            <ReviewRow label="Reason" value={reason} />
                            <ReviewRow
                                label="File"
                                value={file?.name ?? 'Choose a file'}
                            />
                            <ReviewRow
                                label="Previous version"
                                value={previous?.title ?? 'First version'}
                            />
                        </ReviewCard>
                    )}
                </div>
            </WizardShell>
            <ConfirmDialog
                variant="destructive"
                open={discard}
                onClose={() => setDiscard(false)}
                title="Discard document upload?"
                description="The selected file and unsaved details will be cleared."
                confirmText="Discard"
                onConfirm={onClose}
            />
        </>
    );
}
