import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useInitials } from '@/hooks/use-initials';
import { router } from '@inertiajs/react';
import {
    CheckCircle2,
    Loader2,
    Search,
    Star,
    UserPlus,
    Users,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

type Worker = { id: number; name: string; email: string | null };

/**
 * Popup for assigning support workers to a client. Loads the worker list +
 * current assignments as JSON from the assignments endpoint and saves back to
 * it (with `_modal` so the controller returns to the index instead of the full
 * assignments page). The designated key worker is flagged with a star.
 */
export function AssignWorkerDialog({
    client,
    open,
    onOpenChange,
}: {
    client: { id: number; name: string } | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const getInitials = useInitials();
    const [loading, setLoading] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [workers, setWorkers] = useState<Worker[]>([]);
    const [selected, setSelected] = useState<number[]>([]);
    const [keyWorkerId, setKeyWorkerId] = useState<number | null>(null);
    const [search, setSearch] = useState('');

    useEffect(() => {
        if (!open || !client) {
            if (!open) {
                setWorkers([]);
                setSelected([]);
                setSearch('');
            }
            return;
        }
        const controller = new AbortController();
        setLoading(true);
        fetch(`/operations/clients/${client.id}/assignments?modal=1`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then((r) => {
                if (!r.ok) throw new Error('Failed to load workers');
                return r.json();
            })
            .then((json) => {
                setWorkers(json.workers ?? []);
                setSelected((json.assignedIds ?? []).map(Number));
                setKeyWorkerId(json.client?.key_worker_id ?? null);
            })
            .catch((err) => {
                if (err.name === 'AbortError') return;
                console.error(err);
                toast.error('Could not load workers. Please try again.');
            })
            .finally(() => setLoading(false));

        return () => controller.abort();
    }, [open, client]);

    const isSelected = useMemo(() => new Set(selected), [selected]);
    const assigned = useMemo(
        () => workers.filter((w) => isSelected.has(w.id)),
        [workers, isSelected],
    );
    const available = useMemo(() => {
        const q = search.trim().toLowerCase();
        return workers.filter(
            (w) =>
                !isSelected.has(w.id) &&
                (!q ||
                    w.name?.toLowerCase().includes(q) ||
                    w.email?.toLowerCase().includes(q)),
        );
    }, [workers, isSelected, search]);

    function toggle(id: number) {
        setSelected((prev) =>
            prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id],
        );
    }

    function save() {
        if (!client) return;
        setProcessing(true);
        router.put(
            `/operations/clients/${client.id}/assignments`,
            { user_ids: selected, _modal: 1 },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    toast.success('Worker assignments updated.');
                    onOpenChange(false);
                },
                onError: () =>
                    toast.error('Something went wrong saving assignments.'),
                onFinish: () => setProcessing(false),
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        Assign workers{client ? ` · ${client.name}` : ''}
                    </DialogTitle>
                    <DialogDescription>
                        Choose which support workers care for this client. The
                        key worker is marked with a star.
                    </DialogDescription>
                </DialogHeader>

                {loading ? (
                    <div className="flex items-center justify-center py-12">
                        <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
                    </div>
                ) : (
                    <div className="space-y-4">
                        {/* Currently assigned */}
                        <div>
                            <div className="mb-2 flex items-center gap-2">
                                <Users className="h-4 w-4 text-primary" />
                                <h3 className="text-sm font-semibold">
                                    Assigned
                                </h3>
                                <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] font-medium text-muted-foreground tabular-nums">
                                    {assigned.length}
                                </span>
                            </div>
                            {assigned.length === 0 ? (
                                <p className="rounded-lg border border-dashed px-3 py-4 text-center text-sm text-muted-foreground">
                                    No workers assigned yet.
                                </p>
                            ) : (
                                <div className="space-y-1.5">
                                    {assigned.map((w) => (
                                        <div
                                            key={w.id}
                                            className="flex items-center gap-2.5 rounded-lg border border-primary/30 bg-primary/5 px-2.5 py-2"
                                        >
                                            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/15 text-[11px] font-bold text-primary">
                                                {getInitials(w.name)}
                                            </span>
                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-center gap-1.5">
                                                    <span className="truncate text-sm font-medium">
                                                        {w.name}
                                                    </span>
                                                    {keyWorkerId === w.id ? (
                                                        <span className="inline-flex items-center gap-0.5 rounded-full bg-status-warning-bg px-1.5 py-0.5 text-[10px] font-medium text-status-warning">
                                                            <Star className="h-2.5 w-2.5" />
                                                            Key worker
                                                        </span>
                                                    ) : null}
                                                </div>
                                                {w.email ? (
                                                    <p className="truncate text-xs text-muted-foreground">
                                                        {w.email}
                                                    </p>
                                                ) : null}
                                            </div>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => toggle(w.id)}
                                                aria-label={`Remove ${w.name}`}
                                                title="Remove"
                                                className="h-7 w-7 shrink-0 p-0 text-status-critical hover:bg-status-critical-bg hover:text-status-critical"
                                            >
                                                <X className="h-4 w-4" />
                                            </Button>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>

                        {/* Available to add */}
                        <div>
                            <div className="mb-2 flex items-center gap-2">
                                <UserPlus className="h-4 w-4 text-status-success" />
                                <h3 className="text-sm font-semibold">
                                    Available
                                </h3>
                                <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] font-medium text-muted-foreground tabular-nums">
                                    {available.length}
                                </span>
                            </div>
                            <div className="relative mb-2">
                                <Search className="absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    placeholder="Search workers…"
                                    className="h-9 pl-8 text-sm"
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                />
                            </div>
                            {available.length === 0 ? (
                                <p className="flex items-center justify-center gap-1.5 rounded-lg border border-dashed px-3 py-4 text-center text-sm text-muted-foreground">
                                    <CheckCircle2 className="h-4 w-4 text-status-success" />
                                    {search
                                        ? 'No workers match your search.'
                                        : 'All workers are assigned.'}
                                </p>
                            ) : (
                                <div className="max-h-56 space-y-1 overflow-y-auto pr-1">
                                    {available.map((w) => (
                                        <Button
                                            key={w.id}
                                            type="button"
                                            variant="ghost"
                                            onClick={() => toggle(w.id)}
                                            className="h-auto w-full justify-start gap-2.5 rounded-lg px-2.5 py-2 text-left hover:bg-status-success-bg"
                                        >
                                            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-muted text-[11px] font-bold">
                                                {getInitials(w.name)}
                                            </span>
                                            <div className="min-w-0 flex-1">
                                                <span className="block truncate text-sm font-medium">
                                                    {w.name}
                                                </span>
                                                {w.email ? (
                                                    <p className="truncate text-xs text-muted-foreground">
                                                        {w.email}
                                                    </p>
                                                ) : null}
                                            </div>
                                            <UserPlus className="h-4 w-4 shrink-0 text-status-success" />
                                        </Button>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>
                )}

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        onClick={save}
                        disabled={processing || loading}
                    >
                        {processing ? 'Saving…' : 'Save assignments'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
