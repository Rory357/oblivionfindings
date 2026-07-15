import { CommandCentrePage } from '@/components/command-centre/command-centre-page';
import { ConfirmChip } from '@/components/control-room/alert-workspace-dialog';
import { PlaybookWizard } from '@/components/control-room/playbook-wizard';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatRelative } from '@/lib/datetime';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    BookOpen,
    CheckCircle,
    Clock,
    Layers,
    Play,
    Plus,
    Power,
    Search as SearchIcon,
    Shield,
    Wrench,
} from 'lucide-react';
import { useState } from 'react';

// --- Types ---

interface PlaybookSummary {
    id: number;
    name: string;
    code: string | null;
    description: string | null;
    category: string;
    version: number;
    is_active: boolean;
    auto_attach: boolean;
    requires_approval: boolean;
    sla_acknowledge_minutes: number | null;
    sla_response_minutes: number | null;
    sla_resolution_minutes: number | null;
    steps_count: number;
    runs_count: number;
    last_run_at: string | null;
    created_at: string | null;
}

interface Props {
    playbooks: PlaybookSummary[];
    filters: {
        category?: string;
        is_active?: string;
    };
    categories: Record<string, string>;
    stepTypes: Record<string, string>;
    can: {
        manage: boolean;
    };
}

// --- Helpers ---

const categoryConfig: Record<
    string,
    { color: string; icon: typeof AlertTriangle }
> = {
    emergency: {
        color: 'bg-status-critical-bg text-status-critical border-status-critical/30',
        icon: AlertTriangle,
    },
    safety: {
        color: 'bg-status-warning-bg text-status-warning border-status-warning/30',
        icon: Shield,
    },
    compliance: {
        color: 'bg-status-info-bg text-status-info border-status-info/30',
        icon: CheckCircle,
    },
    maintenance: {
        color: 'bg-muted text-foreground border-border',
        icon: Wrench,
    },
    investigation: {
        color: 'bg-primary/10 text-primary border-primary',
        icon: SearchIcon,
    },
};

const stepTypeColors: Record<string, string> = {
    task: 'bg-status-info-bg text-status-info',
    decision: 'bg-primary/10 text-primary',
    notification: 'bg-status-warning-bg text-status-warning',
    escalation: 'bg-status-critical-bg text-status-critical',
    evidence: 'bg-status-success-bg text-status-success',
    approval: 'bg-status-warning-bg text-status-warning',
};

// --- Component ---

