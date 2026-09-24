import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    FileDropzone,
    formatFileSize,
    StagedFileCard,
} from '@/components/ui/file-dropzone';
import { Label } from '@/components/ui/label';
import {
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/wizard/shell';
import { AlertCircle, Loader2, type LucideIcon } from 'lucide-react';
import { useEffect, useId, useRef, useState, type ReactNode } from 'react';

export type CommandState = {
    processing: boolean;
    uncertain: boolean;
    requiresReload: boolean;
    locked: boolean;
    message: string;
};

/**
 * WizardShell chrome shared by the vehicle workspace dialogs: context card,
 * error banner, step navigation, save / retry / reload footer, success pane
 * and a discard guard for unsaved drafts.
 */
export function WorkspaceWizard({
    title,
    description,
    railIcon,
    railSub,
    steps,
    step,
    setStep,
    pct,
    context,
    command,
    dirty,
    saved,
    submitLabel,
    onValidateStep,
    onSubmit,
    onClose,
    onReload,
    success,
    errorKey,
    children,
}: {
    title: string;
    description: string;
    railIcon: LucideIcon;
    railSub: string;
    steps: WizardStep[];
    step: number;
    setStep: (step: number) => void;
    pct: number;
    context: { name: string; detail: string };
    command: CommandState;
    dirty: boolean;
    saved: boolean;
    submitLabel: string;
    /** Returns true when the current step may be left. */
    onValidateStep: (step: number) => boolean;
    onSubmit: () => void;
    onClose: () => void;
    onReload: () => void;
    success: ReactNode;
    /** Changes whenever the visible errors change; moves focus to the first invalid field. */
    errorKey: string;
    children: ReactNode;
}) {
    const [discard, setDiscard] = useState(false);
    const fields = useRef<HTMLFieldSetElement>(null);
    const last = step === steps.length - 1;
    const close = () => {
        if (command.processing) return;
        if (!saved && (dirty || command.uncertain)) setDiscard(true);
        else onClose();
    };
    useEffect(() => {
        if (errorKey === '' || errorKey === '{}') return;
        // After the step pane renders, focus the first field that needs attention.
        const timer = window.setTimeout(() => {
            fields.current
                ?.querySelector<HTMLElement>('[aria-invalid="true"]')
                ?.focus();
        }, 0);
        return () => window.clearTimeout(timer);
    }, [errorKey, step]);

    return (
        <>
            <WizardShell
                open
                onClose={close}
                title={title}
                description={description}
                railIcon={railIcon}
                railTitle={title}
                railSub={railSub}
                steps={steps}
                stepIndex={step}
                onStepClick={(next) => {
                    if (command.locked) return;
                    if (next < step || onValidateStep(step)) setStep(next);
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
                        {step > 0 && !command.requiresReload && (
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
                        ) : !last && !command.uncertain ? (
                            <Button
                                disabled={command.processing}
                                onClick={() => {
                                    if (onValidateStep(step)) setStep(step + 1);
                                }}
                            >
                                Continue
                            </Button>
                        ) : (
                            <Button
                                disabled={command.processing}
                                onClick={onSubmit}
                            >
                                {command.processing && (
                                    <Loader2 className="size-4 animate-spin" />
                                )}
                                {command.processing
                                    ? 'Saving…'
                                    : command.uncertain
                                      ? 'Retry this submission'
                                      : submitLabel}
                            </Button>
                        )}
                    </>
                }
                success={saved ? success : undefined}
            >
                <div className="mb-6 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-primary/25 bg-primary/5 px-4 py-3 text-sm">
                    <strong>{context.name}</strong>
                    <span className="text-caption">{context.detail}</span>
                </div>
                {command.message && (
                    <div
                        role="alert"
                        className="mb-5 rounded-lg border border-status-warning/30 bg-status-warning-bg p-3 text-sm text-status-warning"
                    >
                        <strong className="block">
                            Review before continuing
                        </strong>
                        {command.message}
                    </div>
                )}
                <fieldset
                    ref={fields}
                    disabled={command.locked}
                    className="min-w-0"
                >
                    <WizardStepPane key={step}>
                        <h2 className="text-section-title mb-2">
                            {steps[step].label}
                        </h2>
                        {children}
                    </WizardStepPane>
                </fieldset>
            </WizardShell>
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={() => {
                    setDiscard(false);
                    onClose();
                }}
                title="Discard this draft?"
                description="Unsent changes will be removed."
                confirmText="Discard draft"
                cancelText="Keep editing"
            />
        </>
    );
}

export function WizardSuccess({
    title,
    blurb,
    onClose,
}: {
    title: string;
    blurb: ReactNode;
    onClose: () => void;
}) {
    return (
        <WizardSuccessPane
            title={title}
            blurb={blurb}
            actions={<Button onClick={onClose}>Return to vehicle</Button>}
        />
    );
}

/** Label, control, hint and error with the ids the control needs. */
export function WizardField({
    id,
    label,
    error,
    hint,
    optional,
    children,
    className,
}: {
    id: string;
    label: string;
    error?: string;
    hint?: ReactNode;
    optional?: boolean;
    children: ReactNode;
    className?: string;
}) {
    return (
        <div className={className ?? 'space-y-2'}>
            <Label htmlFor={id}>
                {label}
                {optional ? (
                    <span className="text-muted-foreground"> (optional)</span>
                ) : null}
            </Label>
            {children}
            {hint && <p className="text-caption leading-relaxed">{hint}</p>}
            {error && (
                <p
                    id={`${id}-error`}
                    className="text-xs text-status-critical"
                    role="alert"
                >
                    {error}
                </p>
            )}
        </div>
    );
}

export function fieldProps(id: string, error?: string) {
    return {
        id,
        'aria-invalid': !!error,
        'aria-describedby': error ? `${id}-error` : undefined,
    };
}

export const ACCEPT_EVIDENCE =
    '.pdf,.png,.jpg,.jpeg,application/pdf,image/png,image/jpeg';
const MAX_BYTES = 10 * 1024 * 1024;

/** Premium staged upload: PDF, PNG or JPEG up to 10 MiB each; valid picks are kept. */
export function StagedFilesField({
    label,
    files,
    onChange,
    error,
    imagesOnly = false,
    multiple = true,
    optional = true,
}: {
    label: string;
    files: File[];
    onChange: (files: File[]) => void;
    error?: string;
    imagesOnly?: boolean;
    multiple?: boolean;
    optional?: boolean;
}) {
    const id = useId();
    const [rejected, setRejected] = useState(false);
    const accept = imagesOnly
        ? '.png,.jpg,.jpeg,image/png,image/jpeg'
        : ACCEPT_EVIDENCE;
    const add = (picked: File[]) => {
        const valid = picked.filter((file) => {
            const ext = file.name.split('.').pop()?.toLowerCase() ?? '';
            const allowed = imagesOnly
                ? ['png', 'jpg', 'jpeg']
                : ['pdf', 'png', 'jpg', 'jpeg'];
            return (
                allowed.includes(ext) && file.size > 0 && file.size <= MAX_BYTES
            );
        });
        setRejected(valid.length !== picked.length);
        onChange(
            multiple ? [...files, ...valid].slice(0, 10) : valid.slice(-1),
        );
    };

    return (
        <div className="space-y-2">
            <Label id={`${id}-label`}>
                {label}
                {optional ? (
                    <span className="text-muted-foreground"> (optional)</span>
                ) : null}
            </Label>
            <FileDropzone
                aria-labelledby={`${id}-label`}
                aria-describedby={`${id}-hint`}
                aria-invalid={!!error}
                accept={accept}
                multiple={multiple}
                onFiles={add}
                title={imagesOnly ? 'Drop a photo here' : 'Drop files here'}
                hint={
                    imagesOnly
                        ? 'PNG or JPEG · up to 10 MiB'
                        : 'PDF, PNG or JPEG · up to 10 MiB each'
                }
            />
            <p id={`${id}-hint`} className="text-caption">
                Files are stored privately and can be opened once they pass a
                virus check.
            </p>
            {rejected && (
                <p role="alert" className="text-xs text-status-warning">
                    Some files were not accepted. Choose{' '}
                    {imagesOnly ? 'PNG or JPEG' : 'PDF, PNG or JPEG'} up to 10
                    MiB. Valid selections were kept.
                </p>
            )}
            {error && (
                <p role="alert" className="text-xs text-status-critical">
                    {error}
                </p>
            )}
            {files.length > 0 && (
                <div className="grid gap-2">
                    {files.map((file, index) => (
                        <StagedFileCard
                            key={`${file.name}-${file.size}-${file.lastModified}-${index}`}
                            file={file}
                            onRemove={() =>
                                onChange(files.filter((_, at) => at !== index))
                            }
                        >
                            <span className="text-caption">
                                {formatFileSize(file.size)} · selected
                            </span>
                        </StagedFileCard>
                    ))}
                </div>
            )}
        </div>
    );
}
/** A short record dialog with the same save, retry and reload states as the wizards. */
export function RecordDialog({
    title,
    description,
    command,
    submitLabel,
    onSubmit,
    onClose,
    children,
    destructive,
    submitDisabled = false,
}: {
    title: string;
    description: string;
    command: CommandState;
    submitLabel: string;
    onSubmit: () => void;
    onClose: () => void;
    children: ReactNode;
    destructive?: ReactNode;
    /** Something shown in the dialog must be resolved before saving. */
    submitDisabled?: boolean;
}) {
    return (
        <Dialog
            open
            onOpenChange={(next) => !next && !command.processing && onClose()}
        >
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                {command.message && (
                    <p
                        role="alert"
                        className="rounded-lg bg-status-warning-bg p-3 text-sm text-status-warning"
                    >
                        {command.message}
                    </p>
                )}
                <fieldset
                    disabled={command.locked}
                    className="grid min-w-0 gap-4"
                >
                    {children}
                </fieldset>
                <DialogFooter className="gap-2 sm:justify-between">
                    <div>{destructive}</div>
                    <div className="flex gap-2">
                        <Button
                            variant="outline"
                            disabled={command.processing}
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        <Button
                            disabled={
                                command.processing ||
                                command.requiresReload ||
                                submitDisabled
                            }
                            onClick={onSubmit}
                        >
                            {command.processing && (
                                <Loader2 className="size-4 animate-spin" />
                            )}
                            {command.uncertain
                                ? 'Retry this submission'
                                : submitLabel}
                        </Button>
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** The design's notice: an icon, a bold title and one short explanation. */
export function StudioNotice({
    title,
    tone = 'info',
    children,
}: {
    title: string;
    tone?: 'info' | 'warning' | 'critical';
    children?: ReactNode;
}) {
    return (
        <div
            className={`vehicle-notice ${tone}`}
            role={tone === 'critical' ? 'alert' : 'status'}
        >
            <AlertCircle className="size-[19px]" aria-hidden />
            <div>
                <strong>{title}</strong>
                {children ? <p>{children}</p> : null}
            </div>
        </div>
    );
}
