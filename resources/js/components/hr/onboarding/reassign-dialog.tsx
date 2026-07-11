import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { avatarStyle, initials } from './shared';
import type { OwnerOption } from './task-form-dialog';

export type ReassignTarget =
    | { kind: 'task'; id: number; current: number | null; label: string }
    | { kind: 'checklist'; id: number; current: number | null; label: string };

/**
 * Choose a new owner for a task or a whole checklist. The new owner is notified
 * server-side (tasks) / recorded as the checklist owner.
 */
export function ReassignDialog({
    open,
    onClose,
    target,
    owners,
}: {
    open: boolean;
    onClose: () => void;
    target: ReassignTarget | null;
    owners: OwnerOption[];
}) {
    const [selected, setSelected] = useState<number | null>(null);
    const [processing, setProcessing] = useState(false);

    useEffect(() => {
        if (open) setSelected(target?.current ?? null);
    }, [open, target]);

    if (!target) return null;

    const submit = () => {
        if (!selected) return;
        setProcessing(true);
        if (target.kind === 'task') {
            router.patch(
                `/hr/onboarding/tasks/${target.id}`,
                { assigned_to_user_id: selected },
                { preserveScroll: true, onSuccess: () => onClose(), onFinish: () => setProcessing(false) },
            );
        } else {
            router.post(
                `/hr/onboarding/${target.id}/reassign`,
                { owner_id: selected },
                { preserveScroll: true, onSuccess: () => onClose(), onFinish: () => setProcessing(false) },
            );
        }
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="p-0 sm:max-w-[460px]">
                <DialogHeader className="border-b border-border px-6 py-4">
                    <DialogTitle>Reassign {target.kind === 'task' ? 'task' : 'owner'}</DialogTitle>
                    <DialogDescription>
                        {target.label} — they'll be notified.
                    </DialogDescription>
                </DialogHeader>

                <div className="max-h-[50vh] space-y-2 overflow-y-auto px-6 py-5">
                    {owners.map((o) => {
                        const active = selected === o.id;
                        const av = avatarStyle(o.name ?? '');
                        return (
                            <Button unstyled
                                key={o.id}
                                type="button"
                                onClick={() => setSelected(o.id)}
                                className={`flex w-full items-center gap-3 rounded-[10px] border px-3.5 py-2.5 text-left transition-colors ${
                                    active ? 'border-primary bg-primary/10' : 'border-border hover:bg-muted'
                                }`}
                            >
                                <span
                                    className="grid h-8 w-8 flex-none place-items-center rounded-full text-[11px] font-bold"
                                    style={av}
                                >
                                    {initials(o.name)}
                                </span>
                                <span className="text-sm font-semibold">{o.name}</span>
                            </Button>
                        );
                    })}
                </div>

                <div className="flex items-center justify-end gap-2.5 border-t border-border bg-muted/30 px-6 py-3.5">
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={submit} disabled={!selected || processing}>
                        {processing ? 'Saving…' : 'Reassign'}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}

export default ReassignDialog;
