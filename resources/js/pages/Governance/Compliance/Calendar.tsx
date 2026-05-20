import { Head } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { governanceStatusColor } from '@/lib/governance-status';

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

  const getStatusColor = (status: string) => governanceStatusColor(status);

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

      <PageLayout
        hero={
          <PageHero
            icon={ShieldCheck}
            title="Compliance Calendar"
            description="Upcoming obligations and deadlines across all frameworks."
            stats={[
              { label: 'Events', value: events.length },
              { label: 'Overdue', value: events.filter((e) => e.status === 'overdue').length },
              { label: 'Due Soon', value: events.filter((e) => e.status === 'due_soon').length },
            ]}
          />
        }
      >
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
                          event.status === 'overdue' && "bg-status-critical-bg border-status-critical/30",
                          event.status === 'due_soon' && "bg-status-warning-bg border-status-warning/30"
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
      </PageLayout>
    </AppLayout>
  );
}
