import { Head } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

interface CalendarEvent {
  id: number;
  title: string;
  date: string;
  framework: string;
  status: string;
  days_remaining: number;
  owner: string | null;
}

interface Props extends PageProps {
  events: CalendarEvent[];
}

export default function ComplianceCalendar({ auth, events }: Props) {
  // Group events by month
  const grouped = events.reduce((acc, event) => {
    const month = new Date(event.date).toLocaleDateString('en-NZ', { month: 'long', year: 'numeric' });
    if (!acc[month]) acc[month] = [];
    acc[month].push(event);
    return acc;
  }, {} as Record<string, CalendarEvent[]>);

  const getStatusColor = (status: string) => {
    return {
      complete: 'bg-green-100 text-green-800',
      not_due: 'bg-blue-100 text-blue-800',
      due_soon: 'bg-yellow-100 text-yellow-800',
      overdue: 'bg-red-100 text-red-800',
    }[status] || 'bg-muted text-foreground';
  };

  return (
    <AppLayout
      user={auth.user}
      breadcrumbs={[
        { title: 'Governance', href: '/governance/dashboard' },
        { title: 'Compliance', href: '/governance/compliance' },
        { title: 'Calendar', href: '/governance/compliance/calendar' },
      ]}
    >
      <Head title="Compliance Calendar" />

      <div className="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
          {/* Header */}
          <div className="mb-6">
            <h1 className="text-3xl font-bold text-foreground">Compliance Calendar</h1>
            <p className="text-muted-foreground mt-1">Upcoming obligations and deadlines</p>
          </div>

          {/* Calendar */}
          <div className="space-y-6">
            {Object.entries(grouped).map(([month, monthEvents]) => (
              <Card key={month}>
                <CardHeader>
                  <CardTitle>{month}</CardTitle>
                </CardHeader>
                <CardContent>
                  <div className="space-y-3">
                    {monthEvents.map((event) => (
                      <div
                        key={event.id}
                        className={cn(
                          "flex items-center justify-between p-4 rounded-lg border",
                          event.status === 'overdue' && "bg-red-50 border-red-200",
                          event.status === 'due_soon' && "bg-yellow-50 border-yellow-200"
                        )}
                      >
                        <div className="flex items-center gap-4">
                          <div className="text-center min-w-[60px]">
                            <p className="text-2xl font-bold text-foreground">
                              {new Date(event.date).getDate()}
                            </p>
                            <p className="text-xs text-muted-foreground">
                              {new Date(event.date).toLocaleDateString('en-NZ', { weekday: 'short' })}
                            </p>
                          </div>
                          <div>
                            <p className="font-semibold text-foreground">{event.title}</p>
                            <p className="text-sm text-muted-foreground">{event.framework}</p>
                            {event.owner && (
                              <p className="text-sm text-muted-foreground">Owner: {event.owner}</p>
                            )}
                          </div>
                        </div>
                        <div className="text-right">
                          <Badge className={cn(getStatusColor(event.status))}>
                            {event.status.replace('_', ' ')}
                          </Badge>
                          <p className="text-xs text-muted-foreground mt-1">
                            {event.days_remaining < 0 
                              ? `${Math.abs(event.days_remaining)} days overdue`
                              : `${event.days_remaining} days remaining`
                            }
                          </p>
                        </div>
                      </div>
                    ))}
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
      </div>
    </AppLayout>
  );
}
