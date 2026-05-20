import { Head, Link, router } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { index as complianceIndex, complete as completeObligation } from '@/routes/governance/compliance';
import { upload as uploadEvidence } from '@/routes/governance/compliance/evidence';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger, DialogFooter } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { FileText, Calendar, User, Upload, CheckCircle, Clock, AlertTriangle, FileCheck } from 'lucide-react';
import { PageHero, PageLayout } from '@/components/page';
import { cn } from '@/lib/utils';
import { useState } from 'react';
import axios from 'axios';

interface Evidence {
  id: number;
  evidence_type: string;
  title: string;
  file_path: string;
  valid_until: string | null;
  uploaded_by: { name: string };
  uploaded_at: string;
}

interface Reminder {
  id: number;
  days_before_due: number;
  scheduled_at: string;
  status: string;
  sent_at: string | null;
}

interface Obligation {
  id: number;
  framework: string;
  obligation_code: string | null;
  obligation_title: string;
  description: string;
  frequency: string;
  due_date: string;
  next_due_date: string | null;
  status: string;
  owner: { id: number; name: string } | null;
  completed_at: string | null;
  completed_by: { name: string } | null;
  evidence_required: boolean;
  evidence_provided: boolean;
  sign_off_required: boolean;
  signed_off_at: string | null;
  signed_off_by: { name: string } | null;
  notes: string | null;
  evidence: Evidence[];
  reminders: Reminder[];
}

interface Props extends PageProps {
  obligation: Obligation;
}

