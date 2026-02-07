import { Head, Link, useForm } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Textarea } from '@/components/ui/textarea';
import { CheckCircle, Clock, AlertTriangle, User } from 'lucide-react';
import { cn } from '@/lib/utils';
import { complete as completeAction } from '@/routes/governance/actions';

interface UserRef {
  id: number;
  name: string;
  email?: string | null;
}

interface ActionItem {
  id: number;
  action_reference: string;
  description: string;
  due_date: string;
  status: string;
  priority: string;
  source_type: string | null;
  source_id: number | null;
  evidence_required: boolean;
  evidence_attachments?: string[] | null;
  completion_notes?: string | null;
  completed_at?: string | null;
  assigned_to?: UserRef | null;
  completed_by?: UserRef | null;
  created_by?: UserRef | null;
}

interface Props extends PageProps {
  action: ActionItem;
}

export default function ActionItemShow({ auth, action }: Props) {
  const { data, setData, post, processing } = useForm({
    completion_notes: '',
  });

  const canManage = auth?.can?.governance?.actions?.manage;
  const isAssignee = action.assigned_to?.id === auth.user?.id;
  const canComplete = (canManage || isAssignee) && action.status !== 'complete';

  const getStatusColor = (status: string) => {
    return {
      open: 'bg-blue-100 text-blue-800',
      in_progress: 'bg-yellow-100 text-yellow-800',
      complete: 'bg-green-100 text-green-800',
      overdue: 'bg-red-100 text-red-800',
    }[status] || 'bg-gray-100 text-gray-800';
  };

  const getPriorityColor = (priority: string) => {
    return {
      low: 'bg-gray-100 text-gray-800',
      medium: 'bg-blue-100 text-blue-800',
      high: 'bg-orange-100 text-orange-800',
      critical: 'bg-red-100 text-red-800',
    }[priority] || 'bg-gray-100 text-gray-800';
  };

  const formatDate = (dateString: string | null | undefined) => {
    if (!dateString) return 'Not set';
    const date = new Date(dateString);
    if (Number.isNaN(date.getTime())) return dateString;
    return date.toLocaleDateString('en-NZ', {
      year: 'numeric',
      month: 'long',
      day: 'numeric',
    });
  };

  const handleComplete = (e: React.FormEvent) => {
    e.preventDefault();
    post(completeAction.url({ action: action.id }));
  };

  return (
    <AppLayout
      user={auth.user}
      breadcrumbs={[
        { title: 'Governance', href: '/governance/dashboard' },
        { title: 'Action Items', href: '/governance/actions' },
        { title: action.action_reference, href: `/governance/actions/${action.id}` },
      ]}
    >
      <Head title={`Action ${action.action_reference}`} />

      <div className="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="flex items-start justify-between mb-6">
          <div>
            <div className="flex items-center gap-3 mb-2">
              <h1 className="text-3xl font-bold text-gray-900">{action.action_reference}</h1>
              <Badge className={cn(getStatusColor(action.status))}>{action.status}</Badge>
              <Badge className={cn(getPriorityColor(action.priority))}>{action.priority}</Badge>
            </div>
            <p className="text-gray-600">{action.description}</p>
          </div>
          <Button variant="outline" asChild>
            <Link href="/governance/actions">Back</Link>
          </Button>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
          <Card>
            <CardContent className="pt-6">
              <div className="flex items-center gap-2 text-sm text-gray-500">
                <Clock className="w-4 h-4" />
                Due Date
              </div>
              <p className="font-semibold">{formatDate(action.due_date)}</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="pt-6">
              <div className="flex items-center gap-2 text-sm text-gray-500">
                <User className="w-4 h-4" />
                Assigned To
              </div>
              <p className="font-semibold">{action.assigned_to?.name ?? 'Unassigned'}</p>
              {action.assigned_to?.email && (
                <p className="text-sm text-gray-500">{action.assigned_to.email}</p>
              )}
            </CardContent>
          </Card>
          <Card>
            <CardContent className="pt-6">
              <div className="flex items-center gap-2 text-sm text-gray-500">
                <AlertTriangle className="w-4 h-4" />
                Evidence
              </div>
              <p className="font-semibold">
                {action.evidence_required ? 'Required' : 'Not required'}
              </p>
            </CardContent>
          </Card>
        </div>

        <Card className="mb-6">
          <CardHeader>
            <CardTitle>Source</CardTitle>
            <CardDescription>Where this action item originated</CardDescription>
          </CardHeader>
          <CardContent>
            <p className="text-sm text-gray-600">
              {action.source_type ? `${action.source_type} #${action.source_id}` : 'Not linked'}
            </p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Completion</CardTitle>
            <CardDescription>
              {action.status === 'complete'
                ? 'This action item has been completed.'
                : 'Mark this action item as complete when finished.'}
            </CardDescription>
          </CardHeader>
          <CardContent>
            {action.status === 'complete' ? (
              <div className="space-y-2">
                <div className="flex items-center gap-2 text-green-600">
                  <CheckCircle className="w-4 h-4" />
                  Completed {formatDate(action.completed_at)}
                </div>
                {action.completed_by?.name && (
                  <p className="text-sm text-gray-600">Completed by {action.completed_by.name}</p>
                )}
                {action.completion_notes && (
                  <p className="text-sm text-gray-600">{action.completion_notes}</p>
                )}
                {action.evidence_attachments?.length ? (
                  <div className="text-sm text-gray-600">
                    <p className="font-medium">Evidence</p>
                    <ul className="list-disc list-inside">
                      {action.evidence_attachments.map((item, index) => (
                        <li key={`${item}-${index}`}>{item}</li>
                      ))}
                    </ul>
                  </div>
                ) : null}
              </div>
            ) : canComplete ? (
              <form onSubmit={handleComplete} className="space-y-4">
                <Textarea
                  placeholder="Completion notes (optional)"
                  value={data.completion_notes}
                  onChange={(e) => setData('completion_notes', e.target.value)}
                />
                <Button type="submit" disabled={processing}>Mark Complete</Button>
              </form>
            ) : (
              <p className="text-sm text-gray-500">You do not have permission to complete this action.</p>
            )}
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
