import { Head, Link } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { FileText, Plus } from 'lucide-react';
import { cn } from '@/lib/utils';

interface Report {
  id: number;
  title: string;
  status: string;
  meeting: { id: number; title: string; scheduled_at: string } | null;
  author: { id: number; name: string } | null;
  created_at: string;
}

interface Props extends PageProps {
  reports: {
    data: Report[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
  };
}

export default function CeoReportsIndex({ auth, reports }: Props) {
  const getStatusColor = (status: string) => ({
    draft: 'bg-muted text-foreground',
    submitted: 'bg-status-info-bg text-status-info',
    presented: 'bg-status-success-bg text-status-success',
  }[status] || 'bg-muted text-foreground');

  const submittedCount = reports.data.filter(r => r.status === 'submitted').length;
  const presentedCount = reports.data.filter(r => r.status === 'presented').length;

  return (
    <AppLayout>
      <Head title="CEO Board Reports" />
      <PageLayout
        hero={
          <PageHero
            icon={FileText}
            title="CEO Board Reports"
            description="Monthly CEO reports to the board"
            stats={[
              { label: 'Total', value: reports.data.length },
              { label: 'Submitted', value: submittedCount },
              { label: 'Presented', value: presentedCount },
            ]}
            actions={
              <Link href="/governance/ceo-reports/create">
                <Button size="sm">
                  <Plus className="w-4 h-4 mr-2" /> New Report
                </Button>
              </Link>
            }
          />
        }
      >
        <div className="grid gap-4">
          {reports.data.map(report => (
            <Card key={report.id}>
              <CardContent className="p-4">
                <div className="flex items-center justify-between">
                  <div className="flex-1">
                    <div className="flex items-center gap-3">
                      <FileText className="w-5 h-5 text-primary" />
                      <Link href={`/governance/ceo-reports/${report.id}`} className="text-lg font-medium hover:text-status-info">
                        {report.title}
                      </Link>
                      <Badge className={cn('text-xs', getStatusColor(report.status))}>
                        {report.status}
                      </Badge>
                    </div>
                    <div className="flex items-center gap-4 mt-2 text-sm text-muted-foreground">
                      {report.meeting && <span>Meeting: {report.meeting.title}</span>}
                      {report.author && <span>By: {report.author.name}</span>}
                      <span>{new Date(report.created_at).toLocaleDateString('en-NZ')}</span>
                    </div>
                  </div>
                </div>
              </CardContent>
            </Card>
          ))}
          {reports.data.length === 0 && (
            <Card><CardContent className="p-8 text-center text-muted-foreground">No CEO reports yet.</CardContent></Card>
          )}
        </div>
      </PageLayout>
    </AppLayout>
  );
}
