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
  good: 'bg-green-100 text-green-800 border-green-200',
  done: 'bg-green-100 text-green-800 border-green-200',
  warning: 'bg-amber-100 text-amber-800 border-amber-200',
  todo: 'bg-amber-100 text-amber-800 border-amber-200',
  in_progress: 'bg-blue-100 text-blue-800 border-blue-200',
  critical: 'bg-red-100 text-red-800 border-red-200',
  blocked: 'bg-red-100 text-red-800 border-red-200',
  unknown: 'bg-slate-100 text-slate-800 border-slate-200',
  fresh: 'bg-emerald-100 text-emerald-800 border-emerald-200',
  stable: 'bg-sky-100 text-sky-800 border-sky-200',
  stale: 'bg-orange-100 text-orange-800 border-orange-200',
};

const metricToneStyles: Record<string, string> = {
  default: 'text-slate-900',
  warning: 'text-amber-700',
  critical: 'text-red-700',
  muted: 'text-slate-500',
};

const workflowPriorityStyles: Record<string, string> = {
  critical: 'bg-red-100 text-red-800 border-red-200',
  high: 'bg-orange-100 text-orange-800 border-orange-200',
  medium: 'bg-blue-100 text-blue-800 border-blue-200',
  low: 'bg-slate-100 text-slate-800 border-slate-200',
};

const workflowStatusStyles: Record<string, string> = {
  overdue: 'bg-red-100 text-red-800 border-red-200',
  due_soon: 'bg-amber-100 text-amber-800 border-amber-200',
  pending: 'bg-slate-100 text-slate-800 border-slate-200',
};

