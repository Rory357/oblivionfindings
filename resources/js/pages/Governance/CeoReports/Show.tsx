import { Head, router } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Send } from 'lucide-react';
import { cn } from '@/lib/utils';

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

  const getStatusColor = (status: string) => ({
    draft: 'bg-gray-100 text-gray-800',
    submitted: 'bg-blue-100 text-blue-800',
    presented: 'bg-green-100 text-green-800',
  }[status] || 'bg-gray-100 text-gray-800');

  return (
    <AppLayout>
      <Head title={report.title} />
      <div className="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div className="flex items-center justify-between mb-6">
          <div>
            <div className="flex items-center gap-3">
              <h1 className="text-2xl font-bold text-gray-900">{report.title}</h1>
              <Badge className={cn('text-xs', getStatusColor(report.status))}>{report.status}</Badge>
            </div>
            <div className="flex gap-4 mt-1 text-sm text-gray-500">
              {report.author && <span>Author: {report.author.name}</span>}
              {report.meeting && <span>For: {report.meeting.title}</span>}
            </div>
          </div>
          {report.status === 'draft' && (
            <Button onClick={handleSubmit}>
              <Send className="w-4 h-4 mr-2" /> Submit to Board
            </Button>
          )}
        </div>

        <Card>
          <CardHeader><CardTitle>Executive Summary</CardTitle></CardHeader>
          <CardContent>
            <div className="prose max-w-none whitespace-pre-wrap">{report.executive_summary}</div>
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
