import { Link, router } from '@inertiajs/react';
import { Briefcase, Pencil, Plus, Search, Users } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

import { StatusBadge } from './status-badge';
import type { PositionRow } from './position-dialog';

export interface PositionListRow extends PositionRow {
    current_headcount: number;
    vacancies: number;
}

export interface PaginatedPositions {
    data: PositionListRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
}

export interface PositionFilters {
    q?: string;
    department?: string;
    status?: string;
}

const NONE = '__none__';

const TYPE_LABELS: Record<string, string> = {
    full_time: 'Full Time',
    part_time: 'Part Time',
    casual: 'Casual',
    fixed_term: 'Fixed Term',
};

/** Positions list pane for the People hub (folds /hr/positions). */
export function PositionsPane({
    positions,
    departments,
    filters,
    canManage,
    onCreate,
    onEdit,
}: {
    positions: PaginatedPositions;
    departments: { id: number; name: string }[];
    filters: PositionFilters;
    canManage: boolean;
    onCreate: () => void;
    onEdit: (position: PositionRow) => void;
}) {
    const [search, setSearch] = useState(filters.q ?? '');

    const apply = (next: Partial<PositionFilters>) => {
        router.get(
            '/hr/people',
            {
                tab: 'positions',
                pq: next.q ?? filters.q ?? undefined,
                pdepartment: next.department ?? filters.department ?? undefined,
                pstatus: next.status ?? filters.status ?? undefined,
            },
            { preserveState: true, replace: true },
        );
    };

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap items-center gap-2">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            apply({ q: search });
                        }}
                        className="relative"
                    >
                        <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            placeholder="Search positions…"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="w-56 pl-9"
                        />
                    </form>
                    <Select
                        value={filters.department ?? NONE}
                        onValueChange={(v) =>
                            apply({ department: v === NONE ? undefined : v })
                        }
                    >
                        <SelectTrigger className="w-44">
                            <SelectValue placeholder="All departments" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={NONE}>All departments</SelectItem>
                            {departments.map((d) => (
                                <SelectItem key={d.id} value={String(d.id)}>
                                    {d.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select
                        value={filters.status ?? NONE}
                        onValueChange={(v) =>
                            apply({ status: v === NONE ? undefined : v })
                        }
                    >
                        <SelectTrigger className="w-36">
                            <SelectValue placeholder="All statuses" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={NONE}>All statuses</SelectItem>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="inactive">Inactive</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                {canManage ? (
                    <Button onClick={onCreate} className="gap-1.5">
                        <Plus className="h-4 w-4" />
                        New position
                    </Button>
                ) : null}
            </div>

            <Card>
                <CardContent className="p-0">
                    {positions.data.length === 0 ? (
                        <div className="py-16 text-center">
                            <Briefcase className="mx-auto mb-3 h-10 w-10 text-muted-foreground/40" />
                            <p className="font-medium text-muted-foreground">
                                No positions found
                            </p>
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="border-b bg-muted/50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                            Title
                                        </th>
                                        <th className="hidden px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase sm:table-cell">
                                            Code
                                        </th>
                                        <th className="hidden px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase md:table-cell">
                                            Department
                                        </th>
                                        <th className="hidden px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase lg:table-cell">
                                            Type
                                        </th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                            Headcount
                                        </th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                            Status
                                        </th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {positions.data.map((p) => {
                                        const vacancies = p.vacancies;
                                        return (
                                            <tr
                                                key={p.id}
                                                className="transition-colors hover:bg-muted/40"
                                            >
                                                <td className="px-4 py-3 font-medium">
                                                    {p.title}
                                                </td>
                                                <td className="hidden px-4 py-3 font-mono text-xs text-muted-foreground sm:table-cell">
                                                    {p.code}
                                                </td>
                                                <td className="hidden px-4 py-3 text-muted-foreground md:table-cell">
                                                    {p.department ?? '—'}
                                                </td>
                                                <td className="hidden px-4 py-3 text-muted-foreground lg:table-cell">
                                                    {TYPE_LABELS[
                                                        p.employment_type
                                                    ] ?? p.employment_type}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <span className="inline-flex items-center gap-1">
                                                        <Users className="h-3.5 w-3.5 text-muted-foreground" />
                                                        {p.current_headcount}/
                                                        {p.headcount_budget}
                                                        {vacancies > 0 ? (
                                                            <StatusBadge
                                                                status="open"
                                                                tone="info"
                                                                label={`${vacancies} open`}
                                                                className="ml-1"
                                                            />
                                                        ) : null}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <StatusBadge
                                                        status={
                                                            p.is_active
                                                                ? 'active'
                                                                : 'inactive'
                                                        }
                                                    />
                                                </td>
                                                <td className="px-4 py-3 text-right">
                                                    <div className="flex items-center justify-end gap-1">
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            asChild
                                                        >
                                                            <Link
                                                                href={`/hr/positions/${p.id}`}
                                                            >
                                                                View
                                                            </Link>
                                                        </Button>
                                                        {canManage ? (
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() =>
                                                                    onEdit(p)
                                                                }
                                                                className="h-8 w-8 p-0"
                                                            >
                                                                <Pencil className="h-3.5 w-3.5" />
                                                            </Button>
                                                        ) : null}
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </CardContent>
            </Card>

            {positions.last_page > 1 ? (
                <LaravelPagination links={positions.links} />
            ) : null}
        </div>
    );
}

export default PositionsPane;
