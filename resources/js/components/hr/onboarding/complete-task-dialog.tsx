import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

export interface CompleteTaskTarget {
    id: number;
    title: string;
    sign_off_required: boolean;
    employee?: string | null;
}

/**
 * Complete a task with an optional evidence file + note, and (for sign-off
 * tasks) a sign-off declaration. Posts multipart via forceFormData so the file
 * uploads on the existing complete endpoint.
 */
export function CompleteTaskDialog({
    open,
    onClose,
    task,
    currentUserId,
}: {
    open: boolean;
    onClose: () => void;
    task: CompleteTaskTarget | null;
    currentUserId: number;
}) {
    const [file, setFile] = useState<File | null>(null);
    const [notes, setNotes] = useState('');
    const [signOff, setSignOff] = useState(true);
    const [processing, setProcessing] = useState(false);

    useEffect(() => {
        if (open) {
            setFile(null);
            setNotes('');
            setSignOff(true);
        }
    }, [open, task?.id]);

    if (!task) return null;

    const submit = () => {
        const fd = new FormData();
        if (file) fd.append('evidence', file);
        if (notes.trim()) fd.append('notes', notes.trim());
        if (task.sign_off_required && signOff)
            fd.append('signed_off_by', String(currentUserId));

        setProcessing(true);
        router.post(`/hr/onboarding/tasks/${task.id}/complete`, fd, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => onClose(),
            onFinish: () => setProcessing(false),
        });
    };

    const disabled = processing || (task.sign_off_required && !signOff);

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="p-0 sm:max-w-[520px]">
                <DialogHeader className="border-b border-border px-6 py-4">
                    <DialogTitle>Complete task</DialogTitle>
                    <DialogDescription>
                        {task.sign_off_required
                            ? 'Sign-off required — attach evidence.'
                            : 'Optionally attach evidence and a note.'}
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4 px-6 py-5">
                    <div className="rounded-lg bg-muted px-3.5 py-3 text-sm font-semibold">
                        {task.title}
                        {task.employee ? (
                            <span className="font-normal text-muted-foreground">
                                {' '}
                                — {task.employee}
                            </span>
                        ) : null}
                    </div>

                    <div className="space-y-1.5">
                        <Label>Evidence</Label>
                        {file ? (
                            <StagedFileCard
                                file={file}
                                onRemove={() => setFile(null)}
                            />
                        ) : (
                            <FileDropzone
                                multiple={false}
                                accept="application/pdf,image/png,image/jpeg,.doc,.docx"
                                title="Drop a file or click to upload"
                                hint="PDF, JPG, PNG or Word · stored against the task"
                                onFiles={(files) => setFile(files[0] ?? null)}
                            />
                        )}
                    </div>

                    <div className="space-y-1.5">
                        <Label>Note</Label>
                        <Textarea
                            rows={3}
                            value={notes}
                            onChange={(e) => setNotes(e.target.value)}
                            placeholder="Optional note…"
                        />
                    </div>

                    {task.sign_off_required && (
                        <label className="flex items-center gap-2.5 text-sm font-medium">
                            <Checkbox
                                checked={signOff}
                                onCheckedChange={(c) => setSignOff(Boolean(c))}
                            />
                            Sign off as me
                        </label>
                    )}
                </div>

                <div className="flex items-center justify-end gap-2.5 border-t border-border bg-muted/30 px-6 py-3.5">
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={submit} disabled={disabled}>
                        {processing
                            ? 'Saving…'
                            : task.sign_off_required
                              ? 'Complete & sign off'
                              : 'Complete task'}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}

export default CompleteTaskDialog;
