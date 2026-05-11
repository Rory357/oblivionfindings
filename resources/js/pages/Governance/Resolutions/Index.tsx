import { Head, Link } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { index as resolutionsIndex, create as createResolution, show as showResolution } from '@/routes/governance/resolutions';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Vote, Clock, AlertCircle, CheckCircle } from 'lucide-react';
import { cn } from '@/lib/utils';

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
}

export default function ResolutionsIndex({ auth, resolutions, my_pending_votes }: Props) {
  const getStatusColor = (status: string) => {
    return {
      draft: 'bg-muted text-foreground',
      proposed: 'bg-status-info-bg text-status-info',
      open: 'bg-status-success-bg text-status-success',
      closed: 'bg-primary/10 text-primary',
      implemented: 'bg-status-success-bg text-status-success',
      archived: 'bg-muted text-foreground',
      cancelled: 'bg-status-critical-bg text-status-critical',
    }[status] || 'bg-muted text-foreground';
  };

  const getOutcomeBadge = (outcome: string | null) => {
    if (!outcome) return null;
    return outcome === 'carried' ? (
      <Badge className="bg-status-success-bg text-status-success">
        <CheckCircle className="w-3 h-3 mr-1" /> Carried
      </Badge>
    ) : (
      <Badge className="bg-status-critical-bg text-status-critical">
        <AlertCircle className="w-3 h-3 mr-1" /> Defeated
      </Badge>
    );
  };

  return (
    <AppLayout
      user={auth.user}
      breadcrumbs={[
        { title: 'Governance', href: '/governance/dashboard' },
        { title: 'Resolutions', href: '/governance/resolutions' },
      ]}
    >
      <Head title="Resolutions" />

      <div className="flex flex-col gap-6 p-4 md:p-6">
          {/* Header */}
          <div className="flex items-center justify-between mb-6">
            <div>
              <h1 className="text-3xl font-bold text-foreground">Resolutions</h1>
              <p className="text-muted-foreground mt-1">Board voting and decisions</p>
            </div>
            <Button asChild>
              <Link href={createResolution.url()}>New Resolution</Link>
            </Button>
          </div>

          {/* Pending Votes Alert */}
          {my_pending_votes.length > 0 && (
            <Card className="mb-6 border-status-warning/30 bg-status-warning-bg">
              <CardHeader>
                <CardTitle className="flex items-center gap-2 text-status-warning">
                  <Vote className="w-5 h-5" />
                  Your Vote Required ({my_pending_votes.length})
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div className="space-y-2">
                  {my_pending_votes.map((vote) => (
                    <div
                      key={vote.id}
                      className="flex items-center justify-between p-3 bg-card rounded-lg border border-status-warning/30"
                    >
                      <div>
                        <p className="font-medium text-foreground">{vote.title}</p>
                        <p className="text-sm text-muted-foreground">{vote.resolution_reference}</p>
                      </div>
                      <Button size="sm" asChild>
                        <Link href={showResolution.url({ resolution: vote.id })}>
                          Vote Now
                        </Link>
                      </Button>
                    </div>
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
                    className="flex items-start justify-between p-4 rounded-lg border hover:bg-muted transition-colors"
                  >
                    <div className="flex-1">
                      <div className="flex items-center gap-3 mb-2">
                        <h3 className="font-semibold text-foreground">
                          <Link
                            href={showResolution.url({ resolution: resolution.id })}
                            className="hover:text-status-info"
                          >
                            {resolution.title}
                          </Link>
                        </h3>
                        <Badge className={cn(getStatusColor(resolution.status))}>
                          {resolution.status}
                        </Badge>
                        {getOutcomeBadge(resolution.outcome)}
                      </div>
                      <div className="flex items-center gap-4 text-sm text-muted-foreground">
                        <span>{resolution.resolution_reference}</span>
                        <span>|</span>
                        <span>Threshold: {resolution.voting_threshold.replace('_', ' ')}</span>
                        {resolution.meeting && (
                          <>
                            <span>|</span>
                            <span>{resolution.meeting.title}</span>
                          </>
                        )}
                        {resolution.deadline && (
                          <>
                            <span>|</span>
                            <span className="flex items-center gap-1">
                              <Clock className="w-3 h-3" />
                              Due {new Date(resolution.deadline).toLocaleDateString()}
                            </span>
                          </>
                        )}
                      </div>
                    </div>
                    <Button variant="ghost" size="sm" asChild>
                      <Link href={showResolution.url({ resolution: resolution.id })}>
                        View &rarr;
                      </Link>
                    </Button>
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>
      </div>
    </AppLayout>
  );
}
