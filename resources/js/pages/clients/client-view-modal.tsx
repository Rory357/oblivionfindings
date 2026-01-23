import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';
import { Link, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

type ClientListItem = {
    id: number;
    first_name: string;
    last_name: string;
    status: string;
};

type ClientDetail = {
    id: number;
    first_name: string;
    last_name: string;
    status: string;
    support_workers: Array<{ id: number; name: string; email: string }>;
    site: { id: number; name: string } | null;
};

export default function ClientViewModal({
    open,
    onOpenChange,
    client,
    labels,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    client: ClientListItem | null;
    labels: Record<string, string>;
}) {
    const { auth } = usePage().props as any;
    const canUpdateSite = !!auth?.can?.sites?.update;

    const [loading, setLoading] = useState(false);
    const [detail, setDetail] = useState<ClientDetail | null>(null);
    const [error, setError] = useState<string | null>(null);

    const siteSingular = labels?.['site.singular'] ?? 'Site';

    const title = useMemo(() => {
        if (!client) return '';
        return `${client.first_name} ${client.last_name}`;
    }, [client]);

    useEffect(() => {
        if (!open || !client) return;

        let isCancelled = false;
        setLoading(true);
        setError(null);
        setDetail(null);

        fetch(`/clients/${client.id}?modal=1`, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
            },
            credentials: 'same-origin',
        })
            .then(async (res) => {
                if (!res.ok) {
                    const text = await res.text();
                    throw new Error(text || `Request failed (${res.status})`);
                }
                return res.json();
            })
            .then((json) => {
                if (isCancelled) return;
                // Expecting: { client: { ... } }
                setDetail(json.client as ClientDetail);
            })
            .catch((e) => {
                if (isCancelled) return;
                setError(
                    'Unable to load details. You may not have access to this record.',
                );
                console.error(e);
            })
            .finally(() => {
                if (isCancelled) return;
                setLoading(false);
            });

        return () => {
            isCancelled = true;
        };
    }, [open, client]);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="h-[80vh] max-w-3xl overflow-hidden overflow-y-auto scroll-smooth p-0">
                <div className="flex h-full flex-col">
                    <DialogHeader className="px-6 pt-6">
                        <DialogTitle>
                            {labels['client.singular']}: {title}
                        </DialogTitle>
                    </DialogHeader>

                    <div className="flex-1 px-6 pb-6 md:scroll-auto">
                        {loading && (
                            <div className="space-y-3">
                                <Skeleton className="h-5 w-56" />
                                <Skeleton className="h-4 w-full" />
                                <Skeleton className="h-4 w-2/3" />
                                <Separator />
                                <Skeleton className="h-5 w-40" />
                                <Skeleton className="h-12 w-full" />
                            </div>
                        )}

                        {!loading && error && (
                            <div className="rounded-md border p-3 text-sm text-red-500">
                                {error}
                            </div>
                        )}

                        {!loading && !error && detail && (
                            <div className="space-y-6">
                                <div className="space-y-2">
                                    <div className="text-sm font-semibold">
                                        General
                                    </div>

                                    <div className="grid grid-cols-2 gap-4 text-sm">
                                        <div>
                                            <div className="text-xs text-muted-foreground">
                                                First name
                                            </div>
                                            <div>{detail.first_name}</div>
                                        </div>

                                        <div>
                                            <div className="text-xs text-muted-foreground">
                                                Last name
                                            </div>
                                            <div>{detail.last_name}</div>
                                        </div>

                                        <div>
                                            <div className="text-xs text-muted-foreground">
                                                {siteSingular}
                                            </div>
                                            <div className="mt-1 text-xs text-slate-500">
                                                {detail.site ? (
                                                    canUpdateSite ? (
                                                        <Link
                                                            href={`/sites/${detail.site.id}/edit`}
                                                            className="text-indigo-300 hover:text-indigo-200"
                                                        >
                                                            {detail.site.name}
                                                        </Link>
                                                    ) : (
                                                        <span className="text-slate-300">
                                                            {detail.site.name}
                                                        </span>
                                                    )
                                                ) : (
                                                    <span className="text-slate-500">
                                                        —
                                                    </span>
                                                )}
                                            </div>
                                        </div>

                                        <div>
                                            <div className="text-xs text-muted-foreground">
                                                Status
                                            </div>
                                            <div>{detail.status}</div>
                                        </div>
                                    </div>
                                </div>

                                <Separator />

                                <div className="space-y-2">
                                    <div className="text-sm font-semibold">
                                        Assigned {labels['worker.plural']}
                                    </div>

                                    {detail.support_workers.length === 0 ? (
                                        <div className="text-sm text-muted-foreground">
                                            No{' '}
                                            {labels[
                                                'worker.plural'
                                            ].toLowerCase()}{' '}
                                            assigned.
                                        </div>
                                    ) : (
                                        <div className="space-y-2">
                                            {detail.support_workers.map((w) => (
                                                <div
                                                    key={w.id}
                                                    className="rounded-md border p-3"
                                                >
                                                    <div className="text-sm font-medium">
                                                        {w.name}
                                                    </div>
                                                    <div className="text-xs text-muted-foreground">
                                                        {w.email}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </div>

                                <Separator />

                                <div className="space-y-2">
                                    <div className="text-sm font-semibold">Management</div>
                                    <div className="flex flex-wrap gap-2 text-xs">
                                        <Link href={`/clients/${detail.id}`} className="rounded-md border px-3 py-2 hover:bg-muted">
                                            Open profile
                                        </Link>
                                        <Link href={`/clients/${detail.id}/medical`} className="rounded-md border px-3 py-2 hover:bg-muted">
                                            Medical
                                        </Link>
                                        <Link href={`/clients/${detail.id}/documents`} className="rounded-md border px-3 py-2 hover:bg-muted">
                                            Documents
                                        </Link>
                                        <Link href={`/clients/${detail.id}/portal-users`} className="rounded-md border px-3 py-2 hover:bg-muted">
                                            Portal users
                                        </Link>
                                        <Link href={`/clients/${detail.id}/assignments`} className="rounded-md border px-3 py-2 hover:bg-muted">
                                            Assign workers
                                        </Link>
                                    </div>
                                </div>

                                {/* Future sections (permission-gated later)
                                    - Schedule (upcoming appointments)
                                    - Plans / Notes
                                    - Sensitive Diary
                                */}
                            </div>
                        )}
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}
