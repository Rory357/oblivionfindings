import { AlertCircle, AlertTriangle, Calendar, Clock, Package, Pill, ShieldAlert } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

interface WidgetItem {
  id: number;
  client?: { id: number; name: string };
  client_id?: number;
  message?: string;
  severity?: string;
  medication?: string;
  difference?: number;
  status?: string;
  expiry_date?: string;
  days_remaining?: number;
  dosage?: string;
  [key: string]: unknown;
}

interface Widget {
  title: string;
  count: number;
  severity: 'critical' | 'warning' | 'caution' | 'info';
  items: WidgetItem[];
}

interface TodaysSummary {
  title: string;
  total_scheduled: number;
  completed: number;
  refused: number;
  missed: number;
  remaining: number;
  completion_percentage: number;
}

interface Props {
  widgets: {
    overdue_meds: Widget;
    prn_near_limits: Widget;
    controlled_discrepancies: Widget;
    expiring_medications: Widget;
    high_risk_medications: Widget;
    todays_summary: TodaysSummary;
  };
}

const severityColors = {
  critical: 'border-red-300 bg-red-50 text-red-800',
  warning: 'border-yellow-300 bg-yellow-50 text-yellow-800',
  caution: 'border-orange-300 bg-orange-50 text-orange-800',
  info: 'border-blue-300 bg-blue-50 text-blue-800',
};

const severityIcons = {
  critical: AlertCircle,
  warning: AlertTriangle,
  caution: AlertTriangle,
  info: Clock,
};

function WidgetCard({ title, count, severity, items, icon: Icon }: Widget & { icon: React.ElementType }) {
  if (count === 0) {
    return (
      <Card className="border-slate-200 opacity-75">
        <CardHeader className="pb-2">
          <CardTitle className="flex items-center gap-2 text-sm font-medium text-slate-600">
            <Icon className="h-4 w-4" />
            {title}
          </CardTitle>
        </CardHeader>
        <CardContent>
          <div className="text-2xl font-bold text-slate-400">0</div>
          <div className="text-xs text-slate-400">No items</div>
        </CardContent>
      </Card>
    );
  }

  return (
    <Card className={`${severityColors[severity]} border-2`}>
      <CardHeader className="pb-2">
        <CardTitle className="flex items-center justify-between text-sm font-medium">
          <div className="flex items-center gap-2">
            <Icon className="h-4 w-4" />
            {title}
          </div>
          <span className="text-lg font-bold">{count}</span>
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-2">
        {items.slice(0, 3).map((item) => (
          <div key={item.id} className="text-xs">
            {item.client ? (
              <Link
                href={`/clients/${item.client_id}/mar`}
                className="font-medium hover:underline"
              >
                {item.client.name}
              </Link>
            ) : item.client_id ? (
              <Link
                href={`/clients/${item.client_id}/mar`}
                className="font-medium hover:underline"
              >
                Client #{item.client_id}
              </Link>
            ) : null}
            <div className="mt-0.5 truncate">
              {item.message ?? item.medication ?? `Item #${item.id}`}
            </div>
          </div>
        ))}
        {items.length > 3 && (
          <div className="text-xs text-slate-500">
            +{items.length - 3} more...
          </div>
        )}
      </CardContent>
    </Card>
  );
}

function TodaySummaryCard({ summary }: { summary: TodaysSummary }) {
  const { total_scheduled, completed, refused, missed, remaining, completion_percentage } = summary;

  return (
    <Card className="border-blue-200 bg-blue-50">
      <CardHeader className="pb-2">
        <CardTitle className="flex items-center gap-2 text-sm font-medium text-blue-800">
          <Calendar className="h-4 w-4" />
          {summary.title}
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-3">
        <div className="grid grid-cols-2 gap-2">
          <div className="rounded bg-white p-2 text-center">
            <div className="text-xs text-slate-500">Scheduled</div>
            <div className="text-lg font-bold text-slate-700">{total_scheduled}</div>
          </div>
          <div className="rounded bg-white p-2 text-center">
            <div className="text-xs text-slate-500">Completed</div>
            <div className="text-lg font-bold text-green-600">{completed}</div>
          </div>
          <div className="rounded bg-white p-2 text-center">
            <div className="text-xs text-slate-500">Remaining</div>
            <div className="text-lg font-bold text-orange-600">{remaining}</div>
          </div>
          <div className="rounded bg-white p-2 text-center">
            <div className="text-xs text-slate-500">Completion</div>
            <div className="text-lg font-bold text-blue-600">{completion_percentage}%</div>
          </div>
        </div>
        {(refused > 0 || missed > 0) && (
          <div className="flex gap-2 text-xs">
            {refused > 0 && (
              <span className="text-orange-600">Refused: {refused}</span>
            )}
            {missed > 0 && (
              <span className="text-red-600">Missed: {missed}</span>
            )}
          </div>
        )}
      </CardContent>
    </Card>
  );
}

export default function DashboardWidgets({ widgets }: Props) {
  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
      <TodaySummaryCard summary={widgets.todays_summary} />
      <WidgetCard {...widgets.overdue_meds} icon={Clock} />
      <WidgetCard {...widgets.prn_near_limits} icon={Pill} />
      <WidgetCard {...widgets.controlled_discrepancies} icon={ShieldAlert} />
      <WidgetCard {...widgets.expiring_medications} icon={Calendar} />
      <WidgetCard {...widgets.high_risk_medications} icon={AlertTriangle} />
    </div>
  );
}
