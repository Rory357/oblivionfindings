import { Head, Link, router, usePage } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { index as risksIndex, update as updateRisk, accept as acceptRisk, close as closeRisk } from '@/routes/governance/risks';
import { add as addTreatment } from '@/routes/governance/risks/treatments';
import { link as linkEvent } from '@/routes/governance/risks/events';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger, DialogFooter } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { AlertTriangle, Shield, User, Calendar, TrendingUp, CheckCircle, Clock, AlertCircle } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useState } from 'react';
import axios from 'axios';

interface Treatment {
  id: number;
  action_description: string;
  assigned_to: { name: string };
  due_date: string;
  status: string;
  expected_score_reduction: number | null;
}

interface Acceptance {
  id: number;
  acceptance_type: string;
  justification: string;
  accepted_by: { name: string };
  accepted_at: string;
  expires_at: string;
}

interface EventLink {
  id: number;
  event_type: string;
  event_reference: string;
  event_severity: string;
  link_rationale: string;
  linked_at: string;
}

interface Risk {
  id: number;
  risk_reference: string;
  title: string;
  description: string;
  category: string;
  likelihood_score: number;
  impact_score: number;
  inherent_score: number;
  residual_score: number;
  control_effectiveness: string;
  within_appetite: boolean;
  appetite_threshold: number;
  status: string;
  mitigation_strategy: string;
  review_frequency: string;
  next_review_date: string;
  risk_owner: { id: number; name: string } | null;
  treatments: Treatment[];
  acceptances: Acceptance[];
  events: EventLink[];
}

interface Props extends PageProps {
  risk: Risk;
  assignees: Array<{ id: number; name: string; email: string }>;
  canEdit: boolean;
  canAccept: boolean;
}

