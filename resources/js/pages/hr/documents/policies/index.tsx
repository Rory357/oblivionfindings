import { DocumentsTabs } from '@/components/hr';
import {
    buildCategoryOptions,
    PolicyWizard,
    type CategoryOption,
    type EditablePolicy,
} from '@/components/hr/policy-wizards';
import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Label } from '@/components/ui/label';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    Archive,
    BookOpen,
    CheckCircle,
    FileText,
    MoreHorizontal,
    Pencil,
    Plus,
    ShieldCheck,
} from 'lucide-react';
import { useMemo, useState } from 'react';

type BreadcrumbItem = { title: string; href: string };

type Policy = EditablePolicy & {
    slug: string;
    current_version: {
        id: number;
        version_number: string;
        effective_from: string;
    } | null;
};

type Props = {
    policies: {
        data: Policy[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    categories: string[];
    defaultCategories: CategoryOption[];
    stats: {
        total: number;
        active: number;
        need_attestation: number;
        attestations: number;
    };
    editPolicy: EditablePolicy | null;
    filters: {
        category: string | null;
        active_only: boolean | string | null;
    };
    can: { manage: boolean };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Policies', href: '/hr/documents/policies' },
];

const formatDate = (value?: string | null) => {
    if (!value) return 'Not set';
    const d = new Date(value);
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleDateString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          });
};

const getCategoryColor = (category: string) => {
    const colors: Record<string, string> = {
        employment: 'bg-status-info-bg text-status-info border-status-info/30',
        health_and_safety:
            'bg-status-success-bg text-status-success border-status-success/30',
        safeguarding: 'bg-primary/10 text-primary border-primary',
        data_protection:
            'bg-status-warning-bg text-status-warning border-status-warning/30',
        conduct:
            'bg-status-critical-bg text-status-critical border-status-critical/30',
        leave: 'bg-status-info-bg text-status-info border-status-info/30',
        training: 'bg-primary/10 text-primary border-primary',
        general: 'bg-muted text-foreground border-border',
    };
    return colors[category] || 'bg-muted text-foreground border-border';
};

type WizardState =
    | { mode: 'create' }
    | { mode: 'edit'; policy: EditablePolicy }
    | null;

