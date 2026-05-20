import { Head, Link } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { index as performanceIndex, create as createPerformance, show as showPerformance } from '@/routes/governance/performance';
import { PageHero, PageLayout } from '@/components/page';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Progress } from '@/components/ui/progress';
import { Star, Target, TrendingUp, Calendar } from 'lucide-react';
import { cn } from '@/lib/utils';
import { governanceStatusColor } from '@/lib/governance-status';

interface Review {
  id: number;
  review_cycle: string;
  review_type: string;
  period_start: string;
  period_end: string;
  status: string;
  overall_rating: string | null;
  reviewee: { name: string };
  goals_count: number;
}

interface Props extends PageProps {
  reviews: {
    data: Review[];
  };
  review_cycles: Array<{ value: string; label: string }>;
}

export default function PerformanceIndex({ auth, reviews, review_cycles }: Props) {
  const getStatusColor = (status: string) => governanceStatusColor(status);

  const getRatingColor = (rating: string | null) => {
    return {
      exceeds: 'bg-status-success-bg text-status-success',
      meets: 'bg-status-info-bg text-status-info',
      needs_improvement: 'bg-status-warning-bg text-status-warning',
      unsatisfactory: 'bg-status-critical-bg text-status-critical',
    }[rating || ''] || 'bg-muted text-foreground';
  };

  return (
    <AppLayout
      user={auth.user}
      breadcrumbs={[
        { title: 'Governance', href: '/governance/dashboard' },
        { title: 'Performance', href: '/governance/performance' },
      ]}
    >
      <Head title="Performance Reviews" />

      <PageLayout
        hero={
          <PageHero
            icon={Target}
            title="Performance Reviews"
            description="Manage CEO and executive review cycles, goals, and ratings."
            stats={[
              { label: 'Active reviews', value: reviews.data.filter((r) => r.status !== 'completed').length },
              { label: 'Completed', value: reviews.data.filter((r) => r.status === 'completed').length },
              { label: 'Pending board review', value: reviews.data.filter((r) => r.status === 'board_review').length },
              { label: 'Current cycle', value: review_cycles[0]?.label ?? '—' },
            ]}
            actions={
              auth.can?.governance?.performance?.create ? (
                <Button asChild>
                  <Link href={createPerformance.url()}>New Review</Link>
                </Button>
              ) : undefined
            }
          />
        }
      >
          {/* Summary Cards */}
          <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <Card>
              <CardContent className="pt-6">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm text-muted-foreground">Active Reviews</p>
                    <p className="text-3xl font-bold">
                      {reviews.data.filter(r => r.status !== 'completed').length}
                    </p>
                  </div>
                  <Target className="w-8 h-8 text-status-info" />
                </div>
              </CardContent>
            </Card>
            <Card>
              <CardContent className="pt-6">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm text-muted-foreground">Completed</p>
                    <p className="text-3xl font-bold">
                      {reviews.data.filter(r => r.status === 'completed').length}
                    </p>
                  </div>
                  <Star className="w-8 h-8 text-status-success" />
                </div>
              </CardContent>
            </Card>
            <Card>
              <CardContent className="pt-6">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm text-muted-foreground">Pending Board Review</p>
                    <p className="text-3xl font-bold">
                      {reviews.data.filter(r => r.status === 'board_review').length}
                    </p>
                  </div>
                  <TrendingUp className="w-8 h-8 text-status-warning" />
                </div>
              </CardContent>
            </Card>
            <Card>
              <CardContent className="pt-6">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm text-muted-foreground">Current Cycle</p>
                    <p className="text-lg font-bold">{review_cycles[0]?.label}</p>
                  </div>
                  <Calendar className="w-8 h-8 text-primary" />
                </div>
              </CardContent>
            </Card>
          </div>

          {/* Reviews List */}
          <Card>
            <CardHeader>
              <CardTitle>Performance Reviews</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="space-y-3">
                {reviews.data.map((review) => (
                  <div
                    key={review.id}
                    className="flex items-center justify-between p-4 rounded-lg border hover:bg-muted transition-colors"
                  >
                    <div className="flex-1">
                      <div className="flex items-center gap-3 mb-2">
                        <h3 className="font-semibold text-foreground">
                          <Link
                            href={showPerformance.url({ review: review.id })}
                            className="hover:text-status-info"
                          >
                            {review.reviewee.name} - {review.review_cycle}
                          </Link>
                        </h3>
                        <Badge className={cn(getStatusColor(review.status))}>
                          {review.status.replace('_', ' ')}
                        </Badge>
                        <Badge variant="outline">{review.review_type}</Badge>
                        {review.overall_rating && (
                          <Badge className={cn(getRatingColor(review.overall_rating))}>
                            {review.overall_rating.replace('_', ' ')}
                          </Badge>
                        )}
                      </div>
                      <div className="flex items-center gap-4 text-sm text-muted-foreground">
                        <span>Period: {new Date(review.period_start).toLocaleDateString()} - {new Date(review.period_end).toLocaleDateString()}</span>
                        <span>•</span>
                        <span>{review.goals_count} goals</span>
                      </div>
                    </div>
                    <Button variant="ghost" size="sm" asChild>
                      <Link href={showPerformance.url({ review: review.id })}>
                        View →
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
