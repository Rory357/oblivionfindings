/* eslint-disable no-restricted-syntax -- This pane uses intentional raw controls
 * for the data table: sortable column-header buttons, the row kebab trigger, and
 * filter-chip clear buttons are custom table affordances (not shadcn <Button>
 * cases). All colours are semantic design tokens. */
import { Link, router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    Building2,
    ChevronsUpDown,
    Columns3,
    Download,
    Eye,
    MapPin,
    MoreHorizontal,
    Pencil,
    Rows3,
    Search,
    UserCheck,
    UserCog,
    UserPlus,
    UserX,
    Users,
    X,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

import {
    ShiftContextMenu,
    type ShiftCtxItem,
    type ShiftCtxState,
} from '@/components/rostering/shift-context-menu';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

import { StatusBadge, type StatusTone } from './status-badge';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

export interface PeopleRow {
    id: number;
    profile_id: number | null;
    employee_number: string | null;
    position_title: string | null;
    employment_type: string | null;
    department: string | null;
    is_active: boolean;
    start_date: string | null;
    // Re-hire wizard prefill — only sent for former employees.
    end_date?: string | null;
    position_role?: string | null;
    hours_per_week?: number | null;
    employment_history?: Array<{
        start_date: string | null;
        end_date: string | null;
        position_title: string | null;
        position_role: string | null;
        employment_type: string | null;
        archived_at?: string | null;
    }> | null;
    preferred_name?: string | null;
    profile_photo_path?: string | null;
    user: { id: number; name: string; email: string };
    primary_site: { id: number; name: string } | null;
}

export interface PaginatedPeople {
    data: PeopleRow[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    current_page: number;
    last_page: number;
    total: number;
}

export interface PeopleFilters {
    q: string;
    status: string | null;
    site_id: string | null;
    department: string | null;
    employment_type: string | null;
    joined: string | null;
    probation: string | null;
    sort: string | null;
    dir: string | null;
}

type Density = 'comfortable' | 'compact';

/* ------------------------------------------------------------------ */
/*  Constants + helpers                                                */
/* ------------------------------------------------------------------ */

const NONE = '__none__';

/** Toggleable columns (Employee + Status are always shown). */
const COLUMNS = [
    { key: 'employee_number', label: 'Emp #' },
    { key: 'position', label: 'Position' },
    { key: 'department', label: 'Department' },
    { key: 'type', label: 'Type' },
    { key: 'site', label: 'Site' },
    { key: 'start', label: 'Start' },
] as const;

type ColKey = (typeof COLUMNS)[number]['key'];

const DEFAULT_COLS: Record<ColKey, boolean> = {
    employee_number: true,
    position: true,
    department: true,
    type: true,
    site: true,
    start: true,
};

const TYPE_TONE: Record<string, StatusTone> = {
    full_time: 'info',
    part_time: 'warning',
    casual: 'primary',
    fixed_term: 'success',
    contractor: 'warning',
};

const AVATAR_COLORS = [
    'bg-status-info-bg text-status-info',
    'bg-primary/15 text-primary',
    'bg-status-success-bg text-status-success',
    'bg-status-warning-bg text-status-warning',
    'bg-status-critical-bg text-status-critical',
];

function getInitials(name: string): string {
    return name
        .split(' ')
        .map((n) => n[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
}

function avatarColor(id: number): string {
    return AVATAR_COLORS[id % AVATAR_COLORS.length];
}

function formatLabel(s: string): string {
    return s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function formatDate(value?: string | null): string {
    if (!value) return '—';
    const d = new Date(value);
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleDateString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          });
}

/* ------------------------------------------------------------------ */
/*  Pane                                                               */
/* ------------------------------------------------------------------ */

/**
 * People directory pane — the upgraded workforce table: server-side sortable
 * headers, a column chooser + density toggle (persisted), StatusBadge pills,
 * active-filter chips, a loading skeleton, and a per-row context menu (reusing
 * the {@link ShiftContextMenu} mould). Owns the People-tab filters and folds the
 * standalone directory.
 */
export function PeoplePane({
    profiles,
    filters,
    sites,
    departments,
    managers = [],
    canManage,
    onAdd,
    onRehire,
}: {
    profiles: PaginatedPeople;
    filters: PeopleFilters;
    sites: Array<{ id: number; name: string }>;
    departments: Array<{ id: number; name: string }>;
    managers?: Array<{ value: string; label: string }>;
    canManage: boolean;
    onAdd?: () => void;
    onRehire?: (row: PeopleRow) => void;
}) {
    const [search, setSearch] = useState(filters.q ?? '');
    const [cols, setCols] = useState<Record<ColKey, boolean>>(DEFAULT_COLS);
    const [density, setDensity] = useState<Density>('comfortable');
    const [loading, setLoading] = useState(false);
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [selected, setSelected] = useState<Set<number>>(new Set());

    // Selection is per-view: clear it whenever the displayed rows change
    // (filter / sort / page / post-bulk reload).
    const pageKey = profiles.data.map((p) => p.id).join(',');
    useEffect(() => {
        setSelected(new Set());
    }, [pageKey]);

    // Restore persisted column visibility + density after mount (SSR-safe).
    useEffect(() => {
        try {
            const rawCols = window.localStorage.getItem('hrp.cols');
            if (rawCols) setCols({ ...DEFAULT_COLS, ...JSON.parse(rawCols) });
            const d = window.localStorage.getItem('hrp.density');
            if (d === 'comfortable' || d === 'compact') setDensity(d);
        } catch {
            /* ignore malformed storage */
        }
    }, []);

    // Table skeleton while a People list request is in flight.
    useEffect(() => {
        const onStart = router.on('start', (e) => {
            if (String(e.detail.visit.url).includes('/hr/people'))
                setLoading(true);
        });
        const onFinish = router.on('finish', () => setLoading(false));
        return () => {
            onStart();
            onFinish();
        };
    }, []);

    const setColumn = (key: ColKey, on: boolean) => {
        setCols((prev) => {
            const next = { ...prev, [key]: on };
            try {
                window.localStorage.setItem('hrp.cols', JSON.stringify(next));
            } catch {
                /* ignore */
            }
            return next;
        });
    };

    const setDensityPersist = (d: Density) => {
        setDensity(d);
        try {
            window.localStorage.setItem('hrp.density', d);
        } catch {
            /* ignore */
        }
    };

    const apply = (next: Partial<PeopleFilters>) => {
        const merged: Record<string, string> = {};
        const base: PeopleFilters = { ...filters, ...next };
        (Object.keys(base) as Array<keyof PeopleFilters>).forEach((k) => {
            const v = base[k];
            if (v) merged[k] = String(v);
        });
        router.get('/hr/people', merged, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const toggleSort = (key: string) => {
        const dir =
            filters.sort === key && filters.dir === 'asc' ? 'desc' : 'asc';
        apply({ sort: key, dir });
    };

    const setActive = (row: PeopleRow, active: boolean) => {
        if (!row.profile_id) return;
        router.patch(
            `/hr/people/${row.profile_id}/active`,
            { is_active: active },
            { preserveScroll: true, preserveState: true },
        );
    };

    /* --- multi-select + bulk actions --- */
    const selectableIds = profiles.data
        .filter((p) => p.profile_id)
        .map((p) => p.profile_id as number);
    const allSelected =
        selectableIds.length > 0 &&
        selectableIds.every((id) => selected.has(id));
    const someSelected = selectableIds.some((id) => selected.has(id));

    const toggleAll = () => {
        setSelected((prev) => {
            const next = new Set(prev);
            if (allSelected) selectableIds.forEach((id) => next.delete(id));
            else selectableIds.forEach((id) => next.add(id));
            return next;
        });
    };

    const toggleRow = (id: number) => {
        setSelected((prev) => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });
    };

    const bulkAction = (action: string, extra: Record<string, number> = {}) => {
        router.post(
            '/hr/people/bulk',
            { action, ids: Array.from(selected), ...extra },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => setSelected(new Set()),
            },
        );
    };

    // Export the selected rows as CSV. A file download can't go through Inertia,
    // so submit a native hidden form (carrying the ids[] + CSRF token) that the
    // browser handles as a download.
    const exportSelected = () => {
        const token =
            document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
                ?.content ?? '';
        const form = document.createElement('form');
        form.action = '/hr/import-export/export';
        form.method = 'POST';
        form.style.display = 'none';

        const addField = (name: string, value: string) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            form.appendChild(input);
        };

        addField('_token', token);
        Array.from(selected).forEach((id) => addField('ids[]', String(id)));

        document.body.appendChild(form);
        form.submit();
        form.remove();
    };

    const rowItems = (row: PeopleRow): ShiftCtxItem[] => {
        const items: ShiftCtxItem[] = [];
        if (row.profile_id) {
            items.push({
                icon: <Eye className="h-4 w-4" />,
                label: 'View profile',
                onClick: () => router.visit(`/hr/people/${row.profile_id}`),
            });
            if (canManage) {
                items.push({
                    icon: <Pencil className="h-4 w-4" />,
                    label: 'Edit details',
                    onClick: () =>
                        router.visit(`/hr/people/${row.profile_id}/edit`),
                });
                items.push({ sep: true });
                if (row.is_active) {
                    items.push({
                        icon: <UserX className="h-4 w-4" />,
                        label: 'Deactivate',
                        tone: 'critical',
                        onClick: () => setActive(row, false),
                    });
                } else {
                    if (onRehire) {
                        items.push({
                            icon: <UserPlus className="h-4 w-4" />,
                            label: 'Re-hire…',
                            tone: 'primary',
                            onClick: () => onRehire(row),
                        });
                    }
                    // Light undo for an accidental deactivation — the full
                    // welcome-back workflow is the Re-hire wizard above.
                    items.push({
                        icon: <UserCheck className="h-4 w-4" />,
                        label: 'Reactivate',
                        onClick: () => setActive(row, true),
                    });
                }
            }
        }
        return items;
    };

    const openMenu = (row: PeopleRow, x: number, y: number) => {
        const items = rowItems(row);
        if (items.length === 0) return;
        setCtx({
            x,
            y,
            tag: row.is_active ? 'Active' : 'Inactive',
            meta: [row.user.name, row.position_title].filter(Boolean).join(' · '),
            items,
        });
    };

    const activeChips = useMemo(() => {
        const chips: { key: keyof PeopleFilters; label: string }[] = [];
        if (filters.q) chips.push({ key: 'q', label: `“${filters.q}”` });
        if (filters.status)
            chips.push({ key: 'status', label: formatLabel(filters.status) });
        if (filters.site_id) {
            const site = sites.find((s) => String(s.id) === filters.site_id);
            chips.push({ key: 'site_id', label: site?.name ?? 'Site' });
        }
        if (filters.department) {
            const dept = departments.find(
                (d) => String(d.id) === filters.department,
            );
            chips.push({ key: 'department', label: dept?.name ?? 'Department' });
        }
        if (filters.employment_type)
            chips.push({
                key: 'employment_type',
                label: formatLabel(filters.employment_type),
            });
        if (filters.joined === '30')
            chips.push({ key: 'joined', label: 'New hires · 30d' });
        if (filters.probation)
            chips.push({ key: 'probation', label: 'On probation' });
        return chips;
    }, [filters, sites, departments]);

    const rowPad = density === 'compact' ? 'py-1.5' : 'py-3';
    const showEmpty = !loading && profiles.data.length === 0;

    return (
        <div className="space-y-4">
            {/* Filters + table controls */}
            <div className="flex flex-wrap items-center gap-3">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        apply({ q: search });
                    }}
                    className="relative"
                >
                    <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        placeholder="Search name or email…  ( / )"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        className="w-64 pl-9"
                        aria-label="Search people"
                    />
                </form>

                <Select
                    value={filters.status || NONE}
                    onValueChange={(v) =>
                        apply({ status: v === NONE ? null : v })
                    }
                >
                    <SelectTrigger className="w-36" aria-label="Status">
                        <SelectValue placeholder="All status" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={NONE}>All status</SelectItem>
                        <SelectItem value="active">Active</SelectItem>
                        <SelectItem value="inactive">Inactive</SelectItem>
                    </SelectContent>
                </Select>

                <Select
                    value={filters.site_id || NONE}
                    onValueChange={(v) =>
                        apply({ site_id: v === NONE ? null : v })
                    }
                >
                    <SelectTrigger className="w-44" aria-label="Site">
                        <SelectValue placeholder="All sites" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={NONE}>All sites</SelectItem>
                        {sites.map((s) => (
                            <SelectItem key={s.id} value={String(s.id)}>
                                {s.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                {departments.length > 0 ? (
                    <Select
                        value={filters.department || NONE}
                        onValueChange={(v) =>
                            apply({ department: v === NONE ? null : v })
                        }
                    >
                        <SelectTrigger className="w-44" aria-label="Department">
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
                ) : null}

                <Select
                    value={filters.employment_type || NONE}
                    onValueChange={(v) =>
                        apply({ employment_type: v === NONE ? null : v })
                    }
                >
                    <SelectTrigger className="w-40" aria-label="Employment type">
                        <SelectValue placeholder="All types" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={NONE}>All types</SelectItem>
                        <SelectItem value="full_time">Full time</SelectItem>
                        <SelectItem value="part_time">Part time</SelectItem>
                        <SelectItem value="casual">Casual</SelectItem>
                        <SelectItem value="fixed_term">Fixed term</SelectItem>
                        <SelectItem value="contractor">Contractor</SelectItem>
                    </SelectContent>
                </Select>

                <div className="ml-auto flex items-center gap-2">
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="outline" size="sm" className="gap-1.5">
                                <Columns3 className="h-4 w-4" />
                                Columns
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-48">
                            <DropdownMenuLabel>Columns</DropdownMenuLabel>
                            {COLUMNS.map((c) => (
                                <DropdownMenuCheckboxItem
                                    key={c.key}
                                    checked={cols[c.key]}
                                    onCheckedChange={(v) =>
                                        setColumn(c.key, Boolean(v))
                                    }
                                    onSelect={(e) => e.preventDefault()}
                                >
                                    {c.label}
                                </DropdownMenuCheckboxItem>
                            ))}
                        </DropdownMenuContent>
                    </DropdownMenu>

                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="outline" size="sm" className="gap-1.5">
                                <Rows3 className="h-4 w-4" />
                                {density === 'compact' ? 'Compact' : 'Comfortable'}
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-40">
                            <DropdownMenuRadioGroup
                                value={density}
                                onValueChange={(v) =>
                                    setDensityPersist(v as Density)
                                }
                            >
                                <DropdownMenuRadioItem value="comfortable">
                                    Comfortable
                                </DropdownMenuRadioItem>
                                <DropdownMenuRadioItem value="compact">
                                    Compact
                                </DropdownMenuRadioItem>
                            </DropdownMenuRadioGroup>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>

            {/* Active filter chips */}
            {activeChips.length > 0 ? (
                <div className="flex flex-wrap items-center gap-2">
                    {activeChips.map((chip) => (
                        <span
                            key={chip.key}
                            className="inline-flex items-center gap-1.5 rounded-lg bg-accent py-1 pr-1.5 pl-2.5 text-xs font-semibold text-accent-foreground"
                        >
                            {chip.label}
                            <button
                                type="button"
                                onClick={() => {
                                    if (chip.key === 'q') setSearch('');
                                    apply({ [chip.key]: null });
                                }}
                                aria-label={`Clear ${chip.label}`}
                                className="rounded p-0.5 hover:bg-foreground/10"
                            >
                                <X className="h-3 w-3" />
                            </button>
                        </span>
                    ))}
                    <button
                        type="button"
                        onClick={() => {
                            setSearch('');
                            router.get(
                                '/hr/people',
                                {},
                                { preserveState: true, replace: true },
                            );
                        }}
                        className="px-1.5 text-xs font-semibold text-muted-foreground hover:text-foreground"
                    >
                        Clear all
                    </button>
                </div>
            ) : null}

            {/* Sticky bulk bar */}
            {canManage && selected.size > 0 ? (
                <div className="sticky top-2 z-30 flex flex-wrap items-center gap-2 rounded-xl bg-primary px-3 py-2.5 text-primary-foreground shadow-lg">
                    <span className="px-1 text-sm font-bold">
                        {selected.size} selected
                    </span>
                    <span className="h-5 w-px bg-primary-foreground/25" />
                    <BulkButton
                        icon={UserX}
                        label="Deactivate"
                        onClick={() => bulkAction('deactivate')}
                    />
                    <BulkButton
                        icon={UserCheck}
                        label="Reactivate"
                        onClick={() => bulkAction('reactivate')}
                    />
                    <BulkAssign
                        icon={MapPin}
                        label="Assign site"
                        options={sites.map((s) => ({
                            value: s.id,
                            label: s.name,
                        }))}
                        onPick={(id) => bulkAction('assign_site', { site_id: id })}
                    />
                    <BulkAssign
                        icon={Building2}
                        label="Assign department"
                        options={departments.map((d) => ({
                            value: d.id,
                            label: d.name,
                        }))}
                        onPick={(id) =>
                            bulkAction('assign_department', {
                                department_id: id,
                            })
                        }
                    />
                    <BulkAssign
                        icon={UserCog}
                        label="Assign manager"
                        options={managers.map((m) => ({
                            value: Number(m.value),
                            label: m.label,
                        }))}
                        onPick={(id) =>
                            bulkAction('assign_manager', {
                                manager_user_id: id,
                            })
                        }
                    />
                    <span className="h-5 w-px bg-primary-foreground/25" />
                    <BulkButton
                        icon={Download}
                        label="Export"
                        onClick={exportSelected}
                    />
                    <button
                        type="button"
                        onClick={() => setSelected(new Set())}
                        aria-label="Clear selection"
                        className="ml-auto rounded-md p-1.5 hover:bg-primary-foreground/15"
                    >
                        <X className="h-4 w-4" />
                    </button>
                </div>
            ) : null}

            {/* Table */}
            <Card>
                <CardContent className="p-0">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/50">
                                <tr>
                                    {canManage ? (
                                        <th className="w-10 px-3 py-3">
                                            <Checkbox
                                                checked={
                                                    allSelected
                                                        ? true
                                                        : someSelected
                                                          ? 'indeterminate'
                                                          : false
                                                }
                                                onCheckedChange={toggleAll}
                                                aria-label="Select all on this page"
                                            />
                                        </th>
                                    ) : null}
                                    <SortableTh
                                        label="Employee"
                                        sortKey="name"
                                        filters={filters}
                                        onSort={toggleSort}
                                        className="pl-4"
                                    />
                                    {cols.employee_number ? (
                                        <SortableTh
                                            label="Emp #"
                                            sortKey="employee_number"
                                            filters={filters}
                                            onSort={toggleSort}
                                            hideBelow="lg"
                                        />
                                    ) : null}
                                    {cols.position ? (
                                        <SortableTh
                                            label="Position"
                                            sortKey="position"
                                            filters={filters}
                                            onSort={toggleSort}
                                        />
                                    ) : null}
                                    {cols.department ? (
                                        <SortableTh
                                            label="Department"
                                            sortKey="department"
                                            filters={filters}
                                            onSort={toggleSort}
                                            hideBelow="md"
                                        />
                                    ) : null}
                                    {cols.type ? (
                                        <SortableTh
                                            label="Type"
                                            sortKey="type"
                                            filters={filters}
                                            onSort={toggleSort}
                                            hideBelow="sm"
                                        />
                                    ) : null}
                                    {cols.site ? (
                                        <SortableTh
                                            label="Site"
                                            sortKey="site"
                                            filters={filters}
                                            onSort={toggleSort}
                                            hideBelow="xl"
                                        />
                                    ) : null}
                                    {cols.start ? (
                                        <SortableTh
                                            label="Start"
                                            sortKey="start"
                                            filters={filters}
                                            onSort={toggleSort}
                                            hideBelow="xl"
                                        />
                                    ) : null}
                                    <SortableTh
                                        label="Status"
                                        sortKey="status"
                                        filters={filters}
                                        onSort={toggleSort}
                                    />
                                    <th className="w-10" />
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {loading
                                    ? Array.from({ length: 8 }).map((_, i) => (
                                          <SkeletonRow
                                              key={i}
                                              cols={cols}
                                              rowPad={rowPad}
                                              canManage={canManage}
                                          />
                                      ))
                                    : profiles.data.map((p) => (
                                          <tr
                                              key={p.id}
                                              onContextMenu={(e) => {
                                                  if (rowItems(p).length === 0)
                                                      return;
                                                  e.preventDefault();
                                                  openMenu(
                                                      p,
                                                      e.clientX,
                                                      e.clientY,
                                                  );
                                              }}
                                              className={`group transition-colors hover:bg-muted/40 ${
                                                  p.is_active
                                                      ? ''
                                                      : 'opacity-70'
                                              }`}
                                          >
                                              {canManage ? (
                                                  <td
                                                      className={`px-3 ${rowPad}`}
                                                  >
                                                      {p.profile_id ? (
                                                          <Checkbox
                                                              checked={selected.has(
                                                                  p.profile_id,
                                                              )}
                                                              onCheckedChange={() =>
                                                                  toggleRow(
                                                                      p.profile_id as number,
                                                                  )
                                                              }
                                                              aria-label={`Select ${p.user.name}`}
                                                          />
                                                      ) : null}
                                                  </td>
                                              ) : null}
                                              <td
                                                  className={`cursor-pointer px-4 ${rowPad}`}
                                                  onClick={() =>
                                                      p.profile_id &&
                                                      router.visit(
                                                          `/hr/people/${p.profile_id}`,
                                                      )
                                                  }
                                              >
                                                  <div className="flex items-center gap-3">
                                                      <span
                                                          className={`flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-full text-xs font-semibold ${avatarColor(p.id)}`}
                                                      >
                                                          {p.profile_photo_path ? (
                                                              <img
                                                                  src={`/storage/${p.profile_photo_path}`}
                                                                  alt=""
                                                                  className="h-full w-full object-cover"
                                                              />
                                                          ) : (
                                                              getInitials(
                                                                  p.user.name,
                                                              )
                                                          )}
                                                      </span>
                                                      <div className="min-w-0">
                                                          {p.profile_id ? (
                                                              <Link
                                                                  href={`/hr/people/${p.profile_id}`}
                                                                  className="font-medium text-foreground group-hover:text-primary"
                                                                  onClick={(e) =>
                                                                      e.stopPropagation()
                                                                  }
                                                              >
                                                                  {p.user.name}
                                                              </Link>
                                                          ) : (
                                                              <span className="font-medium">
                                                                  {p.user.name}
                                                              </span>
                                                          )}
                                                          <div className="truncate text-xs text-muted-foreground">
                                                              {p.user.email}
                                                          </div>
                                                      </div>
                                                  </div>
                                              </td>
                                              {cols.employee_number ? (
                                                  <td
                                                      className={`hidden px-4 font-mono text-xs text-muted-foreground lg:table-cell ${rowPad}`}
                                                  >
                                                      {p.employee_number ||
                                                          '—'}
                                                  </td>
                                              ) : null}
                                              {cols.position ? (
                                                  <td className={`px-4 ${rowPad}`}>
                                                      {p.position_title ||
                                                          '—'}
                                                  </td>
                                              ) : null}
                                              {cols.department ? (
                                                  <td
                                                      className={`hidden px-4 text-muted-foreground md:table-cell ${rowPad}`}
                                                  >
                                                      {p.department || '—'}
                                                  </td>
                                              ) : null}
                                              {cols.type ? (
                                                  <td
                                                      className={`hidden px-4 sm:table-cell ${rowPad}`}
                                                  >
                                                      {p.employment_type ? (
                                                          <StatusBadge
                                                              status={
                                                                  p.employment_type
                                                              }
                                                              tone={
                                                                  TYPE_TONE[
                                                                      p
                                                                          .employment_type
                                                                  ] ?? 'neutral'
                                                              }
                                                              label={formatLabel(
                                                                  p.employment_type,
                                                              )}
                                                          />
                                                      ) : (
                                                          <span className="text-muted-foreground">
                                                              {'—'}
                                                          </span>
                                                      )}
                                                  </td>
                                              ) : null}
                                              {cols.site ? (
                                                  <td
                                                      className={`hidden px-4 text-muted-foreground xl:table-cell ${rowPad}`}
                                                  >
                                                      {p.primary_site?.name ||
                                                          '—'}
                                                  </td>
                                              ) : null}
                                              {cols.start ? (
                                                  <td
                                                      className={`hidden px-4 text-muted-foreground tabular-nums xl:table-cell ${rowPad}`}
                                                  >
                                                      {formatDate(p.start_date)}
                                                  </td>
                                              ) : null}
                                              <td className={`px-4 ${rowPad}`}>
                                                  <StatusBadge
                                                      status={
                                                          p.is_active
                                                              ? 'active'
                                                              : 'inactive'
                                                      }
                                                  />
                                              </td>
                                              <td
                                                  className={`pr-2 text-right ${rowPad}`}
                                              >
                                                  {rowItems(p).length > 0 ? (
                                                      <button
                                                          type="button"
                                                          aria-label={`Actions for ${p.user.name}`}
                                                          onClick={(e) => {
                                                              const r = (
                                                                  e.currentTarget as HTMLElement
                                                              ).getBoundingClientRect();
                                                              openMenu(
                                                                  p,
                                                                  r.right,
                                                                  r.bottom,
                                                              );
                                                          }}
                                                          className="rounded-md p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                                                      >
                                                          <MoreHorizontal className="h-4 w-4" />
                                                      </button>
                                                  ) : null}
                                              </td>
                                          </tr>
                                      ))}
                            </tbody>
                        </table>
                    </div>

                    {showEmpty ? (
                        <div className="flex flex-col items-center gap-2.5 px-5 py-14 text-center">
                            <span className="grid h-14 w-14 place-items-center rounded-full bg-muted text-muted-foreground">
                                <Users className="h-7 w-7" />
                            </span>
                            <p className="text-base font-bold">
                                {activeChips.length > 0
                                    ? 'No people match these filters'
                                    : 'No people yet'}
                            </p>
                            <p className="max-w-sm text-sm text-muted-foreground">
                                {activeChips.length > 0
                                    ? 'Try clearing the filters, or add a new employee to your workforce.'
                                    : 'Add employees to build out your workforce directory.'}
                            </p>
                            <div className="mt-1 flex gap-2">
                                {activeChips.length > 0 ? (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            setSearch('');
                                            router.get(
                                                '/hr/people',
                                                {},
                                                {
                                                    preserveState: true,
                                                    replace: true,
                                                },
                                            );
                                        }}
                                    >
                                        Clear filters
                                    </Button>
                                ) : null}
                                {onAdd ? (
                                    <Button
                                        size="sm"
                                        onClick={onAdd}
                                        className="gap-1.5"
                                    >
                                        <UserPlus className="h-4 w-4" />
                                        Add employee
                                    </Button>
                                ) : null}
                            </div>
                        </div>
                    ) : null}
                </CardContent>
            </Card>

            {/* Footer: count + pagination */}
            <div className="flex items-center justify-between gap-3 text-xs text-muted-foreground">
                <span>
                    {profiles.total} {profiles.total === 1 ? 'person' : 'people'}
                </span>
                {profiles.last_page > 1 ? (
                    <LaravelPagination links={profiles.links} />
                ) : null}
            </div>

            {ctx ? (
                <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} />
            ) : null}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Pieces                                                             */
/* ------------------------------------------------------------------ */

const HIDE_CLASS: Record<string, string> = {
    sm: 'hidden sm:table-cell',
    md: 'hidden md:table-cell',
    lg: 'hidden lg:table-cell',
    xl: 'hidden xl:table-cell',
};

function SortableTh({
    label,
    sortKey,
    filters,
    onSort,
    hideBelow,
    className = '',
}: {
    label: string;
    sortKey: string;
    filters: PeopleFilters;
    onSort: (key: string) => void;
    hideBelow?: 'sm' | 'md' | 'lg' | 'xl';
    className?: string;
}) {
    const active = filters.sort === sortKey;
    const Icon = !active ? ChevronsUpDown : filters.dir === 'desc' ? ArrowDown : ArrowUp;
    return (
        <th
            className={`px-4 py-3 text-left ${hideBelow ? HIDE_CLASS[hideBelow] : ''} ${className}`}
        >
            <button
                type="button"
                onClick={() => onSort(sortKey)}
                className={`inline-flex items-center gap-1 text-xs font-semibold tracking-wider uppercase transition-colors hover:text-foreground ${
                    active ? 'text-foreground' : 'text-muted-foreground'
                }`}
            >
                {label}
                <Icon className="h-3 w-3" />
            </button>
        </th>
    );
}

function SkeletonRow({
    cols,
    rowPad,
    canManage,
}: {
    cols: Record<ColKey, boolean>;
    rowPad: string;
    canManage: boolean;
}) {
    const bar = (w: string, extra = '') => (
        <span
            className={`block h-3 animate-pulse rounded bg-muted ${w} ${extra}`}
        />
    );
    return (
        <tr>
            {canManage ? (
                <td className={`px-3 ${rowPad}`}>
                    <span className="block h-4 w-4 animate-pulse rounded bg-muted" />
                </td>
            ) : null}
            <td className={`px-4 ${rowPad}`}>
                <div className="flex items-center gap-3">
                    <span className="h-9 w-9 shrink-0 animate-pulse rounded-full bg-muted" />
                    {bar('w-32')}
                </div>
            </td>
            {cols.employee_number ? (
                <td className={`hidden px-4 lg:table-cell ${rowPad}`}>
                    {bar('w-14')}
                </td>
            ) : null}
            {cols.position ? (
                <td className={`px-4 ${rowPad}`}>{bar('w-24')}</td>
            ) : null}
            {cols.department ? (
                <td className={`hidden px-4 md:table-cell ${rowPad}`}>
                    {bar('w-20')}
                </td>
            ) : null}
            {cols.type ? (
                <td className={`hidden px-4 sm:table-cell ${rowPad}`}>
                    {bar('w-16', 'rounded-full')}
                </td>
            ) : null}
            {cols.site ? (
                <td className={`hidden px-4 xl:table-cell ${rowPad}`}>
                    {bar('w-20')}
                </td>
            ) : null}
            {cols.start ? (
                <td className={`hidden px-4 xl:table-cell ${rowPad}`}>
                    {bar('w-16')}
                </td>
            ) : null}
            <td className={`px-4 ${rowPad}`}>{bar('w-14', 'rounded-full')}</td>
            <td className={`px-4 ${rowPad}`} />
        </tr>
    );
}

function BulkButton({
    icon: Icon,
    label,
    onClick,
}: {
    icon: LucideIcon;
    label: string;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="inline-flex h-8 items-center gap-1.5 rounded-lg border border-primary-foreground/25 bg-primary-foreground/10 px-2.5 text-xs font-semibold transition-colors hover:bg-primary-foreground/20"
        >
            <Icon className="h-3.5 w-3.5" />
            {label}
        </button>
    );
}

function BulkAssign({
    icon: Icon,
    label,
    options,
    onPick,
}: {
    icon: LucideIcon;
    label: string;
    options: { value: number; label: string }[];
    onPick: (id: number) => void;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    className="inline-flex h-8 items-center gap-1.5 rounded-lg border border-primary-foreground/25 bg-primary-foreground/10 px-2.5 text-xs font-semibold transition-colors hover:bg-primary-foreground/20"
                >
                    <Icon className="h-3.5 w-3.5" />
                    {label}
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="start"
                className="max-h-72 w-56 overflow-y-auto"
            >
                {options.length === 0 ? (
                    <DropdownMenuItem disabled>No options</DropdownMenuItem>
                ) : (
                    options.map((o) => (
                        <DropdownMenuItem
                            key={o.value}
                            onSelect={() => onPick(o.value)}
                        >
                            {o.label}
                        </DropdownMenuItem>
                    ))
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

export default PeoplePane;
