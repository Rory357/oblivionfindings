import { Head } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Progress } from '@/components/ui/progress';

interface Props extends PageProps {
  report: {
    summary: {
      total: number;
      complete: number;
      overdue: number;
      due_soon: number;
      completion_rate: number;
    };
    frameworks: Array<{
      key: string;
      title: string;
      count: number;
      items: Array<{
        id: number;
        title: string;
        code: string;
        owner: string | null;
        due_date: string | null;
        status: string;
        days_remaining: number | null;
      }>;
    }>;
  };
}

const statusStyles: Record<string, string> = {
  complete: 'bg-status-success-bg text-status-success border-status-success/30',
  overdue: 'bg-status-critical-bg text-status-critical border-status-critical/30',
  due_soon: 'bg-status-warning-bg text-status-warning border-status-warning/30',
  not_due: 'bg-status-info-bg text-status-info border-status-info/30',
};

export default function ComplianceStatus({ auth, report }: Props) {
  return (
    <AppLayout
      user={auth.user}
      breadcrumbs={[
        { title: 'Governance', href: '/governance/dashboard' },
        { title: 'Reports', href: '/governance/reports' },
        { title: 'Compliance', href: '#' },
      ]}
    >
      <Head title="Compliance Status Report" />

      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="mb-6 space-y-2">
          <h1 className="text-3xl font-bold text-foreground">Compliance Status Report</h1>
          <p className="text-sm text-muted-foreground">A framework-by-framework view of obligations due, overdue, and complete.</p>
        </div>

        <div className="mb-8 grid gap-4 md:grid-cols-2 xl:grid-cols-5">
          {[
            { label: 'Total Obligations', value: report.summary.total, tone: 'text-foreground' },
            { label: 'Complete', value: report.summary.complete, tone: 'text-status-success' },
            { label: 'Overdue', value: report.summary.overdue, tone: 'text-status-critical' },
            { label: 'Due Soon', value: report.summary.due_soon, tone: 'text-status-warning' },
            { label: 'Completion Rate', value: `${report.summary.completion_rate}%`, tone: 'text-status-info' },
          ].map((item) => (
            <Card key={item.label}>
              <CardContent className="pt-6">
                <p className="text-sm text-muted-foreground">{item.label}</p>
                <p className={`mt-2 text-3xl font-bold ${item.tone}`}>{item.value}</p>
              </CardContent>
            </Card>
          ))}
        </div>

        <Card className="mb-8">
          <CardHeader>
            <CardTitle>Overall Progress</CardTitle>
            <CardDescription>Completion rate across the governance compliance register.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            <Progress value={report.summary.completion_rate} />
            <p className="text-sm text-muted-foreground">{report.summary.completion_rate}% of tracked obligations are currently complete.</p>
          </CardContent>
        </Card>

        <div className="space-y-6">
          {report.frameworks.length ? (
            report.frameworks.map((framework) => (
              <Card key={framework.key}>
                <CardHeader>
                  <CardTitle>{framework.title}</CardTitle>
                  <CardDescription>{framework.count} obligation(s)</CardDescription>
                </CardHeader>
                <CardContent className="space-y-3">
                  {framework.items.map((item) => (
                    <div key={item.id} className="flex flex-col gap-3 rounded-lg border border-border p-4 lg:flex-row lg:items-center lg:justify-between">
                      <div className="space-y-1">
                        <div className="flex flex-wrap items-center gap-2">
                          <p className="font-medium text-foreground">{item.title}</p>
                          <Badge variant="outline">{item.code}</Badge>
                        </div>
                        <div className="flex flex-wrap gap-3 text-sm text-muted-foreground">
                          {item.owner && <span>Owner: {item.owner}</span>}
                          {item.due_date && <span>Due: {item.due_date}</span>}
                          {item.days_remaining !== null && (
                            <span>
                              {item.days_remaining < 0 ? `${Math.abs(item.days_remaining)} day(s) overdue` : `${item.days_remaining} day(s) remaining`}
                            </span>
                          )}
                        </div>
                      </div>
                      <Badge className={statusStyles[item.status] ?? 'bg-muted text-foreground border-border'}>
                        {item.status.replace(/_/g, ' ')}
                      </Badge>
                    </div>
                  ))}
                </CardContent>
              </Card>
            ))
          ) : (
            <Card>
              <CardContent className="pt-6">
                <p className="text-sm text-muted-foreground">No compliance obligations were found.</p>
              </CardContent>
            </Card>
          )}
        </div>
      </div>
    </AppLayout>
  );
}