export default function ComplianceShow({ auth, obligation }: Props) {
  const evidenceItems = obligation.evidence ?? [];
  const reminderItems = obligation.reminders ?? [];
  const [showUploadDialog, setShowUploadDialog] = useState(false);
  const [uploadForm, setUploadForm] = useState({
    evidence_type: 'document',
    title: '',
    description: '',
    valid_until: '',
    file: null as File | null,
  });
  const [submitting, setSubmitting] = useState(false);

  const getFrameworkLabel = (framework: string) => {
    const labels: Record<string, string> = {
      charities: 'Charities Services',
      nga_paerewa: 'Ngā Paerewa NZS 8134:2021',
      hdsa_safety: 'H&D Services (Safety) Act',
      privacy_act: 'Privacy Act 2020',
      hip_code: 'Health Information Privacy Code',
      hswa: 'Health and Safety at Work Act',
      employment: 'Employment Relations',
      funding_moh: 'MoH/Health NZ Funding',
      funding_msd: 'MSD Funding',
      funding_acc: 'ACC Funding',
    };
    return labels[framework] || framework;
  };

  const getStatusColor = (status: string) => {
    switch (status) {
      case 'complete':
        return 'bg-status-success-bg text-status-success';
      case 'overdue':
        return 'bg-status-critical-bg text-status-critical';
      case 'due_soon':
        return 'bg-status-warning-bg text-status-warning';
      default:
        return 'bg-muted text-foreground';
    }
  };

  const daysRemaining = () => {
    const due = new Date(obligation.due_date);
    const now = new Date();
    const diff = Math.ceil((due.getTime() - now.getTime()) / (1000 * 60 * 60 * 24));
    return diff;
  };

  const handleUpload = async () => {
    if (!uploadForm.file) return;
    setSubmitting(true);

    const formData = new FormData();
    formData.append('evidence_type', uploadForm.evidence_type);
    formData.append('title', uploadForm.title);
    formData.append('description', uploadForm.description || '');
    formData.append('file', uploadForm.file);
    if (uploadForm.valid_until) {
      formData.append('valid_until', uploadForm.valid_until);
    }

    try {
      await axios.post(uploadEvidence.url({ obligation: obligation.id }), formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      router.reload();
      setShowUploadDialog(false);
    } catch (error) {
      console.error('Upload failed:', error);
    } finally {
      setSubmitting(false);
    }
  };

  const markComplete = async () => {
    if (!confirm('Mark this obligation as complete?')) return;
    try {
      await axios.post(completeObligation.url({ obligation: obligation.id }));
      router.reload();
    } catch (error) {
      console.error('Failed to mark complete:', error);
    }
  };

  return (
    <AppLayout
      user={auth.user}
      breadcrumbs={[
        { title: 'Governance', href: '/governance/dashboard' },
        { title: 'Compliance', href: '/governance/compliance' },
        { title: 'Obligation', href: `/governance/compliance/${obligation.id}` },
      ]}
    >
      <Head title={obligation.obligation_title} />

      <PageLayout
        hero={
          <PageHero
            variant="compact"
            backHref={complianceIndex.url()}
            title={
              <span className="flex flex-wrap items-center gap-3">
                {obligation.obligation_title}
                <Badge variant="outline">{getFrameworkLabel(obligation.framework)}</Badge>
                {obligation.obligation_code && (
                  <Badge variant="outline">{obligation.obligation_code}</Badge>
                )}
                <Badge className={getStatusColor(obligation.status)}>{obligation.status}</Badge>
              </span>
            }
            actions={
              <div className="flex gap-2">
              <Dialog open={showUploadDialog} onOpenChange={setShowUploadDialog}>
                <DialogTrigger asChild>
                  <Button variant="outline">
                    <Upload className="w-4 h-4 mr-2" />
                    Upload Evidence
                  </Button>
                </DialogTrigger>
                <DialogContent>
                  <DialogHeader>
                    <DialogTitle>Upload Evidence</DialogTitle>
                  </DialogHeader>
                  <div className="space-y-4 py-4">
                    <div>
                      <Label>Evidence Type</Label>
                      <Select
                        value={uploadForm.evidence_type}
                        onValueChange={(v) => setUploadForm({ ...uploadForm, evidence_type: v })}
                      >
                        <SelectTrigger>
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="document">Document</SelectItem>
                          <SelectItem value="audit_report">Audit Report</SelectItem>
                          <SelectItem value="certification">Certification</SelectItem>
                          <SelectItem value="system_export">System Export</SelectItem>
                          <SelectItem value="attestation">Attestation</SelectItem>
                        </SelectContent>
                      </Select>
                    </div>
                    <div>
                      <Label>Title</Label>
                      <Input
                        value={uploadForm.title}
                        onChange={(e) => setUploadForm({ ...uploadForm, title: e.target.value })}
                        placeholder="Evidence title..."
                      />
                    </div>
                    <div>
                      <Label>File</Label>
                      <Input
                        type="file"
                        onChange={(e) => setUploadForm({ ...uploadForm, file: e.target.files?.[0] || null })}
                      />
                    </div>
                    <div>
                      <Label>Valid Until (optional)</Label>
                      <Input
                        type="date"
                        value={uploadForm.valid_until}
                        onChange={(e) => setUploadForm({ ...uploadForm, valid_until: e.target.value })}
                      />
                    </div>
                  </div>
                  <DialogFooter>
                    <Button onClick={handleUpload} disabled={submitting || !uploadForm.file || !uploadForm.title}>
                      {submitting ? 'Uploading...' : 'Upload'}
                    </Button>
                  </DialogFooter>
                </DialogContent>
              </Dialog>
              {obligation.status !== 'complete' && (
                <Button onClick={markComplete}>
                  <CheckCircle className="w-4 h-4 mr-2" />
                  Mark Complete
                </Button>
              )}
            </div>
            }
          />
        }
      >
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {/* Main Content */}
            <div className="lg:col-span-2 space-y-6">
              {/* Description */}
              <Card>
                <CardHeader>
                  <CardTitle>Description</CardTitle>
                </CardHeader>
                <CardContent>
                  <p className="text-foreground whitespace-pre-wrap">{obligation.description}</p>
                  {obligation.notes && (
                    <div className="mt-4 p-4 bg-muted rounded-lg">
                      <p className="text-sm font-medium text-foreground">Notes</p>
                      <p className="text-sm text-muted-foreground">{obligation.notes}</p>
                    </div>
                  )}
                </CardContent>
              </Card>

              {/* Evidence */}
              <Card>
                <CardHeader>
                  <CardTitle>Evidence</CardTitle>
                  <CardDescription>
                    {obligation.evidence_required ? 'Evidence is required for this obligation' : 'Evidence is optional'}
                  </CardDescription>
                </CardHeader>
                <CardContent>
                  {evidenceItems.length > 0 ? (
                    <div className="space-y-3">
                      {evidenceItems.map((ev) => (
                        <div key={ev.id} className="flex items-center justify-between p-4 border rounded-lg">
                          <div className="flex items-center gap-3">
                            <FileCheck className="w-6 h-6 text-status-success" />
                            <div>
                              <p className="font-medium">{ev.title}</p>
                              <div className="flex items-center gap-3 text-sm text-muted-foreground">
                                <Badge variant="outline" className="text-xs capitalize">{ev.evidence_type}</Badge>
                                <span>by {ev.uploaded_by?.name}</span>
                                <span>{ev.uploaded_at}</span>
                              </div>
                            </div>
                          </div>
                          {ev.valid_until && (
                            <Badge variant="outline">Valid until {ev.valid_until}</Badge>
                          )}
                        </div>
                      ))}
                    </div>
                  ) : (
                    <div className="text-center py-8 text-muted-foreground">
                      <Upload className="w-12 h-12 mx-auto mb-2 opacity-50" />
                      <p>No evidence uploaded yet</p>
                    </div>
                  )}
                </CardContent>
              </Card>

              {/* Reminders */}
              {reminderItems.length > 0 && (
                <Card>
                  <CardHeader>
                    <CardTitle>Scheduled Reminders</CardTitle>
                  </CardHeader>
                  <CardContent>
                    <div className="space-y-2">
                      {reminderItems.map((reminder) => (
                        <div key={reminder.id} className="flex items-center justify-between p-3 border rounded-lg">
                          <div className="flex items-center gap-2">
                            <Clock className="w-4 h-4 text-muted-foreground" />
                            <span className="text-sm">{reminder.days_before_due} days before due</span>
                          </div>
                          <Badge className={cn(
                            reminder.status === 'sent' && 'bg-status-success-bg text-status-success',
                            reminder.status === 'pending' && 'bg-muted text-foreground',
                          )}>
                            {reminder.status}
                          </Badge>
                        </div>
                      ))}
                    </div>
                  </CardContent>
                </Card>
              )}
            </div>

            {/* Sidebar */}
            <div className="space-y-6">
              {/* Status Card */}
              <Card className={cn(
                obligation.status === 'overdue' && 'border-status-critical/30 bg-status-critical-bg',
                obligation.status === 'due_soon' && 'border-status-warning/30 bg-status-warning-bg',
                obligation.status === 'complete' && 'border-status-success/30 bg-status-success-bg',
              )}>
                <CardHeader>
                  <CardTitle className="flex items-center gap-2">
                    {obligation.status === 'complete' ? (
                      <CheckCircle className="w-5 h-5 text-status-success" />
                    ) : obligation.status === 'overdue' ? (
                      <AlertTriangle className="w-5 h-5 text-status-critical" />
                    ) : (
                      <Clock className="w-5 h-5 text-status-warning" />
                    )}
                    Status
                  </CardTitle>
                </CardHeader>
                <CardContent>
                  {obligation.status === 'complete' ? (
                    <div>
                      <p className="text-status-success font-medium">Completed</p>
                      <p className="text-sm text-status-success">
                        {obligation.completed_at} by {obligation.completed_by?.name}
                      </p>
                    </div>
                  ) : (
                    <div>
                      <p className="text-2xl font-bold">
                        {daysRemaining() < 0 ? (
                          <span className="text-status-critical">{Math.abs(daysRemaining())} days overdue</span>
                        ) : (
                          <span className={daysRemaining() <= 7 ? 'text-status-warning' : 'text-foreground'}>
                            {daysRemaining()} days remaining
                          </span>
                        )}
                      </p>
                      <p className="text-sm text-muted-foreground mt-1">Due: {obligation.due_date}</p>
                    </div>
                  )}
                </CardContent>
              </Card>

              {/* Details */}
              <Card>
                <CardHeader>
                  <CardTitle>Details</CardTitle>
                </CardHeader>
                <CardContent className="space-y-4">
                  <div>
                    <p className="text-sm text-muted-foreground">Owner</p>
                    <p className="font-medium flex items-center gap-2">
                      <User className="w-4 h-4" />
                      {obligation.owner?.name || 'Not assigned'}
                    </p>
                  </div>
                  <div>
                    <p className="text-sm text-muted-foreground">Frequency</p>
                    <p className="font-medium capitalize">{obligation.frequency}</p>
                  </div>
                  {obligation.next_due_date && (
                    <div>
                      <p className="text-sm text-muted-foreground">Next Due Date</p>
                      <p className="font-medium">{obligation.next_due_date}</p>
                    </div>
                  )}
                  <div>
                    <p className="text-sm text-muted-foreground">Evidence</p>
                    <p className="font-medium">
                      {obligation.evidence_provided ? (
                        <span className="text-status-success flex items-center gap-1">
                          <CheckCircle className="w-4 h-4" />
                          Provided
                        </span>
                      ) : obligation.evidence_required ? (
                        <span className="text-status-critical">Required - Not provided</span>
                      ) : (
                        <span className="text-muted-foreground">Not required</span>
                      )}
                    </p>
                  </div>
                  {obligation.sign_off_required && (
                    <div>
                      <p className="text-sm text-muted-foreground">Sign-off</p>
                      {obligation.signed_off_at ? (
                        <p className="text-status-success text-sm">
                          Signed by {obligation.signed_off_by?.name} on {obligation.signed_off_at}
                        </p>
                      ) : (
                        <p className="text-status-warning text-sm">Pending sign-off</p>
                      )}
                    </div>
                  )}
                </CardContent>
              </Card>
            </div>
          </div>
      </PageLayout>
    </AppLayout>
  );
}
