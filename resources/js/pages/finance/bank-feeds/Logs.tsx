import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

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

const formatDuration = (ms: number | null): string => {
    if (ms === null) return '-';
    if (ms < 1000) return `${ms}ms`;
    return `${(ms / 1000).toFixed(1)}s`;
};

export default function BankFeedLogs({ feed, logs }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Finance', href: '/finance' },
        { title: 'Bank Feeds', href: '/finance/bank-feeds' },
        {
            title: `${feed.bank_account_name} Logs`,
            href: `/finance/bank-feeds/${feed.id}/logs`,
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Bank Feed Logs - ${feed.bank_account_name}`} />

            <PageLayout
                hero={
                    <PageHero
                        category="finance"
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
                            <h3 className="mb-1 text-lg font-medium text-foreground">
                                No sync logs
                            </h3>
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
                                                <th className="px-4 py-3 text-left font-medium">
                                                    Synced At
                                                </th>
                                                <th className="px-4 py-3 text-left font-medium">
                                                    Status
                                                </th>
                                                <th className="px-4 py-3 text-right font-medium">
                                                    Fetched
                                                </th>
                                                <th className="px-4 py-3 text-right font-medium">
                                                    Imported
                                                </th>
                                                <th className="px-4 py-3 text-right font-medium">
                                                    Skipped
                                                </th>
                                                <th className="px-4 py-3 text-right font-medium">
                                                    Duration
                                                </th>
                                                <th className="px-4 py-3 text-left font-medium">
                                                    Error
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {logs.data.map((log) => {
                                                return (
                                                    <tr
                                                        key={log.id}
                                                        className="border-b last:border-0 hover:bg-muted/25"
                                                    >
                                                        <td className="px-4 py-3 font-mono text-xs">
                                                            {log.synced_at}
                                                        </td>
                                                        <td className="px-4 py-3">
                                                            <StatusBadge
                                                                status={
                                                                    log.status
                                                                }
                                                                size="sm"
                                                            />
                                                        </td>
                                                        <td className="px-4 py-3 text-right font-mono tabular-nums">
                                                            {
                                                                log.transactions_fetched
                                                            }
                                                        </td>
                                                        <td className="px-4 py-3 text-right font-mono tabular-nums">
                                                            {
                                                                log.transactions_imported
                                                            }
                                                        </td>
                                                        <td className="px-4 py-3 text-right font-mono tabular-nums">
                                                            {
                                                                log.transactions_skipped
                                                            }
                                                        </td>
                                                        <td className="px-4 py-3 text-right font-mono text-xs tabular-nums">
                                                            {formatDuration(
                                                                log.duration_ms,
                                                            )}
                                                        </td>
                                                        <td className="max-w-xs truncate px-4 py-3 text-destructive">
                                                            {log.error_message ||
                                                                '-'}
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
                            <div className="mt-4 flex items-center justify-center gap-2">
                                {logs.links.map((link, index) => (
                                    <Button
                                        key={index}
                                        variant={
                                            link.active ? 'default' : 'outline'
                                        }
                                        size="sm"
                                        disabled={!link.url}
                                        asChild={!!link.url}
                                    >
                                        {link.url ? (
                                            <Link
                                                href={link.url}
                                                dangerouslySetInnerHTML={{
                                                    __html: link.label,
                                                }}
                                            />
                                        ) : (
                                            <span
                                                dangerouslySetInnerHTML={{
                                                    __html: link.label,
                                                }}
                                            />
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
