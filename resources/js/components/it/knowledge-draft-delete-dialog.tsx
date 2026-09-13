import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { FileX2 } from 'lucide-react';
import { useEffect, useState } from 'react';

interface DraftArticle {
    id: number;
    title: string;
    lock_version?: number;
}

export function KnowledgeDraftDeleteDialog({
    article,
    open,
    onOpenChange,
}: {
    article: DraftArticle | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const actorId = usePage<SharedData>().props.auth.user.id;
    const [originActor, setOriginActor] = useState(actorId);
    const [reason, setReason] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState('');

    useEffect(() => {
        if (!open) {
            setReason('');
            setError('');
        }
    }, [open, article?.id]);
    useEffect(() => {
        if (originActor !== actorId) {
            setReason('');
            setError('');
            onOpenChange(false);
        }
        if (!open) setOriginActor(actorId);
    }, [actorId, originActor, open, onOpenChange]);

    const close = () => {
        if (submitting) return;
        setReason('');
        onOpenChange(false);
    };

    const submit = () => {
        const cleanReason = reason.trim();
        if (!article || !cleanReason || submitting || originActor !== actorId)
            return;

        setSubmitting(true);
        setError('');
        router.delete(`/it/kb/${article.id}`, {
            data: {
                actor_user_id: originActor,
                reason: cleanReason,
                lock_version: article.lock_version ?? 1,
            },
            preserveScroll: true,
            onSuccess: (page) => {
                const flash = page.props.flash as
                    | { error?: string; success?: string }
                    | undefined;
                if (flash?.error) {
                    setError(flash.error);
                    return;
                }
                setReason('');
                onOpenChange(false);
            },
            onError: (errors) =>
                setError(
                    Object.values(errors)[0] ??
                        'The draft was not deleted. Your reason is retained.',
                ),
            onFinish: () => setSubmitting(false),
        });
    };

    if (originActor !== actorId) return null;
    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!submitting) onOpenChange(next);
            }}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <FileX2 className="h-5 w-5 text-destructive" />
                        Delete “{article?.title}”?
                    </DialogTitle>
                    <DialogDescription>
                        Only draft articles can be deleted. Drafts with saved
                        files or revisions are archived instead, keeping their
                        history and allowing them to be restored. Published
                        knowledge must be retired through review.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-2">
                    {error && (
                        <p
                            role="alert"
                            className="rounded-lg border border-destructive/30 p-3 text-sm"
                        >
                            {error}
                        </p>
                    )}
                    <Label htmlFor="knowledge-draft-delete-reason">
                        Reason for deleting this draft
                    </Label>
                    <Textarea
                        id="knowledge-draft-delete-reason"
                        value={reason}
                        onChange={(event) => setReason(event.target.value)}
                        placeholder="For example: duplicate draft created during authoring."
                        maxLength={2000}
                        required
                        disabled={submitting}
                    />
                    <p className="text-xs text-muted-foreground">
                        The reason is retained in the audit history after the
                        draft is removed.
                    </p>
                </div>

                <DialogFooter>
                    <Button
                        variant="outline"
                        className="min-h-11"
                        onClick={close}
                        disabled={submitting}
                    >
                        Keep draft
                    </Button>
                    <Button
                        variant="destructive"
                        className="min-h-11"
                        disabled={submitting || reason.trim() === ''}
                        onClick={submit}
                    >
                        <FileX2 className="h-4 w-4" /> Delete draft
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
