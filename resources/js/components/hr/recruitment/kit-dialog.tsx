/* eslint-disable no-restricted-syntax -- Interview-kit editor: a single-form
 * dialog (not a multi-step wizard) for create/edit of a weighted-criteria
 * scorecard. Styled native rows for the criteria list; semantic tokens only. */
import { useForm } from '@inertiajs/react';
import { GripVertical, Plus, Trash2 } from 'lucide-react';
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
import { cn } from '@/lib/utils';

export type KitDraft = {
    id: number;
    name: string;
    role: string | null;
    is_active: boolean;
    criteria: { label: string; weight: number }[];
};

type Criterion = { label: string; weight: string };

export function KitDialog({
    open,
    onClose,
    kit,
}: {
    open: boolean;
    onClose: () => void;
    /** null → create, existing → edit. */
    kit: KitDraft | null;
}) {
    const editing = kit !== null;
    const [name, setName] = useState(kit?.name ?? '');
    const [role, setRole] = useState(kit?.role ?? '');
    const [guidance, setGuidance] = useState('');
    const [criteria, setCriteria] = useState<Criterion[]>(
        kit && kit.criteria.length > 0
            ? kit.criteria.map((c) => ({
                  label: c.label,
                  weight: String(c.weight ?? ''),
              }))
            : [{ label: '', weight: '' }],
    );
    const form = useForm({});

    const totalWeight = criteria.reduce(
        (a, c) => a + (Number(c.weight) || 0),
        0,
    );
    const canSubmit =
        name.trim() !== '' && criteria.some((c) => c.label.trim() !== '');

    const setCriterion = (i: number, patch: Partial<Criterion>) =>
        setCriteria((rows) =>
            rows.map((r, idx) => (idx === i ? { ...r, ...patch } : r)),
        );
    const addRow = () =>
        setCriteria((rows) => [...rows, { label: '', weight: '' }]);
    const removeRow = (i: number) =>
        setCriteria((rows) =>
            rows.length > 1 ? rows.filter((_, idx) => idx !== i) : rows,
        );

    const submit = () => {
        const payload = {
            name: name.trim(),
            role: role.trim() || null,
            guidance: guidance.trim() || null,
            criteria: criteria
                .filter((c) => c.label.trim() !== '')
                .map((c) => ({
                    label: c.label.trim(),
                    weight: Number(c.weight) || 0,
                })),
        };
        const opts = {
            preserveScroll: true,
            onSuccess: (page: { props: Record<string, unknown> }) => {
                const f = (page.props as { flash?: { error?: string } }).flash;
                if (f?.error) {
                    toast.error('Could not save kit', { description: f.error });
                    return;
                }
                toast.success(
                    editing ? 'Interview kit updated' : 'Interview kit created',
                );
                onClose();
            },
        };
        form.transform(() => payload);
        if (editing && kit) form.put(`/hr/recruitment/kits/${kit.id}`, opts);
        else form.post('/hr/recruitment/kits', opts);
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-xl">
                <DialogHeader>
                    <DialogTitle>
                        {editing ? 'Edit interview kit' : 'New interview kit'}
                    </DialogTitle>
                    <DialogDescription>
                        Reusable weighted scorecard — interviewers score
                        candidates against these criteria.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex flex-col gap-4">
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <Label className="mb-1.5 block text-sm font-semibold">
                                Name
                            </Label>
                            <input
                                value={name}
                                onChange={(e) => setName(e.target.value)}
                                placeholder="e.g. Support Worker scorecard"
                                className="h-9 w-full rounded-md border border-border bg-card px-3 text-[13px] outline-none focus:border-primary"
                            />
                        </div>
                        <div>
                            <Label className="mb-1.5 block text-sm font-semibold">
                                Role{' '}
                                <span className="font-normal text-muted-foreground">
                                    (optional)
                                </span>
                            </Label>
                            <input
                                value={role}
                                onChange={(e) => setRole(e.target.value)}
                                placeholder="e.g. support_worker"
                                className="h-9 w-full rounded-md border border-border bg-card px-3 text-[13px] outline-none focus:border-primary"
                            />
                        </div>
                    </div>

                    <div>
                        <div className="mb-1.5 flex items-center justify-between">
                            <Label className="text-sm font-semibold">
                                Weighted criteria
                            </Label>
                            <span
                                className={cn(
                                    'text-[12px] font-bold tabular-nums',
                                    totalWeight === 100
                                        ? 'text-status-success'
                                        : 'text-muted-foreground',
                                )}
                            >
                                total {totalWeight}%
                            </span>
                        </div>
                        <div className="flex flex-col gap-2">
                            {criteria.map((c, i) => (
                                <div
                                    key={i}
                                    className="flex items-center gap-2"
                                >
                                    <GripVertical className="h-4 w-4 shrink-0 text-muted-foreground" />
                                    <input
                                        value={c.label}
                                        onChange={(e) =>
                                            setCriterion(i, {
                                                label: e.target.value,
                                            })
                                        }
                                        placeholder="Criterion (e.g. Values & person-centred care)"
                                        className="h-9 flex-1 rounded-md border border-border bg-card px-3 text-[13px] outline-none focus:border-primary"
                                    />
                                    <input
                                        value={c.weight}
                                        onChange={(e) =>
                                            setCriterion(i, {
                                                weight: e.target.value,
                                            })
                                        }
                                        placeholder="%"
                                        inputMode="numeric"
                                        className="h-9 w-16 rounded-md border border-border bg-card px-2 text-center text-[13px] outline-none focus:border-primary"
                                    />
                                    <button
                                        type="button"
                                        onClick={() => removeRow(i)}
                                        aria-label="Remove criterion"
                                        className="grid h-9 w-9 shrink-0 place-items-center rounded-md text-muted-foreground hover:bg-muted"
                                    >
                                        <Trash2 className="h-3.5 w-3.5" />
                                    </button>
                                </div>
                            ))}
                        </div>
                        <button
                            type="button"
                            onClick={addRow}
                            className="mt-2 inline-flex items-center gap-1.5 text-[13px] font-semibold text-primary hover:underline"
                        >
                            <Plus className="h-3.5 w-3.5" /> Add criterion
                        </button>
                    </div>

                    <div>
                        <Label className="mb-1.5 block text-sm font-semibold">
                            Guidance{' '}
                            <span className="font-normal text-muted-foreground">
                                (optional)
                            </span>
                        </Label>
                        <textarea
                            value={guidance}
                            onChange={(e) => setGuidance(e.target.value)}
                            rows={2}
                            placeholder="Notes for the panel on how to score"
                            className="w-full resize-y rounded-md border border-border bg-card p-2.5 text-[13px] outline-none focus:border-primary"
                        />
                    </div>
                </div>

                <DialogFooter>
                    <button
                        type="button"
                        onClick={onClose}
                        className="h-9 rounded-md border border-border bg-card px-4 text-[13px] font-semibold hover:bg-muted"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        onClick={submit}
                        disabled={!canSubmit || form.processing}
                        className="h-9 rounded-md bg-primary px-4 text-[13px] font-bold text-primary-foreground disabled:opacity-50"
                    >
                        {editing ? 'Save kit' : 'Create kit'}
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default KitDialog;
