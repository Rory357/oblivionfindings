import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';

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

import type { TimeEntry } from './types';

/** Lightweight team-visible note modal (recorded on the amendment trail). */
export function NoteDialog({
    entry,
    onClose,
}: {
    entry: TimeEntry | null;
    onClose: () => void;
}) {
    const form = useForm<{ note: string }>({ note: '' });

    useEffect(() => {
        if (entry) {
            form.reset();
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [entry?.id]);

    const submit = () => {
        if (!entry || !form.data.note.trim()) return;
        form.post(`/hr/time/entries/${entry.id}/note`, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Note added.');
                onClose();
            },
        });
    };

    return (
        <Dialog open={entry != null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Add a note</DialogTitle>
                    <DialogDescription>
                        A timestamped, team-visible note on {entry?.user_name}
                        &apos;s entry for {entry?.entry_date}. Shows in the
                        amendment history.
                    </DialogDescription>
                </DialogHeader>
                <Textarea
                    rows={4}
                    autoFocus
                    value={form.data.note}
                    onChange={(e) => form.setData('note', e.target.value)}
                    placeholder="e.g. Confirmed the extra hour with the duty manager."
                />
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        onClick={submit}
                        disabled={!form.data.note.trim() || form.processing}
                    >
                        {form.processing ? 'Saving…' : 'Add note'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default NoteDialog;
