import { Head, Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { AlertCircle, Calendar, CheckCircle2, FileText, Plus, Search } from 'lucide-react';
import { cn } from '@/lib/utils';
import { governanceStatusColor } from '@/lib/governance-status';
import { CeoReportDialog, type MeetingOption } from './_dialogs';

interface Report {
  id: number;
  title: string;
  status: 'draft' | 'submitted' | 'presented' | string;
  meeting: { id: number; title: string; scheduled_at: string } | null;
  author: { id: number; name: string } | null;
  period_label?: string | null;
  is_overdue?: boolean;
  days_until_deadline?: number | null;
  submitted_at?: string | null;
  presented_at?: string | null;
  created_at: string;
}

interface Props extends PageProps {
  reports: {
    data: Report[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
  };
  meetings: MeetingOption[];
}

type StatusFilter = 'all' | 'draft' | 'submitted' | 'presented' | 'overdue';

function statusVerb(report: Report): string {
  switch (report.status) {
    case 'draft':
      return 'Continue draft';
    case 'submitted':
      return 'Read report';
    case 'presented':
      return 'Read report';
    default:
      return 'Read report';
  }
}

function deadlineLabel(report: Report): string | null {
  if (report.status !== 'draft') return null;
  if (report.is_overdue) return 'Overdue';
  if (report.days_until_deadline == null) return null;
  if (report.days_until_deadline <= 0) return 'Due today';
  return `Due in ${report.days_until_deadline} day${report.days_until_deadline === 1 ? '' : 's'}`;
}

function submittedLabel(report: Report): string | null {
  if (report.status === 'submitted' && report.submitted_at) {
    return `Submitted ${new Date(report.submitted_at).toLocaleDateString('en-NZ')}`;
  }
  if (report.status === 'presented' && report.presented_at) {
    return `Presented ${new Date(report.presented_at).toLocaleDateString('en-NZ')}`;
  }
  return null;
}

export default function CeoReportsIndex({ auth, reports, meetings }: Props) {
  const [newOpen, setNewOpen] = useState(false);
  const [query, setQuery] = useState('');
  const [filter, setFilter] = useState<StatusFilter>('all');

  const counts = useMemo(() => {
    const c = { total: reports.data.length, draft: 0, submitted: 0, presented: 0, overdue: 0 };
    for (const r of reports.data) {
      if (r.status === 'draft') c.draft += 1;
      else if (r.status === 'submitted') c.submitted += 1;
      else if (r.status === 'presented') c.presented += 1;
      if (r.is_overdue) c.overdue += 1;
    }
    return c;
  }, [reports.data]);

  const visible = useMemo(() => {
    return reports.data.filter((r) => {
      if (filter === 'draft' && r.status !== 'draft') return false;
      if (filter === 'submitted' && r.status !== 'submitted') return false;
      if (filter === 'presented' && r.status !== 'presented') return false;
      if (filter === 'overdue' && !r.is_overdue) return false;
      if (query) {
        const q = query.toLowerCase();
        const haystack = `${r.title} ${r.meeting?.title ?? ''} ${r.author?.name ?? ''}`.toLowerCase();
        if (!haystack.includes(q)) return false;
      }
      return true;
    });
  }, [reports.data, filter, query]);

  const getStatusColor = (status: string) => governanceStatusColor(status);

  const FILTER_CHIPS: Array<{ key: StatusFilter; label: string; count?: number; tone?: string }> = [
    { key: 'all', label: 'All', count: counts.total },
    { key: 'draft', label: 'Draft', count: counts.draft },
    { key: 'submitted', label: 'Submitted', count: counts.submitted },
    { key: 'presented', label: 'Presented', count: counts.presented },
    {
      key: 'overdue',
      label: 'Overdue',
      count: counts.overdue,
      tone: counts.overdue > 0 ? 'critical' : undefined,
    },
  ];

  return (
    <AppLayout user={auth.user}>
      <Head title="CEO Board Reports" />
      <PageLayout
        hero={
          <PageHero
            category="governance"
            icon={FileText}
            title="CEO Board Reports"
            description="Monthly CEO updates for the board — narrative, KPIs, decisions sought, and matters arising."
            stats={[
              { label: 'Total', value: counts.total },
              { label: 'Draft', value: counts.draft },
              { label: 'Submitted', value: counts.submitted },
              { label: 'Presented', value: counts.presented },
            ]}
            badges={
              counts.overdue > 0
                ? [
                    {
                      label: `${counts.overdue} overdue`,
                      tone: 'critical' as const,
                      icon: AlertCircle,
                    },
                  ]
                : undefined
            }
            actions={
              <Button onClick={() => setNewOpen(true)} dusk="new-ceo-report-button">
                <Plus className="mr-1.5 h-4 w-4" />
                New Report
              </Button>
            }
          />
        }
      >
        <CeoReportDialog
          isOpen={newOpen}
          onClose={() => setNewOpen(false)}
          meetings={meetings ?? []}
        />

        <div className="space-y-4">
          <div className="flex flex-wrap items-center gap-2">
            <div className="relative flex-1 min-w-[240px] max-w-md">
              <Search className="absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                placeholder="Search reports, meetings, authors…"
                className="pl-9"
                value={query}
                onChange={(e) => setQuery(e.target.value)}
              />
            </div>
            <div className="flex flex-wrap items-center gap-2">
              {FILTER_CHIPS.map((chip) => (
                <Button unstyled
                  key={chip.key}
                  type="button"
                  onClick={() => setFilter(chip.key)}
                  className={cn(
                    'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition',
                    filter === chip.key
                      ? 'border-primary bg-primary/10 text-primary'
                      : 'border-border text-muted-foreground hover:text-foreground',
                    chip.tone === 'critical' && filter !== chip.key && 'text-status-critical',
                  )}
                >
                  <span>{chip.label}</span>
                  {chip.count != null && (
                    <span
                      className={cn(
                        'rounded-full px-1.5 text-[10px]',
                        filter === chip.key ? 'bg-primary/20' : 'bg-muted',
                      )}
                    >
                      {chip.count}
                    </span>
                  )}
                </Button>
              ))}
            </div>
          </div>

          <div className="grid gap-3">
            {visible.map((report) => {
              const deadline = deadlineLabel(report);
              const submitted = submittedLabel(report);
              return (
                <Card key={report.id} className="transition hover:border-primary/40">
                  <CardContent className="p-4">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                      <div className="flex min-w-0 flex-1 items-start gap-3">
                        <div className="rounded-lg bg-primary/10 p-2 text-primary">
                          <FileText className="h-5 w-5" />
                        </div>
                        <div className="min-w-0 flex-1 space-y-1">
                          <div className="flex flex-wrap items-center gap-2">
                            <Link
                              href={`/governance/ceo-reports/${report.id}`}
                              className="truncate text-base font-semibold text-foreground hover:text-primary"
                            >
                              {report.title}
                            </Link>
                            <Badge className={cn('text-[10px] uppercase', getStatusColor(report.status))}>
                              {report.status}
                            </Badge>
                            {report.period_label && (
                              <Badge variant="outline" className="text-[10px]">
                                <Calendar className="mr-1 h-3 w-3" />
                                {report.period_label}
                              </Badge>
                            )}
                            {deadline && (
                              <Badge
                                className={cn(
                                  'text-[10px]',
                                  report.is_overdue
                                    ? 'border border-status-critical/30 bg-status-critical-bg text-status-critical'
                                    : 'border border-status-warning/30 bg-status-warning-bg text-status-warning',
                                )}
                              >
                                {report.is_overdue ? (
                                  <AlertCircle className="mr-1 h-3 w-3" />
                                ) : null}
                                {deadline}
                              </Badge>
                            )}
                            {submitted && (
                              <Badge variant="outline" className="text-[10px]">
                                <CheckCircle2 className="mr-1 h-3 w-3 text-status-success" />
                                {submitted}
                              </Badge>
                            )}
                          </div>
                          <div className="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                            {report.meeting && <span>For: {report.meeting.title}</span>}
                            {report.author && <span>By: {report.author.name}</span>}
                            <span>Created {new Date(report.created_at).toLocaleDateString('en-NZ')}</span>
                          </div>
                        </div>
                      </div>
                      <Button variant="outline" size="sm" asChild>
                        <Link href={`/governance/ceo-reports/${report.id}`}>{statusVerb(report)}</Link>
                      </Button>
                    </div>
                  </CardContent>
                </Card>
              );
            })}

            {visible.length === 0 && (
              <Card>
                <CardContent className="p-8 text-center text-sm text-muted-foreground">
                  No CEO reports match the current filter.
                </CardContent>
              </Card>
            )}
          </div>
        </div>
      </PageLayout>
    </AppLayout>
  );
}