const cardIcon = (key: string) => {
  switch (key) {
    case 'meeting_readiness':
    case 'compliance_calendar':
      return <Calendar className="h-5 w-5 text-sky-600" />;
    case 'follow_through':
      return <ClipboardList className="h-5 w-5 text-orange-600" />;
    case 'decisions_required':
      return <AlertOctagon className="h-5 w-5 text-amber-600" />;
    case 'client_safety':
    case 'privacy_data':
    case 'hs_backbone':
      return <Shield className="h-5 w-5 text-emerald-600" />;
    case 'workforce':
      return <Users className="h-5 w-5 text-indigo-600" />;
    default:
      return <LayoutGrid className="h-5 w-5 text-slate-600" />;
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
            <p className="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Governance Dashboard</p>
            <div className="flex flex-wrap items-center gap-3">
              <h1 className="text-3xl font-bold text-slate-900" dusk="governance-cockpit-heading">Executive & Board Cockpit</h1>
              {isBoardMember && boardRole && (
                <Badge className="bg-blue-100 text-blue-800 border-blue-200">
                  {formatLabel(boardRole)}
                </Badge>
              )}
            </div>
            <p className="max-w-3xl text-sm text-slate-600">
              A single governance map for meetings, plans, risk, compliance, workforce, finance, safety, privacy, and operational control.
            </p>
            {cockpit?.period_label && (
              <p className="text-xs uppercase tracking-wide text-slate-500">{cockpit.period_label}</p>
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
                <Badge className="bg-red-100 text-red-800 border-red-200">{workflow?.summary.critical ?? 0} critical</Badge>
                <Badge className="bg-amber-100 text-amber-800 border-amber-200">{workflow?.summary.overdue ?? 0} overdue</Badge>
              </div>

              {workflow?.actions.length ? (
                workflow.actions.slice(0, 8).map((action) => (
                  <div key={action.id} className="flex flex-col gap-3 rounded-lg border border-slate-200 p-4 lg:flex-row lg:justify-between">
                    <div className="space-y-1">
                      <div className="flex flex-wrap items-center gap-2">
                        <Badge variant="outline">{action.area}</Badge>
                        <Badge className={workflowPriorityStyles[action.priority]}>{action.priority}</Badge>
                        <Badge className={workflowStatusStyles[action.status]}>{formatLabel(action.status)}</Badge>
                        {action.due_date && <span className="text-xs text-slate-500">Due {action.due_date}</span>}
                      </div>
                      <p className="font-medium text-slate-900">{action.title}</p>
                      <p className="text-sm text-slate-600">{action.detail}</p>
                      {action.owner && <p className="text-xs text-slate-500">Owner: {action.owner}</p>}
                    </div>
                    <Button size="sm" variant="outline" asChild>
                      <a href={action.action_url}>{action.action_label}</a>
                    </Button>
                  </div>
                ))
              ) : (
                <p className="text-sm text-slate-500">No open workflow blockers right now.</p>
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
                    className="block rounded-lg border border-slate-200 p-3 transition hover:border-slate-300 hover:bg-slate-50"
                  >
                    <p className="font-medium text-slate-900">{action.label}</p>
                    <p className="text-sm text-slate-600">{action.description}</p>
                  </Link>
                ))
              ) : (
                <p className="text-sm text-slate-500">No role-specific actions are available.</p>
              )}
            </CardContent>
          </Card>
        </div>

        <div className="space-y-8">
          {cockpit?.sections.map((section) => (
            <section key={section.key} className="space-y-4">
              <div className="space-y-1">
                <h2 className="text-xl font-semibold text-slate-900">{section.title}</h2>
                <p className="text-sm text-slate-600">{section.description}</p>
              </div>

              <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                {section.cards.map((card) => (
                  <Card key={card.key} className="h-full">
                    <CardHeader className="space-y-3 pb-3">
                      <div className="flex items-start justify-between gap-3">
                        <div className="flex items-center gap-3">
                          <div className="rounded-lg bg-slate-100 p-2">{cardIcon(card.key)}</div>
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
                          <div key={`${card.key}-${metric.label}`} className="rounded-lg bg-slate-50 p-3">
                            <p className="text-xs uppercase tracking-wide text-slate-500">{metric.label}</p>
                            <p className={cn('mt-1 text-lg font-semibold', metricToneStyles[metric.tone] ?? metricToneStyles.default)}>
                              {metric.value}
                            </p>
                          </div>
                        ))}
                      </div>

                      {card.highlights.length > 0 && (
                        <div className="space-y-2">
                          <p className="text-xs uppercase tracking-wide text-slate-500">Highlights</p>
                          <div className="space-y-2">
                            {card.highlights.slice(0, 3).map((highlight) => (
                              <p key={highlight} className="rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-700">
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
          <h2 className="mb-4 text-lg font-semibold text-slate-900">Governance Modules</h2>
          <div className="grid grid-cols-2 gap-3 md:grid-cols-4 lg:grid-cols-7">
            {[
              { label: 'Policies', href: '/governance/policies', icon: <BookOpen className="h-5 w-5" />, color: 'text-blue-600 bg-blue-50' },
              { label: 'CEO Reports', href: '/governance/ceo-reports', icon: <FileText className="h-5 w-5" />, color: 'text-indigo-600 bg-indigo-50' },
              { label: 'Interests', href: '/governance/interests/mine', icon: <ClipboardList className="h-5 w-5" />, color: 'text-purple-600 bg-purple-50' },
              { label: 'Evaluations', href: '/governance/evaluations', icon: <Star className="h-5 w-5" />, color: 'text-amber-600 bg-amber-50' },
              { label: 'Documents', href: '/governance/documents', icon: <FolderOpen className="h-5 w-5" />, color: 'text-emerald-600 bg-emerald-50' },
              { label: 'Clinical', href: '/governance/clinical', icon: <HeartPulse className="h-5 w-5" />, color: 'text-rose-600 bg-rose-50' },
              { label: 'Te Tiriti', href: '/governance/te-tiriti', icon: <Landmark className="h-5 w-5" />, color: 'text-teal-600 bg-teal-50' },
            ].map((tile) => (
              <Link
                key={tile.href}
                href={tile.href}
                className="flex flex-col items-center gap-2 rounded-lg border border-slate-200 p-4 text-center transition hover:border-slate-300 hover:bg-slate-50"
              >
                <div className={cn('rounded-lg p-2', tile.color)}>{tile.icon}</div>
                <span className="text-sm font-medium text-slate-700">{tile.label}</span>
              </Link>
            ))}
          </div>
        </div>
      </div>
    </AppLayout>
  );
}
