import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { query } from '@/pages/fleet-assets/geofences/workspace/api';
import type { BoundaryRecord } from '@/pages/fleet-assets/geofences/workspace/data';
import { RemotePicker } from '@/pages/fleet-assets/geofences/workspace/remote-picker';
import { Link, router } from '@inertiajs/react';
import { Plus, Shapes } from 'lucide-react';
export type SiteGeofenceRecord = {
    id: number;
    name: string;
    type: 'circle' | 'polygon';
    shape: Record<string, unknown> | null;
    is_active?: boolean;
    retired_at?: string | null;
};
export default function SiteGeofenceDialog({
    isOpen,
    onClose,
    siteId,
    siteName,
    geofences,
}: {
    isOpen: boolean;
    onClose: () => void;
    siteId: number;
    siteName: string;
    geofences: SiteGeofenceRecord[];
}) {
    return (
        <Dialog open={isOpen} onOpenChange={(o) => !o && onClose()}>
            <DialogContent
                className="p-0"
                style={{ maxWidth: 'min(92vw,720px)' }}
            >
                <DialogHeader className="border-b p-6">
                    <DialogTitle>Shared boundaries · {siteName}</DialogTitle>
                    <DialogDescription>
                        Choose the area to review. Each boundary keeps its own
                        identity, geometry and history.
                    </DialogDescription>
                </DialogHeader>
                <div className="max-h-[55vh] space-y-3 overflow-y-auto px-6 py-4">
                    <RemotePicker<BoundaryRecord>
                        label="Find a site boundary"
                        url={(q) =>
                            query('/catalogue', {
                                site_id: siteId,
                                q,
                                size: 20,
                                status: 'all',
                            })
                        }
                        describe={(b) => ({
                            id: b.id,
                            name: b.name,
                            detail: b.retired_at ? 'Retired' : 'Available',
                        })}
                        onSelect={(b) =>
                            router.visit(
                                `/fleet-assets/geofences?site_id=${siteId}&selected=${b.id}&${b.retired_at ? 'tab=history' : 'tab=boundaries&edit=' + b.id}`,
                            )
                        }
                    />
                    {geofences.length ? (
                        geofences.map((b) => (
                            <Link
                                key={b.id}
                                href={`/fleet-assets/geofences?site_id=${siteId}&selected=${b.id}&${b.retired_at ? 'tab=history' : 'tab=boundaries&edit=' + b.id}`}
                                className="flex items-center gap-3 rounded-lg border p-4 hover:bg-accent"
                            >
                                <Shapes className="size-5 text-primary" />
                                <span>
                                    <strong className="block">{b.name}</strong>
                                    <small className="text-muted-foreground">
                                        BG-{b.id} · {b.type}
                                        {b.retired_at ? ' · Retired' : ''}
                                    </small>
                                </span>
                            </Link>
                        ))
                    ) : (
                        <p>No shared boundaries are recorded for this site.</p>
                    )}
                    <p className="text-sm text-muted-foreground">
                        Showing up to 20 areas. Search to find another, or open
                        the full library.
                    </p>
                    <Button asChild variant="outline">
                        <Link
                            href={`/fleet-assets/geofences?tab=boundaries&site_id=${siteId}`}
                        >
                            Browse all site boundaries
                        </Link>
                    </Button>
                    <p className="text-sm text-muted-foreground">
                        Editing shared geometry does not replace Client
                        Location, EVV, vehicle or asset rules. Protected
                        dependencies must be reviewed by their owners.
                    </p>
                </div>
                <DialogFooter className="border-t bg-muted/30 p-4">
                    <Button variant="outline" onClick={onClose}>
                        Close
                    </Button>
                    <Button asChild>
                        <Link
                            href={`/fleet-assets/geofences?new=1&site_id=${siteId}`}
                        >
                            <Plus />
                            Create another boundary
                        </Link>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
