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
import { router } from '@inertiajs/react';
import { FileX2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

interface DraftArticle {
    id: number;
    title: string;
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
    const [reason, setReason] = useState('');
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        if (!open) setReason('');
    }, [open, article?.id]);

    const close = () => {
        setReason('');
        onOpenChange(false);
    };

    const submit = () => {
        const cleanReason = reason.trim();
        if (!article || !cleanReason) return;

        setSubmitting(true);
        router.delete(`/it/kb/${article.id}`, {
            data: { reason: cleanReason },
            preserveScroll: true,
            onSuccess: (page) => {
                const flash = page.props.flash as
                    | { error?: string; success?: string }
                    | undefined;
                if (flash?.error) {
                    toast.error(flash.error);
                    return;
                }
                toast.success(flash?.success ?? 'Draft deleted.');
                close();
            },
            onFinish: () => setSubmitting(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <FileX2 className="h-5 w-5 text-destructive" />
                        Delete “{article?.title}”?
                    </DialogTitle>
                    <DialogDescription>
                        Only draft articles can be deleted. Reviewed, published
                        and retired knowledge keeps its history; publishable
                        content should be retired instead.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-2">
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
