import FleetHero from '@/components/fleet-hero';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/date-format';
import { Head, Link, router } from '@inertiajs/react';
import { FileText, Plus } from 'lucide-react';

type Props = {
    procedures: {
        data: any[];
        links: any[];
    };
    stats: { total: number; approved: number; due_for_review: number };
    filters?: { category?: string | null; status?: string | null };
};

const CATEGORIES = [
    { value: 'manual_handling', label: 'Manual Handling' },
    { value: 'fire_safety', label: 'Fire Safety' },
    { value: 'chemical_handling', label: 'Chemical Handling' },
    { value: 'electrical_safety', label: 'Electrical Safety' },
    { value: 'working_at_height', label: 'Working at Height' },
    { value: 'confined_spaces', label: 'Confined Spaces' },
    { value: 'infection_control', label: 'Infection Control' },
    { value: 'medication', label: 'Medication' },
    { value: 'vehicle_safety', label: 'Vehicle Safety' },
    { value: 'vehicle_operation', label: 'Vehicle Operation' },
    { value: 'personal_care', label: 'Personal Care' },
    { value: 'challenging_behaviour', label: 'Challenging Behaviour' },
    { value: 'lone_working', label: 'Lone Working' },
    { value: 'equipment_use', label: 'Equipment Use' },
    { value: 'emergency_procedures', label: 'Emergency Procedures' },
    { value: 'ppe', label: 'PPE' },
    { value: 'general', label: 'General' },
    { value: 'other', label: 'Other' },
];

const STATUSES = [
    { value: 'draft', label: 'Draft' },
    { value: 'under_review', label: 'Under Review' },
    { value: 'approved', label: 'Approved' },
    { value: 'archived', label: 'Archived' },
];

function categoryBadge(cat: string) {
    switch (cat) {
        case 'fire_safety':
            return 'bg-status-critical-bg text-status-critical border-status-critical/30';
        case 'chemical_handling':
            return 'bg-primary/10 text-primary border-primary';
        case 'manual_handling':
            return 'bg-status-info-bg text-status-info border-status-info/30';
        case 'infection_control':
            return 'bg-status-success-bg text-status-success border-status-success/30';
        case 'emergency_procedures':
            return 'bg-status-warning-bg text-status-warning border-status-warning/30';
        default:
            return 'bg-muted text-foreground border-border';
    }
}

function statusBadge(status: string) {
    switch (status) {
        case 'approved':
            return 'bg-status-success-bg text-status-success border-status-success/30';
        case 'draft':
            return 'bg-muted text-foreground border-border';
        case 'under_review':
            return 'bg-status-warning-bg text-status-warning border-status-warning/30';
        case 'archived':
            return 'bg-status-critical-bg text-status-critical border-status-critical/30';
        default:
            return 'bg-muted text-foreground border-border';
    }
}

