import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card as GuardrailCard } from '@/components/ui/card';
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
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { Loader2, Package } from 'lucide-react';
import { useMemo, useState } from 'react';
import { type RoomRecord } from './_dialogs';

export type AssetForPicker = {
    id: number;
    name: string;
    asset_tag?: string | null;
    category?: string | null;
    status?: string | null;
};

export function AssignAssetDialog({
    siteId,
    room,
    assets,
    isOpen,
    onClose,
}: {
    siteId: number;
    room: RoomRecord | null;
    assets: AssetForPicker[];
    isOpen: boolean;
    onClose: () => void;
}) {
    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-xl">
                {isOpen && room && (
                    <AssignAssetBody
                        siteId={siteId}
                        room={room}
                        assets={assets}
                        onClose={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function AssignAssetBody({
    siteId,
    room,
    assets,
    onClose,
}: {
    siteId: number;
    room: RoomRecord;
    assets: AssetForPicker[];
    onClose: () => void;
}) {
    const [query, setQuery] = useState('');
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [submitting, setSubmitting] = useState(false);

    const filtered = useMemo(() => {
        const q = query.trim().toLowerCase();
        if (!q) return assets;
        return assets.filter((a) => {
            const hay =
                `${a.name} ${a.asset_tag ?? ''} ${a.category ?? ''}`.toLowerCase();
            return hay.includes(q);
        });
    }, [assets, query]);

    const handleAttach = () => {
        if (!selectedId) return;
        setSubmitting(true);
        router.post(
            `/sites/${siteId}/rooms/${room.id}/assets`,
            { asset_id: selectedId },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setSubmitting(false),
                onSuccess: () => onClose(),
            },
        );
    };

    return (
        <>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <Package className="h-4 w-4 text-primary" />
                    Attach an asset to {room.name}
                </DialogTitle>
                <DialogDescription>
                    Pick from assets at this site that aren't already in a room.
                    Detach from this room by clicking the bin icon in the asset
                    list.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-3 space-y-3">
                <div>
                    <Label htmlFor="ar-asset-search">Search assets</Label>
                    <Input
                        id="ar-asset-search"
                        placeholder="Search by name, tag, or category…"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                    />
                </div>

                <GuardrailCard
                    unstyled
                    className="max-h-72 overflow-y-auto rounded-xl border bg-card/40"
                >
                    {filtered.length === 0 ? (
                        <p className="px-4 py-6 text-center text-xs text-muted-foreground">
                            {assets.length === 0
                                ? 'No unallocated assets at this site. Create one from the Fleet & Assets module first.'
                                : `No assets match "${query}".`}
                        </p>
                    ) : (
                        <ul className="divide-y">
                            {filtered.map((a) => {
                                const active = selectedId === a.id;
                                return (
                                    <li key={a.id}>
                                        <Button
                                            unstyled
                                            type="button"
                                            onClick={() => setSelectedId(a.id)}
                                            className={cn(
                                                'flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm transition-colors',
                                                active
                                                    ? 'bg-primary/10'
                                                    : 'hover:bg-muted/50',
                                            )}
                                        >
                                            <div className="flex min-w-0 items-center gap-3">
                                                <span className="shrink-0 rounded-lg border bg-background/60 p-1.5">
                                                    <Package className="h-4 w-4 text-muted-foreground" />
                                                </span>
                                                <div className="min-w-0">
                                                    <p className="truncate font-medium">
                                                        {a.name}
                                                    </p>
                                                    <p className="truncate text-xs text-muted-foreground">
                                                        {[
                                                            a.asset_tag,
                                                            a.category,
                                                        ]
                                                            .filter(Boolean)
                                                            .join(' · ') ||
                                                            'Unallocated'}
                                                    </p>
                                                </div>
                                            </div>
                                            {a.status && (
                                                <Badge
                                                    variant="outline"
                                                    className="text-[10px]"
                                                >
                                                    {a.status}
                                                </Badge>
                                            )}
                                        </Button>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </GuardrailCard>
            </div>

            <DialogFooter className="mt-4">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button
                    type="button"
                    onClick={handleAttach}
                    disabled={!selectedId || submitting}
                >
                    {submitting && (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    )}
                    Attach asset
                </Button>
            </DialogFooter>
        </>
    );
}