export default function PlaybooksIndex({
    playbooks,
    filters,
    categories,
    stepTypes,
    can,
}: Props) {
    const [activeCategory, setActiveCategory] = useState<string>(
        filters.category || 'all',
    );

    // ?new=1 deep-links straight into the playbook wizard (house pattern).
    const [wizardOpen, setWizardOpen] = useState<boolean>(
        () =>
            typeof window !== 'undefined' &&
            new URLSearchParams(window.location.search).get('new') === '1',
    );

    const applyFilter = (key: string, value: string) => {
        const newFilters = {
            ...filters,
            [key]: value === 'all' ? undefined : value,
        };
        router.get(
            '/control-room/playbooks',
            newFilters as Record<string, string>,
            {
                preserveState: true,
                preserveScroll: true,
            },
        );
    };

    const handleCategoryTab = (cat: string) => {
        setActiveCategory(cat);
        applyFilter('category', cat);
    };

    const toggleActive = (playbook: PlaybookSummary) => {
        router.post(
            `/control-room/playbooks/${playbook.id}/toggle-active`,
            {},
            {
                preserveScroll: true,
            },
        );
    };

    const categoryTabs = [
        { key: 'all', label: 'All' },
        ...Object.entries(categories).map(([key, label]) => ({ key, label })),
    ];

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Control Room', href: '/control-room' },
                { title: 'Playbooks', href: '/control-room/playbooks' },
            ]}
        >
            <Head title="Playbooks - Control Room" />

            <div className="flex flex-col gap-6 p-6">
                <PageShell>
                    <CommandCentrePage
                        variant="compact"
                        current="/control-room/playbooks"
                        icon={BookOpen}
                        title="Playbooks"
                        description="Create and manage response procedures that guide consistent alert handling."
                        status="Response procedure workspace"
                        freshness={`${playbooks.filter((playbook) => playbook.is_active).length} active`}
                        actions={
                            can.manage ? (
                                <Button
                                    variant="secondary"
                                    onClick={() => setWizardOpen(true)}
                                >
                                    <Plus className="mr-2 h-4 w-4" />
                                    Create playbook
                                </Button>
                            ) : undefined
                        }
                    >
                        {/* Category Tabs */}
                        <div className="flex flex-wrap gap-1 rounded-lg border bg-muted/50 p-1">
                            {categoryTabs.map((tab) => (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    key={tab.key}
                                    onClick={() => handleCategoryTab(tab.key)}
                                    className={`h-auto rounded-md px-3 py-1.5 text-sm font-medium ${
                                        activeCategory === tab.key
                                            ? 'bg-background text-foreground shadow-sm'
                                            : 'text-muted-foreground hover:text-foreground'
                                    }`}
                                >
                                    {tab.label}
                                </Button>
                            ))}
                        </div>

                        {/* Active/Inactive Filter */}
                        <div className="flex items-center gap-3">
                            <Select
                                value={filters.is_active ?? 'all'}
                                onValueChange={(v) =>
                                    applyFilter('is_active', v)
                                }
                            >
                                <SelectTrigger className="w-40">
                                    <SelectValue placeholder="Status" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        All Playbooks
                                    </SelectItem>
                                    <SelectItem value="1">
                                        Active Only
                                    </SelectItem>
                                    <SelectItem value="0">
                                        Inactive Only
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <span className="text-sm text-muted-foreground">
                                {playbooks.length} playbook
                                {playbooks.length !== 1 ? 's' : ''}
                            </span>
                        </div>

                        {/* Playbook Grid */}
                        {playbooks.length === 0 ? (
                            <Card>
                                <CardContent className="pt-6">
                                    <div className="py-12 text-center">
                                        <BookOpen className="mx-auto mb-3 h-12 w-12 text-muted-foreground/50" />
                                        <p className="text-sm text-muted-foreground">
                                            No playbooks found.
                                        </p>
                                        {can.manage && (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                className="mt-3"
                                                onClick={() =>
                                                    setWizardOpen(true)
                                                }
                                            >
                                                <Plus className="mr-1 h-3 w-3" />
                                                Create your first playbook
                                            </Button>
                                        )}
                                    </div>
                                </CardContent>
                            </Card>
                        ) : (
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                {playbooks.map((pb) => {
                                    const catConfig =
                                        categoryConfig[pb.category] ??
                                        categoryConfig.maintenance;
                                    const CatIcon = catConfig.icon;
                                    return (
                                        <Card
                                            key={pb.id}
                                            className={`transition-colors hover:shadow-md ${!pb.is_active ? 'opacity-60' : ''}`}
                                        >
                                            <CardHeader className="pb-3">
                                                <div className="flex items-start justify-between gap-2">
                                                    <div className="min-w-0 flex-1">
                                                        <Link
                                                            href={`/control-room/playbooks/${pb.id}`}
                                                            className="font-semibold hover:underline"
                                                        >
                                                            {pb.name}
                                                        </Link>
                                                        <div className="mt-1 flex flex-wrap items-center gap-1.5">
                                                            <Badge
                                                                variant="outline"
                                                                className={
                                                                    catConfig.color
                                                                }
                                                            >
                                                                <CatIcon className="mr-1 h-3 w-3" />
                                                                {categories[
                                                                    pb.category
                                                                ] ??
                                                                    pb.category}
                                                            </Badge>
                                                            <Badge
                                                                variant="outline"
                                                                className="text-xs"
                                                            >
                                                                v{pb.version}
                                                            </Badge>
                                                        </div>
                                                    </div>
                                                    {can.manage && (
                                                        <ConfirmChip
                                                            label={
                                                                pb.is_active
                                                                    ? 'Deactivate'
                                                                    : 'Activate'
                                                            }
                                                            icon={Power}
                                                            destructive={
                                                                pb.is_active
                                                            }
                                                            onConfirm={() =>
                                                                toggleActive(pb)
                                                            }
                                                            title={
                                                                pb.is_active
                                                                    ? `Stop ${pb.name} auto-attaching to new alerts`
                                                                    : `Make ${pb.name} available`
                                                            }
                                                        />
                                                    )}
                                                </div>
                                            </CardHeader>
                                            <CardContent>
                                                {pb.description && (
                                                    <p className="mb-3 line-clamp-2 text-sm text-muted-foreground">
                                                        {pb.description}
                                                    </p>
                                                )}
                                                <div className="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                                                    <span className="flex items-center gap-1">
                                                        <Layers className="h-3 w-3" />
                                                        {pb.steps_count} step
                                                        {pb.steps_count !== 1
                                                            ? 's'
                                                            : ''}
                                                    </span>
                                                    <span className="flex items-center gap-1">
                                                        <Play className="h-3 w-3" />
                                                        {pb.runs_count} run
                                                        {pb.runs_count !== 1
                                                            ? 's'
                                                            : ''}
                                                    </span>
                                                    <span className="flex items-center gap-1">
                                                        <Clock className="h-3 w-3" />
                                                        {formatRelative(
                                                            pb.last_run_at,
                                                        )}
                                                    </span>
                                                </div>
                                                {(pb.sla_acknowledge_minutes ||
                                                    pb.sla_response_minutes ||
                                                    pb.sla_resolution_minutes) && (
                                                    <div className="mt-2 flex flex-wrap gap-2">
                                                        {pb.sla_acknowledge_minutes && (
                                                            <span className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium">
                                                                ACK{' '}
                                                                {
                                                                    pb.sla_acknowledge_minutes
                                                                }
                                                                m
                                                            </span>
                                                        )}
                                                        {pb.sla_response_minutes && (
                                                            <span className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium">
                                                                RESP{' '}
                                                                {
                                                                    pb.sla_response_minutes
                                                                }
                                                                m
                                                            </span>
                                                        )}
                                                        {pb.sla_resolution_minutes && (
                                                            <span className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium">
                                                                RES{' '}
                                                                {
                                                                    pb.sla_resolution_minutes
                                                                }
                                                                m
                                                            </span>
                                                        )}
                                                    </div>
                                                )}
                                            </CardContent>
                                        </Card>
                                    );
                                })}
                            </div>
                        )}
                    </CommandCentrePage>
                </PageShell>
            </div>

            {/* Guided playbook builder — mounted only while open so every run starts fresh. */}
            {wizardOpen ? (
                <PlaybookWizard
                    open
                    onClose={() => setWizardOpen(false)}
                    categories={categories}
                    stepTypes={stepTypes}
                />
            ) : null}
        </AppLayout>
    );
}
