import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { Loader2 } from 'lucide-react';
import { useState } from 'react';
import {
    uploadSummary,
    useEvidenceUpload,
    type EvidenceSource,
} from './evidence-upload';
import type { VehicleProfile } from './types';
import { fieldProps, StagedFilesField, WizardField } from './wizard-kit';

/** Add supporting files to a record that already exists (a reading, schedule or service). */
export function AddEvidenceDialog({
    vehicle,
    title,
    category,
    sourceType,
    sourceId,
    onClose,
    onSaved,
}: {
    vehicle: VehicleProfile;
    title: string;
    category: string;
    sourceType: EvidenceSource;
    sourceId: number;
    onClose: () => void;
    onSaved: () => void;
}) {
    const [files, setFiles] = useState<File[]>([]);
    const [reason, setReason] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [done, setDone] = useState<string | null>(null);
    const { upload, command } = useEvidenceUpload(vehicle.id);

    const save = async () => {
        const found: Record<string, string> = {};
        if (!files.length) found.files = 'Attach at least one file.';
        if (!reason.trim()) found.reason = 'Record what these files show.';
        setErrors(found);
        if (Object.keys(found).length) return;
        const outcome = await upload(files, {
            category,
            reason: reason.trim(),
            sourceType,
            sourceId,
        });
        if (outcome) {
            setDone(
                `Evidence kept with its record.${uploadSummary(outcome, files.length)}`,
            );
            onSaved();
        }
    };

    return (
        <Dialog
            open
            onOpenChange={(open) => !open && !command.processing && onClose()}
        >
            <DialogContent className="max-w-xl">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>
                        {vehicle.name} · original evidence stays with its
                        record.
                    </DialogDescription>
                </DialogHeader>
                {done ? (
                    <p className="text-sm" role="status">
                        {done}
                    </p>
                ) : (
                    <div className="grid gap-4">
                        {command.message && (
                            <p
                                role="alert"
                                className="rounded-lg bg-status-warning-bg p-3 text-sm text-status-warning"
                            >
                                {command.errors['files.0'] ?? command.message}
                            </p>
                        )}
                        <StagedFilesField
                            label="Files"
                            files={files}
                            onChange={setFiles}
                            error={errors.files}
                            optional={false}
                        />
                        <WizardField
                            id="evidence-reason"
                            label="What these files show"
                            error={errors.reason ?? command.errors.reason}
                        >
                            <Textarea
                                {...fieldProps(
                                    'evidence-reason',
                                    errors.reason,
                                )}
                                rows={2}
                                maxLength={2000}
                                value={reason}
                                onChange={(event) =>
                                    setReason(event.target.value)
                                }
                            />
                        </WizardField>
                    </div>
                )}
                <DialogFooter>
                    {done ? (
                        <Button onClick={onClose}>Done</Button>
                    ) : (
                        <>
                            <Button
                                variant="outline"
                                disabled={command.processing}
                                onClick={onClose}
                            >
                                Cancel
                            </Button>
                            <Button
                                disabled={
                                    command.processing || command.requiresReload
                                }
                                onClick={save}
                            >
                                {command.processing && (
                                    <Loader2 className="size-4 animate-spin" />
                                )}
                                {command.uncertain
                                    ? 'Retry upload'
                                    : 'Save evidence'}
                            </Button>
                        </>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
