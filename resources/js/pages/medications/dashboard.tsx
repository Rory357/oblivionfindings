import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import axios from 'axios';
import DashboardWidgets from '@/components/medications/DashboardWidgets';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Link } from '@inertiajs/react';
import { AlertCircle, CheckCircle, Clock, Eye, Pill } from 'lucide-react';

interface Alert {
  id: number;
  type: string;
  type_label: string;
  severity: string;
  severity_info: { label: string; color: string; icon: string };
  message: string;
  client: { id: number; name: string } | null;
  medication: { id: number; name: string } | null;
  created_at: string;
  status: string;
}

type WidgetSeverity = 'critical' | 'warning' | 'caution' | 'info';

interface DashboardData {
  overdue_meds: {
    title: string;
    count: number;
    severity: WidgetSeverity;
    items: Array<{ id: number; client?: { id: number; name: string }; client_id?: number; message: string }>;
  };
  prn_near_limits: {
    title: string;
    count: number;
    severity: WidgetSeverity;
    items: Array<{ id: number; client?: { id: number; name: string }; client_id?: number; message: string; severity?: string }>;
  };
  controlled_discrepancies: {
    title: string;
    count: number;
    severity: WidgetSeverity;
    items: Array<{ id: number; client?: { id: number; name: string }; client_id?: number; medication?: string; difference?: number; status?: string }>;
  };
  expiring_medications: {
    title: string;
    count: number;
    severity: WidgetSeverity;
    items: Array<{ id: number; client?: { id: number; name: string }; client_id?: number; medication?: string; expiry_date?: string; days_remaining?: number }>;
  };
  high_risk_medications: {
    title: string;
    count: number;
    severity: WidgetSeverity;
    items: Array<{ id: number; client?: { id: number; name: string }; client_id?: number; medication?: string; dosage?: string }>;
  };
  todays_summary: {
    title: string;
    total_scheduled: number;
    completed: number;
    refused: number;
    missed: number;
    remaining: number;
    completion_percentage: number;
  };
}

export default function MedicationsDashboard() {
  const [widgets, setWidgets] = useState<DashboardData | null>(null);
  const [alerts, setAlerts] = useState<Alert[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    loadDashboardData();
    loadAlerts();
  }, []);

  const loadDashboardData = async () => {
    try {
      const response = await axios.get('/api/medications/dashboard/widgets');
      setWidgets(response.data);
    } catch (error) {
      console.error('Failed to load dashboard widgets:', error);
    }
  };

  const loadAlerts = async () => {
    try {
      const response = await axios.get('/api/medications/alerts');
      setAlerts(response.data.alerts);
    } catch (error) {
      console.error('Failed to load alerts:', error);
    } finally {
      setLoading(false);
    }
  };

  const acknowledgeAlert = async (alertId: number) => {
    try {
      await axios.post(`/api/medications/alerts/${alertId}/acknowledge`);
      loadAlerts();
    } catch (error) {
      console.error('Failed to acknowledge alert:', error);
    }
  };

  const getSeverityBadgeClass = (severity: string) => {
    switch (severity) {
      case 'critical':
        return 'bg-red-100 text-red-800 border-red-200';
      case 'warning':
        return 'bg-yellow-100 text-yellow-800 border-yellow-200';
      case 'caution':
        return 'bg-orange-100 text-orange-800 border-orange-200';
      default:
        return 'bg-blue-100 text-blue-800 border-blue-200';
    }
  };

  const getAlertIcon = (type: string) => {
    switch (type) {
      case 'overdue':
        return <Clock className="h-4 w-4" />;
      case 'prn_near_limit':
      case 'prn_over_limit':
        return <Pill className="h-4 w-4" />;
      case 'controlled_discrepancy':
        return <AlertCircle className="h-4 w-4" />;
      default:
        return <AlertCircle className="h-4 w-4" />;
    }
  };

  if (loading && !widgets) {
    return (
      <AppLayout breadcrumbs={[{ title: 'Medications', href: '/medications' }, { title: 'Dashboard', href: '#' }]}>
        <Head title="Medication Dashboard" />
        <div className="flex h-64 items-center justify-center">
          <div className="text-muted-foreground">Loading dashboard...</div>
        </div>
      </AppLayout>
    );
  }

  return (
    <AppLayout breadcrumbs={[{ title: 'Medications', href: '/medications' }, { title: 'Dashboard', href: '#' }]}>
      <Head title="Medication Dashboard" />

      <div className="space-y-6">
        <div>
          <h1 className="text-xl font-semibold">Medication Dashboard</h1>
          <p className="text-sm text-muted-foreground">Real-time medication management overview</p>
        </div>

        {/* Widgets */}
        {widgets && <DashboardWidgets widgets={widgets} />}

        {/* Active Alerts */}
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Active Alerts</CardTitle>
          </CardHeader>
          <CardContent>
            {alerts.length === 0 ? (
              <div className="flex items-center justify-center gap-2 py-8 text-muted-foreground">
                <CheckCircle className="h-5 w-5 text-green-500" />
                <span>No active alerts</span>
              </div>
            ) : (
              <div className="space-y-2">
                {alerts.map((alert) => (
                  <div
                    key={alert.id}
                    className="flex items-start justify-between gap-2 rounded-md border p-3 hover:bg-muted"
                  >
                    <div className="flex items-start gap-3">
                      <div className={`mt-0.5 ${getSeverityBadgeClass(alert.severity)}`}>
                        {getAlertIcon(alert.type)}
                      </div>
                      <div>
                        <div className="flex items-center gap-2">
                          <Badge variant="outline" className={getSeverityBadgeClass(alert.severity)}>
                            {alert.severity_info.label}
                          </Badge>
                          <span className="text-xs text-muted-foreground">{alert.type_label}</span>
                        </div>
                        <div className="mt-1 text-sm">{alert.message}</div>
                        <div className="mt-1 flex items-center gap-2 text-xs text-muted-foreground">
                          {alert.client && (
                            <Link
                              href={`/clients/${alert.client.id}/mar`}
                              className="font-medium hover:underline"
                            >
                              {alert.client.name}
                            </Link>
                          )}
                          <span>•</span>
                          <span>{new Date(alert.created_at).toLocaleString()}</span>
                        </div>
                      </div>
                    </div>
                    <div className="flex items-center gap-2">
                      {alert.client && (
                        <Button size="sm" variant="outline" asChild>
                          <Link href={`/clients/${alert.client.id}/mar`}>
                            <Eye className="mr-1 h-3 w-3" />
                            View MAR
                          </Link>
                        </Button>
                      )}
                      <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => acknowledgeAlert(alert.id)}
                      >
                        Acknowledge
                      </Button>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>

        {/* Quick Links */}
        <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
          <Card>
            <CardHeader>
              <CardTitle className="text-sm">Quick Actions</CardTitle>
            </CardHeader>
            <CardContent className="space-y-2">
              <Button variant="outline" className="w-full justify-start" asChild>
                <Link href="/medications">
                  <Pill className="mr-2 h-4 w-4" />
                  Medications List
                </Link>
              </Button>
              <Button variant="outline" className="w-full justify-start" asChild>
                <Link href="/reports/medications">
                  <Clock className="mr-2 h-4 w-4" />
                  Reports
                </Link>
              </Button>
            </CardContent>
          </Card>
        </div>
      </div>
    </AppLayout>
  );
}
