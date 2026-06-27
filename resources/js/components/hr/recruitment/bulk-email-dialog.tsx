/* eslint-disable no-restricted-syntax -- Bulk candidate email: a single-form
 * dialog posting to the bulk-email endpoint. Native inputs; semantic tokens. */
import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';

import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';

export function BulkEmailDialog({
    open,
    onClose,
    candidateIds,
}: {
    open: boolean;
    onClose: () => void;
    candidateIds: number[];
}) {
    const [subject, setSubject] = useState('');
    const [body, setBody] = useState('');
    const form = useForm({});

    const count = candidateIds.length;
    const canSend = subject.trim() !== '' && body.trim() !== '' && count > 0;

    const submit = () => {
        form.transform(() => ({ candidate_ids: candidateIds, subject: subject.trim(), body: body.trim() }));
        form.post('/hr/recruitment/candidates/bulk-email', {
            preserveScroll: true,
            onSuccess: (page) => {
                const f = (page.props as { flash?: { error?: string; success?: string } }).flash;
                if (f?.error) {
                    toast.error('Could not send', { description: f.error });
                    return;
                }
                toast.success(f?.success ?? `Message sent to ${count} candidate(s)`);
                setSubject('');
                setBody('');
                onClose();
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Email {count} candidate{count === 1 ? '' : 's'}</DialogTitle>
                    <DialogDescription>
                        Sends the same message to each selected candidate's personal email. Candidates without an email on file are skipped.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex flex-col gap-4">
                    <div>
                        <Label className="mb-1.5 block text-sm font-semibold">Subject</Label>
                        <input
                            value={subject}
                            onChange={(e) => setSubject(e.target.value)}
                            placeholder="e.g. An update on your application"
                            className="h-9 w-full rounded-md border border-border bg-card px-3 text-[13px] outline-none focus:border-primary"
                        />
                    </div>
                    <div>
                        <Label className="mb-1.5 block text-sm font-semibold">Message</Label>
                        <textarea
                            value={body}
                            onChange={(e) => setBody(e.target.value)}
                            rows={7}
                            placeholder="Write your message. Each line is sent as its own paragraph; a greeting and sign-off are added automatically."
                            className="w-full resize-y rounded-md border border-border bg-card p-2.5 text-[13px] outline-none focus:border-primary"
                        />
                    </div>
                </div>

                <DialogFooter>
                    <button type="button" onClick={onClose} className="h-9 rounded-md border border-border bg-card px-4 text-[13px] font-semibold hover:bg-muted">
                        Cancel
                    </button>
                    <button
                        type="button"
                        onClick={submit}
                        disabled={!canSend || form.processing}
                        className="h-9 rounded-md bg-primary px-4 text-[13px] font-bold text-primary-foreground disabled:opacity-50"
                    >
                        Send to {count}
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default BulkEmailDialog;
