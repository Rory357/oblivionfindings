import { Head, Link } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { index as strategyIndex } from '@/routes/governance/strategy';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Progress } from '@/components/ui/progress';
import { Compass, Target, Rocket, Calendar, CheckCircle, Clock, AlertTriangle } from 'lucide-react';
import { cn } from '@/lib/utils';

interface Initiative {
  id: number;
  name: string;
  description: string;
  budget_allocated: number;
  budget_spent: number;
  start_date: string;
  target_completion: string;
  status: string;
  owner: { name: string } | null;
}

interface Goal {
  id: number;
  pillar: string;
  timeframe: string;
  title: string;
  description: string;
  key_results: Array<{ result: string; status: string }>;
  status: string;
  initiatives: Initiative[];
}

interface StrategicPlan {
  id: number;
  title: string;
  planning_horizon: string;
  period_start: string;
  period_end: string;
  vision_statement: string;
  mission_statement: string;
  values: Array<{ value: string; description?: string }>;
  status: string;
  version_number: number;
  approval_resolution: { resolution_reference: string; outcome: string } | null;
  goals: Goal[];
}

interface Props extends PageProps {
  plan: StrategicPlan;
}

export default function StrategyShow({ auth, plan }: Props) {
  const getPillarLabel = (pillar: string) => {
    const labels: Record<string, string> = {
      safety: 'Safety',
      quality: 'Quality',
      people: 'People',
      finance: 'Finance',
      compliance: 'Compliance',
      it_resilience: 'IT Resilience',
    };
    return labels[pillar] || pillar;
  };

  const getPillarColor = (pillar: string) => {
    const colors: Record<string, string> = {
      safety: 'bg-red-100 text-red-800 border-red-200',
      quality: 'bg-blue-100 text-blue-800 border-blue-200',
      people: 'bg-green-100 text-green-800 border-green-200',
      finance: 'bg-yellow-100 text-yellow-800 border-yellow-200',
      compliance: 'bg-primary/10 text-primary border-primary',
      it_resilience: 'bg-cyan-100 text-cyan-800 border-cyan-200',
    };
    return colors[pillar] || 'bg-muted text-foreground border-border';
  };

  const getStatusColor = (status: string) => {
    switch (status) {
      case 'achieved':
      case 'complete':
      case 'approved':
        return 'bg-green-100 text-green-800';
      case 'in_progress':
        return 'bg-blue-100 text-blue-800';
      case 'at_risk':
      case 'on_hold':
        return 'bg-yellow-100 text-yellow-800';
      case 'blocked':
      case 'cancelled':
        return 'bg-red-100 text-red-800';
      default:
        return 'bg-muted text-foreground';
    }
  };

  const groupGoalsByPillar = () => {
    const grouped: Record<string, Goal[]> = {};
    plan.goals.forEach((goal) => {
      if (!grouped[goal.pillar]) {
        grouped[goal.pillar] = [];
      }
      grouped[goal.pillar].push(goal);
    });
    return grouped;
  };

  const calculateInitiativeProgress = (initiative: Initiative) => {
    if (initiative.budget_allocated === 0) return 0;
    return Math.round((initiative.budget_spent / initiative.budget_allocated) * 100);
  };

  return (
    <AppLayout
      user={auth.user}
      breadcrumbs={[
        { title: 'Governance', href: '/governance/dashboard' },
        { title: 'Strategy', href: '/governance/strategy' },
        { title: 'Plan', href: `/governance/strategy/${plan.id}` },
      ]}
    >
      <Head title={plan.title} />

      <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
          {/* Back Link */}
          <div className="mb-4">
            <Link href={strategyIndex.url()} className="text-sm text-blue-600 hover:underline">
              ← Back to Strategic Plans
            </Link>
          </div>

          {/* Header */}
          <div className="flex items-start justify-between mb-6">
            <div>
              <div className="flex items-center gap-3 mb-2">
                <Compass className="w-8 h-8 text-primary" />
                <div>
                  <h1 className="text-2xl font-bold text-foreground">{plan.title}</h1>
                  <p className="text-muted-foreground">{plan.period_start} to {plan.period_end}</p>
                </div>
              </div>
              <div className="flex items-center gap-2 mt-2">
                <Badge variant="outline">{plan.planning_horizon.replace('_', ' ')} Plan</Badge>
                <Badge className={getStatusColor(plan.status)}>{plan.status}</Badge>
                <Badge variant="outline">v{plan.version_number}</Badge>
              </div>
            </div>
            {plan.status === 'draft' && (
              <Button>Submit for Approval</Button>
            )}
          </div>

          {/* Vision & Mission */}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
            <Card className="border-primary bg-primary/10">
              <CardHeader>
                <CardTitle className="text-primary">Vision</CardTitle>
              </CardHeader>
              <CardContent>
                <p className="text-primary italic text-lg">{plan.vision_statement}</p>
              </CardContent>
            </Card>
            <Card className="border-primary bg-primary/10">
              <CardHeader>
                <CardTitle className="text-primary">Mission</CardTitle>
              </CardHeader>
              <CardContent>
                <p className="text-primary">{plan.mission_statement}</p>
              </CardContent>
            </Card>
          </div>

          {/* Values */}
          {plan.values.length > 0 && (
            <Card className="mb-6">
              <CardHeader>
                <CardTitle>Our Values</CardTitle>
              </CardHeader>
              <CardContent>
                <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                  {plan.values.map((value, index) => (
                    <div key={index} className="p-4 bg-muted rounded-lg text-center">
                      <p className="font-semibold text-foreground">{value.value}</p>
                      {value.description && (
                        <p className="text-sm text-muted-foreground mt-1">{value.description}</p>
                      )}
                    </div>
                  ))}
                </div>
              </CardContent>
            </Card>
          )}

          {/* Approval Status */}
          {plan.approval_resolution && (
            <Card className="mb-6 border-green-200 bg-green-50">
              <CardContent className="pt-6">
                <div className="flex items-center gap-3">
                  <CheckCircle className="w-6 h-6 text-green-600" />
                  <div>
                    <p className="font-medium text-green-800">Board Approved</p>
                    <p className="text-sm text-green-600">
                      Resolution {plan.approval_resolution.resolution_reference} - {plan.approval_resolution.outcome}
                    </p>
                  </div>
                </div>
              </CardContent>
            </Card>
          )}

          {/* Strategic Goals by Pillar */}
          <div className="space-y-6">
            <h2 className="text-xl font-bold text-foreground">Strategic Goals</h2>

            {Object.entries(groupGoalsByPillar()).map(([pillar, goals]) => (
              <Card key={pillar}>
                <CardHeader className={cn('border-b', getPillarColor(pillar))}>
                  <CardTitle className="flex items-center gap-2">
                    <Target className="w-5 h-5" />
                    {getPillarLabel(pillar)}
                  </CardTitle>
                  <CardDescription>{goals.length} goal{goals.length !== 1 ? 's' : ''}</CardDescription>
                </CardHeader>
                <CardContent className="pt-4">
                  <div className="space-y-6">
                    {goals.map((goal) => (
                      <div key={goal.id} className="border-l-4 border-border pl-4">
                        <div className="flex items-start justify-between mb-2">
                          <div>
                            <h4 className="font-semibold text-foreground">{goal.title}</h4>
                            <p className="text-sm text-muted-foreground">{goal.description}</p>
                          </div>
                          <div className="flex gap-2">
                            <Badge variant="outline" className="text-xs">{goal.timeframe}</Badge>
                            <Badge className={getStatusColor(goal.status)}>{goal.status.replace('_', ' ')}</Badge>
                          </div>
                        </div>

                        {/* Key Results */}
                        {goal.key_results.length > 0 && (
                          <div className="mt-3 space-y-2">
                            <p className="text-sm font-medium text-foreground">Key Results:</p>
                            {goal.key_results.map((kr, index) => (
                              <div key={index} className="flex items-center gap-2 text-sm">
                                {kr.status === 'achieved' ? (
                                  <CheckCircle className="w-4 h-4 text-green-500" />
                                ) : kr.status === 'in_progress' ? (
                                  <Clock className="w-4 h-4 text-blue-500" />
                                ) : (
                                  <AlertTriangle className="w-4 h-4 text-yellow-500" />
                                )}
                                <span className={cn(
                                  kr.status === 'achieved' && 'line-through text-muted-foreground'
                                )}>{kr.result}</span>
                              </div>
                            ))}
                          </div>
                        )}

                        {/* Initiatives */}
                        {goal.initiatives.length > 0 && (
                          <div className="mt-4">
                            <p className="text-sm font-medium text-foreground mb-2">Initiatives:</p>
                            <div className="space-y-3">
                              {goal.initiatives.map((initiative) => (
                                <div key={initiative.id} className="p-3 bg-muted rounded-lg">
                                  <div className="flex items-start justify-between">
                                    <div>
                                      <div className="flex items-center gap-2">
                                        <Rocket className="w-4 h-4 text-muted-foreground" />
                                        <p className="font-medium text-sm">{initiative.name}</p>
                                      </div>
                                      <p className="text-xs text-muted-foreground mt-1">
                                        {initiative.owner?.name || 'No owner'} •
                                        Due: {initiative.target_completion}
                                      </p>
                                    </div>
                                    <Badge className={getStatusColor(initiative.status)} variant="outline">
                                      {initiative.status.replace('_', ' ')}
                                    </Badge>
                                  </div>
                                  <div className="mt-2">
                                    <div className="flex items-center justify-between text-xs text-muted-foreground mb-1">
                                      <span>Budget</span>
                                      <span>${initiative.budget_spent.toLocaleString()} / ${initiative.budget_allocated.toLocaleString()}</span>
                                    </div>
                                    <Progress value={calculateInitiativeProgress(initiative)} className="h-1" />
                                  </div>
                                </div>
                              ))}
                            </div>
                          </div>
                        )}
                      </div>
                    ))}
                  </div>
                </CardContent>
              </Card>
            ))}

            {plan.goals.length === 0 && (
              <Card>
                <CardContent className="py-12 text-center text-muted-foreground">
                  <Target className="w-12 h-12 mx-auto mb-4 opacity-50" />
                  <p>No strategic goals defined yet.</p>
                  <Button variant="outline" className="mt-4">Add Goal</Button>
                </CardContent>
              </Card>
            )}
          </div>
      </div>
    </AppLayout>
  );
}
