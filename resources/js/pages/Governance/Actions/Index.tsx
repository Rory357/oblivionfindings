import { Head, Link } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { show as showAction } from '@/routes/governance/actions';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { AlertTriangle, Clock, User } from 'lucide-react';
import { cn } from '@/lib/utils';

interface ActionItem {
  id: number;
  action_reference: string;
  description: string;
  due_date: string;
  status: string;
  priority: string;
  assigned_to: { name: string };
  source_type: string;
}

interface Props extends PageProps {
  items: {
    data: ActionItem[];
  };
  summary: {
    total_open: number;
    overdue: number;
    my_open: number;
    high_priority: number;
  };
}

export default function ActionsIndex({ auth, items, summary }: Props) {
  const getStatusColor = (status: string) => {
    return {
      open: 'bg-status-info-bg text-status-info',
      in_progress: 'bg-status-warning-bg text-status-warning',
      complete: 'bg-status-success-bg text-status-success',
      overdue: 'bg-status-critical-bg text-status-critical',
    }[status] || 'bg-muted text-foreground';
  };

  const getPriorityColor = (priority: string) => {
    return {
      low: 'bg-muted text-foreground',
      medium: 'bg-status-info-bg text-status-info',
      high: 'bg-status-warning-bg text-status-warning',
      critical: 'bg-status-critical-bg text-status-critical',
    }[priority] || 'bg-muted text-foreground';
  };

  const formatDate = (dateString: string) => {
    const date = new Date(dateString);
    const days = Math.ceil((date.getTime() - new Date().getTime()) / (1000 * 60 * 60 * 24));
    
    if (days < 0) return { text: `${Math.abs(days)} days overdue`, color: 'text-status-critical' };
    if (days === 0) return { text: 'Due today', color: 'text-status-warning' };
    return { text: `${days} days left`, color: days <= 3 ? 'text-status-warning' : 'text-muted-foreground' };
  };

  return (
    <AppLayout
      user={auth.user}
      breadcrumbs={[
        { title: 'Governance', href: '/governance/dashboard' },
        { title: 'Actions', href: '/governance/actions' },
      ]}
    >
      <Head title="Actions" />

      <div className="flex flex-col gap-6 p-4 md:p-6">
          {/* Header */}
          <div className="flex items-center justify-between mb-6">
            <div>
              <h1 className="text-3xl font-bold text-foreground">Actions</h1>
              <p className="text-muted-foreground mt-1">Track board decisions and follow-ups</p>
            </div>
          </div>

          {/* Summary */}
          <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <Card>
              <CardContent className="pt-6">
                <p className="text-sm text-muted-foreground">Open</p>
                <p className="text-3xl font-bold">{summary.total_open}</p>
              </CardContent>
            </Card>
            <Card className="border-status-critical/30">
              <CardContent className="pt-6">
                <p className="text-sm text-status-critical">Overdue</p>
                <p className="text-3xl font-bold text-status-critical">{summary.overdue}</p>
              </CardContent>
            </Card>
            <Card>
              <CardContent className="pt-6">
                <p className="text-sm text-muted-foreground">My Open</p>
                <p className="text-3xl font-bold">{summary.my_open}</p>
              </CardContent>
            </Card>
            <Card className="border-status-warning/30">
              <CardContent className="pt-6">
                <p className="text-sm text-status-warning">High Priority</p>
                <p className="text-3xl font-bold text-status-warning">{summary.high_priority}</p>
              </CardContent>
            </Card>
          </div>

          {/* List */}
          <Card>
            <CardHeader>
              <CardTitle>Action Items</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="space-y-3">
                {items.data.map((item) => {
                  const dateInfo = formatDate(item.due_date);
                  return (
                    <div
                      key={item.id}
                      className={cn(
                        "flex items-start justify-between p-4 rounded-lg border",
                        dateInfo.text.includes('overdue') && "bg-status-critical-bg border-status-critical/30"
                      )}
                    >
                      <div className="flex-1">
                        <div className="flex items-center gap-2 mb-1">
                          <span className="text-sm text-muted-foreground">{item.action_reference}</span>
                          <Badge className={cn(getPriorityColor(item.priority))}>
                            {item.priority}
                          </Badge>
                        </div>
                        <p className="font-medium">{item.description}</p>
                        <div className="flex items-center gap-4 mt-2 text-sm text-muted-foreground">
                          <span className="flex items-center gap-1">
                            <User className="w-3 h-3" />
                            {item.assigned_to.name}
                          </span>
                          <span className={cn(dateInfo.color)}>
                            {dateInfo.text}
                          </span>
                        </div>
                      </div>
                      <Button variant="ghost" size="sm" asChild>
                        <Link href={showAction.url({ action: item.id })}>View &rarr;</Link>
                      </Button>
                    </div>
                  );
                })}
              </div>
            </CardContent>
          </Card>
      </div>
    </AppLayout>
  );
}
