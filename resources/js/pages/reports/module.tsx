import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';

type PaginationLink = { url: string | null; label: string; active: boolean };

type Props = {
    module: {
        key: string;
        label: string;
        description: string;
        route: string;
        columns: Record<string, string>;
    };
    filters: {
        search: string | null;
        date_from: string | null;
        date_to: string | null;
        status: string | null;
    };
    statuses: string[];
    rows: {
        data: Array<Record<string, string>>;
        links?: PaginationLink[];
        total?: number;
        per_page?: number;
        current_page?: number;
        last_page?: number;
    };
};

function Pagination({ links }: { links?: PaginationLink[] }) {
    if (!links || links.length <= 3) return null;

    return (
        <div className="flex flex-wrap items-center gap-1">
            {links.map((link, idx) => (
                <Button
                    key={idx}
                    variant={link.active ? 'default' : 'outline'}
                    size="sm"
                    disabled={!link.url}
                    onClick={() => {
                        if (link.url) {
                            router.visit(link.url, { preserveScroll: true, preserveState: true });
                        }
                    }}
                    dangerouslySetInnerHTML={{
                        __html: link.label.replace('&laquo;', '<<').replace('&raquo;', '>>'),
                    }}
                />
            ))}
        </div>
    );
}

export default function ModuleReport({ module, filters, statuses, rows }: Props) {
    const apply = (next: Partial<Props['filters']>) => {
        router.get(
            module.route,
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const exportCsv = () => {
        const params = new URLSearchParams();
        Object.entries(filters).forEach(([key, value]) => {
            if (value === null || value === '') return;
            params.set(key, String(value));
        });
        window.location.href = `/reports/modules/${module.key}/export?${params.toString()}`;
    };

    const columns = Object.entries(module.columns);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Reports', href: '/reports' },
                { title: module.label, href: module.route },
            ]}
        >
            <Head title={`${module.label} report`} />

            <div className="space-y-4 p-4">
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">{module.label}</CardTitle>
                        <div className="text-sm text-muted-foreground">{module.description}</div>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 md:grid-cols-5">
                        <div className="md:col-span-2">
                            <Label>Search</Label>
                            <Input
                                value={filters.search ?? ''}
                                placeholder="Search records"
                                onChange={(e) => apply({ search: e.target.value || null })}
                            />
                        </div>
                        <div>
                            <Label>Date from</Label>
                            <Input
                                type="date"
                                value={filters.date_from ?? ''}
                                onChange={(e) => apply({ date_from: e.target.value || null })}
                            />
                        </div>
                        <div>
                            <Label>Date to</Label>
                            <Input
                                type="date"
                                value={filters.date_to ?? ''}
                                onChange={(e) => apply({ date_to: e.target.value || null })}
                            />
                        </div>
                        <div>
                            <Label>Status</Label>
                            <select
                                className="w-full rounded-md border bg-background p-2 text-sm"
                                value={filters.status ?? ''}
                                onChange={(e) => apply({ status: e.target.value || null })}
                                disabled={statuses.length === 0}
                            >
                                <option value="">All statuses</option>
                                {statuses.map((status) => (
                                    <option key={status} value={status}>
                                        {status}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div className="flex items-end gap-2 md:col-span-5">
                            <Button variant="outline" onClick={exportCsv}>
                                Export CSV
                            </Button>
                            <Button
                                variant="ghost"
                                onClick={() => apply({ search: null, date_from: null, date_to: null, status: null })}
                            >
                                Clear filters
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                <div className="overflow-x-auto rounded-md border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/40">
                            <tr>
                                {columns.map(([column, label]) => (
                                    <th key={column} className="p-3 text-left font-medium">
                                        {label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {rows.data.map((row, idx) => (
                                <tr key={idx} className="border-t">
                                    {columns.map(([column]) => (
                                        <td key={column} className="p-3">
                                            {row[column] || '-'}
                                        </td>
                                    ))}
                                </tr>
                            ))}
                            {rows.data.length === 0 ? (
                                <tr>
                                    <td colSpan={columns.length || 1} className="p-6 text-center text-muted-foreground">
                                        No records found for the selected filters.
                                    </td>
                                </tr>
                            ) : null}
                        </tbody>
                    </table>
                </div>

                <div className="flex items-center justify-between">
                    <div className="text-xs text-muted-foreground">
                        Total: {rows.total ?? rows.data.length}
                    </div>
                    <Pagination links={rows.links} />
                </div>
            </div>
        </AppLayout>
    );
}

