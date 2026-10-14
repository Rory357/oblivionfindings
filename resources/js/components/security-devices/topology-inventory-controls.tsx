import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useId, useState, type FormEvent } from 'react';
import { WorkspaceFilterBar } from './security-devices-workspace-shell';
import type { TopologyInventory } from './topology-types';

export function TopologyInventoryControls({
    inventory,
}: {
    inventory: TopologyInventory;
}) {
    const [site, setSite] = useState(
        inventory.siteId === null ? 'all' : String(inventory.siteId),
    );
    const [search, setSearch] = useState(inventory.search);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const id = useId();
    const currentSite = inventory.sites.find(
        (item) => item.id === inventory.siteId,
    );
    const apply = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (busy) return;
        setError('');
        const params: Record<string, string> = { tab: 'map' };
        if (site !== 'all') params.map_site_id = site;
        if (search.trim()) params.map_search = search.trim();
        // A new filter view deliberately drops both history and inventory positions.
        router.get('/security-devices/network-it', params, {
            preserveState: false,
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onError: () =>
                setError(
                    'The view could not be applied. Check your choices and try again.',
                ),
            onCancel: () =>
                setError('The view change was cancelled. You can try again.'),
        });
    };
    return (
        <WorkspaceFilterBar>
            <form
                aria-label="Choose topology devices"
                aria-busy={busy}
                onSubmit={apply}
                className="space-y-3"
            >
                <div className="grid items-end gap-3 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]">
                    <div className="min-w-0 space-y-2">
                        <Label htmlFor={id + '-site'}>Site</Label>
                        <Select
                            value={site}
                            onValueChange={setSite}
                            disabled={busy}
                        >
                            <SelectTrigger id={id + '-site'}>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    All visible devices
                                </SelectItem>
                                {inventory.sites.map((item) => (
                                    <SelectItem
                                        key={item.id}
                                        value={String(item.id)}
                                    >
                                        {item.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="min-w-0 space-y-2">
                        <Label htmlFor={id + '-name'}>Device name</Label>
                        <Input
                            id={id + '-name'}
                            value={search}
                            maxLength={200}
                            disabled={busy}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Search all matching devices"
                            aria-describedby={id + '-help'}
                        />
                    </div>
                    <Button type="submit" disabled={busy}>
                        {busy ? 'Applying view…' : 'Apply view'}
                    </Button>
                </div>
                <p
                    id={id + '-help'}
                    className="text-caption text-muted-foreground"
                >
                    Search includes every device you may view at the selected
                    site, beyond this page.
                </p>
                {error && (
                    <p
                        role="alert"
                        className="text-caption text-status-critical"
                    >
                        {error}
                    </p>
                )}
            </form>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="min-w-0 space-y-1">
                    <p className="text-caption break-words">
                        Current view:{' '}
                        {currentSite?.name ?? 'All visible devices'}
                        {inventory.search
                            ? ' · Name contains “' + inventory.search + '”'
                            : ''}
                    </p>
                    <p className="text-caption text-muted-foreground">
                        Showing {inventory.shown} of {inventory.total} matching
                        devices.
                    </p>
                </div>
                {(inventory.siteId !== null ||
                    inventory.search ||
                    inventory.previousHref) && (
                    <Button asChild variant="ghost" size="sm">
                        <Link
                            href={inventory.resetHref}
                            preserveState={false}
                            preserveScroll
                        >
                            Clear view
                        </Link>
                    </Button>
                )}
            </div>
            {inventory.positionReset && (
                <p role="status" className="text-caption text-muted-foreground">
                    That device page is no longer available. Showing the first
                    visible devices.
                </p>
            )}
            {inventory.isPartial && (
                <div className="space-y-2">
                    <StatusBadge variant="info">
                        Limited device view
                    </StatusBadge>
                    <p className="text-caption text-muted-foreground">
                        Connections and paths include only devices shown here. A
                        device may have other connections outside this view.
                    </p>
                </div>
            )}
            {(inventory.previousHref || inventory.nextHref) && (
                <nav
                    aria-label="Topology device pages"
                    className="flex flex-wrap gap-2"
                >
                    {inventory.previousHref && (
                        <Button asChild variant="outline" size="sm">
                            <Link
                                href={inventory.previousHref}
                                preserveState={false}
                                preserveScroll
                            >
                                <ChevronLeft aria-hidden />
                                Previous devices
                            </Link>
                        </Button>
                    )}
                    {inventory.nextHref && (
                        <Button asChild variant="outline" size="sm">
                            <Link
                                href={inventory.nextHref}
                                preserveState={false}
                                preserveScroll
                            >
                                More devices
                                <ChevronRight aria-hidden />
                            </Link>
                        </Button>
                    )}
                </nav>
            )}
        </WorkspaceFilterBar>
    );
}
