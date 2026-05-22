import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { router } from '@inertiajs/react';
import { AlertTriangle, MessageSquare, Send } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    NoteCategoryPicker,
    type NoteCategoryKey,
} from './_note-category-picker';

type QuickNoteDialogProps = {
    clientId: number;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onSubmitted?: () => void;
};

const defaultState = {
    category: 'other' as NoteCategoryKey,
    subject: '',
    body: '',
    is_flagged: false,
    flagged_reason: '',
};

export function QuickNoteDialog({
    clientId,
    open,
    onOpenChange,
    onSubmitted,
}: QuickNoteDialogProps) {
    const [form, setForm] = useState(defaultState);
    const [processing, setProcessing] = useState(false);
    const openedAt = useRef<number | null>(null);

    useEffect(() => {
        if (open) {
            openedAt.current = Date.now();
        }
        if (!open) {
            setForm(defaultState);
            setProcessing(false);
            openedAt.current = null;
        }
    }, [open]);

    const update = <K extends keyof typeof form>(
        key: K,
        value: (typeof form)[K],
    ) => setForm((current) => ({ ...current, [key]: value }));

    const submit = () => {
        if (!form.body.trim()) return;
        setProcessing(true);
        router.post(
            `/operations/clients/${clientId}/daily-notes`,
            {
                type: 'quick',
                category: form.category,
                subject: form.subject,
                body: form.body,
                is_flagged: form.is_flagged,
                flagged_reason: form.is_flagged ? form.flagged_reason : null,
                visibility: 'internal',
                appears_on_timeline: true,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    window.dispatchEvent(
                        new CustomEvent('client-profile:note-capture', {
                            detail: {
                                mode: 'quick',
                                category: form.category,
                                flagged: form.is_flagged,
                                elapsed_ms: openedAt.current
                                    ? Date.now() - openedAt.current
                                    : null,
                            },
                        }),
                    );
                    onOpenChange(false);
                    onSubmitted?.();
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="max-h-[90vh] overflow-y-auto sm:max-w-lg"
                data-test="client-quick-note-dialog"
            >
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <MessageSquare className="h-5 w-5 text-primary" />
                        Quick Note
                    </DialogTitle>
                    <DialogDescription>
                        Capture the important detail now. It will be added to
                        daily notes and the client timeline.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-5">
                    <div className="space-y-2">
                        <Label>Category</Label>
                        <NoteCategoryPicker
                            value={form.category}
                            onChange={(value) => update('category', value)}
                            compact
                        />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="quick-note-subject">
                            Short heading
                        </Label>
                        <Input
                            id="quick-note-subject"
                            value={form.subject}
                            onChange={(event) =>
                                update('subject', event.target.value)
                            }
                            placeholder="Optional"
                            className="min-h-11"
                            data-test="client-quick-note-subject"
                        />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="quick-note-body">Note</Label>
                        <Textarea
                            id="quick-note-body"
                            value={form.body}
                            onChange={(event) =>
                                update('body', event.target.value)
                            }
                            placeholder="What happened?"
                            className="min-h-32"
                            autoFocus
                            data-test="client-quick-note-body"
                        />
                    </div>

                    <label className="frontline-focus flex min-h-11 items-start gap-3 rounded-lg border p-3">
                        <Checkbox
                            checked={form.is_flagged}
                            onCheckedChange={(checked) =>
                                update('is_flagged', checked === true)
                            }
                        />
                        <span className="space-y-1">
                            <span className="flex items-center gap-2 text-sm font-medium">
                                <AlertTriangle className="h-4 w-4 text-status-warning" />
                                Needs review
                            </span>
                            <span className="block text-xs text-muted-foreground">
                                Use this for concerns or follow-up that a
                                coordinator should see.
                            </span>
                        </span>
                    </label>

                    {form.is_flagged ? (
                        <div className="space-y-2">
                            <Label htmlFor="quick-note-flag">Review note</Label>
                            <Textarea
                                id="quick-note-flag"
                                value={form.flagged_reason}
                                onChange={(event) =>
                                    update('flagged_reason', event.target.value)
                                }
                                placeholder="Why does this need review?"
                                className="min-h-20"
                            />
                        </div>
                    ) : null}
                </div>

                <DialogFooter className="gap-2 sm:gap-0">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                        data-test="client-quick-note-cancel"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        onClick={submit}
                        disabled={!form.body.trim() || processing}
                        className="min-h-11"
                        data-test="client-quick-note-submit"
                    >
                        <Send className="mr-2 h-4 w-4" />
                        Save Note
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
