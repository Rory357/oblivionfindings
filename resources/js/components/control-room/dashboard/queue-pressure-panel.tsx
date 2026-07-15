import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Link } from '@inertiajs/react';
import { ArrowRight, Inbox } from 'lucide-react';

export type DeskQueue = {
    id: number;
    name: string;
    tier: number;
    active: number;
    critical: number;
};

export function QueuePressurePanel({ queues }: { queues: DeskQueue[] }) {
    return (
        <Card className="gap-4 py-5">
            <CardHeader className="gap-1 px-5">
                <CardTitle>Queue pressure</CardTitle>
                <CardDescription>
                    Where the live response load is building.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-2 px-5">
                {queues.length === 0 ? (
                    <div className="rounded-xl border border-dashed p-4 text-sm text-muted-foreground">
                        No active response queues.
                    </div>
                ) : (
                    queues.map((queue) => (
                        <Link
                            key={queue.id}
                            href={`/control-room?queue_id=${queue.id}`}
                            className="group flex items-center gap-3 rounded-xl border p-3 transition-colors hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-muted">
                                <Inbox
                                    className="h-4 w-4 text-muted-foreground"
                                    aria-hidden
                                />
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="block truncate text-sm font-semibold">
                                    {queue.name}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    Tier {queue.tier} · {queue.active} active
                                </span>
                            </span>
                            {queue.critical > 0 ? (
                                <span className="rounded-full bg-status-critical/10 px-2 py-1 text-xs font-semibold text-status-critical-foreground">
                                    {queue.critical} critical
                                </span>
                            ) : null}
                            <ArrowRight
                                className="h-4 w-4 text-muted-foreground transition-transform group-hover:translate-x-0.5"
                                aria-hidden
                            />
                        </Link>
                    ))
                )}
            </CardContent>
        </Card>
    );
}
