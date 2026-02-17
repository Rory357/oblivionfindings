import { AlertTriangle, Ban, Clock, Pill, User, X, XCircle } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import SafetyCheckPanel from './SafetyCheckPanel';
import PrnHistoryPanel from './PrnHistoryPanel';

interface Witness {
  id: number;
  name: string;
}

interface SafetyCheck {
  safe: boolean;
  blocked: boolean;
  block_reason?: string;
  safety_level: string;
  safety_info: { label: string; color: string; icon: string };
  warnings: Array<{ type: string; severity: string; message: string; details?: Record<string, unknown> }>;
  can_proceed: boolean;
  requires_acknowledgment: boolean;
}

interface Medication {
  id: number;
  name: string;
  dosage: string;
  route?: string;
  form?: string;
  is_prn: boolean;
  prn_reason?: string;
  controlled_drug: boolean;
  high_risk: boolean;
  witness_required: boolean;
  instructions?: string;
  stock?: { on_hand: number; unit: string } | null;
}

interface PrnData {
  history: Array<{
    id: number;
    administered_at: string;
    dose_given?: string;
    reason?: string;
    administered_by?: string;
  }>;
  count: number;
  max_per_day?: string;
  remaining_today?: number;
}

interface Props {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (data: Record<string, unknown>) => void;
  medication: Medication | null;
  scheduledTime?: string | null;
  witnesses: Witness[];
  currentUserId: number;
  safetyCheck: SafetyCheck | null;
  prnData?: PrnData | null;
  isLoading?: boolean;
}

const statusOptions = [
  { value: 'given', label: 'Given', color: 'text-green-600' },
  { value: 'refused', label: 'Refused', color: 'text-orange-600' },
  { value: 'withheld', label: 'Withheld', color: 'text-yellow-600' },
  { value: 'missed', label: 'Missed', color: 'text-red-600' },
];

