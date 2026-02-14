import { useEffect, useState } from 'react';
import { Head } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { data as dashboardData } from '@/routes/governance/dashboard';
import { show as showResolution } from '@/routes/governance/resolutions';
import { index as risksIndex } from '@/routes/governance/risks';
import { calendar as complianceCalendar } from '@/routes/governance/compliance';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Calendar, Shield, Users, Vote, AlertOctagon } from 'lucide-react';
import { cn } from '@/lib/utils';
import axios from 'axios';

interface DashboardWidget {
  top_risks?: {
    critical: number;
    high: number;
    medium: number;
    above_appetite: number;
    items: Array<{
      id: number;
      reference: string;
      title: string;
      category: string;
      score: number;
      color: string;
    }>;
  };
  decisions_required?: {
    count: number;
    overdue: number;
    items: Array<{
      id: number;
      reference: string;
      title: string;
      deadline: string | null;
      is_overdue: boolean;
      source?: string;
    }>;
  };
  client_safety?: {
    high_risk_clients: number;
    serious_incidents_period: number;
    open_critical_incidents: number;
    status: string;
  };
  workforce?: {
    overtime_percentage: number;
    unfilled_shifts: number;
    training_compliance: number;
    status: string;
  };
  compliance_calendar?: Array<{
    id: number;
    framework: string;
    title: string;
    due_date: string;
    days_remaining: number;
    status: string;
  }>;
}

interface WorkflowAction {
  id: string;
  area: string;
  title: string;
  detail: string;
  priority: 'critical' | 'high' | 'medium' | 'low';
  status: 'overdue' | 'due_soon' | 'pending';
  due_date: string | null;
  action_label: string;
  action_url: string;
  owner: string | null;
}

interface WorkflowPayload {
  summary: {
    total: number;
    critical: number;
    overdue: number;
  };
  actions: WorkflowAction[];
}

interface DashboardData {
  snapshot_id: number;
  period: {
    type: string;
    start: string;
    end: string;
  };
  widgets: DashboardWidget;
  workflow?: WorkflowPayload;
}

