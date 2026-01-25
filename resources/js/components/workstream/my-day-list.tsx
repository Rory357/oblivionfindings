import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Link } from '@inertiajs/react';

export type MyDayItem = {
    kind: 'shift' | 'followup' | string;
    id: number;
    at: string;
    end_at?: string | null;
    title: string;
    subtitle?: string | null;
    status?: string | null;
    url?: string | null;
};

function formatWhen(startIso: string, endIso?: string | null) {
    const start = new Date(startIso);
    const startStr = start.toLocaleString([], {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });

    if (!endIso) return startStr;

    const end = new Date(endIso);
    const sameDay =
        start.getFullYear() === end.getFullYear() &&
        start.getMonth() === end.getMonth() &&
        start.getDate() === end.getDate();

    const endStr = end.toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
    });

    return sameDay ? `${startStr} – ${endStr}` : `${startStr} – ${end.toLocaleString([], {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    })}`;
}

function badgeLabel(item: MyDayItem) {
    if (item.kind === 'followup') {
        return item.status === 'overdue' ? 'Overdue follow-up' : 'Follow-up';
    }
    if (item.kind === 'shift') {
        return item.status ? `Shift: ${item.status}` : 'Shift';
    }
    return item.status ?? item.kind;
}

export function MyDayList({
    title = 'My Day',
    items,
    emptyLabel = 'Nothing scheduled.',
}: {
    title?: string;
    items: MyDayItem[];
    emptyLabel?: string;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>{title}</CardTitle>
            </CardHeader>
            <CardContent>
                {items?.length ? (
                    <div className="divide-y">
                        {items.map((item) => (
                            <div
                                key={`${item.kind}-${item.id}`}
                                className="flex items-start justify-between gap-4 py-3"
                            >
                                <div className="min-w-0">
                                    <div className="text-sm font-medium truncate">
                                        {item.url ? (
                                            <Link href={item.url} className="underline">
                                                {item.title}
                                            </Link>
                                        ) : (
                                            item.title
                                        )}
                                    </div>
                                    <div className="mt-1 text-xs text-muted-foreground">
                                        {formatWhen(item.at, item.end_at ?? null)}
                                        {item.subtitle ? ` • ${item.subtitle}` : ''}
                                    </div>
                                </div>
                                <Badge variant="outline" className="shrink-0">
                                    {badgeLabel(item)}
                                </Badge>
                            </div>
                        ))}
                    </div>
                ) : (
                    <div className="text-sm text-muted-foreground">{emptyLabel}</div>
                )}
            </CardContent>
        </Card>
    );
}
