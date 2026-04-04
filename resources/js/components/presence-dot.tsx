const statusConfig: Record<string, { color: string; label: string }> = {
    online: { color: 'bg-emerald-500', label: 'Online' },
    away: { color: 'bg-amber-500', label: 'Away' },
    busy: { color: 'bg-red-500', label: 'Busy' },
    offline: { color: 'bg-gray-300', label: 'Offline' },
};

export function PresenceDot({ status, size = 'sm' }: { status: string; size?: 'sm' | 'md' | 'lg' }) {
    const config = statusConfig[status] ?? statusConfig.offline;
    const sizes = { sm: 'h-2.5 w-2.5', md: 'h-3 w-3', lg: 'h-3.5 w-3.5' };
    return (
        <span
            className={`inline-block rounded-full border-2 border-white ${config.color} ${sizes[size]}`}
            title={config.label}
        />
    );
}

export function PresenceBadge({ status }: { status: string }) {
    const config = statusConfig[status] ?? statusConfig.offline;
    return (
        <span className="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
            <span className={`h-2 w-2 rounded-full ${config.color}`} />
            {config.label}
        </span>
    );
}

export function derivePresenceStatus(presenceStatus?: string | null, lastSeenAt?: string | null): string {
    if (!lastSeenAt) return 'offline';
    const lastSeen = new Date(lastSeenAt);
    const now = new Date();
    const diffMin = (now.getTime() - lastSeen.getTime()) / 60000;
    if (presenceStatus === 'online' && diffMin < 5) return 'online';
    if (diffMin < 15) return 'away';
    return 'offline';
}
