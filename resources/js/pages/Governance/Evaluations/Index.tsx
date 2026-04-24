import { Head, Link } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Star, Plus } from 'lucide-react';
import { cn } from '@/lib/utils';

interface Evaluation {
  id: number;
  title: string;
  evaluation_type: string;
  status: string;
  period_start: string;
  period_end: string;
  due_date: string;
  responses_count: number;
}

interface Props extends PageProps {
  evaluations: {
    data: Evaluation[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
  };
}

export default function EvaluationsIndex({ auth, evaluations }: Props) {
  const getStatusColor = (status: string) => ({
    draft: 'bg-muted text-foreground',
    active: 'bg-status-info-bg text-status-info',
    closed: 'bg-status-success-bg text-status-success',
  }[status] || 'bg-muted text-foreground');

  const getTypeLabel = (type: string) => ({
    board: 'Full Board',
    committee: 'Committee',
    chair: 'Chair',
    individual: 'Individual',
  }[type] || type);

  return (
    <AppLayout>
      <Head title="Board Evaluations" />
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div className="flex items-center justify-between mb-6">
          <div>
            <h1 className="text-2xl font-bold text-foreground">Board Evaluations</h1>
            <p className="text-muted-foreground mt-1">Board and committee performance evaluations</p>
          </div>
          <Link href="/governance/evaluations/create">
            <Button><Plus className="w-4 h-4 mr-2" /> New Evaluation</Button>
          </Link>
        </div>

        <div className="grid gap-4">
          {evaluations.data.map(evaluation => (
            <Card key={evaluation.id}>
              <CardContent className="p-4">
                <div className="flex items-center justify-between">
                  <div>
                    <div className="flex items-center gap-3">
                      <Star className="w-5 h-5 text-status-warning" />
                      <Link href={`/governance/evaluations/${evaluation.id}`} className="text-lg font-medium hover:text-status-info">
                        {evaluation.title}
                      </Link>
                      <Badge variant="outline">{getTypeLabel(evaluation.evaluation_type)}</Badge>
                      <Badge className={cn('text-xs', getStatusColor(evaluation.status))}>{evaluation.status}</Badge>
                    </div>
                    <div className="flex gap-4 mt-2 text-sm text-muted-foreground">
                      <span>Period: {new Date(evaluation.period_start).toLocaleDateString('en-NZ')} - {new Date(evaluation.period_end).toLocaleDateString('en-NZ')}</span>
                      <span>{evaluation.responses_count} response(s)</span>
                      <span>Due: {new Date(evaluation.due_date).toLocaleDateString('en-NZ')}</span>
                    </div>
                  </div>
                </div>
              </CardContent>
            </Card>
          ))}
          {evaluations.data.length === 0 && (
            <Card><CardContent className="p-8 text-center text-muted-foreground">No evaluations yet.</CardContent></Card>
          )}
        </div>
      </div>
    </AppLayout>
  );
}
