import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { CheckCircle2, XCircle, AlertTriangle } from 'lucide-react';
import { type BreadcrumbItem } from '@/types';

interface LogEntry {
    id: number;
    synced_at: string;
    status: 'success' | 'failed' | 'partial';
    transactions_fetched: number;
    transactions_imported: number;
    transactions_skipped: number;
    error_message: string | null;
    duration_ms: number | null;
}

interface PaginatedLogs {
    data: LogEntry[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
}

interface Feed {
    id: number;
    provider: string;
    bank_account_name: string;
    bank_name: string;
}

interface Props {
    feed: Feed;
    logs: PaginatedLogs;
}

const providerLabels: Record<string, string> = {
    asb: 'ASB',
    anz: 'ANZ',
    westpac: 'Westpac',
    bnz: 'BNZ',
};

const statusConfig: Record<string, { icon: typeof CheckCircle2; variant: 'default' | 'destructive' | 'secondary'; label: string }> = {
    success: { icon: CheckCircle2, variant: 'default', label: 'Success' },
    failed: { icon: XCircle, variant: 'destructive', label: 'Failed' },
    partial: { icon: AlertTriangle, variant: 'secondary', label: 'Partial' },
};

const formatDuration = (ms: number | null): string => {
    if (ms === null) return '-';
    if (ms < 1000) return `${ms}ms`;
    return `${(ms / 1000).toFixed(1)}s`;
};

export default function BankFeedLogs({ feed, logs }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Finance', href: '/finance' },
        { title: 'Bank Feeds', href: '/finance/bank-feeds' },
        { title: `${feed.bank_account_name} Logs`, href: `/finance/bank-feeds/${feed.id}/logs` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Bank Feed Logs - ${feed.bank_account_name}`} />

            <PageLayout
                hero={
                    <PageHero category="finance"
                        variant="compact"
                        backHref="/finance/bank-feeds"
                        title="Sync Logs"
                        description={`${feed.bank_account_name} · ${providerLabels[feed.provider] || feed.provider} · ${feed.bank_name}`}
                    />
                }
            >

                {logs.data.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center justify-center py-12 text-center">
                            <h3 className="text-lg font-medium text-foreground mb-1">No sync logs</h3>
                            <p className="text-muted-foreground">
                                This bank feed has not been synced yet.
                            </p>
                        </CardContent>
                    </Card>
                ) : (
                    <>
                        <Card>
                            <CardContent className="p-0">
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b bg-muted/50">
                                                <th className="text-left font-medium px-4 py-3">Synced At</th>
                                                <th className="text-left font-medium px-4 py-3">Status</th>
                                                <th className="text-right font-medium px-4 py-3">Fetched</th>
                                                <th className="text-right font-medium px-4 py-3">Imported</th>
                                                <th className="text-right font-medium px-4 py-3">Skipped</th>
                                                <th className="text-right font-medium px-4 py-3">Duration</th>
                                                <th className="text-left font-medium px-4 py-3">Error</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {logs.data.map((log) => {
                                                const config = statusConfig[log.status] || statusConfig.failed;
                                                const StatusIcon = config.icon;

                                                return (
                                                    <tr key={log.id} className="border-b last:border-0 hover:bg-muted/25">
                                                        <td className="px-4 py-3 font-mono text-xs">
                                                            {log.synced_at}
                                                        </td>
                                                        <td className="px-4 py-3">
                                                            <Badge variant={config.variant} className="gap-1">
                                                                <StatusIcon className="w-3 h-3" />
                                                                {config.label}
                                                            </Badge>
                                                        </td>
                                                        <td className="px-4 py-3 text-right font-mono tabular-nums">
                                                            {log.transactions_fetched}
                                                        </td>
                                                        <td className="px-4 py-3 text-right font-mono tabular-nums">
                                                            {log.transactions_imported}
                                                        </td>
                                                        <td className="px-4 py-3 text-right font-mono tabular-nums">
                                                            {log.transactions_skipped}
                                                        </td>
                                                        <td className="px-4 py-3 text-right font-mono tabular-nums text-xs">
                                                            {formatDuration(log.duration_ms)}
                                                        </td>
                                                        <td className="px-4 py-3 text-destructive max-w-xs truncate">
                                                            {log.error_message || '-'}
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </table>
                                </div>
                            </CardContent>
                        </Card>

                        {logs.last_page > 1 && (
                            <div className="flex items-center justify-center gap-2 mt-4">
                                {logs.links.map((link, index) => (
                                    <Button
                                        key={index}
                                        variant={link.active ? 'default' : 'outline'}
                                        size="sm"
                                        disabled={!link.url}
                                        asChild={!!link.url}
                                    >
                                        {link.url ? (
                                            <Link
                                                href={link.url}
                                                dangerouslySetInnerHTML={{ __html: link.label }}
                                            />
                                        ) : (
                                            <span dangerouslySetInnerHTML={{ __html: link.label }} />
                                        )}
                                    </Button>
                                ))}
                            </div>
                        )}
                    </>
                )}
            </PageLayout>
        </AppLayout>
    );
}
