import {
    PageHeader,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageLayout,
} from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { governanceStatusColor } from '@/lib/governance-status';
import { cn } from '@/lib/utils';
import { show as showResolution } from '@/routes/governance/resolutions';
import { PageProps } from '@/types';
import { Head, Link } from '@inertiajs/react';
import {
    AlertCircle,
    CheckCircle,
    Clock,
    Gavel,
    Plus,
    Vote,
} from 'lucide-react';
import { useState } from 'react';
import { ResolutionWizardDialog, type MeetingOption } from './_dialogs';

interface Resolution {
    id: number;
    resolution_reference: string;
    title: string;
    status: string;
    voting_threshold: string;
    deadline: string | null;
    outcome: string | null;
    meeting: { title: string } | null;
    proposed_by: { name: string };
    votes_count?: number;
}

interface Props extends PageProps {
    resolutions: {
        data: Resolution[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    my_pending_votes: Resolution[];
    meetings: MeetingOption[];
}

export default function ResolutionsIndex({
    auth,
    resolutions,
    my_pending_votes,
    meetings,
}: Props) {
    const [newResolutionOpen, setNewResolutionOpen] = useState(false);
    const getStatusColor = (status: string) => governanceStatusColor(status);

    const getOutcomeBadge = (outcome: string | null) => {
        if (!outcome) return null;
        return outcome === 'carried' ? (
            <Badge className="bg-status-success-bg text-status-success">
                <CheckCircle className="mr-1 h-3 w-3" /> Carried
            </Badge>
        ) : (
            <Badge className="bg-status-critical-bg text-status-critical">
                <AlertCircle className="mr-1 h-3 w-3" /> Defeated
            </Badge>
        );
    };

    const formatDeadline = (dateStr: string | null): string => {
        if (!dateStr) return '';
        const d = new Date(dateStr);
        if (isNaN(d.getTime())) return dateStr;
        return d.toLocaleDateString('en-NZ', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        });
    };

    const formatThreshold = (threshold: string): string => {
        switch (threshold) {
            case 'simple_majority':
                return 'Simple majority (50% + 1)';
            case 'two_thirds':
                return 'Two-thirds majority (66.7%)';
            case 'special_majority':
            case 'three_quarters':
                return 'Special majority (75%)';
            case 'unanimous':
                return 'Unanimous (100% entitled)';
            default:
                return threshold.replace(/_/g, ' ');
        }
    };

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Resolutions', href: '/governance/resolutions' },
            ]}
        >
            <Head title="Resolutions" />

            <PageLayout
                hero={
                    <PageHeader
                        variant="index"
                        icon={Gavel}
                        title="Resolutions"
                        subline="Record board voting outcomes and track decisions through to implementation."
                        actions={
                            <PageHeaderPrimaryButton
                                onClick={() => setNewResolutionOpen(true)}
                                dusk="new-resolution-button"
                            >
                                <Plus className="mr-1.5 h-4 w-4" />
                                New Resolution
                            </PageHeaderPrimaryButton>
                        }
                        meters={
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <PageHeaderMeterBlock
                                    label="Total"
                                    href="/governance/resolutions"
                                >
                                    <PageHeaderMeterBig>
                                        {resolutions.data.length}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Total resolutions
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Awaiting Vote"
                                    href="/governance/my-work?kind=vote"
                                    tone={
                                        my_pending_votes.length > 0
                                            ? 'warning'
                                            : 'brand'
                                    }
                                >
                                    <PageHeaderMeterBig>
                                        {my_pending_votes.length}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Awaiting your vote
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Carried"
                                    href="/governance/resolutions?status=closed"
                                    tone="success"
                                >
                                    <PageHeaderMeterBig>
                                        {
                                            resolutions.data.filter(
                                                (r) => r.outcome === 'carried',
                                            ).length
                                        }
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Carried decisions
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </div>
                        }
                    />
                }
            >
                <ResolutionWizardDialog
                    isOpen={newResolutionOpen}
                    onClose={() => setNewResolutionOpen(false)}
                    meetings={meetings ?? []}
                />
                {/* Pending Votes Alert */}
                {my_pending_votes.length > 0 && (
                    <Card className="mb-6 border-status-warning/30 bg-status-warning-bg">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-status-warning">
                                <Vote className="h-5 w-5" />
                                Your Vote Required ({my_pending_votes.length})
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-2">
                                {my_pending_votes.map((vote) => (
                                    <Card
                                        key={vote.id}
                                        unstyled
                                        className="flex items-center justify-between rounded-lg border border-status-warning/30 bg-card p-3"
                                    >
                                        <div>
                                            <p className="font-medium text-foreground">
                                                {vote.title}
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                {vote.resolution_reference}
                                            </p>
                                        </div>
                                        <Button size="sm" asChild>
                                            <Link
                                                href={showResolution.url({
                                                    resolution: vote.id,
                                                })}
                                            >
                                                Vote Now
                                            </Link>
                                        </Button>
                                    </Card>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Resolutions List */}
                <Card>
                    <CardHeader>
                        <CardTitle>All Resolutions</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="space-y-3">
                            {resolutions.data.map((resolution) => (
                                <div
                                    key={resolution.id}
                                    className="flex items-start justify-between rounded-lg border p-4 transition-colors hover:bg-muted"
                                >
                                    <div className="flex-1">
                                        <div className="mb-2 flex items-center gap-3">
                                            <h3 className="font-semibold text-foreground">
                                                <Link
                                                    href={showResolution.url({
                                                        resolution:
                                                            resolution.id,
                                                    })}
                                                    className="hover:text-status-info"
                                                >
                                                    {resolution.title}
                                                </Link>
                                            </h3>
                                            <Badge
                                                className={cn(
                                                    getStatusColor(
                                                        resolution.status,
                                                    ),
                                                )}
                                            >
                                                {resolution.status}
                                            </Badge>
                                            {getOutcomeBadge(
                                                resolution.outcome,
                                            )}
                                        </div>
                                        <div className="flex items-center gap-4 text-sm text-muted-foreground">
                                            <span>
                                                {
                                                    resolution.resolution_reference
                                                }
                                            </span>
                                            <span>|</span>
                                            <span>
                                                Threshold:{' '}
                                                {formatThreshold(resolution.voting_threshold)}
                                            </span>
                                            {resolution.meeting && (
                                                <>
                                                    <span>|</span>
                                                    <span>
                                                        {
                                                            resolution.meeting
                                                                .title
                                                        }
                                                    </span>
                                                </>
                                            )}
                                            {resolution.deadline && (
                                                <>
                                                    <span>|</span>
                                                    <span className="flex items-center gap-1">
                                                        <Clock className="h-3 w-3" />
                                                        Due{' '}
                                                        {formatDeadline(resolution.deadline)}
                                                    </span>
                                                </>
                                            )}
                                        </div>
                                    </div>
                                    <Button variant="ghost" size="sm" asChild>
                                        <Link
                                            href={showResolution.url({
                                                resolution: resolution.id,
                                            })}
                                        >
                                            View &rarr;
                                        </Link>
                                    </Button>
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
