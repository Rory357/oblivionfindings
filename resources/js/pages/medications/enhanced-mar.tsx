import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import { AlertCircle, AlertTriangle, Calendar, ChevronLeft, ChevronRight, Clock, Pill, Plus, ShieldAlert, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import axios from 'axios';
import RecordAdministrationDialog from '@/components/medications/RecordAdministrationDialog';
import SafetyCheckPanel, { type SafetyCheck } from '@/components/medications/SafetyCheckPanel';
import PrnHistoryPanel from '@/components/medications/PrnHistoryPanel';

interface Medication {
  id: number;
  name: string;
  dosage: string;
  route?: string;
  form?: string;
  instructions?: string;
  indication?: string;
  is_prn: boolean;
  prn_reason?: string;
  controlled_drug: boolean;
  high_risk: boolean;
  witness_required: boolean;
  prescriber?: string;
  pharmacy?: string;
  state: string;
  stock?: { on_hand: number; unit: string; reorder_level?: number } | null;
}

interface ScheduledRow {
  id?: number;
  client_medication_id: number;
  medication: Medication;
  scheduled_for: string;
  scheduled_time: string;
  schedule_state: string;
  schedule_state_label: { label: string; color: string; icon: string };
  window_start: string;
  window_end: string;
  can_record: boolean;
  is_overdue: boolean;
  requires_witness: boolean;
  administration?: {
    id: number;
    status: string;
    status_label: { label: string; color: string };
    dose_given?: string;
    reason?: string;
    notes?: string;
    administered_at?: string;
    scheduled_for?: string;
    administered_by?: string;
    witnessed_by?: string;
    is_correction?: boolean;
    correction_reason?: string;
  } | null;
  safety_check: SafetyCheck | null;
  is_correction?: boolean;
}

interface PrnRow {
  client_medication_id: number;
  medication: Medication;
  is_prn: true;
  prn_reason?: string;
  max_per_day?: string;
  count_24h: number;
  remaining_today?: number;
  prn_history: Array<{
    id: number;
    administered_at: string;
    dose_given?: string;
    reason?: string;
    administered_by?: string;
  }>;
  is_near_limit: boolean;
  is_over_limit: boolean;
  is_blocked: boolean;
  can_record: boolean;
  requires_witness: boolean;
  safety_check: SafetyCheck | null;
}

interface HistoryItem {
  id: number;
  medication_name: string;
  status: string;
  status_label: { label: string; color: string };
  dose_given?: string;
  reason?: string;
  notes?: string;
  administered_at?: string;
  scheduled_for?: string;
  administered_by?: string;
  witnessed_by?: string;
  is_correction?: boolean;
  correction_reason?: string;
  controlled_drug?: boolean;
}

interface MarData {
  date: string;
  is_today: boolean;
  scheduled: ScheduledRow[];
  prn: PrnRow[];
  history: HistoryItem[];
  upcoming: ScheduledRow[];
  stats: {
    scheduled: { total: number; completed: number; due: number; late: number; missed: number; upcoming: number };
    prn: { total_medications: number; near_limit: number; over_limit: number };
    controlled_count: number;
    history_count: number;
    completion_percentage: number;
  };
  allergies: Array<{ id: number; allergen: string; reaction?: string; severity?: string; is_severe: boolean }>;
  settings: { window_before_minutes: number; window_after_minutes: number; due_soon_minutes: number };
  can: { record: boolean; correct: boolean; witness: boolean };
  alerts?: Array<{ id: number; alert_type: string; severity: string; message: string; created_at: string }>;
  controlled_discrepancies?: Array<{ id: number; medication_name?: string; difference?: number; reason?: string; reported_at?: string }>;
}

interface Props {
  client: { id: number; first_name: string; last_name: string };
  initialDate: string;
  witnesses: Array<{ id: number; name: string }>;
  userId: number;
  [key: string]: unknown;
}

const scheduleStateColors: Record<string, string> = {
  upcoming: 'bg-slate-100 text-slate-700 border-slate-200',
  due_soon: 'bg-yellow-100 text-yellow-800 border-yellow-200',
  due: 'bg-blue-100 text-blue-800 border-blue-200',
  late: 'bg-orange-100 text-orange-800 border-orange-200',
  missed_auto: 'bg-red-100 text-red-800 border-red-200',
  completed: 'bg-green-100 text-green-800 border-green-200',
  future: 'bg-slate-100 text-slate-600 border-slate-200',
};

export default function EnhancedMar() {
  const { client, initialDate, witnesses, userId } = usePage<Props>().props;

  const [date, setDate] = useState(initialDate);
  const [marData, setMarData] = useState<MarData | null>(null);
  const [loading, setLoading] = useState(true);
  const [selectedRow, setSelectedRow] = useState<ScheduledRow | PrnRow | null>(null);
  const [isDialogOpen, setIsDialogOpen] = useState(false);
  const [selectedPrnHistory, setSelectedPrnHistory] = useState<PrnRow | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  const clientName = `${client.first_name} ${client.last_name}`.trim();

  useEffect(() => {
    loadMarData();
  }, [date]);

  const loadMarData = async () => {
    setLoading(true);
    try {
      const response = await axios.get(`/api/medications/clients/${client.id}/mar`, {
        params: { date },
      });
      setMarData(response.data);
    } catch (error) {
      console.error('Failed to load MAR data:', error);
    } finally {
      setLoading(false);
    }
  };

  const goToDate = (newDate: string) => {
    setDate(newDate);
  };

  const navigateDay = (direction: 'prev' | 'next') => {
    const current = new Date(date);
    current.setDate(current.getDate() + (direction === 'next' ? 1 : -1));
    goToDate(current.toISOString().split('T')[0]);
  };

  const openRecordDialog = async (row: ScheduledRow | PrnRow) => {
    if (!marData?.can.record) return;

    // For scheduled rows, check if we can record
    if ('schedule_state' in row && !row.can_record) return;

    // For PRN rows, check if blocked
    if ('is_prn' in row && row.is_blocked) return;

    setSelectedRow(row);
    setIsDialogOpen(true);
  };

  const handleSubmit = async (data: Record<string, unknown>) => {
    if (!selectedRow) return;

    setIsSubmitting(true);
    try {
      await axios.post(
        `/api/medications/clients/${client.id}/medications/${selectedRow.medication.id}/administrations`,
        data
      );
      setIsDialogOpen(false);
      setSelectedRow(null);
      await loadMarData();
    } catch (error: any) {
      console.error('Failed to record administration:', error);
      alert(error.response?.data?.error || 'Failed to record administration');
    } finally {
      setIsSubmitting(false);
    }
  };

  const getStateBadgeClass = (state: string) => {
    return scheduleStateColors[state] || 'bg-slate-100 text-slate-700 border-slate-200';
  };

  if (loading && !marData) {
    return (
      <AppLayout breadcrumbs={[{ title: 'Clients', href: '/clients' }, { title: clientName, href: `/clients/${client.id}` }, { title: 'MAR', href: '#' }]}>
        <Head title={`MAR • ${clientName}`} />
        <div className="flex h-64 items-center justify-center">
          <div className="text-slate-500">Loading MAR...</div>
        </div>
      </AppLayout>
    );
  }

  return (
    <AppLayout breadcrumbs={[{ title: 'Clients', href: '/clients' }, { title: clientName, href: `/clients/${client.id}` }, { title: 'MAR', href: '#' }]}>
      <Head title={`MAR • ${clientName}`} />

      <div className="space-y-4">
        {/* Header */}
        <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
          <div>
            <h1 className="text-xl font-semibold">Medication Administration Record</h1>
            <p className="text-sm text-slate-500">{clientName}</p>
          </div>
          <div className="flex items-center gap-2">
            <Button variant="outline" size="sm" onClick={() => navigateDay('prev')}>
              <ChevronLeft className="h-4 w-4" />
            </Button>
            <Input
              type="date"
              value={date}
              onChange={(e) => goToDate(e.target.value)}
              className="w-auto"
            />
            <Button variant="outline" size="sm" onClick={() => navigateDay('next')}>
              <ChevronRight className="h-4 w-4" />
            </Button>
            <Button variant="outline" size="sm" onClick={() => goToDate(new Date().toISOString().split('T')[0])}>
              Today
            </Button>
          </div>
        </div>

        {/* Allergies Alert */}
        {marData?.allergies && marData.allergies.length > 0 && (
          <Card className="border-red-200 bg-red-50">
            <CardContent className="py-3">
              <div className="flex items-start gap-2">
                <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-red-600" />
                <div>
                  <div className="font-semibold text-red-800">Allergy Alert</div>
                  <div className="mt-1 flex flex-wrap gap-2">
                    {marData.allergies.map((allergy) => (
                      <Badge
                        key={allergy.id}
                        variant="outline"
                        className={allergy.is_severe ? 'border-red-300 bg-red-100 text-red-800' : 'border-yellow-300 bg-yellow-100 text-yellow-800'}
                      >
                        {allergy.allergen}
                        {allergy.severity && ` (${allergy.severity})`}
                      </Badge>
                    ))}
                  </div>
                </div>
              </div>
            </CardContent>
          </Card>
        )}

        {/* Controlled Drug Discrepancies */}
        {marData?.controlled_discrepancies && marData.controlled_discrepancies.length > 0 && (
          <Card className="border-red-200 bg-red-50">
            <CardContent className="py-3">
              <div className="flex items-start gap-2">
                <ShieldAlert className="mt-0.5 h-5 w-5 shrink-0 text-red-600" />
                <div className="flex-1">
                  <div className="font-semibold text-red-800">Controlled Drug Discrepancy</div>
                  <div className="mt-1 space-y-2">
                    {marData.controlled_discrepancies.map((disc) => (
                      <div key={disc.id} className="text-sm text-red-700">
                        <span className="font-medium">{disc.medication_name || 'Medication'}</span>
                        {disc.difference != null && (
                          <span className="ml-2">Difference: {disc.difference > 0 ? '+' : ''}{disc.difference}</span>
                        )}
                        {disc.reason && <span className="ml-2 text-red-600">- {disc.reason}</span>}
                      </div>
                    ))}
                  </div>
                </div>
              </div>
            </CardContent>
          </Card>
        )}

        {/* Active Alerts */}
        {marData?.alerts && marData.alerts.length > 0 && (
          <div className="space-y-2">
            {marData.alerts.map((alert) => (
              <Card 
                key={alert.id} 
                className={alert.severity === 'critical' ? 'border-red-200 bg-red-50' : alert.severity === 'warning' ? 'border-amber-200 bg-amber-50' : 'border-blue-200 bg-blue-50'}
              >
                <CardContent className="py-3">
                  <div className="flex items-start gap-2">
                    <AlertCircle className={`mt-0.5 h-5 w-5 shrink-0 ${alert.severity === 'critical' ? 'text-red-600' : alert.severity === 'warning' ? 'text-amber-600' : 'text-blue-600'}`} />
                    <div>
                      <div className={`font-semibold ${alert.severity === 'critical' ? 'text-red-800' : alert.severity === 'warning' ? 'text-amber-800' : 'text-blue-800'}`}>
                        {alert.alert_type.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}
                      </div>
                      <div className={`text-sm ${alert.severity === 'critical' ? 'text-red-700' : alert.severity === 'warning' ? 'text-amber-700' : 'text-blue-700'}`}>
                        {alert.message}
                      </div>
                    </div>
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
        )}

        {/* Stats */}
        {marData?.stats && (
          <div className="grid grid-cols-2 gap-2 md:grid-cols-4 lg:grid-cols-6">
            <Card className="border-slate-200">
              <CardContent className="py-3">
                <div className="text-xs text-slate-500">Scheduled</div>
                <div className="text-lg font-semibold">{marData.stats.scheduled.total}</div>
              </CardContent>
            </Card>
            <Card className="border-green-200 bg-green-50">
              <CardContent className="py-3">
                <div className="text-xs text-green-600">Completed</div>
                <div className="text-lg font-semibold text-green-700">{marData.stats.scheduled.completed}</div>
              </CardContent>
            </Card>
            <Card className="border-blue-200 bg-blue-50">
              <CardContent className="py-3">
                <div className="text-xs text-blue-600">Due</div>
                <div className="text-lg font-semibold text-blue-700">{marData.stats.scheduled.due}</div>
              </CardContent>
            </Card>
            <Card className="border-orange-200 bg-orange-50">
              <CardContent className="py-3">
                <div className="text-xs text-orange-600">Late</div>
                <div className="text-lg font-semibold text-orange-700">{marData.stats.scheduled.late}</div>
              </CardContent>
            </Card>
            <Card className="border-red-200 bg-red-50">
              <CardContent className="py-3">
                <div className="text-xs text-red-600">Missed</div>
                <div className="text-lg font-semibold text-red-700">{marData.stats.scheduled.missed}</div>
              </CardContent>
            </Card>
            <Card className="border-slate-200">
              <CardContent className="py-3">
                <div className="text-xs text-slate-500">Completion</div>
                <div className="text-lg font-semibold">{marData.stats.completion_percentage}%</div>
              </CardContent>
            </Card>
          </div>
        )}

        {/* Scheduled Medications */}
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Scheduled Medications</CardTitle>
          </CardHeader>
          <CardContent className="space-y-2">
            {marData?.scheduled.length === 0 ? (
              <div className="text-center text-sm text-slate-500">No scheduled medications for this date</div>
            ) : (
              marData?.scheduled.map((row, idx) => (
                <div
                  key={`${row.client_medication_id}-${row.scheduled_time}-${idx}`}
                  className="rounded-lg border p-3 transition-colors hover:bg-slate-50"
                >
                  <div className="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="font-medium">{row.medication.name}</span>
                        <Badge variant="outline" className={getStateBadgeClass(row.schedule_state)}>
                          {row.schedule_state_label.label}
                        </Badge>
                        {row.administration && (
                          <Badge variant="outline" className={getStateBadgeClass(row.administration.status)}>
                            {row.administration.status_label.label}
                          </Badge>
                        )}
                        {row.is_correction && (
                          <Badge variant="outline" className="bg-purple-100 text-purple-800 border-purple-200">
                            Correction
                          </Badge>
                        )}
                        {row.medication.controlled_drug && (
                          <Badge variant="outline" className="bg-red-100 text-red-800 border-red-200">
                            <ShieldAlert className="mr-1 h-3 w-3" />
                            Controlled
                          </Badge>
                        )}
                        {row.medication.high_risk && (
                          <Badge variant="outline" className="bg-orange-100 text-orange-800 border-orange-200">
                            High Risk
                          </Badge>
                        )}
                      </div>
                      <div className="mt-1 text-xs text-slate-500">
                        {row.scheduled_time} • {row.medication.dosage}
                        {row.medication.route && ` • ${row.medication.route}`}
                        {row.medication.form && ` • ${row.medication.form}`}
                      </div>
                      {row.administration && (
                        <div className="mt-2 text-xs text-slate-600">
                          {row.administration.administered_at && (
                            <span>Given: {new Date(row.administration.administered_at).toLocaleString()}</span>
                          )}
                          {row.administration.administered_by && (
                            <span className="ml-2">by {row.administration.administered_by}</span>
                          )}
                          {row.administration.reason && (
                            <div className="mt-1">Reason: {row.administration.reason}</div>
                          )}
                          {row.administration.is_correction && row.administration.correction_reason && (
                            <div className="mt-1 text-purple-600">
                              Correction: {row.administration.correction_reason}
                            </div>
                          )}
                        </div>
                      )}
                    </div>
                    <div className="flex items-center gap-2">
                      {marData?.can.record && row.can_record && !row.administration && (
                        <Button size="sm" onClick={() => openRecordDialog(row)}>
                          <Plus className="mr-1 h-3 w-3" />
                          Record
                        </Button>
                      )}
                      {row.administration && marData?.can.correct && (
                        <Button size="sm" variant="outline" onClick={() => openRecordDialog(row)}>
                          Correct
                        </Button>
                      )}
                    </div>
                  </div>
                </div>
              ))
            )}
          </CardContent>
        </Card>

        {/* PRN Medications */}
        {marData?.prn && marData.prn.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle className="text-base flex items-center gap-2">
                <Pill className="h-4 w-4" />
                PRN (As Required) Medications
              </CardTitle>
            </CardHeader>
            <CardContent className="space-y-2">
              {marData.prn.map((row) => (
                <div
                  key={row.client_medication_id}
                  className="rounded-lg border p-3 transition-colors hover:bg-slate-50"
                >
                  <div className="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="font-medium">{row.medication.name}</span>
                        <Badge variant="outline" className="bg-indigo-100 text-indigo-800 border-indigo-200">
                          PRN
                        </Badge>
                        {row.is_over_limit && (
                          <Badge variant="outline" className="bg-red-100 text-red-800 border-red-200">
                            Limit Reached
                          </Badge>
                        )}
                        {row.is_near_limit && !row.is_over_limit && (
                          <Badge variant="outline" className="bg-orange-100 text-orange-800 border-orange-200">
                            Near Limit
                          </Badge>
                        )}
                        {row.medication.controlled_drug && (
                          <Badge variant="outline" className="bg-red-100 text-red-800 border-red-200">
                            Controlled
                          </Badge>
                        )}
                      </div>
                      <div className="mt-1 text-xs text-slate-500">
                        {row.medication.dosage}
                        {row.medication.route && ` • ${row.medication.route}`}
                        {row.max_per_day && ` • Max: ${row.max_per_day}`}
                      </div>
                      {row.prn_reason && (
                        <div className="mt-1 text-xs text-slate-600">
                          Indication: {row.prn_reason}
                        </div>
                      )}
                      <div className="mt-2 flex items-center gap-2 text-xs">
                        <span className={row.is_over_limit ? 'text-red-600 font-medium' : 'text-slate-600'}>
                          Last 24h: {row.count_24h} given
                        </span>
                        {row.remaining_today !== undefined && (
                          <span className="text-slate-500">
                            ({row.remaining_today} remaining)
                          </span>
                        )}
                      </div>
                    </div>
                    <div className="flex items-center gap-2">
                      <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setSelectedPrnHistory(row)}
                      >
                        <Clock className="mr-1 h-3 w-3" />
                        History
                      </Button>
                      {marData.can.record && row.can_record && (
                        <Button size="sm" onClick={() => openRecordDialog(row)}>
                          <Plus className="mr-1 h-3 w-3" />
                          Give
                        </Button>
                      )}
                    </div>
                  </div>
                </div>
              ))}
            </CardContent>
          </Card>
        )}

        {/* History */}
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Recent History</CardTitle>
          </CardHeader>
          <CardContent className="space-y-2">
            {marData?.history.length === 0 ? (
              <div className="text-center text-sm text-slate-500">No administrations recorded</div>
            ) : (
              marData?.history.slice(0, 10).map((item) => (
                <div
                  key={item.id}
                  className="flex items-start justify-between gap-2 rounded-md border p-2 text-sm"
                >
                  <div>
                    <div className="flex items-center gap-2">
                      <span className="font-medium">{item.medication_name}</span>
                      <Badge variant="outline" className={scheduleStateColors[item.status]}>
                        {item.status_label.label}
                      </Badge>
                      {item.is_correction && (
                        <Badge variant="outline" className="bg-purple-100 text-purple-800 border-purple-200">
                          Correction
                        </Badge>
                      )}
                      {item.controlled_drug && (
                        <Badge variant="outline" className="bg-red-100 text-red-800 border-red-200">
                          Controlled
                        </Badge>
                      )}
                    </div>
                    <div className="mt-1 text-xs text-slate-500">
                      {item.administered_at && new Date(item.administered_at).toLocaleString()}
                      {item.administered_by && ` • ${item.administered_by}`}
                    </div>
                    {item.reason && (
                      <div className="mt-1 text-xs text-slate-600">{item.reason}</div>
                    )}
                  </div>
                </div>
              ))
            )}
          </CardContent>
        </Card>
      </div>

      {/* Record Dialog */}
      <RecordAdministrationDialog
        isOpen={isDialogOpen}
        onClose={() => {
          setIsDialogOpen(false);
          setSelectedRow(null);
        }}
        onSubmit={handleSubmit}
        medication={selectedRow?.medication || null}
        scheduledTime={'scheduled_for' in (selectedRow || {}) ? (selectedRow as ScheduledRow)?.scheduled_for : null}
        witnesses={witnesses}
        currentUserId={userId}
        safetyCheck={selectedRow?.safety_check || null}
        prnData={selectedRow && 'is_prn' in selectedRow ? {
          history: (selectedRow as PrnRow).prn_history,
          count: (selectedRow as PrnRow).count_24h,
          max_per_day: (selectedRow as PrnRow).max_per_day,
          remaining_today: (selectedRow as PrnRow).remaining_today,
        } : null}
        isLoading={isSubmitting}
      />

      {/* PRN History Dialog */}
      {selectedPrnHistory && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div className="max-h-[80vh] w-full max-w-md overflow-y-auto rounded-lg bg-white p-4">
            <div className="mb-4 flex items-center justify-between">
              <h3 className="font-semibold">{selectedPrnHistory.medication.name} - History</h3>
              <Button variant="ghost" size="sm" onClick={() => setSelectedPrnHistory(null)}>
                <X className="h-4 w-4" />
              </Button>
            </div>
            <PrnHistoryPanel
              history={selectedPrnHistory.prn_history}
              count24h={selectedPrnHistory.count_24h}
              maxPerDay={selectedPrnHistory.max_per_day}
              remainingToday={selectedPrnHistory.remaining_today}
            />
          </div>
        </div>
      )}
    </AppLayout>
  );
}
