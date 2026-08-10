import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import { statusColors } from '@/lib/status-colors';
import { PageProps } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    Calendar,
    ChevronLeft,
    ChevronRight,
    ExternalLink,
    FolderOpen,
    Plus,
} from 'lucide-react';
import { useState } from 'react';
import { GenerateBoardPackDialog, type MeetingWithoutPack } from './_dialogs';

interface Pack {
    id: number;
    meeting_id: number;
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
                        description="Board packs are generated from a meeting's agenda, CEO report, resolutions, and attendance — one pack per meeting."
                        stats={[
                            { label: 'Total', value: summary.total },
                            {
                                label: 'Distributed',
                                value: summary.distributed,
                            },
                            { label: 'Draft', value: summary.draft },
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
                        All
                    </Button>
                    <Button
                        variant={
                            filters.status === 'distributed'
                                ? 'default'
                                : 'outline'
                        }
                        size="sm"
                        onClick={() => setStatus('distributed')}
                    >
                        Distributed
                    </Button>
                    <Button
                        variant={
                            filters.status === 'draft' ? 'default' : 'outline'
                        }
                        size="sm"
                        onClick={() => setStatus('draft')}
                    >
                        Draft
                    </Button>
                </div>

                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle>Packs</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {packs.data.length === 0 ? (
                            <div className="flex flex-col items-center gap-3 py-6">
                                <EmptyState
                                    icon={FolderOpen}
                                    title="No board packs yet"
                                    description="Board packs are generated from a scheduled meeting's agenda, CEO report and resolutions. Pick a meeting to generate one."
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
                                    className="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3 transition-colors hover:bg-muted/30"
                                >
                                    <div className="space-y-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Badge
                                                className={
                                                    pack.distributed_at
                                                        ? (statusColors.approved ??
                                                          '')
                                                        : (statusColors.draft ??
                                                          '')
                                                }
                                            >
                                                {pack.distributed_at
                                                    ? 'Distributed'
                                                    : 'Draft'}
                                            </Badge>
                                            {pack.meeting && (
                                                <Badge
                                                    variant="outline"
                                                    className="gap-1"
                                                >
                                                    <Calendar className="h-3 w-3" />
                                                    {pack.meeting.meeting_type}
                                                </Badge>
                                            )}
                                        </div>
                                        <p className="font-medium">
                                            {pack.meeting?.title ??
                                                'Untitled meeting'}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {pack.meeting?.scheduled_at && (
                                                <>
                                                    Scheduled{' '}
                                                    {pack.meeting.scheduled_at}{' '}
                                                    ·{' '}
                                                </>
                                            )}
                                            Generated by{' '}
                                            {pack.generatedBy?.name ?? 'system'}{' '}
                                            on {pack.created_at}
                                            {pack.distributed_at && (
                                                <>
                                                    {' '}
                                                    · Distributed{' '}
                                                    {pack.distributed_at}
                                                </>
                                            )}
                                        </p>
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
                            Page {packs.current_page} of {packs.last_page} (
                            {packs.total} total)
                        </p>
                        <div className="flex items-center gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={packs.current_page <= 1}
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
                                <ChevronLeft className="mr-1 h-4 w-4" />{' '}
                                Previous
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={packs.current_page >= packs.last_page}
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
                                Next <ChevronRight className="ml-1 h-4 w-4" />
                            </Button>
                        </div>
                    </div>
                )}
            </PageLayout>
        </AppLayout>
    );
}
