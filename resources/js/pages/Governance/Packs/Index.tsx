import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { PageProps } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertCircle,
    Calendar,
    ChevronLeft,
    ChevronRight,
    ExternalLink,
    FileText,
    FolderOpen,
    Plus,
} from 'lucide-react';
import { useState } from 'react';
import { GenerateBoardPackDialog, type MeetingWithoutPack } from './_dialogs';

interface Pack {
    id: number;
    meeting_id: number;
    revision_number: number;
    supersedes_id: number | null;
    build_status: string;
    is_current: boolean;
    actual_document_count: number;
    meeting: {
        id: number;
        title: string;
        scheduled_at: string;
        meeting_type: string;
    } | null;
    generatedBy: { id: number; name: string } | null;
    distributed_at: string | null;
    created_at: string;
    updated_at: string;
    read_count?: number;
    download_count?: number;
}

interface Props extends PageProps {
    packs: {
        data: Pack[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: {
        status: string | null;
    };
    summary: {
        total: number;
        distributed: number;
        draft: number;
        superseded?: number;
        failed?: number;
    };
    meetings_without_pack: MeetingWithoutPack[];
}

export default function PacksIndex({
    auth,
    packs,
    filters,
    summary,
    meetings_without_pack,
}: Props) {
    const [generateOpen, setGenerateOpen] = useState(false);
    const canManagePacks =
        (auth as { can?: { governance?: { packs?: { manage?: boolean } } } })
            ?.can?.governance?.packs?.manage ?? true;

    const setStatus = (status: string | null) => {
        router.get(
            '/governance/packs',
            { status },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Board Packs', href: '/governance/packs' },
            ]}
        >
            <Head title="Board Packs" />

            <PageLayout
                hero={
                    <PageHero
                        icon={FolderOpen}
                        category="governance"
                        title="Board Packs"
                        description="Immutable, audience-safe board packs assembled for meetings. Each version preserves exact decision papers, snapshots, and reading receipts."
                        stats={[
                            { label: 'Total', value: summary.total },
                            {
                                label: 'Current',
                                value: summary.distributed,
                            },
                            { label: 'Draft', value: summary.draft },
                            { label: 'Superseded', value: summary.superseded ?? 0 },
                        ]}
                        actions={
                            canManagePacks ? (
                                <Button
                                    onClick={() => setGenerateOpen(true)}
                                    dusk="generate-board-pack-button"
                                >
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    Generate Board Pack
                                </Button>
                            ) : undefined
                        }
                    />
                }
            >
                {canManagePacks && (
                    <GenerateBoardPackDialog
                        isOpen={generateOpen}
                        onClose={() => setGenerateOpen(false)}
                        meetings={meetings_without_pack ?? []}
                    />
                )}

                <div className="mb-4 flex flex-wrap items-center gap-2">
                    <Button
                        variant={!filters.status ? 'default' : 'outline'}
                        size="sm"
                        onClick={() => setStatus(null)}
                    >
                        All ({summary.total})
                    </Button>
                    <Button
                        variant={
                            filters.status === 'current' || filters.status === 'distributed'
                                ? 'default'
                                : 'outline'
                        }
                        size="sm"
                        onClick={() => setStatus('current')}
                    >
                        Current ({summary.distributed})
                    </Button>
                    <Button
                        variant={
                            filters.status === 'draft' ? 'default' : 'outline'
                        }
                        size="sm"
                        onClick={() => setStatus('draft')}
                    >
                        Draft ({summary.draft})
                    </Button>
                    <Button
                        variant={
                            filters.status === 'superseded' ? 'default' : 'outline'
                        }
                        size="sm"
                        onClick={() => setStatus('superseded')}
                    >
                        Superseded ({summary.superseded ?? 0})
                    </Button>
                    {(summary.failed ?? 0) > 0 && (
                        <Button
                            variant={
                                filters.status === 'failed' ? 'default' : 'outline'
                            }
                            size="sm"
                            onClick={() => setStatus('failed')}
                        >
                            Failed ({summary.failed})
                        </Button>
                    )}
                </div>

                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle>Board Packs</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {packs.data.length === 0 ? (
                            <div className="flex flex-col items-center gap-3 py-6">
                                <EmptyState
                                    icon={FolderOpen}
                                    title="No board packs match filter"
                                    description="Board packs are generated from scheduled meetings with frozen snapshots and decision papers."
                                />
                                {canManagePacks && (
                                    <Button
                                        onClick={() => setGenerateOpen(true)}
                                    >
                                        <Plus className="mr-1.5 h-4 w-4" />
                                        Generate from a meeting
                                    </Button>
                                )}
                            </div>
                        ) : (
                            packs.data.map((pack) => (
                                <Link
                                    key={pack.id}
                                    href={`/governance/packs/${pack.id}`}
                                    className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border p-3.5 transition-colors hover:bg-muted/30"
                                >
                                    <div className="space-y-1.5">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Badge variant="outline" className="text-xs">
                                                Rev {pack.revision_number ?? 1}
                                            </Badge>
                                            <Badge
                                                className={cn(
                                                    'text-xs uppercase',
                                                    pack.is_current
                                                        ? 'border-status-success/30 bg-status-success-bg text-status-success'
                                                        : 'border-status-warning/30 bg-status-warning-bg text-status-warning',
                                                )}
                                            >
                                                {pack.is_current ? 'Current' : 'Superseded'}
                                            </Badge>
                                            <Badge
                                                className={cn(
                                                    'text-xs uppercase',
                                                    pack.distributed_at
                                                        ? 'border-status-success/30 bg-status-success-bg text-status-success'
                                                        : 'border-status-neutral/30 bg-muted text-muted-foreground',
                                                )}
                                            >
                                                {pack.distributed_at ? 'Distributed' : 'Draft'}
                                            </Badge>
                                            {pack.build_status === 'failed' && (
                                                <Badge className="border-status-critical/30 bg-status-critical-bg text-status-critical text-xs uppercase">
                                                    Failed
                                                </Badge>
                                            )}
                                            {pack.meeting && (
                                                <Badge
                                                    variant="outline"
                                                    className="gap-1 text-xs"
                                                >
                                                    <Calendar className="h-3 w-3" />
                                                    {pack.meeting.meeting_type}
                                                </Badge>
                                            )}
                                        </div>
                                        <p className="font-medium text-foreground">
                                            {pack.meeting?.title ?? 'Untitled meeting'}
                                        </p>
                                        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                                            {pack.meeting?.scheduled_at && (
                                                <span>
                                                    Scheduled {new Date(pack.meeting.scheduled_at).toLocaleDateString('en-NZ', { timeZone: 'Pacific/Auckland' })}
                                                </span>
                                            )}
                                            <span>
                                                {pack.actual_document_count} paper(s) & docs
                                            </span>
                                            {pack.distributed_at && (
                                                <span>
                                                    Distributed {new Date(pack.distributed_at).toLocaleDateString('en-NZ', { timeZone: 'Pacific/Auckland' })}
                                                </span>
                                            )}
                                            {canManagePacks && pack.read_count !== undefined && (
                                                <span>
                                                    Reads: {pack.read_count} · Downloads: {pack.download_count ?? 0}
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                    <ExternalLink className="h-4 w-4 text-muted-foreground" />
                                </Link>
                            ))
                        )}
                    </CardContent>
                </Card>

                {packs.last_page > 1 && (
                    <div className="mt-4 flex items-center justify-between">
                        <p className="text-sm text-muted-foreground">
                            Page {packs.current_page} of {packs.last_page} ({packs.total} total)
                        </p>
                        <div className="flex items-center gap-2">
                            {packs.current_page > 1 && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        router.get(
                                            '/governance/packs',
                                            {
                                                ...filters,
                                                page: packs.current_page - 1,
                                            },
                                            { preserveState: true },
                                        )
                                    }
                                >
                                    <ChevronLeft className="mr-1 h-4 w-4" />
                                    Previous
                                </Button>
                            )}
                            {packs.current_page < packs.last_page && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        router.get(
                                            '/governance/packs',
                                            {
                                                ...filters,
                                                page: packs.current_page + 1,
                                            },
                                            { preserveState: true },
                                        )
                                    }
                                >
                                    Next
                                    <ChevronRight className="ml-1 h-4 w-4" />
                                </Button>
                            )}
                        </div>
                    </div>
                )}
            </PageLayout>
        </AppLayout>
    );
}
