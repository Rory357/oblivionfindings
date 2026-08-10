import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatDateTime } from '@/lib/datetime';
import {
    Archive,
    CheckCircle2,
    Clock3,
    FileCheck2,
    ShieldAlert,
} from 'lucide-react';

export type DeviceDocumentHistoryItem = {
    id: number;
    title: string;
    category: string;
    version: string | null;
    original_name: string;
    size_bytes: number;
    uploaded_at: string | null;
    uploaded_by: string | null;
    state: 'upload_staged' | 'active' | 'removal_pending' | 'removed';
    status_label: string;
    needs_attention: boolean;
    storage_verified_at: string | null;
    integrity_sha256: string | null;
    removal_requested_at: string | null;
    removed_at: string | null;
    removed_by: string | null;
    removal_reason: string | null;
    storage_deleted_at: string | null;
};

function formatBytes(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function IntegrityFingerprint({ sha256 }: { sha256: string | null }) {
    if (!sha256) {
        return <span>Integrity verification pending</span>;
    }

    return (
        <span title={`SHA-256 ${sha256}`}>
            SHA-256 {sha256.slice(0, 12)}…{sha256.slice(-8)}
        </span>
    );
}

function StatusIcon({ item }: { item: DeviceDocumentHistoryItem }) {
    if (item.needs_attention) {
        return <ShieldAlert className="h-4 w-4" aria-hidden="true" />;
    }
    if (item.state === 'upload_staged' || item.state === 'removal_pending') {
        return <Clock3 className="h-4 w-4" aria-hidden="true" />;
    }
    if (item.state === 'removed') {
        return item.storage_deleted_at ? (
            <CheckCircle2 className="h-4 w-4" aria-hidden="true" />
        ) : (
            <Archive className="h-4 w-4" aria-hidden="true" />
        );
    }

    return <FileCheck2 className="h-4 w-4" aria-hidden="true" />;
}

export function DeviceDocumentHistory({
    items,
}: {
    items: DeviceDocumentHistoryItem[];
}) {
    if (items.length === 0) return null;

    return (
        <Card>
            <CardHeader>
                <CardTitle role="heading" aria-level={2}>
                    Document lifecycle history
                </CardTitle>
                <CardDescription>
                    Retained upload, integrity, removal and private-storage
                    recovery evidence for this Device.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div className="space-y-3">
                    {items.map((item) => (
                        <article
                            key={item.id}
                            className="space-y-3 rounded-md border p-4"
                        >
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <p className="font-medium">{item.title}</p>
                                    <p className="text-sm text-muted-foreground">
                                        {item.original_name} ·{' '}
                                        {formatBytes(item.size_bytes)}
                                    </p>
                                </div>
                                <Badge
                                    variant={
                                        item.needs_attention
                                            ? 'destructive'
                                            : 'outline'
                                    }
                                    className="gap-1.5"
                                >
                                    <StatusIcon item={item} />
                                    {item.status_label}
                                </Badge>
                            </div>

                            <dl className="grid gap-2 text-sm md:grid-cols-2">
                                <div>
                                    <dt className="text-muted-foreground">
                                        Uploaded
                                    </dt>
                                    <dd>
                                        {formatDateTime(item.uploaded_at)}
                                        {item.uploaded_by
                                            ? ` · ${item.uploaded_by}`
                                            : ''}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        Integrity
                                    </dt>
                                    <dd className="font-mono text-xs">
                                        <IntegrityFingerprint
                                            sha256={item.integrity_sha256}
                                        />
                                    </dd>
                                </div>
                                {item.removal_requested_at ? (
                                    <div>
                                        <dt className="text-muted-foreground">
                                            Removal requested
                                        </dt>
                                        <dd>
                                            {formatDateTime(
                                                item.removal_requested_at,
                                            )}
                                        </dd>
                                    </div>
                                ) : null}
                                {item.removed_at ? (
                                    <div>
                                        <dt className="text-muted-foreground">
                                            Removed
                                        </dt>
                                        <dd>
                                            {formatDateTime(item.removed_at)}
                                            {item.removed_by
                                                ? ` · ${item.removed_by}`
                                                : ''}
                                        </dd>
                                    </div>
                                ) : null}
                            </dl>

                            {item.removal_reason ? (
                                <div className="rounded-md bg-muted/50 px-3 py-2 text-sm">
                                    <span className="font-medium">
                                        Removal reason:{' '}
                                    </span>
                                    {item.removal_reason}
                                </div>
                            ) : null}
                        </article>
                    ))}
                </div>
            </CardContent>
        </Card>
    );
}
