import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { History, Trash2 } from 'lucide-react';

type Props = {
    logs: any[];
    filters: {
        q?: string;
        model_type?: string;
    };
};

export default function DeletionLogs({ logs, filters }: Props) {
    const ANY = '__any__';

    const onFilter = (next: Partial<typeof filters>) => {
        router.get(
            '/privacy/deletion-logs',
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true },
        );
    };

    const formatDate = (dateString: string) =>
        new Date(dateString).toLocaleDateString('en-GB', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Data & Privacy', href: '/privacy/dashboard' },
                { title: 'Deletion Logs', href: '/privacy/deletion-logs' },
            ]}
        >
            <Head title="Deletion Logs" />

            <PageLayout
                hero={
                    <PageHero
                        icon={History}
                        title="Deletion Logs"
                        description="Audit trail of data deletion operations performed under retention policies"
                        stats={[{ label: 'Total', value: logs.length }]}
                    />
                }
            >
                {/* Filters */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <Label className="text-xs text-muted-foreground">
                                Search
                            </Label>
                            <Input
                                placeholder="Search by reference or description"
                                value={filters.q || ''}
                                onChange={(e) =>
                                    onFilter({ q: e.target.value })
                                }
                            />
                        </div>
                        <div>
                            <Label className="text-xs text-muted-foreground">
                                Model Type
                            </Label>
                            <Select
                                value={filters.model_type ?? ANY}
                                onValueChange={(v) =>
                                    onFilter({
                                        model_type: v === ANY ? undefined : v,
                                    })
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Model Type" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>
                                        All Types
                                    </SelectItem>
                                    <SelectItem value="Client">
                                        Client
                                    </SelectItem>
                                    <SelectItem value="ClientDocument">
                                        Client Document
                                    </SelectItem>
                                    <SelectItem value="ClientNote">
                                        Client Note
                                    </SelectItem>
                                    <SelectItem value="Incident">
                                        Incident
                                    </SelectItem>
                                    <SelectItem value="AuditLog">
                                        Audit Log
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                {/* Logs List */}
                <div className="space-y-2">
                    {logs.map((log: any) => (
                        <Card key={log.id}>
                            <CardContent className="pt-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="flex-1">
                                        <div className="flex items-center gap-2">
                                            <Trash2 className="h-4 w-4 text-status-critical" />
                                            <span className="font-medium">
                                                {log.model_type ?? 'Unknown'}
                                            </span>
                                            {log.model_id && (
                                                <Badge variant="outline">
                                                    #{log.model_id}
                                                </Badge>
                                            )}
                                        </div>
                                        {log.reason && (
                                            <p className="mt-1 text-sm text-muted-foreground">
                                                {log.reason}
                                            </p>
                                        )}
                                        <div className="mt-1 text-xs text-muted-foreground">
                                            {log.deleted_at &&
                                                formatDate(log.deleted_at)}
                                            {log.deleted_by_name &&
                                                ` by ${log.deleted_by_name}`}
                                            {log.policy_name &&
                                                ` (Policy: ${log.policy_name})`}
                                        </div>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                    {logs.length === 0 && (
                        <div className="py-8 text-center text-sm text-muted-foreground">
                            <Trash2 className="mx-auto mb-3 h-12 w-12 opacity-30" />
                            No deletion logs recorded yet.
                        </div>
                    )}
                </div>
            </PageLayout>
        </AppLayout>
    );
}
