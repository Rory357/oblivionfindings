import { useEffect, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { data as dashboardData } from '@/routes/governance/dashboard';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import {
  AlertOctagon,
  BookOpen,
  Calendar,
  ClipboardList,
  FileText,
  FolderOpen,
  HeartPulse,
  Landmark,
  LayoutGrid,
  Shield,
  Star,
  Users,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import axios from 'axios';

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

interface CardMetric {
  label: string;
  value: string;
  tone: 'default' | 'warning' | 'critical' | 'muted';
}

interface CockpitCard {
  key: string;
  title: string;
  description: string;
  status: string;
  source: string;
  freshness: {
    status: string;
    at: string | null;
    label: string;
  };
  metrics: CardMetric[];
  highlights: string[];
  href: string;
}

interface CockpitSection {
  key: string;
  title: string;
  description: string;
  cards: CockpitCard[];
}

interface CockpitAction {
  label: string;
  href: string;
  description: string;
}

interface DashboardPayload {
  snapshot_id: number | null;
  workflow: WorkflowPayload;
  cockpit: {
    period_label: string;
    sections: CockpitSection[];
    role_actions: CockpitAction[];
  };
}

type Props = PageProps & {
  isBoardMember: boolean;
  boardRole?: string;
};

const statusStyles: Record<string, string> = {
  good: 'bg-status-success-bg text-status-success border-status-success/30',
  done: 'bg-status-success-bg text-status-success border-status-success/30',
  warning: 'bg-status-warning-bg text-status-warning border-status-warning/30',
  todo: 'bg-status-warning-bg text-status-warning border-status-warning/30',
  in_progress: 'bg-status-info-bg text-status-info border-status-info/30',
  critical: 'bg-status-critical-bg text-status-critical border-status-critical/30',
  blocked: 'bg-status-critical-bg text-status-critical border-status-critical/30',
  unknown: 'bg-muted text-foreground border-border',
  fresh: 'bg-status-success-bg text-status-success border-status-success/30',
  stable: 'bg-status-info-bg text-status-info border-status-info/30',
  stale: 'bg-status-warning-bg text-status-warning border-status-warning/30',
};

const metricToneStyles: Record<string, string> = {
  default: 'text-foreground',
  warning: 'text-status-warning',
  critical: 'text-status-critical',
  muted: 'text-muted-foreground',
};

const workflowPriorityStyles: Record<string, string> = {
  critical: 'bg-status-critical-bg text-status-critical border-status-critical/30',
  high: 'bg-status-warning-bg text-status-warning border-status-warning/30',
  medium: 'bg-status-info-bg text-status-info border-status-info/30',
  low: 'bg-muted text-foreground border-border',
};

const workflowStatusStyles: Record<string, string> = {
  overdue: 'bg-status-critical-bg text-status-critical border-status-critical/30',
  due_soon: 'bg-status-warning-bg text-status-warning border-status-warning/30',
  pending: 'bg-muted text-foreground border-border',
};

const cardIcon = (key: string) => {
  switch (key) {
    case 'meeting_readiness':
    case 'compliance_calendar':
      return <Calendar className="h-5 w-5 text-status-info" />;
    case 'follow_through':
      return <ClipboardList className="h-5 w-5 text-status-warning" />;
    case 'decisions_required':
      return <AlertOctagon className="h-5 w-5 text-status-warning" />;
    case 'client_safety':
    case 'privacy_data':
    case 'hs_backbone':
      return <Shield className="h-5 w-5 text-status-success" />;
    case 'workforce':
      return <Users className="h-5 w-5 text-primary" />;
    default:
      return <LayoutGrid className="h-5 w-5 text-muted-foreground" />;
  }
};

const formatLabel = (value: string) => value.replace(/_/g, ' ');
const toDuskKey = (value: string) => value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');

export default function GovernanceDashboard({ auth, isBoardMember, boardRole }: Props) {
  const [period, setPeriod] = useState('month');
  const [payload, setPayload] = useState<DashboardPayload | null>(null);
  const [loading, setLoading] = useState(true);

  const fetchData = async () => {
    setLoading(true);

    try {
      const response = await axios.get(dashboardData.url(), { params: { period } });
      setPayload(response.data);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    const loadDashboard = async () => {
      setLoading(true);

      try {
        const response = await axios.get(dashboardData.url(), { params: { period } });
        setPayload(response.data);
      } finally {
        setLoading(false);
      }
    };

    void loadDashboard();
  }, [period]);

  const workflow = payload?.workflow;
  const cockpit = payload?.cockpit;

  return (
    <AppLayout user={auth.user} breadcrumbs={[{ title: 'Governance', href: '/governance/dashboard' }]}>
      <Head title="Governance Dashboard" />

      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="mb-8 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
          <div className="space-y-2">
            <p className="text-xs font-semibold uppercase tracking-[0.2em] text-muted-foreground">Governance Dashboard</p>
            <div className="flex flex-wrap items-center gap-3">
              <h1 className="text-3xl font-bold text-foreground" dusk="governance-cockpit-heading">Executive & Board Cockpit</h1>
              {isBoardMember && boardRole && (
                <Badge className="bg-status-info-bg text-status-info border-status-info/30">
                  {formatLabel(boardRole)}
                </Badge>
              )}
            </div>
            <p className="max-w-3xl text-sm text-muted-foreground">
              A single governance map for meetings, plans, risk, compliance, workforce, finance, safety, privacy, and operational control.
            </p>
            {cockpit?.period_label && (
              <p className="text-xs uppercase tracking-wide text-muted-foreground">{cockpit.period_label}</p>
            )}
          </div>

          <div className="flex items-center gap-3">
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
              {loading ? 'Refreshing...' : 'Refresh'}
            </Button>
          </div>
        </div>

        <div className="mb-6 grid gap-6 lg:grid-cols-[2fr,1fr]">
          <Card>
            <CardHeader className="pb-3">
              <CardTitle>Workflow Center</CardTitle>
              <CardDescription>Prioritized actions across meetings, decisions, risk, compliance, budgets, and follow-through.</CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="flex flex-wrap gap-2">
                <Badge variant="outline">{workflow?.summary.total ?? 0} open actions</Badge>
                <Badge className="bg-status-critical-bg text-status-critical border-status-critical/30">{workflow?.summary.critical ?? 0} critical</Badge>
                <Badge className="bg-status-warning-bg text-status-warning border-status-warning/30">{workflow?.summary.overdue ?? 0} overdue</Badge>
              </div>

              {workflow?.actions.length ? (
                workflow.actions.slice(0, 8).map((action) => (
                  <div key={action.id} className="flex flex-col gap-3 rounded-lg border border-border p-4 lg:flex-row lg:justify-between">
                    <div className="space-y-1">
                      <div className="flex flex-wrap items-center gap-2">
                        <Badge variant="outline">{action.area}</Badge>
                        <Badge className={workflowPriorityStyles[action.priority]}>{action.priority}</Badge>
                        <Badge className={workflowStatusStyles[action.status]}>{formatLabel(action.status)}</Badge>
                        {action.due_date && <span className="text-xs text-muted-foreground">Due {action.due_date}</span>}
                      </div>
                      <p className="font-medium text-foreground">{action.title}</p>
                      <p className="text-sm text-muted-foreground">{action.detail}</p>
                      {action.owner && <p className="text-xs text-muted-foreground">Owner: {action.owner}</p>}
                    </div>
                    <Button size="sm" variant="outline" asChild>
                      <a href={action.action_url}>{action.action_label}</a>
                    </Button>
                  </div>
                ))
              ) : (
                <p className="text-sm text-muted-foreground">No open workflow blockers right now.</p>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-3">
              <CardTitle>Role Actions</CardTitle>
              <CardDescription>Fast paths into the governance work you’re expected to act on.</CardDescription>
            </CardHeader>
            <CardContent className="space-y-3">
              {cockpit?.role_actions?.length ? (
                cockpit.role_actions.map((action) => (
                  <Link
                    key={action.href}
                    href={action.href}
                    dusk={`role-action-${toDuskKey(action.label)}`}
                    className="block rounded-lg border border-border p-3 transition hover:border-border hover:bg-muted"
                  >
                    <p className="font-medium text-foreground">{action.label}</p>
                    <p className="text-sm text-muted-foreground">{action.description}</p>
                  </Link>
                ))
              ) : (
                <p className="text-sm text-muted-foreground">No role-specific actions are available.</p>
              )}
            </CardContent>
          </Card>
        </div>

        <div className="space-y-8">
          {cockpit?.sections.map((section) => (
            <section key={section.key} className="space-y-4">
              <div className="space-y-1">
                <h2 className="text-xl font-semibold text-foreground">{section.title}</h2>
                <p className="text-sm text-muted-foreground">{section.description}</p>
              </div>

              <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                {section.cards.map((card) => (
                  <Card key={card.key} className="h-full">
                    <CardHeader className="space-y-3 pb-3">
                      <div className="flex items-start justify-between gap-3">
                        <div className="flex items-center gap-3">
                          <div className="rounded-lg bg-muted p-2">{cardIcon(card.key)}</div>
                          <div>
                            <CardTitle className="text-lg">{card.title}</CardTitle>
                            <CardDescription>{card.description}</CardDescription>
                          </div>
                        </div>
                        <Badge className={statusStyles[card.status] ?? statusStyles.unknown}>{formatLabel(card.status)}</Badge>
                      </div>
                      <div className="flex flex-wrap gap-2 text-xs">
                        <Badge variant="outline">{card.source}</Badge>
                        <Badge className={statusStyles[card.freshness.status] ?? statusStyles.unknown}>{card.freshness.label}</Badge>
                      </div>
                    </CardHeader>
                    <CardContent className="space-y-4">
                      <div className="grid grid-cols-2 gap-3">
                        {card.metrics.map((metric) => (
                          <div key={`${card.key}-${metric.label}`} className="rounded-lg bg-muted p-3">
                            <p className="text-xs uppercase tracking-wide text-muted-foreground">{metric.label}</p>
                            <p className={cn('mt-1 text-lg font-semibold', metricToneStyles[metric.tone] ?? metricToneStyles.default)}>
                              {metric.value}
                            </p>
                          </div>
                        ))}
                      </div>

                      {card.highlights.length > 0 && (
                        <div className="space-y-2">
                          <p className="text-xs uppercase tracking-wide text-muted-foreground">Highlights</p>
                          <div className="space-y-2">
                            {card.highlights.slice(0, 3).map((highlight) => (
                              <p key={highlight} className="rounded-lg border border-border px-3 py-2 text-sm text-foreground">
                                {highlight}
                              </p>
                            ))}
                          </div>
                        </div>
                      )}

                      <Button variant="ghost" size="sm" className="w-full justify-between" asChild>
                        <a href={card.href} dusk={`cockpit-open-${card.key}`}>Open {card.title}</a>
                      </Button>
                    </CardContent>
                  </Card>
                ))}
              </div>
            </section>
          ))}
        </div>

        <div className="mt-10">
          <h2 className="mb-4 text-lg font-semibold text-foreground">Governance Modules</h2>
          <div className="grid grid-cols-2 gap-3 md:grid-cols-4 lg:grid-cols-7">
            {[
              { label: 'Policies', href: '/governance/policies', icon: <BookOpen className="h-5 w-5" />, color: 'text-status-info bg-status-info-bg' },
              { label: 'CEO Reports', href: '/governance/ceo-reports', icon: <FileText className="h-5 w-5" />, color: 'text-primary bg-primary/10' },
              { label: 'Interests', href: '/governance/interests/mine', icon: <ClipboardList className="h-5 w-5" />, color: 'text-primary bg-primary/10' },
              { label: 'Evaluations', href: '/governance/evaluations', icon: <Star className="h-5 w-5" />, color: 'text-status-warning bg-status-warning-bg' },
              { label: 'Documents', href: '/governance/documents', icon: <FolderOpen className="h-5 w-5" />, color: 'text-status-success bg-status-success-bg' },
              { label: 'Clinical', href: '/governance/clinical', icon: <HeartPulse className="h-5 w-5" />, color: 'text-status-critical bg-status-critical-bg' },
              { label: 'Te Tiriti', href: '/governance/te-tiriti', icon: <Landmark className="h-5 w-5" />, color: 'text-status-info bg-status-info-bg' },
            ].map((tile) => (
              <Link
                key={tile.href}
                href={tile.href}
                className="flex flex-col items-center gap-2 rounded-lg border border-border p-4 text-center transition hover:border-border hover:bg-muted"
              >
                <div className={cn('rounded-lg p-2', tile.color)}>{tile.icon}</div>
                <span className="text-sm font-medium text-foreground">{tile.label}</span>
              </Link>
            ))}
          </div>
        </div>
      </div>
    </AppLayout>
  );
}
