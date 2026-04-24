import { Head, Link } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { index as complianceIndex, create as createCompliance, show as showCompliance } from '@/routes/governance/compliance';
import { calendar as complianceCalendar } from '@/routes/governance/compliance';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Progress } from '@/components/ui/progress';
import { Calendar, CheckCircle, Clock, AlertTriangle, FileCheck } from 'lucide-react';
import { cn } from '@/lib/utils';

interface Obligation {
  id: number;
  framework: string;
  obligation_title: string;
  due_date: string;
  status: string;
  evidence_provided: boolean;
  owner: { name: string } | null;
}

interface FrameworkSummary {
  total: number;
  complete: number;
  overdue: number;
  due_soon: number;
  not_due: number;
}

interface Props extends PageProps {
  obligations: {
    data: Obligation[];
  };
  summary: {
    by_framework: Record<string, FrameworkSummary>;
    total_overdue: number;
    total_due_soon: number;
    next_30_days: any[];
  };
  frameworks: Array<{ value: string; label: string }>;
}

export default function ComplianceIndex({ auth, obligations, summary, frameworks }: Props) {
  const getStatusColor = (status: string) => {
    return {
      complete: 'bg-status-success-bg text-status-success',
      not_due: 'bg-status-info-bg text-status-info',
      due_soon: 'bg-status-warning-bg text-status-warning',
      overdue: 'bg-status-critical-bg text-status-critical',
    }[status] || 'bg-muted text-foreground';
  };

  const getFrameworkColor = (framework: string) => {
    const colors: Record<string, string> = {
      charities: 'bg-status-info',
      nga_paerewa: 'bg-status-success',
      privacy_act: 'bg-primary',
      hswa: 'bg-status-warning',
    };
    return colors[framework] || 'bg-muted-foreground/80';
  };

  const formatDate = (dateString: string) => {
    const date = new Date(dateString);
    const days = Math.ceil((date.getTime() - new Date().getTime()) / (1000 * 60 * 60 * 24));
    
    if (days < 0) return `${Math.abs(days)} days overdue`;
    if (days === 0) return 'Due today';
    if (days === 1) return 'Due tomorrow';
    return `${days} days remaining`;
  };

  return (
    <AppLayout
      user={auth.user}
      breadcrumbs={[
        { title: 'Governance', href: '/governance/dashboard' },
        { title: 'Compliance', href: '/governance/compliance' },
      ]}
    >
      <Head title="Compliance" />

      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          {/* Header */}
          <div className="flex items-center justify-between mb-6">
            <div>
              <h1 className="text-3xl font-bold text-foreground">Compliance</h1>
              <p className="text-muted-foreground mt-1">Track regulatory obligations and deadlines</p>
            </div>
            <div className="flex gap-2">
              <Button variant="outline" asChild>
                <Link href={complianceCalendar.url()}>Calendar View</Link>
              </Button>
              {(auth.can as any)?.governance?.compliance?.create && (
                <Button asChild>
                  <Link href={createCompliance.url()}>New Obligation</Link>
                </Button>
              )}
            </div>
          </div>

          {/* Summary Stats */}
          <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <Card>
              <CardContent className="pt-6">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm text-muted-foreground">Overdue</p>
                    <p className="text-3xl font-bold text-status-critical">{summary.total_overdue}</p>
                  </div>
                  <AlertTriangle className="w-8 h-8 text-status-critical" />
                </div>
              </CardContent>
            </Card>
            <Card>
              <CardContent className="pt-6">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm text-muted-foreground">Due Soon (30d)</p>
                    <p className="text-3xl font-bold text-status-warning">{summary.total_due_soon}</p>
                  </div>
                  <Clock className="w-8 h-8 text-status-warning" />
                </div>
              </CardContent>
            </Card>
            <Card>
              <CardContent className="pt-6">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm text-muted-foreground">Next 30 Days</p>
                    <p className="text-3xl font-bold text-status-info">{summary.next_30_days.length}</p>
                  </div>
                  <Calendar className="w-8 h-8 text-status-info" />
                </div>
              </CardContent>
            </Card>
            <Card>
              <CardContent className="pt-6">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm text-muted-foreground">Compliance Rate</p>
                    <p className="text-3xl font-bold text-status-success">
                      {(() => {
                        const total = Object.values(summary.by_framework).reduce((a, f) => a + f.total, 0);
                        const complete = Object.values(summary.by_framework).reduce((a, f) => a + f.complete, 0);
                        return total > 0 ? Math.round((complete / total) * 100) : 0;
                      })()}%
                  </p>
                  </div>
                  <FileCheck className="w-8 h-8 text-status-success" />
                </div>
              </CardContent>
            </Card>
          </div>

          {/* Framework Summary */}
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
            {Object.entries(summary.by_framework).map(([framework, data]) => {
              const completionRate = data.total > 0 ? (data.complete / data.total) * 100 : 0;
              return (
                <Card key={framework}>
                  <CardContent className="pt-6">
                    <div className="flex items-center gap-2 mb-3">
                      <div className={cn("w-3 h-3 rounded-full", getFrameworkColor(framework))} />
                      <h3 className="font-semibold">{frameworks.find(f => f.value === framework)?.label || framework}</h3>
                    </div>
                    <div className="space-y-2">
                      <div className="flex justify-between text-sm">
                        <span className="text-muted-foreground">{data.complete} of {data.total} complete</span>
                        <span className="font-medium">{Math.round(completionRate)}%</span>
                      </div>
                      <Progress value={completionRate} />
                      <div className="flex gap-2 text-xs">
                        {data.overdue > 0 && (
                          <Badge variant="destructive">{data.overdue} overdue</Badge>
                        )}
                        {data.due_soon > 0 && (
                          <Badge className="bg-status-warning-bg text-status-warning">{data.due_soon} due soon</Badge>
                        )}
                      </div>
                    </div>
                  </CardContent>
                </Card>
              );
            })}
          </div>

          {/* Obligations List */}
          <Card>
            <CardHeader>
              <CardTitle>Compliance Obligations</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="space-y-2">
                {obligations.data.map((obligation) => (
                  <div
                    key={obligation.id}
                    className={cn(
                      "flex items-center justify-between p-4 rounded-lg border",
                      obligation.status === 'overdue' && "bg-status-critical-bg border-status-critical/30",
                      obligation.status === 'due_soon' && "bg-status-warning-bg border-status-warning/30"
                    )}
                  >
                    <div className="flex-1">
                      <div className="flex items-center gap-2 mb-1">
                        <Link
                          href={showCompliance.url({ obligation: obligation.id })}
                          className="font-semibold text-foreground hover:text-status-info"
                        >
                          {obligation.obligation_title}
                        </Link>
                        {obligation.evidence_provided && (
                          <Badge variant="outline" className="text-status-success">
                            <CheckCircle className="w-3 h-3 mr-1" />
                            Evidence
                          </Badge>
                        )}
                      </div>
                      <div className="flex items-center gap-4 text-sm text-muted-foreground">
                        <span>{frameworks.find(f => f.value === obligation.framework)?.label}</span>
                        <span>•</span>
                        <span>Owner: {obligation.owner?.name || 'Unassigned'}</span>
                      </div>
                    </div>
                    <div className="flex items-center gap-4">
                      <div className="text-right">
                        <Badge className={cn(getStatusColor(obligation.status))}>
                          {obligation.status.replace('_', ' ')}
                        </Badge>
                        <p className="text-xs text-muted-foreground mt-1">
                          {formatDate(obligation.due_date)}
                        </p>
                      </div>
                      <Button variant="ghost" size="sm" asChild>
                        <Link href={showCompliance.url({ obligation: obligation.id })}>
                          View →
                        </Link>
                      </Button>
                    </div>
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>
      </div>
    </AppLayout>
  );
}
