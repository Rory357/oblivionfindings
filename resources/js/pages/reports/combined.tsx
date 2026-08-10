import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';

type Props = {
    report: {
        key: string;
        label: string;
        description: string;
        route: string;
        export_route: string;
        modules: string[];
    };
    generated_at: string;
    metrics: Array<{ label: string; value: string | number }>;
    sections: Array<{
        title: string;
        columns: string[];
        rows: Array<{ id: number; cells: string[]; href: string | null }>;
    }>;
};

export default function CombinedReport({
    report,
    generated_at,
    metrics,
    sections = [],
}: Props) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Reports', href: '/reports' },
                { title: report.label, href: report.route },
            ]}
        >
            <Head title={report.label} />
            <div className="space-y-4 p-4">
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            {report.label}
                        </CardTitle>
                        <div className="text-sm text-muted-foreground">
                            {report.description}
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div className="flex flex-wrap gap-1">
                            {report.modules.map((module) => (
                                <span
                                    key={module}
                                    className="rounded-full border px-2 py-0.5 text-[11px] text-muted-foreground"
                                >
                                    {module}
                                </span>
                            ))}
                        </div>
                        <div className="mt-3 text-xs text-muted-foreground">
                            Generated at: {generated_at}
                        </div>
                        <div className="mt-3">
                            <a
                                href={report.export_route}
                                className="rounded-md border px-2 py-1 text-xs hover:bg-muted"
                            >
                                Export CSV
                            </a>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Comprehensive Metrics
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
                            {metrics.map((metric) => (
                                <div
                                    key={metric.label}
                                    className="rounded-md border p-3"
                                >
                                    <div className="text-xs text-muted-foreground">
                                        {metric.label}
                                    </div>
                                    <div className="mt-1 text-2xl font-semibold">
                                        {metric.value}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>

                {sections.map((section) => (
                    <Card key={section.title}>
                        <CardHeader>
                            <CardTitle className="text-base">
                                {section.title}
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-x-auto rounded-md border">
                                <table className="w-full text-sm">
                                    <thead className="bg-muted/40">
                                        <tr>
                                            {section.columns.map((column) => (
                                                <th
                                                    key={column}
                                                    className="p-3 text-left font-medium"
                                                >
                                                    {column}
                                                </th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {section.rows.map((row) => (
                                            <tr
                                                key={row.id}
                                                className="border-t"
                                            >
                                                {row.cells.map((cell, idx) => (
                                                    <td
                                                        key={`${row.id}-${idx}`}
                                                        className="p-3"
                                                    >
                                                        {idx === 0 &&
                                                        row.href ? (
                                                            <Link
                                                                href={row.href}
                                                                className="underline"
                                                            >
                                                                {cell || '-'}
                                                            </Link>
                                                        ) : (
                                                            cell || '-'
                                                        )}
                                                    </td>
                                                ))}
                                            </tr>
                                        ))}
                                        {section.rows.length === 0 ? (
                                            <tr>
                                                <td
                                                    colSpan={
                                                        section.columns
                                                            .length || 1
                                                    }
                                                    className="p-6 text-center text-muted-foreground"
                                                >
                                                    No records found.
                                                </td>
                                            </tr>
                                        ) : null}
                                    </tbody>
                                </table>
                            </div>
                        </CardContent>
                    </Card>
                ))}
            </div>
        </AppLayout>
    );
}
