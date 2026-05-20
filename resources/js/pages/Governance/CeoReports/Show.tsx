import { Head, router } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Send } from 'lucide-react';
import { PageHero, PageLayout } from '@/components/page';
import { cn } from '@/lib/utils';
import { governanceStatusColor } from '@/lib/governance-status';

interface Report {
  id: number;
  title: string;
  status: string;
  executive_summary: string;
  operational_highlights: any[] | null;
  financial_summary: any[] | null;
  risk_updates: any[] | null;
  compliance_updates: any[] | null;
  strategic_progress: any[] | null;
  recommendations: any[] | null;
  meeting: { id: number; title: string; scheduled_at: string } | null;
  author: { id: number; name: string } | null;
  submitted_at: string | null;
  created_at: string;
}

interface Props extends PageProps {
  report: Report;
}

export default function CeoReportShow({ auth, report }: Props) {
  const handleSubmit = () => {
    router.post(`/governance/ceo-reports/${report.id}/submit`);
  };

  const getStatusColor = (status: string) => governanceStatusColor(status);

  return (
    <AppLayout>
      <Head title={report.title} />
      <PageLayout
        hero={
          <PageHero
            variant="compact"
            backHref="/governance/ceo-reports"
            title={
              <span className="flex flex-wrap items-center gap-3">
                {report.title}
                <Badge className={cn('text-xs', getStatusColor(report.status))}>{report.status}</Badge>
              </span>
            }
            description={
              <div className="flex gap-4 text-sm">
                {report.author && <span>Author: {report.author.name}</span>}
                {report.meeting && <span>For: {report.meeting.title}</span>}
              </div>
            }
            actions={
              report.status === 'draft' && (
                <Button onClick={handleSubmit}>
                  <Send className="w-4 h-4 mr-2" /> Submit to Board
                </Button>
              )
            }
          />
        }
      >
        <Card>
          <CardHeader><CardTitle>Executive Summary</CardTitle></CardHeader>
          <CardContent>
            <div className="prose max-w-none whitespace-pre-wrap">{report.executive_summary}</div>
          </CardContent>
        </Card>
      </PageLayout>
    </AppLayout>
  );
}