export default function ProceduresIndex({ procedures, stats, filters }: Props) {
    const ANY = '__any__';
    const currentFilters = filters ?? { category: null, status: null };

    const onFilter = (next: Partial<typeof currentFilters>) => {
        router.get(
            '/health-safety/procedures',
            { ...currentFilters, ...next },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Health & Safety', href: '/health-safety' },
                {
                    title: 'Safe Work Procedures',
                    href: '/health-safety/procedures',
                },
            ]}
        >
            <Head title="Safe Work Procedures" />

            <div className="flex flex-col gap-6 p-6">
                {/* Hero Header */}
                <FleetHero
                    title="Safe Work Procedures"
                    description="Manage safe work procedures and safety documentation"
                    icon={<FileText className="h-7 w-7 text-white" />}
                    stats={[
                        { label: 'Total', value: stats.total },
                        { label: 'Approved', value: stats.approved },
                        {
                            label: 'Due for Review',
                            value: stats.due_for_review,
                        },
                    ]}
                    actions={
                        <Link href="/health-safety/procedures/create">
                            <Button size="sm">
                                <Plus className="mr-1.5 h-4 w-4" />
                                New Procedure
                            </Button>
                        </Link>
                    }
                />

                {/* Filters */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <Label className="text-xs text-muted-foreground">
                                Category
                            </Label>
                            <Select
                                value={currentFilters.category ?? ANY}
                                onValueChange={(v) =>
                                    onFilter({ category: v === ANY ? null : v })
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Category" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>Any</SelectItem>
                                    {CATEGORIES.map((c) => (
                                        <SelectItem
                                            key={c.value}
                                            value={c.value}
                                        >
                                            {c.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label className="text-xs text-muted-foreground">
                                Status
                            </Label>
                            <Select
                                value={currentFilters.status ?? ANY}
                                onValueChange={(v) =>
                                    onFilter({ status: v === ANY ? null : v })
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Status" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>Any</SelectItem>
                                    {STATUSES.map((s) => (
                                        <SelectItem
                                            key={s.value}
                                            value={s.value}
                                        >
                                            {s.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                {/* Table */}
                <Card>
                    <CardContent className="pt-4">
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left text-xs text-muted-foreground">
                                        <th className="pb-2 font-medium">
                                            Reference
                                        </th>
                                        <th className="pb-2 font-medium">
                                            Title
                                        </th>
                                        <th className="pb-2 font-medium">
                                            Category
                                        </th>
                                        <th className="pb-2 font-medium">
                                            Status
                                        </th>
                                        <th className="pb-2 font-medium">
                                            Version
                                        </th>
                                        <th className="pb-2 font-medium">
                                            Approved By
                                        </th>
                                        <th className="pb-2 font-medium">
                                            Review Date
                                        </th>
                                        <th className="pb-2 font-medium">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {procedures.data.map((p: any) => {
                                        const reviewOverdue =
                                            p.review_date &&
                                            new Date(p.review_date) <
                                                new Date();
                                        return (
                                            <tr
                                                key={p.id}
                                                className="border-b last:border-0"
                                            >
                                                <td className="py-2 font-mono text-xs">
                                                    {p.reference_number ?? '-'}
                                                </td>
                                                <td className="py-2 font-medium">
                                                    {p.title}
                                                </td>
                                                <td className="py-2">
                                                    <Badge
                                                        className={categoryBadge(
                                                            p.category,
                                                        )}
                                                    >
                                                        {p.category?.replace(
                                                            /_/g,
                                                            ' ',
                                                        )}
                                                    </Badge>
                                                </td>
                                                <td className="py-2">
                                                    <Badge
                                                        className={statusBadge(
                                                            p.status,
                                                        )}
                                                    >
                                                        {p.status?.replace(
                                                            /_/g,
                                                            ' ',
                                                        )}
                                                    </Badge>
                                                </td>
                                                <td className="py-2">
                                                    {p.version ?? 1}
                                                </td>
                                                <td className="py-2">
                                                    {p.approved_by?.name ?? '-'}
                                                </td>
                                                <td className="py-2">
                                                    <span
                                                        className={
                                                            reviewOverdue
                                                                ? 'font-semibold text-status-critical'
                                                                : ''
                                                        }
                                                    >
                                                        {formatDate(
                                                            p.review_date,
                                                        )}
                                                    </span>
                                                </td>
                                                <td className="py-2">
                                                    <Link
                                                        href={`/health-safety/procedures/${p.id}`}
                                                        className="rounded-md border px-3 py-1.5 text-xs hover:bg-muted"
                                                    >
                                                        View
                                                    </Link>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                    {!procedures.data.length && (
                                        <tr>
                                            <td
                                                colSpan={8}
                                                className="py-8 text-center text-muted-foreground"
                                            >
                                                No procedures found.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                {/* Pagination */}
                {procedures.links?.length ? (
                    <div className="flex flex-wrap gap-2">
                        {procedures.links.map((l: any) => (
                            <Button
                                type="button"
                                key={l.label}
                                disabled={!l.url}
                                variant={l.active ? 'secondary' : 'outline'}
                                size="sm"
                                className="text-xs"
                                onClick={() =>
                                    l.url &&
                                    router.get(
                                        l.url,
                                        {},
                                        {
                                            preserveState: true,
                                            preserveScroll: true,
                                        },
                                    )
                                }
                                dangerouslySetInnerHTML={{ __html: l.label }}
                            />
                        ))}
                    </div>
                ) : null}
            </div>
        </AppLayout>
    );
}
