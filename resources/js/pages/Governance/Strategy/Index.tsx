import { Head, Link } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { index as strategyIndex, create as createStrategy, show as showStrategy } from '@/routes/governance/strategy';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Progress } from '@/components/ui/progress';
import { Target, Compass, TrendingUp, Calendar } from 'lucide-react';
import { cn } from '@/lib/utils';

interface StrategicPlan {
  id: number;
  title: string;
  planning_horizon: string;
  period_start: string;
  period_end: string;
  status: string;
  progress_pct: number;
  version_number: number;
}

interface Props extends PageProps {
  plans: {
    data: StrategicPlan[];
  };
}

export default function StrategyIndex({ auth, plans }: Props) {
  const getStatusColor = (status: string) => {
    return {
      draft: 'bg-muted text-foreground',
      consultation: 'bg-blue-100 text-blue-800',
      approved: 'bg-green-100 text-green-800',
      archived: 'bg-muted text-foreground',
    }[status] || 'bg-muted text-foreground';
  };

  const getHorizonLabel = (horizon: string) => {
    return {
      '3_year': '3-Year Plan',
      '5_year': '5-Year Plan',
    }[horizon] || horizon;
  };

  return (
    <AppLayout
      user={auth.user}
      breadcrumbs={[
        { title: 'Governance', href: '/governance/dashboard' },
        { title: 'Strategy', href: '/governance/strategy' },
      ]}
    >
      <Head title="Strategic Plans" />

      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          {/* Header */}
          <div className="flex items-center justify-between mb-6">
            <div>
              <h1 className="text-3xl font-bold text-foreground">Strategic Planning</h1>
              <p className="text-muted-foreground mt-1">Long-term goals and initiatives</p>
            </div>
            <Button asChild>
              <Link href={createStrategy.url()}>New Strategic Plan</Link>
            </Button>
          </div>

          {/* Active Plan Highlight */}
          {plans.data.find(p => p.status === 'approved') && (
            <Card className="mb-6 border-green-200 bg-green-50/50">
              <CardContent className="pt-6">
                {plans.data.filter(p => p.status === 'approved').map(plan => (
                  <div key={plan.id} className="flex items-start justify-between">
                    <div>
                      <div className="flex items-center gap-3 mb-2">
                        <Compass className="w-6 h-6 text-green-600" />
                        <h2 className="text-xl font-semibold">{plan.title}</h2>
                        <Badge className="bg-green-100 text-green-800">Active</Badge>
                      </div>
                      <p className="text-muted-foreground">
                        {new Date(plan.period_start).getFullYear()} - {new Date(plan.period_end).getFullYear()}
                      </p>
                    </div>
                    <div className="text-right">
                      <p className="text-3xl font-bold text-green-600">{plan.progress_pct}%</p>
                      <p className="text-sm text-muted-foreground">Complete</p>
                    </div>
                  </div>
                ))}
              </CardContent>
            </Card>
          )}

          {/* Plans List */}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            {plans.data.map((plan) => (
              <Card key={plan.id}>
                <CardContent className="pt-6">
                  <div className="flex items-start justify-between mb-4">
                    <div>
                      <div className="flex items-center gap-2 mb-1">
                        <h3 className="font-semibold text-lg">
                          <Link
                            href={showStrategy.url({ plan: plan.id })}
                            className="hover:text-blue-600"
                          >
                            {plan.title}
                          </Link>
                        </h3>
                      </div>
                      <Badge className={cn(getStatusColor(plan.status))}>
                        {plan.status}
                      </Badge>
                    </div>
                    <Badge variant="outline">{getHorizonLabel(plan.planning_horizon)}</Badge>
                  </div>

                  <div className="space-y-3">
                    <div className="flex justify-between text-sm text-muted-foreground">
                      <span>Progress</span>
                      <span>{plan.progress_pct}%</span>
                    </div>
                    <Progress value={plan.progress_pct} />
                    <div className="flex items-center gap-4 text-sm text-muted-foreground">
                      <span className="flex items-center gap-1">
                        <Calendar className="w-4 h-4" />
                        {new Date(plan.period_start).getFullYear()} - {new Date(plan.period_end).getFullYear()}
                      </span>
                      <span>v{plan.version_number}</span>
                    </div>
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
      </div>
    </AppLayout>
  );
}
