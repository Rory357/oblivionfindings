import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { router } from '@inertiajs/react';
import { Laptop, Search } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

export interface ProvisionableAsset {
    id: number;
    name: string;
    asset_tag: string | null;
}

export interface ProvisionTarget {
    id: number;
    title: string;
    sign_off_required: boolean;
}

/**
 * Issue a specific company asset to the new hire from an IT onboarding task —
 * creates the assignment and completes the task in one action.
 */
export function ProvisionAssetDialog({
    open,
    onClose,
    task,
    assets,
    currentUserId,
}: {
    open: boolean;
    onClose: () => void;
    task: ProvisionTarget | null;
    assets: ProvisionableAsset[];
    currentUserId: number;
}) {
    const [assetId, setAssetId] = useState<number | null>(null);
    const [purpose, setPurpose] = useState('');
    const [signOff, setSignOff] = useState(true);
    const [query, setQuery] = useState('');
    const [processing, setProcessing] = useState(false);

    useEffect(() => {
        if (open) {
            setAssetId(null);
            setPurpose('');
            setSignOff(true);
            setQuery('');
        }
    }, [open, task?.id]);

    const filtered = useMemo(() => {
        const q = query.trim().toLowerCase();
        if (!q) return assets.slice(0, 50);
        return assets
            .filter((a) => a.name.toLowerCase().includes(q) || (a.asset_tag ?? '').toLowerCase().includes(q))
            .slice(0, 50);
    }, [assets, query]);

    if (!task) return null;

    const post = (payload: Record<string, unknown>) => {
        setProcessing(true);
        router.post(
            `/hr/onboarding/tasks/${task.id}/provision-asset`,
            {
                ...payload,
                purpose: purpose.trim() || undefined,
                signed_off_by: task.sign_off_required && signOff ? currentUserId : undefined,
            },
            { preserveScroll: true, onSuccess: () => onClose(), onFinish: () => setProcessing(false) },
        );
    };

    const submit = () => {
        if (!assetId) return;
        post({ asset_id: assetId });
    };

    // Omitting asset_id lets the server pick the first available asset.
    const autoPick = () => post({});

    const signOffMissing = task.sign_off_required && !signOff;
    const disabled = processing || !assetId || signOffMissing;

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="p-0 sm:max-w-[520px]">
                <DialogHeader className="border-b border-border px-6 py-4">
                    <DialogTitle>Provision asset</DialogTitle>
                    <DialogDescription>
                        Issue a company asset for “{task.title}” and complete the task.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4 px-6 py-5">
                    <div className="relative">
                        <Search className="absolute top-1/2 left-3 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder="Search assets by name or tag…"
                            className="pl-9"
                        />
                    </div>

                    <div className="max-h-[240px] space-y-1.5 overflow-y-auto">
                        {assets.length === 0 ? (
                            <p className="px-1 py-6 text-center text-sm text-muted-foreground">
                                No available assets to issue.
                            </p>
                        ) : (
                            filtered.map((a) => {
                                const active = assetId === a.id;
                                return (
                                    <Button unstyled
                                        key={a.id}
                                        type="button"
                                        onClick={() => setAssetId(a.id)}
                                        className={`flex w-full items-center gap-3 rounded-[10px] border px-3 py-2.5 text-left transition-colors ${
                                            active ? 'border-primary bg-primary/10' : 'border-border hover:bg-muted'
                                        }`}
                                    >
                                        <span className="grid h-8 w-8 flex-none place-items-center rounded-lg bg-muted text-muted-foreground">
                                            <Laptop className="h-4 w-4" />
                                        </span>
                                        <span className="min-w-0">
                                            <span className="block truncate text-[13px] font-semibold">{a.name}</span>
                                            {a.asset_tag && (
                                                <span className="block truncate text-[11px] text-muted-foreground">
                                                    Tag {a.asset_tag}
                                                </span>
                                            )}
                                        </span>
                                    </Button>
                                );
                            })
                        )}
                    </div>

                    <div className="space-y-1.5">
                        <Label>Purpose (optional)</Label>
                        <Input
                            value={purpose}
                            onChange={(e) => setPurpose(e.target.value)}
                            placeholder="e.g. Field laptop"
                        />
                    </div>

                    {task.sign_off_required && (
                        <label className="flex items-center gap-2.5 text-sm font-medium">
                            <Checkbox checked={signOff} onCheckedChange={(c) => setSignOff(Boolean(c))} />
                            Sign off as me
                        </label>
                    )}
                </div>

                <div className="flex items-center gap-2.5 border-t border-border bg-muted/30 px-6 py-3.5">
                    <Button
                        variant="outline"
                        onClick={autoPick}
                        disabled={processing || signOffMissing || assets.length === 0}
                        title="Assign the first available asset"
                    >
                        Auto-pick available
                    </Button>
                    <div className="flex-1" />
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={submit} disabled={disabled}>
                        {processing ? 'Issuing…' : 'Issue & complete'}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}

export default ProvisionAssetDialog;
