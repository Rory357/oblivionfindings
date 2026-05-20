import { Head, Link } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { show as showAction } from '@/routes/governance/actions';
import { PageHero, PageLayout } from '@/components/page';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { AlertTriangle, CheckSquare, Clock, User } from 'lucide-react';
import { cn } from '@/lib/utils';
import { governanceStatusColor } from '@/lib/governance-status';

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
  const getStatusColor = (status: string) => governanceStatusColor(status);

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

      <PageLayout
        hero={
          <PageHero
            icon={CheckSquare}
            title="Actions"
            description="Track board decisions and follow-ups through to completion."
            stats={[
              { label: 'Open', value: summary.total_open },
              { label: 'Overdue', value: summary.overdue },
              { label: 'My open', value: summary.my_open },
              { label: 'High priority', value: summary.high_priority },
            ]}
          />
        }
      >
          {/* List */}
          <Card dusk="actions-list-card">
            <CardHeader>
              <CardTitle>Action Items</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="space-y-3">
                {items.data.length === 0 ? (
                  <p className="text-muted-foreground text-center py-8">No actions assigned or outstanding.</p>
                ) : (
                  items.data.map((item) => {
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
                  })
                )}
              </div>
            </CardContent>
          </Card>
      </PageLayout>
    </AppLayout>
  );
}
