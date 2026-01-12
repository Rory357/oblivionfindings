import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';
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
    const [loading, setLoading] = useState(false);
    const [detail, setDetail] = useState<ClientDetail | null>(null);
    const [error, setError] = useState<string | null>(null);

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
            <DialogContent className="max-w-2xl h-[80vh] overflow-hidden p-0">
                <div className="flex h-full flex-col">
                    <DialogHeader className="px-6 pt-6">
                    <DialogTitle>
                        {labels['client.singular']}: {title}
                    </DialogTitle>
                    </DialogHeader>

                    <div className="flex-1 overflow-y-auto px-6 pb-6">

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
                            <div className="text-sm font-semibold">General</div>
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
                                    No {labels['worker.plural'].toLowerCase()} assigned.
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
