/* eslint-disable no-restricted-syntax -- Tag management: rename (to merge) or
 * delete a candidate tag across the whole pipeline. Native inputs; tokens. */
import { router } from '@inertiajs/react';
import { Check, Pencil, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

export function TagManagerDialog({
    open,
    onClose,
    tags,
    canManage,
}: {
    open: boolean;
    onClose: () => void;
    tags: { tag: string; count: number }[];
    canManage: boolean;
}) {
    const [editing, setEditing] = useState<string | null>(null);
    const [draft, setDraft] = useState('');
    const [busy, setBusy] = useState(false);

    const onSuccess = (pg: { props: object }) => {
        const f = (pg.props as { flash?: { error?: string; success?: string } }).flash;
        if (f?.error) toast.error(f.error);
        else toast.success(f?.success ?? 'Tags updated');
    };

    const rename = (from: string) => {
        const to = draft.trim();
        if (!to || to === from) {
            setEditing(null);
            return;
        }
        setBusy(true);
        router.post(
            '/hr/recruitment/tags/rename',
            { from, to },
            { preserveScroll: true, onSuccess, onFinish: () => { setBusy(false); setEditing(null); } },
        );
    };

    const remove = (tag: string) => {
        if (!window.confirm(`Remove the tag “${tag}” from every candidate that carries it?`)) return;
        setBusy(true);
        router.post(
            '/hr/recruitment/tags/delete',
            { tag },
            { preserveScroll: true, onSuccess, onFinish: () => setBusy(false) },
        );
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Manage tags</DialogTitle>
                    <DialogDescription>Rename a tag (to merge duplicates) or remove it from every candidate. Matching is case-insensitive.</DialogDescription>
                </DialogHeader>
                {tags.length === 0 ? (
                    <p className="py-8 text-center text-[13px] text-muted-foreground">No tags yet. Add tags from a candidate profile or the pipeline bulk bar.</p>
                ) : (
                    <div className="max-h-[60vh] space-y-1.5 overflow-y-auto pr-1">
                        {tags.map((t) => (
                            <div key={t.tag} className="flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-2">
                                {editing === t.tag ? (
                                    <>
                                        <input
                                            autoFocus
                                            value={draft}
                                            onChange={(e) => setDraft(e.target.value)}
                                            onKeyDown={(e) => {
                                                if (e.key === 'Enter') rename(t.tag);
                                                if (e.key === 'Escape') setEditing(null);
                                            }}
                                            className="h-8 flex-1 rounded-md border border-border bg-background px-2 text-[13px] outline-none focus:border-primary"
                                        />
                                        <button type="button" disabled={busy} onClick={() => rename(t.tag)} title="Save" className="grid h-8 w-8 place-items-center rounded-md bg-primary text-primary-foreground disabled:opacity-50">
                                            <Check className="h-4 w-4" />
                                        </button>
                                        <button type="button" onClick={() => setEditing(null)} title="Cancel" className="grid h-8 w-8 place-items-center rounded-md border border-border hover:bg-muted">
                                            <X className="h-4 w-4" />
                                        </button>
                                    </>
                                ) : (
                                    <>
                                        <span className="flex-1 truncate text-[13.5px] font-semibold">{t.tag}</span>
                                        <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] font-semibold tabular-nums text-muted-foreground">{t.count}</span>
                                        {canManage ? (
                                            <>
                                                <button type="button" onClick={() => { setEditing(t.tag); setDraft(t.tag); }} title="Rename / merge" className="grid h-8 w-8 place-items-center rounded-md border border-border hover:bg-muted">
                                                    <Pencil className="h-3.5 w-3.5" />
                                                </button>
                                                <button type="button" disabled={busy} onClick={() => remove(t.tag)} title="Delete tag" className="grid h-8 w-8 place-items-center rounded-md border border-status-critical/30 text-status-critical hover:bg-status-critical-bg disabled:opacity-50">
                                                    <Trash2 className="h-3.5 w-3.5" />
                                                </button>
                                            </>
                                        ) : null}
                                    </>
                                )}
                            </div>
                        ))}
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