export default function PoliciesIndex({
    policies,
    categories,
    defaultCategories,
    stats,
    editPolicy,
    filters,
    can,
}: Props) {
    const NONE = '__none__';

    // Open the right wizard mode on mount when arriving via the legacy GET
    // create/edit routes (they redirect here with ?new=1 / ?edit={id}).
    const [wizard, setWizard] = useState<WizardState>(() => {
        if (typeof window === 'undefined' || !can.manage) return null;
        const params = new URLSearchParams(window.location.search);
        if (params.has('new')) return { mode: 'create' };
        if (params.has('edit') && editPolicy) return { mode: 'edit', policy: editPolicy };
        return null;
    });
    const [deleting, setDeleting] = useState<Policy | null>(null);
    const [deleteBusy, setDeleteBusy] = useState(false);

    const categoryOptions = useMemo(
        () => buildCategoryOptions(categories, defaultCategories),
        [categories, defaultCategories],
    );

    const onFilter = (next: Partial<typeof filters>) => {
        router.get(
            '/hr/documents/policies',
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true },
        );
    };

    const confirmDelete = () => {
        if (!deleting) return;
        setDeleteBusy(true);
        router.delete(`/hr/documents/policies/${deleting.id}`, {
            preserveScroll: true,
            onFinish: () => {
                setDeleteBusy(false);
                setDeleting(null);
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Policy Library" />

            <PageLayout
                hero={
                    <PageHero category="hr"
                        icon={BookOpen}
                        title="Policy Library"
                        description="Organisation policies, procedures, and staff attestations."
                        stats={[
                            { label: 'Policies', value: stats.total },
                            { label: 'Active', value: stats.active, tone: 'success' },
                            {
                                label: 'Need attestation',
                                value: stats.need_attestation,
                                tone: 'warning',
                            },
                            { label: 'Attestations recorded', value: stats.attestations },
                        ]}
                        actions={
                            <div className="flex flex-wrap items-center gap-2">
                                <Link href="/hr/documents/policies/attestations">
                                    <Button size="sm" variant="outline" className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground">
                                        <ShieldCheck className="mr-1.5 h-4 w-4" />
                                        Attestations
                                    </Button>
                                </Link>
                                {can.manage && (
                                    <Button
                                        size="sm"
                                        onClick={() => setWizard({ mode: 'create' })}
                                    >
                                        <Plus className="mr-1.5 h-4 w-4" />
                                        New policy
                                    </Button>
                                )}
                            </div>
                        }
                    />
                }
            >
                <DocumentsTabs active="policies" />

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div>
                            <Label className="text-xs text-muted-foreground">
                                Category
                            </Label>
                            <Select
                                value={filters.category ?? NONE}
                                onValueChange={(v) =>
                                    onFilter({
                                        category: v === NONE ? null : v,
                                    })
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="All categories" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>
                                        All Categories
                                    </SelectItem>
                                    {categories.map((c) => (
                                        <SelectItem
                                            key={c}
                                            value={c}
                                            className="capitalize"
                                        >
                                            {c.replace(/_/g, ' ')}
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
                                value={
                                    filters.active_only === true ||
                                    filters.active_only === 'true'
                                        ? 'active'
                                        : filters.active_only === false ||
                                            filters.active_only === 'false'
                                          ? 'inactive'
                                          : NONE
                                }
                                onValueChange={(v) => {
                                    if (v === NONE)
                                        onFilter({ active_only: null });
                                    else if (v === 'active')
                                        onFilter({ active_only: 'true' });
                                    else onFilter({ active_only: 'false' });
                                }}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="All" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>All</SelectItem>
                                    <SelectItem value="active">
                                        Active Only
                                    </SelectItem>
                                    <SelectItem value="inactive">
                                        Inactive Only
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Policy</TableHead>
                                    <TableHead>Category</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Current Version</TableHead>
                                    <TableHead>Effective From</TableHead>
                                    <TableHead>Attestation</TableHead>
                                    <TableHead className="w-24"></TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {policies.data.map((policy) => (
                                    <TableRow key={policy.id}>
                                        <TableCell>
                                            <div className="flex items-center gap-2">
                                                <FileText className="h-4 w-4 text-muted-foreground" />
                                                <Link
                                                    href={`/hr/documents/policies/${policy.id}`}
                                                    className="font-medium hover:underline"
                                                >
                                                    {policy.title}
                                                </Link>
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                className={getCategoryColor(
                                                    policy.category,
                                                )}
                                            >
                                                {policy.category.replace(
                                                    /_/g,
                                                    ' ',
                                                )}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>
                                            {policy.is_active ? (
                                                <Badge className="border-status-success/30 bg-status-success-bg text-status-success">
                                                    <CheckCircle className="mr-1 h-3 w-3" />
                                                    Active
                                                </Badge>
                                            ) : (
                                                <Badge
                                                    variant="outline"
                                                    className="text-muted-foreground"
                                                >
                                                    Inactive
                                                </Badge>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {policy.current_version ? (
                                                <span className="text-sm font-medium">
                                                    v
                                                    {
                                                        policy.current_version
                                                            .version_number
                                                    }
                                                </span>
                                            ) : (
                                                <span className="text-sm text-muted-foreground">
                                                    No version
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {policy.current_version
                                                ? formatDate(
                                                      policy.current_version
                                                          .effective_from,
                                                  )
                                                : '--'}
                                        </TableCell>
                                        <TableCell>
                                            {policy.requires_attestation ? (
                                                <Badge
                                                    variant="outline"
                                                    className="border-status-warning/30 bg-status-warning-bg text-status-warning"
                                                >
                                                    <ShieldCheck className="mr-1 h-3 w-3" />
                                                    Required
                                                </Badge>
                                            ) : (
                                                <span className="text-xs text-muted-foreground">
                                                    Not required
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex items-center justify-end gap-1.5">
                                                <Link
                                                    href={`/hr/documents/policies/${policy.id}`}
                                                    className="rounded-md border px-3 py-1.5 text-xs hover:bg-muted"
                                                >
                                                    View
                                                </Link>
                                                {can.manage && (
                                                    <DropdownMenu>
                                                        <DropdownMenuTrigger asChild>
                                                            <Button
                                                                variant="ghost"
                                                                size="icon"
                                                                className="h-7 w-7"
                                                                aria-label={`Actions for ${policy.title}`}
                                                            >
                                                                <MoreHorizontal className="h-4 w-4" />
                                                            </Button>
                                                        </DropdownMenuTrigger>
                                                        <DropdownMenuContent align="end">
                                                            <DropdownMenuItem
                                                                onSelect={() =>
                                                                    setWizard({
                                                                        mode: 'edit',
                                                                        policy,
                                                                    })
                                                                }
                                                            >
                                                                <Pencil className="mr-2 h-4 w-4" />
                                                                Edit policy
                                                            </DropdownMenuItem>
                                                            <DropdownMenuSeparator />
                                                            <DropdownMenuItem
                                                                className="text-status-critical focus:text-status-critical"
                                                                onSelect={() =>
                                                                    setDeleting(policy)
                                                                }
                                                            >
                                                                <Archive className="mr-2 h-4 w-4" />
                                                                Archive policy
                                                            </DropdownMenuItem>
                                                        </DropdownMenuContent>
                                                    </DropdownMenu>
                                                )}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {!policies.data.length && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={7}
                                            className="py-8 text-center text-sm text-muted-foreground"
                                        >
                                            No policies found.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {policies?.links?.length ? (
                    <LaravelPagination links={policies.links} />
                ) : null}
            </PageLayout>

            {wizard ? (
                <PolicyWizard
                    policy={wizard.mode === 'edit' ? wizard.policy : null}
                    categoryOptions={categoryOptions}
                    onClose={() => setWizard(null)}
                />
            ) : null}

            <Dialog
                open={deleting !== null}
                onOpenChange={(open) => {
                    if (!open) setDeleting(null);
                }}
            >
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Archive policy</DialogTitle>
                        <DialogDescription>
                            Archive “{deleting?.title}”? It will leave the active
                            library while every published version, attestation and
                            stored PDF remains available for audit history.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="ghost"
                            onClick={() => setDeleting(null)}
                            disabled={deleteBusy}
                        >
                            Cancel
                        </Button>
                        <Button
                            onClick={confirmDelete}
                            disabled={deleteBusy}
                        >
                            <Archive className="mr-1.5 h-4 w-4" />
                            {deleteBusy ? 'Archiving…' : 'Archive policy'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