export default function GovernanceDashboard({ auth, isBoardMember, boardRole }: PageProps & { isBoardMember: boolean; boardRole?: string }) {
  const [period, setPeriod] = useState('month');
  const [data, setData] = useState<DashboardData | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    fetchData();
  }, [period]);

  const fetchData = async () => {
    setLoading(true);
    try {
      const response = await axios.get(dashboardData.url(), {
        params: { period },
      });
      setData(response.data);
    } catch (error) {
      console.error('Failed to fetch dashboard data:', error);
    } finally {
      setLoading(false);
    }
  };

  const widgets = data?.widgets || {};
  const workflow = data?.workflow;

  const getStatusColor = (status: string) => {
    return {
      critical: 'bg-red-100 text-red-800 border-red-200',
      warning: 'bg-yellow-100 text-yellow-800 border-yellow-200',
      good: 'bg-green-100 text-green-800 border-green-200',
      unknown: 'bg-gray-100 text-gray-800 border-gray-200',
    }[status] || 'bg-gray-100 text-gray-800';
  };

  const getWorkflowStatusColor = (status: WorkflowAction['status']) => {
    return {
      overdue: 'bg-red-100 text-red-800 border-red-200',
      due_soon: 'bg-amber-100 text-amber-800 border-amber-200',
      pending: 'bg-slate-100 text-slate-800 border-slate-200',
    }[status];
  };

  const getWorkflowPriorityColor = (priority: WorkflowAction['priority']) => {
    return {
      critical: 'bg-red-100 text-red-800 border-red-200',
      high: 'bg-orange-100 text-orange-800 border-orange-200',
      medium: 'bg-blue-100 text-blue-800 border-blue-200',
      low: 'bg-gray-100 text-gray-700 border-gray-200',
    }[priority];
  };

  const decisionHref = (decision: DashboardWidget['decisions_required']['items'][number]) => {
    if (decision.source === 'roadmap_decision_request') {
      return '/roadmap/decisions';
    }

    return showResolution.url({ resolution: decision.id });
  };

  const decisionActionLabel = (decision: DashboardWidget['decisions_required']['items'][number]) => {
    if (decision.source === 'roadmap_decision_request') {
      return 'Open Request';
    }

    return 'Vote Now';
  };

  return (
    <AppLayout
      user={auth.user}
      breadcrumbs={[{ title: 'Governance', href: '/governance/dashboard' }]}
    >
      <Head title="Governance Dashboard" />

      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="flex items-center justify-between mb-8">
            <div>
              <h1 className="text-3xl font-bold text-gray-900">Governance Dashboard</h1>
              <p className="text-gray-500 mt-1">
                Board oversight and decision-making center
                {isBoardMember && boardRole && (
                  <span className="ml-2 text-sm font-medium text-blue-600">
                    ({boardRole.replace('_', ' ')})
                  </span>
                )}
              </p>
            </div>
            <div className="flex items-center gap-4">
              <Select value={period} onValueChange={setPeriod}>
                <SelectTrigger className="w-40">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="today">Today</SelectItem>
                  <SelectItem value="week">This Week</SelectItem>
                  <SelectItem value="month">This Month</SelectItem>
                  <SelectItem value="year">This Year</SelectItem>
                </SelectContent>
              </Select>
              <Button variant="outline" onClick={fetchData} disabled={loading}>
                {loading ? 'Loading...' : 'Refresh'}
              </Button>
            </div>
          </div>

          <Card className="mb-6">
            <CardHeader className="pb-3">
              <CardTitle>Workflow Center</CardTitle>
              <CardDescription>Prioritized governance actions across meetings, risk, compliance, budget, and decisions.</CardDescription>
            </CardHeader>
            <CardContent>
              {workflow && workflow.actions.length > 0 ? (
                <div className="space-y-3">
                  <div className="flex flex-wrap gap-2">
                    <Badge variant="outline">{workflow.summary.total} open actions</Badge>
                    {workflow.summary.critical > 0 && (
                      <Badge className="bg-red-100 text-red-800 border-red-200">
                        {workflow.summary.critical} critical
                      </Badge>
                    )}
                    {workflow.summary.overdue > 0 && (
                      <Badge className="bg-amber-100 text-amber-800 border-amber-200">
                        {workflow.summary.overdue} overdue
                      </Badge>
                    )}
                  </div>
                  {workflow.actions.slice(0, 8).map((action) => (
                    <div
                      key={action.id}
                      className="flex flex-col gap-3 rounded-lg border border-gray-200 p-3 md:flex-row md:items-start md:justify-between"
                    >
                      <div className="space-y-1">
                        <div className="flex flex-wrap items-center gap-2">
                          <Badge variant="outline">{action.area}</Badge>
                          <Badge className={getWorkflowPriorityColor(action.priority)}>{action.priority}</Badge>
                          <Badge className={getWorkflowStatusColor(action.status)}>{action.status.replace('_', ' ')}</Badge>
                          {action.due_date && (
                            <span className="text-xs text-gray-500">Due {action.due_date}</span>
                          )}
                        </div>
                        <p className="font-medium text-gray-900">{action.title}</p>
                        <p className="text-sm text-gray-600">{action.detail}</p>
                        {action.owner && (
                          <p className="text-xs text-gray-500">Owner: {action.owner}</p>
                        )}
                      </div>
                      <div>
                        <Button size="sm" variant="outline" asChild>
                          <a href={action.action_url}>{action.action_label}</a>
                        </Button>
                      </div>
                    </div>
                  ))}
                </div>
              ) : (
                <p className="text-sm text-gray-500">No open workflow blockers. Keep monthly checks running.</p>
              )}
            </CardContent>
          </Card>

          {widgets.decisions_required && widgets.decisions_required.count > 0 && (
            <Card className="mb-6 border-orange-200 bg-orange-50">
              <CardContent className="pt-6">
                <div className="flex items-start gap-4">
                  <div className="p-3 bg-orange-100 rounded-full">
                    <Vote className="w-6 h-6 text-orange-600" />
                  </div>
                  <div className="flex-1">
                    <h3 className="text-lg font-semibold text-orange-900">
                      Decisions Required ({widgets.decisions_required.count})
                    </h3>
                    <div className="mt-3 space-y-2">
                      {(widgets.decisions_required.items || []).map((decision) => (
                        <div
                          key={`${decision.source ?? 'governance'}-${decision.id}`}
                          className="flex items-center justify-between p-3 bg-white rounded-lg border border-orange-100"
                        >
                          <div>
                            <p className="font-medium text-gray-900">{decision.title}</p>
                            <p className="text-sm text-gray-500">{decision.reference}</p>
                          </div>
                          <div className="flex items-center gap-3">
                            {decision.is_overdue && (
                              <Badge variant="destructive">Overdue</Badge>
                            )}
                            <Button size="sm" asChild>
                              <a href={decisionHref(decision)}>
                                {decisionActionLabel(decision)}
                              </a>
                            </Button>
                          </div>
                        </div>
                      ))}
                    </div>
                  </div>
                </div>
              </CardContent>
            </Card>
          )}

          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <Card>
              <CardHeader className="pb-3">
                <CardTitle className="flex items-center gap-2 text-lg">
                  <AlertOctagon className="w-5 h-5 text-red-500" />
                  Top Risks
                </CardTitle>
                <CardDescription>Current risk posture</CardDescription>
              </CardHeader>
              <CardContent>
                {widgets.top_risks ? (
                  <div className="space-y-4">
                    <div className="flex gap-2">
                      {widgets.top_risks.critical > 0 && (
                        <Badge variant="destructive" className="text-sm">
                          {widgets.top_risks.critical} Critical
                        </Badge>
                      )}
                      {widgets.top_risks.high > 0 && (
                        <Badge className="bg-orange-500 text-sm">
                          {widgets.top_risks.high} High
                        </Badge>
                      )}
                    </div>
                    <div className="space-y-2">
                      {(widgets.top_risks.items || []).slice(0, 3).map((risk) => (
                        <div key={risk.id} className="flex items-center justify-between text-sm">
                          <span className="truncate flex-1">{risk.title}</span>
                          <Badge
                            variant="outline"
                            className={cn(
                              'ml-2',
                              risk.color === 'red' && 'border-red-200 text-red-700',
                              risk.color === 'orange' && 'border-orange-200 text-orange-700',
                            )}
                          >
                            {risk.score}
                          </Badge>
                        </div>
                      ))}
                    </div>
                    <Button variant="ghost" size="sm" className="w-full" asChild>
                      <a href={risksIndex.url()}>View Risk Register →</a>
                    </Button>
                  </div>
                ) : (
                  <p className="text-gray-500 text-sm">No risk data available</p>
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader className="pb-3">
                <CardTitle className="flex items-center gap-2 text-lg">
                  <Shield className="w-5 h-5 text-blue-500" />
                  Client Safety
                </CardTitle>
                <CardDescription>Care quality indicators</CardDescription>
              </CardHeader>
              <CardContent>
                {widgets.client_safety ? (
                  <div className="space-y-4">
                    <Badge className={getStatusColor(widgets.client_safety.status)}>
                      {widgets.client_safety.status === 'good' ? 'Good' :
                       widgets.client_safety.status === 'warning' ? 'Attention Needed' : 'Critical'}
                    </Badge>
                    <div className="grid grid-cols-2 gap-4">
                      <div className="text-center p-3 bg-gray-50 rounded-lg">
                        <p className="text-2xl font-bold text-gray-900">
                          {widgets.client_safety.high_risk_clients}
                        </p>
                        <p className="text-xs text-gray-500">High Risk Clients</p>
                      </div>
                      <div className="text-center p-3 bg-gray-50 rounded-lg">
                        <p className="text-2xl font-bold text-gray-900">
                          {widgets.client_safety.serious_incidents_period}
                        </p>
                        <p className="text-xs text-gray-500">Serious Incidents</p>
                      </div>
                    </div>
                  </div>
                ) : (
                  <p className="text-gray-500 text-sm">No safety data available</p>
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader className="pb-3">
                <CardTitle className="flex items-center gap-2 text-lg">
                  <Users className="w-5 h-5 text-green-500" />
                  Workforce
                </CardTitle>
                <CardDescription>Staffing and capacity</CardDescription>
              </CardHeader>
              <CardContent>
                {widgets.workforce ? (
                  <div className="space-y-4">
                    <div className="grid grid-cols-3 gap-2">
                      <div className="text-center">
                        <p className="text-xl font-bold">{widgets.workforce.overtime_percentage}%</p>
                        <p className="text-xs text-gray-500">Overtime</p>
                      </div>
                      <div className="text-center">
                        <p className="text-xl font-bold">{widgets.workforce.unfilled_shifts}</p>
                        <p className="text-xs text-gray-500">Unfilled</p>
                      </div>
                      <div className="text-center">
                        <p className="text-xl font-bold">{widgets.workforce.training_compliance}%</p>
                        <p className="text-xs text-gray-500">Training</p>
                      </div>
                    </div>
                    <p className="text-xs text-gray-500">
                      {widgets.workforce.overtime_percentage > 10
                        ? 'High overtime levels detected'
                        : 'Workforce metrics within normal range'}
                    </p>
                  </div>
                ) : (
                  <p className="text-gray-500 text-sm">No workforce data available</p>
                )}
              </CardContent>
            </Card>

            <Card className="md:col-span-2 lg:col-span-3">
              <CardHeader className="pb-3">
                <CardTitle className="flex items-center gap-2 text-lg">
                  <Calendar className="w-5 h-5 text-purple-500" />
                  Compliance Calendar (Next 90 Days)
                </CardTitle>
              </CardHeader>
              <CardContent>
                {(widgets.compliance_calendar || []).length > 0 ? (
                  <div className="space-y-2">
                    {(widgets.compliance_calendar || []).slice(0, 5).map((item) => (
                      <div
                        key={item.id}
                        className={cn(
                          'flex items-center justify-between p-3 rounded-lg border',
                          item.days_remaining < 0 && 'bg-red-50 border-red-200',
                          item.days_remaining < 7 && item.days_remaining >= 0 && 'bg-yellow-50 border-yellow-200',
                          item.days_remaining >= 7 && 'bg-gray-50 border-gray-200',
                        )}
                      >
                        <div className="flex items-center gap-4">
                          <div className={cn(
                            'w-2 h-2 rounded-full',
                            item.days_remaining < 0 && 'bg-red-500',
                            item.days_remaining < 7 && item.days_remaining >= 0 && 'bg-yellow-500',
                            item.days_remaining >= 7 && 'bg-green-500',
                          )} />
                          <div>
                            <p className="font-medium text-gray-900">{item.title}</p>
                            <p className="text-sm text-gray-500">{item.framework}</p>
                          </div>
                        </div>
                        <div className="flex items-center gap-4">
                          <div className="text-right">
                            <p className="text-sm font-medium">
                              {item.days_remaining < 0
                                ? `${Math.abs(item.days_remaining)} days overdue`
                                : `${item.days_remaining} days remaining`
                              }
                            </p>
                            <p className="text-xs text-gray-500">Due {item.due_date}</p>
                          </div>
                        </div>
                      </div>
                    ))}
                  </div>
                ) : (
                  <p className="text-gray-500 text-sm">No upcoming compliance obligations</p>
                )}
                <Button variant="ghost" size="sm" className="mt-4 w-full" asChild>
                  <a href={complianceCalendar.url()}>View Full Calendar →</a>
                </Button>
              </CardContent>
            </Card>
          </div>
      </div>
    </AppLayout>
  );
}