export default function RecordAdministrationDialog({
  isOpen,
  onClose,
  onSubmit,
  medication,
  scheduledTime,
  witnesses,
  currentUserId,
  safetyCheck,
  prnData,
  isLoading,
}: Props) {
  const [status, setStatus] = useState('given');
  const [reason, setReason] = useState('');
  const [doseGiven, setDoseGiven] = useState('');
  const [notes, setNotes] = useState('');
  const [administeredAt, setAdministeredAt] = useState('');
  const [witnessedBy, setWitnessedBy] = useState('');
  const [outcome, setOutcome] = useState('');
  const [site, setSite] = useState('');
  const [showOverride, setShowOverride] = useState(false);

  // Reset form when dialog opens
  useEffect(() => {
    if (isOpen && medication) {
      setStatus('given');
      setReason('');
      setDoseGiven(medication.dosage || '');
      setNotes('');
      setAdministeredAt(new Date().toISOString().slice(0, 16));
      setWitnessedBy('');
      setOutcome('');
      setSite('');
      setShowOverride(false);
    }
  }, [isOpen, medication]);

  const needsReason = useMemo(() => {
    if (status !== 'given') return true;
    if (medication?.is_prn) return true;
    return false;
  }, [status, medication]);

  const needsWitness = useMemo(() => {
    if (status !== 'given') return false;
    return medication?.controlled_drug || medication?.witness_required || false;
  }, [status, medication]);

  const availableWitnesses = useMemo(() => {
    return witnesses.filter((w) => w.id !== currentUserId);
  }, [witnesses, currentUserId]);

  const canSubmit = useMemo(() => {
    if (!safetyCheck) return false;
    if (safetyCheck.blocked && !showOverride) return false;
    if (needsReason && !reason.trim()) return false;
    if (needsWitness && !witnessedBy) return false;
    return true;
  }, [safetyCheck, showOverride, needsReason, reason, needsWitness, witnessedBy]);

  const handleSubmit = () => {
    if (!canSubmit) return;

    const data: Record<string, unknown> = {
      status,
      reason: reason || null,
      dose_given: doseGiven || null,
      notes: notes || null,
      administered_at: administeredAt ? new Date(administeredAt).toISOString() : null,
      witnessed_by: witnessedBy ? parseInt(witnessedBy, 10) : null,
      scheduled_for: scheduledTime || null,
      outcome: outcome || null,
      site: site || null,
    };

    if (showOverride) {
      data.override_safety = true;
    }

    onSubmit(data);
  };

  if (!medication) return null;

  return (
    <Dialog open={isOpen} onOpenChange={onClose}>
      <DialogContent className="max-h-[90vh] max-w-lg overflow-y-auto">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Pill className="h-5 w-5" />
            Record Administration
          </DialogTitle>
        </DialogHeader>

        <div className="space-y-4">
          {/* Medication Info */}
          <div className="rounded-md bg-slate-50 p-3">
            <div className="font-medium">{medication.name}</div>
            <div className="text-sm text-slate-600">
              {medication.dosage}
              {medication.route && ` • ${medication.route}`}
              {medication.form && ` • ${medication.form}`}
            </div>
            {medication.is_prn && (
              <Badge variant="outline" className="mt-2 bg-indigo-50 text-indigo-700">
                PRN
              </Badge>
            )}
            {medication.controlled_drug && (
              <Badge variant="outline" className="mt-2 ml-2 bg-red-50 text-red-700">
                Controlled
              </Badge>
            )}
            {medication.high_risk && (
              <Badge variant="outline" className="mt-2 ml-2 bg-orange-50 text-orange-700">
                High Risk
              </Badge>
            )}
            {scheduledTime && (
              <div className="mt-2 flex items-center gap-1 text-xs text-slate-500">
                <Clock className="h-3 w-3" />
                Scheduled: {new Date(scheduledTime).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
              </div>
            )}
          </div>

          {/* Safety Check */}
          <SafetyCheckPanel
            safetyCheck={safetyCheck}
            onOverride={safetyCheck?.blocked ? () => setShowOverride(true) : undefined}
          />

          {/* PRN History */}
          {medication.is_prn && prnData && (
            <PrnHistoryPanel
              history={prnData.history}
              count24h={prnData.count}
              maxPerDay={prnData.max_per_day}
              remainingToday={prnData.remaining_today}
            />
          )}

          {/* Administration Form */}
          <div className="space-y-3">
            <div>
              <Label>Status *</Label>
              <Select value={status} onValueChange={setStatus}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {statusOptions.map((opt) => (
                    <SelectItem key={opt.value} value={opt.value}>
                      <span className={opt.color}>{opt.label}</span>
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div>
              <Label>Administered At</Label>
              <Input
                type="datetime-local"
                value={administeredAt}
                onChange={(e) => setAdministeredAt(e.target.value)}
              />
            </div>

            <div>
              <Label>Dose Given</Label>
              <Input
                value={doseGiven}
                onChange={(e) => setDoseGiven(e.target.value)}
                placeholder={medication.dosage || 'e.g., 1 tablet'}
              />
            </div>

            {needsReason && (
              <div>
                <Label>
                  Reason / Indication *
                  {medication.is_prn && status === 'given' && (
                    <span className="ml-1 text-xs text-slate-500">(PRN indication required)</span>
                  )}
                </Label>
                <Textarea
                  value={reason}
                  onChange={(e) => setReason(e.target.value)}
                  placeholder={status === 'given' && medication.is_prn ? 'Why is this PRN being given?' : 'Why was medication not given?'}
                  className="min-h-[60px]"
                />
              </div>
            )}

            {needsWitness && (
              <div>
                <Label className="flex items-center gap-1">
                  <User className="h-3 w-3" />
                  Witness *
                </Label>
                <Select value={witnessedBy} onValueChange={setWitnessedBy}>
                  <SelectTrigger>
                    <SelectValue placeholder="Select witness..." />
                  </SelectTrigger>
                  <SelectContent>
                    {availableWitnesses.map((w) => (
                      <SelectItem key={w.id} value={String(w.id)}>
                        {w.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <p className="mt-1 text-xs text-slate-500">
                  A witness is required for controlled drugs
                </p>
              </div>
            )}

            {status === 'given' && (
              <>
                <div>
                  <Label>Outcome (Optional)</Label>
                  <Select value={outcome} onValueChange={setOutcome}>
                    <SelectTrigger>
                      <SelectValue placeholder="Select outcome..." />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="effective">Effective</SelectItem>
                      <SelectItem value="ineffective">Ineffective</SelectItem>
                      <SelectItem value="adverse_reaction">Adverse Reaction</SelectItem>
                    </SelectContent>
                  </Select>
                </div>

                <div>
                  <Label>Site (Optional)</Label>
                  <Input
                    value={site}
                    onChange={(e) => setSite(e.target.value)}
                    placeholder="e.g., Left arm, Oral"
                  />
                </div>
              </>
            )}

            <div>
              <Label>Notes (Optional)</Label>
              <Textarea
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
                placeholder="Additional notes..."
                className="min-h-[60px]"
              />
            </div>
          </div>
        </div>

        <DialogFooter className="gap-2">
          <Button variant="outline" onClick={onClose} disabled={isLoading}>
            Cancel
          </Button>
          <Button onClick={handleSubmit} disabled={!canSubmit || isLoading}>
            {isLoading ? 'Saving...' : 'Record Administration'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
