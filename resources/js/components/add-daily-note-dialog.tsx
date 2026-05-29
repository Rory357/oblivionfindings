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
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { useInitials } from '@/hooks/use-initials';
import { NOTE_CATEGORIES } from '@/pages/operations/clients/dialogs/_note-category-picker';
import { router } from '@inertiajs/react';
import { AlertTriangle, NotebookPen } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

export type DailyNoteClient = {
    id: number;
    name: string;
    nhi?: string | null;
};

/**
 * Reusable popup for adding a daily note against a client. Styled to match
 * AssignWorkerDialog and intentionally self-contained so it can be dropped into
 * any page (index, profile, care view…). The client it's for is shown
 * prominently at the top. Saves to POST /operations/clients/{id}/daily-notes,
 * which returns back() so the caller's page refreshes in place.
 */
export function AddDailyNoteDialog({
    client,
    open,
    onOpenChange,
    onSubmitted,
}: {
    client: DailyNoteClient | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onSubmitted?: () => void;
}) {
    const getInitials = useInitials();
    const [category, setCategory] = useState('other');
    const [subject, setSubject] = useState('');
    const [body, setBody] = useState('');
    const [occurredAt, setOccurredAt] = useState('');
    const [isFlagged, setIsFlagged] = useState(false);
    const [flaggedReason, setFlaggedReason] = useState('');
    const [visibleToFamily, setVisibleToFamily] = useState(false);
    const [processing, setProcessing] = useState(false);

    // Reset the form each time the dialog opens for a (possibly different) client.
    useEffect(() => {
        if (open) {
            setCategory('other');
            setSubject('');
            setBody('');
            setOccurredAt('');
            setIsFlagged(false);
            setFlaggedReason('');
            setVisibleToFamily(false);
            setProcessing(false);
        }
    }, [open, client?.id]);

    const canSave = body.trim().length >= 2 && !processing;

    function save() {
        if (!client || body.trim().length < 2) return;
        setProcessing(true);
        router.post(
            `/operations/clients/${client.id}/daily-notes`,
            {
                type: 'daily_note',
                category,
                subject: subject.trim() || null,
                body: body.trim(),
                occurred_at: occurredAt || null,
                visibility: visibleToFamily ? 'portal' : 'internal',
                is_flagged: isFlagged,
                flagged_reason: isFlagged ? flaggedReason.trim() || null : null,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    toast.success('Daily note added.');
                    onOpenChange(false);
                    onSubmitted?.();
                },
                onError: () =>
                    toast.error('Could not save the note. Please try again.'),
                onFinish: () => setProcessing(false),
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <NotebookPen className="h-5 w-5 text-primary" />
                        Add daily note
                    </DialogTitle>
                    <DialogDescription>
                        Capture what happened for the next worker and for review
                        later.
                    </DialogDescription>
                </DialogHeader>

                {/* Who this note is for */}
                {client ? (
                    <div className="flex items-center gap-3 rounded-lg border border-border bg-muted/40 px-3 py-2.5">
                        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary">
                            {getInitials(client.name)}
                        </span>
                        <div className="min-w-0">
                            <div className="truncate text-sm font-semibold">
                                {client.name}
                            </div>
                            <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                <span className="rounded-[4px] bg-accent px-1 py-px text-[9px] font-bold tracking-wide text-primary">
                                    NHI
                                </span>
                                <span className="tabular-nums">
                                    {client.nhi ?? '—'}
                                </span>
                            </div>
                        </div>
                        <span className="ml-auto shrink-0 rounded-full border border-border bg-secondary px-2.5 py-0.5 text-[11px] font-semibold text-muted-foreground">
                            Daily note
                        </span>
                    </div>
                ) : null}

                <div className="space-y-4">
                    {/* Category */}
                    <div className="space-y-1.5">
                        <Label>Category</Label>
                        <div className="flex flex-wrap gap-1.5">
                            {NOTE_CATEGORIES.map((cat) => (
                                <Button
                                    key={cat.key}
                                    type="button"
                                    size="sm"
                                    variant={
                                        category === cat.key
                                            ? 'default'
                                            : 'outline'
                                    }
                                    onClick={() => setCategory(cat.key)}
                                >
                                    {cat.label}
                                </Button>
                            ))}
                        </div>
                    </div>

                    {/* Short heading */}
                    <div className="space-y-1.5">
                        <Label htmlFor="daily-note-subject">
                            Short heading{' '}
                            <span className="font-normal text-muted-foreground">
                                (optional)
                            </span>
                        </Label>
                        <Input
                            id="daily-note-subject"
                            value={subject}
                            onChange={(e) => setSubject(e.target.value)}
                            placeholder="e.g. Afternoon walk and afternoon tea"
                        />
                    </div>

                    {/* Body */}
                    <div className="space-y-1.5">
                        <Label htmlFor="daily-note-body">What happened?</Label>
                        <Textarea
                            id="daily-note-body"
                            value={body}
                            onChange={(e) => setBody(e.target.value)}
                            className="min-h-32"
                            autoFocus
                            placeholder="Describe what happened so the next worker has the context they need."
                        />
                    </div>

                    {/* When */}
                    <div className="space-y-1.5">
                        <Label htmlFor="daily-note-when">
                            When{' '}
                            <span className="font-normal text-muted-foreground">
                                (leave blank for now)
                            </span>
                        </Label>
                        <Input
                            id="daily-note-when"
                            type="datetime-local"
                            value={occurredAt}
                            onChange={(e) => setOccurredAt(e.target.value)}
                        />
                    </div>

                    {/* Flags */}
                    <label className="flex cursor-pointer items-start gap-3 rounded-lg border border-border bg-card p-3">
                        <Checkbox
                            checked={isFlagged}
                            onCheckedChange={(checked) =>
                                setIsFlagged(checked === true)
                            }
                            className="mt-0.5"
                        />
                        <span>
                            <span className="flex items-center gap-1.5 text-sm font-medium">
                                <AlertTriangle className="h-4 w-4 text-status-warning" />
                                Needs review
                            </span>
                            <span className="block text-xs text-muted-foreground">
                                Send this to the review queue for a manager.
                            </span>
                        </span>
                    </label>

                    {isFlagged ? (
                        <Textarea
                            value={flaggedReason}
                            onChange={(e) => setFlaggedReason(e.target.value)}
                            placeholder="Reason for review"
                            className="min-h-20"
                        />
                    ) : null}

                    <label className="flex cursor-pointer items-center justify-between gap-3 rounded-lg border border-border bg-card p-3 text-sm">
                        <span>
                            <span className="font-medium">Visible to family</span>
                            <span className="block text-xs text-muted-foreground">
                                Share this note on the family portal.
                            </span>
                        </span>
                        <Switch
                            checked={visibleToFamily}
                            onCheckedChange={setVisibleToFamily}
                        />
                    </label>
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button type="button" onClick={save} disabled={!canSave}>
                        {processing ? 'Saving…' : 'Save note'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
