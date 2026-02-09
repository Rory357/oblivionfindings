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

  const formatDate = (dateString: string) => {
    const date = new Date(dateString);
    const days = Math.ceil((date.getTime() - new Date().getTime()) / (1000 * 60 * 60 * 24));
    
    if (days < 0) return { text: `${Math.abs(days)} days overdue`, color: 'text-red-600' };
    if (days === 0) return { text: 'Due today', color: 'text-orange-600' };
    return { text: `${days} days left`, color: days <= 3 ? 'text-yellow-600' : 'text-gray-500' };
  };

  return (
    <AppLayout
      user={auth.user}
      breadcrumbs={[
        { title: 'Governance', href: '/governance/dashboard' },
        { title: 'Action Items', href: '/governance/actions' },
      ]}
    >
      <Head title="Action Items" />

      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          {/* Header */}
          <div className="flex items-center justify-between mb-6">
            <div>
              <h1 className="text-3xl font-bold text-gray-900">Action Items</h1>
              <p className="text-gray-500 mt-1">Track board decisions and follow-ups</p>
            </div>
          </div>

          {/* Summary */}
          <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <Card>
              <CardContent className="pt-6">
                <p className="text-sm text-gray-500">Open</p>
                <p className="text-3xl font-bold">{summary.total_open}</p>
              </CardContent>
            </Card>
            <Card className="border-red-200">
              <CardContent className="pt-6">
                <p className="text-sm text-red-600">Overdue</p>
                <p className="text-3xl font-bold text-red-600">{summary.overdue}</p>
              </CardContent>
            </Card>
            <Card>
              <CardContent className="pt-6">
                <p className="text-sm text-gray-500">My Open</p>
                <p className="text-3xl font-bold">{summary.my_open}</p>
              </CardContent>
            </Card>
            <Card className="border-orange-200">
              <CardContent className="pt-6">
                <p className="text-sm text-orange-600">High Priority</p>
                <p className="text-3xl font-bold text-orange-600">{summary.high_priority}</p>
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
                        dateInfo.text.includes('overdue') && "bg-red-50 border-red-200"
                      )}
                    >
                      <div className="flex-1">
                        <div className="flex items-center gap-2 mb-1">
                          <span className="text-sm text-gray-500">{item.action_reference}</span>
                          <Badge className={cn(getPriorityColor(item.priority))}>
                            {item.priority}
                          </Badge>
                        </div>
                        <p className="font-medium">{item.description}</p>
                        <div className="flex items-center gap-4 mt-2 text-sm text-gray-500">
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