export default function RiskShow({ auth, risk, assignees, canEdit, canAccept }: Props) {
  const { labels: pageLabels } = usePage().props as any;
  const clientSingular = pageLabels?.['client.singular'] ?? 'Client';
  const [showTreatmentDialog, setShowTreatmentDialog] = useState(false);
  const [showAcceptDialog, setShowAcceptDialog] = useState(false);
  const [treatmentForm, setTreatmentForm] = useState({
    action_description: '',
    assigned_to: '',
    due_date: '',
    expected_score_reduction: '',
    evidence_required: false,
  });
  const [acceptForm, setAcceptForm] = useState({
    justification: '',
    expiry_months: 12,
    conditions: [],
  });
  const [submitting, setSubmitting] = useState(false);

  const getRiskColor = (score: number) => {
    if (score >= 20) return 'bg-red-500';
    if (score >= 15) return 'bg-orange-500';
    if (score >= 10) return 'bg-yellow-500';
    return 'bg-green-500';
  };

  const getRiskLevel = (score: number) => {
    if (score >= 20) return 'Critical';
    if (score >= 15) return 'High';
    if (score >= 10) return 'Medium';
    return 'Low';
  };

  const getCategoryLabel = (category: string) => {
    const labels: Record<string, string> = {
      client_safety: `${clientSingular} Safety`,
      reputational: 'Reputational',
      financial: 'Financial',
      it_cyber: 'IT/Cyber',
      workforce: 'Workforce',
      legal_compliance: 'Legal/Compliance',
      operational: 'Operational',
      clinical: 'Clinical',
    };
    return labels[category] || category;
  };

  const submitTreatment = async () => {
    setSubmitting(true);
    try {
      await axios.post(addTreatment.url({ risk: risk.id }), treatmentForm);
      router.reload();
      setShowTreatmentDialog(false);
    } catch (error) {
      console.error('Failed to add treatment:', error);
    } finally {
      setSubmitting(false);
    }
  };

  const submitAcceptance = async () => {
    setSubmitting(true);
    try {
      await axios.post(acceptRisk.url({ risk: risk.id }), acceptForm);
      router.reload();
      setShowAcceptDialog(false);
    } catch (error) {
      console.error('Failed to accept risk:', error);
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <AppLayout
      user={auth.user}
      breadcrumbs={[
        { title: 'Governance', href: '/governance/dashboard' },
        { title: 'Risks', href: '/governance/risks' },
        { title: 'Risk', href: `/governance/risks/${risk.id}` },
      ]}
    >
      <Head title={risk.title} />

      <div className="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
          {/* Back Link */}
          <div className="mb-4">
            <Link href={risksIndex.url()} className="text-sm text-blue-600 hover:underline">
              ← Back to Risk Register
            </Link>
          </div>

          {/* Header */}
          <div className="flex items-start justify-between mb-6">
            <div className="flex items-start gap-4">
              <div className={cn('w-16 h-16 rounded-full flex items-center justify-center text-white font-bold text-xl', getRiskColor(risk.residual_score))}>
                {risk.residual_score}
              </div>
              <div>
                <div className="flex items-center gap-3 mb-1">
                  <h1 className="text-2xl font-bold text-foreground">{risk.title}</h1>
                  <Badge variant="outline">{risk.risk_reference}</Badge>
                </div>
                <div className="flex items-center gap-2">
                  <Badge className={getRiskColor(risk.residual_score) + ' text-white'}>
                    {getRiskLevel(risk.residual_score)}
                  </Badge>
                  <Badge variant="outline">{getCategoryLabel(risk.category)}</Badge>
                  {!risk.within_appetite && (
                    <Badge className="bg-primary/10 text-primary">Above Appetite</Badge>
                  )}
                  <Badge variant={risk.status === 'active' ? 'default' : 'secondary'}>
                    {risk.status}
                  </Badge>
                </div>
              </div>
            </div>
            {canEdit && (
              <div className="flex gap-2">
                <Dialog open={showTreatmentDialog} onOpenChange={setShowTreatmentDialog}>
                  <DialogTrigger asChild>
                    <Button variant="outline">Add Treatment</Button>
                  </DialogTrigger>
                  <DialogContent>
                    <DialogHeader>
                      <DialogTitle>Add Treatment Action</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-4 py-4">
                      <div>
                        <Label>Action Description</Label>
                        <Textarea
                          value={treatmentForm.action_description}
                          onChange={(e) => setTreatmentForm({ ...treatmentForm, action_description: e.target.value })}
                          placeholder="Describe the treatment action..."
                        />
                      </div>
                      <div>
                        <Label>Assign To</Label>
                        <Select
                          value={treatmentForm.assigned_to || undefined}
                          onValueChange={(v) => setTreatmentForm({ ...treatmentForm, assigned_to: v })}
                        >
                          <SelectTrigger>
                            <SelectValue placeholder="Choose staff member..." />
                          </SelectTrigger>
                          <SelectContent>
                            {assignees.map((user) => (
                              <SelectItem key={user.id} value={String(user.id)}>
                                {user.name} ({user.email})
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      </div>
                      <div>
                        <Label>Due Date</Label>
                        <Input
                          type="date"
                          value={treatmentForm.due_date}
                          onChange={(e) => setTreatmentForm({ ...treatmentForm, due_date: e.target.value })}
                        />
                      </div>
                      <div>
                        <Label>Expected Score Reduction</Label>
                        <Input
                          type="number"
                          min={1}
                          max={24}
                          value={treatmentForm.expected_score_reduction}
                          onChange={(e) => setTreatmentForm({ ...treatmentForm, expected_score_reduction: e.target.value })}
                          placeholder="e.g., 5"
                        />
                      </div>
                    </div>
                    <DialogFooter>
                      <Button onClick={submitTreatment} disabled={submitting}>
                        {submitting ? 'Saving...' : 'Add Treatment'}
                      </Button>
                    </DialogFooter>
                  </DialogContent>
                </Dialog>
                {canAccept && !risk.within_appetite && risk.status === 'active' && (
                  <Dialog open={showAcceptDialog} onOpenChange={setShowAcceptDialog}>
                    <DialogTrigger asChild>
                      <Button>Accept Risk</Button>
                    </DialogTrigger>
                    <DialogContent>
                      <DialogHeader>
                        <DialogTitle>Accept Risk Above Appetite</DialogTitle>
                      </DialogHeader>
                      <div className="space-y-4 py-4">
                        <div className="p-4 bg-orange-50 rounded-lg border border-orange-200">
                          <p className="text-sm text-orange-800">
                            This risk is currently above the appetite threshold ({risk.appetite_threshold}).
                            Board acceptance is required to formally acknowledge and accept this risk.
                          </p>
                        </div>
                        <div>
                          <Label>Justification (min 50 characters)</Label>
                          <Textarea
                            value={acceptForm.justification}
                            onChange={(e) => setAcceptForm({ ...acceptForm, justification: e.target.value })}
                            placeholder="Provide detailed justification for accepting this risk..."
                            rows={4}
                          />
                        </div>
                        <div>
                          <Label>Acceptance Period (months)</Label>
                          <Select
                            value={String(acceptForm.expiry_months)}
                            onValueChange={(v) => setAcceptForm({ ...acceptForm, expiry_months: parseInt(v) })}
                          >
                            <SelectTrigger>
                              <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                              <SelectItem value="3">3 months</SelectItem>
                              <SelectItem value="6">6 months</SelectItem>
                              <SelectItem value="12">12 months</SelectItem>
                              <SelectItem value="24">24 months</SelectItem>
                            </SelectContent>
                          </Select>
                        </div>
                      </div>
                      <DialogFooter>
                        <Button
                          onClick={submitAcceptance}
                          disabled={submitting || acceptForm.justification.length < 50}
                        >
                          {submitting ? 'Submitting...' : 'Accept Risk'}
                        </Button>
                      </DialogFooter>
                    </DialogContent>
                  </Dialog>
                )}
              </div>
            )}
          </div>

          <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {/* Main Content */}
            <div className="lg:col-span-2 space-y-6">
              {/* Description */}
              <Card>
                <CardHeader>
                  <CardTitle>Description</CardTitle>
                </CardHeader>
                <CardContent>
                  <p className="text-foreground whitespace-pre-wrap">{risk.description}</p>
                </CardContent>
              </Card>

              {/* Risk Scoring */}
              <Card>
                <CardHeader>
                  <CardTitle>Risk Assessment</CardTitle>
                </CardHeader>
                <CardContent>
                  <div className="grid grid-cols-3 gap-4">
                    <div className="text-center p-4 bg-muted rounded-lg">
                      <p className="text-3xl font-bold text-foreground">{risk.likelihood_score}</p>
                      <p className="text-sm text-muted-foreground">Likelihood</p>
                    </div>
                    <div className="text-center p-4 bg-muted rounded-lg">
                      <p className="text-3xl font-bold text-foreground">{risk.impact_score}</p>
                      <p className="text-sm text-muted-foreground">Impact</p>
                    </div>
                    <div className="text-center p-4 bg-muted rounded-lg">
                      <p className="text-3xl font-bold text-foreground">{risk.inherent_score}</p>
                      <p className="text-sm text-muted-foreground">Inherent Score</p>
                    </div>
                  </div>
                  <div className="mt-4 grid grid-cols-2 gap-4">
                    <div className="p-4 border rounded-lg">
                      <p className="text-sm text-muted-foreground">Control Effectiveness</p>
                      <p className="font-medium capitalize">{risk.control_effectiveness}</p>
                    </div>
                    <div className="p-4 border rounded-lg">
                      <p className="text-sm text-muted-foreground">Residual Score</p>
                      <div className="flex items-center gap-2">
                        <span className={cn('w-3 h-3 rounded-full', getRiskColor(risk.residual_score))} />
                        <p className="font-medium">{risk.residual_score} ({getRiskLevel(risk.residual_score)})</p>
                      </div>
                    </div>
                  </div>
                  <div className="mt-4 p-4 border rounded-lg">
                    <div className="flex items-center justify-between">
                      <span className="text-sm text-muted-foreground">Appetite Threshold</span>
                      <span className="font-medium">{risk.appetite_threshold}</span>
                    </div>
                    <div className="mt-2">
                      {risk.within_appetite ? (
                        <div className="flex items-center gap-2 text-green-600">
                          <CheckCircle className="w-4 h-4" />
                          <span className="text-sm">Within appetite</span>
                        </div>
                      ) : (
                        <div className="flex items-center gap-2 text-primary">
                          <AlertTriangle className="w-4 h-4" />
                          <span className="text-sm">Above appetite - requires acceptance</span>
                        </div>
                      )}
                    </div>
                  </div>
                </CardContent>
              </Card>

              {/* Treatments */}
              <Card>
                <CardHeader>
                  <CardTitle>Treatment Actions</CardTitle>
                  <CardDescription>Active mitigation measures</CardDescription>
                </CardHeader>
                <CardContent>
                  {risk.treatments.length > 0 ? (
                    <div className="space-y-3">
                      {risk.treatments.map((treatment) => (
                        <div key={treatment.id} className="p-4 border rounded-lg">
                          <div className="flex items-start justify-between">
                            <div>
                              <p className="font-medium">{treatment.action_description}</p>
                              <div className="flex items-center gap-4 mt-2 text-sm text-muted-foreground">
                                <span className="flex items-center gap-1">
                                  <User className="w-4 h-4" />
                                  {treatment.assigned_to?.name || 'Unassigned'}
                                </span>
                                <span className="flex items-center gap-1">
                                  <Calendar className="w-4 h-4" />
                                  {treatment.due_date}
                                </span>
                              </div>
                            </div>
                            <Badge className={cn(
                              treatment.status === 'complete' && 'bg-green-100 text-green-800',
                              treatment.status === 'in_progress' && 'bg-blue-100 text-blue-800',
                              treatment.status === 'overdue' && 'bg-red-100 text-red-800',
                              treatment.status === 'planned' && 'bg-muted text-foreground',
                            )}>
                              {treatment.status}
                            </Badge>
                          </div>
                          {treatment.expected_score_reduction && (
                            <p className="mt-2 text-sm text-green-600">
                              Expected score reduction: -{treatment.expected_score_reduction}
                            </p>
                          )}
                        </div>
                      ))}
                    </div>
                  ) : (
                    <p className="text-muted-foreground text-sm">No treatment actions defined yet.</p>
                  )}
                </CardContent>
              </Card>

              {/* Linked Events */}
              {risk.events.length > 0 && (
                <Card>
                  <CardHeader>
                    <CardTitle>Linked Events</CardTitle>
                    <CardDescription>Related incidents, alerts, and concerns</CardDescription>
                  </CardHeader>
                  <CardContent>
                    <div className="space-y-2">
                      {risk.events.map((event) => (
                        <div key={event.id} className="flex items-center justify-between p-3 border rounded-lg">
                          <div>
                            <Badge variant="outline" className="capitalize">{event.event_type}</Badge>
                            <span className="ml-2 text-sm">{event.event_reference}</span>
                          </div>
                          <Badge className={cn(
                            event.event_severity === 'critical' && 'bg-red-100 text-red-800',
                            event.event_severity === 'high' && 'bg-orange-100 text-orange-800',
                            event.event_severity === 'medium' && 'bg-yellow-100 text-yellow-800',
                          )}>
                            {event.event_severity}
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
              {/* Details */}
              <Card>
                <CardHeader>
                  <CardTitle>Details</CardTitle>
                </CardHeader>
                <CardContent className="space-y-4">
                  <div>
                    <p className="text-sm text-muted-foreground">Risk Owner</p>
                    <p className="font-medium">{risk.risk_owner?.name || 'Not assigned'}</p>
                  </div>
                  <div>
                    <p className="text-sm text-muted-foreground">Mitigation Strategy</p>
                    <p className="font-medium capitalize">{risk.mitigation_strategy}</p>
                  </div>
                  <div>
                    <p className="text-sm text-muted-foreground">Review Frequency</p>
                    <p className="font-medium capitalize">{risk.review_frequency}</p>
                  </div>
                  <div>
                    <p className="text-sm text-muted-foreground">Next Review</p>
                    <p className="font-medium">{risk.next_review_date}</p>
                  </div>
                </CardContent>
              </Card>

              {/* Acceptances */}
              {risk.acceptances.length > 0 && (
                <Card>
                  <CardHeader>
                    <CardTitle>Risk Acceptances</CardTitle>
                  </CardHeader>
                  <CardContent>
                    <div className="space-y-3">
                      {risk.acceptances.map((acceptance) => (
                        <div key={acceptance.id} className="p-3 bg-primary/10 border border-primary rounded-lg">
                          <p className="text-sm text-primary font-medium capitalize">
                            {acceptance.acceptance_type.replace('_', ' ')}
                          </p>
                          <p className="text-xs text-primary mt-1">
                            Accepted by {acceptance.accepted_by?.name}
                          </p>
                          <p className="text-xs text-primary">
                            Expires: {acceptance.expires_at}
                          </p>
                        </div>
                      ))}
                    </div>
                  </CardContent>
                </Card>
              )}
            </div>
          </div>
      </div>
    </AppLayout>
  );
}
