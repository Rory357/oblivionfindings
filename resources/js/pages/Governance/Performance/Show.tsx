import { Head, Link, useForm } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { index as performanceIndex, assess as assessReview } from '@/routes/governance/performance';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Progress } from '@/components/ui/progress';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Target, TrendingUp, Star, Award, User, Calendar } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useMemo, useState } from 'react';

interface Goal {
  id: number;
  pillar: string;
  goal_description: string;
  weight: number;
  target_score: number;
  actual_score: number | null;
  status: string;
  evidence_summary: string | null;
  board_assessment: string | null;
}

interface Kpi {
  id: number;
  kpi_name: string;
  target_value: number;
  actual_value: number | null;
  unit: string;
  is_automated: boolean;
}

interface Review {
  id: number;
  reviewee: { id: number; name: string };
  review_cycle: string;
  period_start: string;
  period_end: string;
  status: string;
  overall_rating: string | null;
  board_decision: string | null;
  decision_notes: string | null;
  self_assessment_submitted_at: string | null;
  goals: Goal[];
  kpis: Kpi[];
}

interface Props extends PageProps {
  review: Review;
  can_assess: boolean;
}

export default function PerformanceShow({ auth, review, can_assess }: Props) {
  const [assessmentOpen, setAssessmentOpen] = useState(false);

  const initialAssessments = useMemo(() => {
    return review.goals.reduce<Record<string, { score: string; comments: string }>>((acc, goal) => {
      acc[String(goal.id)] = {
        score: String(goal.actual_score ?? goal.target_score ?? 3),
        comments: goal.board_assessment ?? '',
      };
      return acc;
    }, {});
  }, [review.goals]);

  const { data, setData, post, processing, errors } = useForm({
    goal_assessments: initialAssessments,
    overall_rating: review.overall_rating ?? '',
    board_decision: review.board_decision ?? '',
    decision_notes: review.decision_notes ?? '',
  });

  const getPillarLabel = (pillar: string) => {
    const labels: Record<string, string> = {
      safety: 'Safety',
      quality: 'Quality',
      people: 'People',
      finance: 'Finance',
      compliance: 'Compliance',
      it_resilience: 'IT Resilience',
    };
    return labels[pillar] || pillar;
  };

  const getStatusColor = (status: string) => {
    switch (status) {
      case 'completed':
        return 'bg-green-100 text-green-800';
      case 'board_review':
        return 'bg-purple-100 text-purple-800';
      case 'peer_review':
        return 'bg-blue-100 text-blue-800';
      case 'self_review':
        return 'bg-yellow-100 text-yellow-800';
      default:
        return 'bg-gray-100 text-gray-800';
    }
  };

  const getRatingLabel = (rating: string | null) => {
    if (!rating) return 'Not yet rated';
    const labels: Record<string, string> = {
      exceeds: 'Exceeds Expectations',
      meets: 'Meets Expectations',
      needs_improvement: 'Needs Improvement',
      unsatisfactory: 'Unsatisfactory',
    };
    return labels[rating] || rating;
  };

  const getRatingColor = (rating: string | null) => {
    switch (rating) {
      case 'exceeds':
        return 'bg-green-100 text-green-800';
      case 'meets':
        return 'bg-blue-100 text-blue-800';
      case 'needs_improvement':
        return 'bg-yellow-100 text-yellow-800';
      case 'unsatisfactory':
        return 'bg-red-100 text-red-800';
      default:
        return 'bg-gray-100 text-gray-800';
    }
  };

  const getGoalStatusColor = (status: string) => {
    switch (status) {
      case 'achieved':
        return 'bg-green-100 text-green-800';
      case 'partially_achieved':
        return 'bg-yellow-100 text-yellow-800';
      case 'missed':
        return 'bg-red-100 text-red-800';
      case 'in_progress':
        return 'bg-blue-100 text-blue-800';
      default:
        return 'bg-gray-100 text-gray-800';
    }
  };

  const calculateOverallProgress = () => {
    if (review.goals.length === 0) return 0;
    const totalWeight = review.goals.reduce((sum, g) => sum + g.weight, 0);
    const weightedScore = review.goals.reduce((sum, g) => {
      const score = g.actual_score || 0;
      return sum + (score / g.target_score) * g.weight;
    }, 0);
    return Math.round((weightedScore / totalWeight) * 100);
  };

  const updateGoalAssessment = (goalId: number, field: 'score' | 'comments', value: string) => {
    const key = String(goalId);
    setData('goal_assessments', {
      ...data.goal_assessments,
      [key]: {
        ...(data.goal_assessments[key] ?? { score: '', comments: '' }),
        [field]: value,
      },
    });
  };

  const submitAssessment = (event: React.FormEvent) => {
    event.preventDefault();
    post(assessReview.url({ review: review.id }), {
      onSuccess: () => setAssessmentOpen(false),
    });
  };

  return (
    <AppLayout
      user={auth.user}
      breadcrumbs={[
        { title: 'Governance', href: '/governance/dashboard' },
        { title: 'Performance', href: '/governance/performance' },
        { title: 'Review', href: `/governance/performance/${review.id}` },
      ]}
    >
      <Head title={`Performance Review - ${review.reviewee.name}`} />

      <div className="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
          {/* Back Link */}
          <div className="mb-4">
            <Link href={performanceIndex.url()} className="text-sm text-blue-600 hover:underline">
              ← Back to Performance Reviews
            </Link>
          </div>

          {/* Header */}
          <div className="flex items-start justify-between mb-6">
            <div>
              <div className="flex items-center gap-3 mb-2">
                <Award className="w-8 h-8 text-purple-500" />
                <div>
                  <h1 className="text-2xl font-bold text-gray-900">Performance Review</h1>
                  <p className="text-gray-500">{review.reviewee.name} - {review.review_cycle}</p>
                </div>
              </div>
              <div className="flex items-center gap-2 mt-2">
                <Badge className={getStatusColor(review.status)}>{review.status.replace('_', ' ')}</Badge>
                {review.overall_rating && (
                  <Badge className={getRatingColor(review.overall_rating)}>
                    {getRatingLabel(review.overall_rating)}
                  </Badge>
                )}
              </div>
            </div>
            {review.status !== 'completed' && can_assess && (
              <Button onClick={() => setAssessmentOpen(true)}>Continue Review</Button>
            )}
          </div>

          {/* Overview Card */}
          <Card className="mb-6">
            <CardHeader>
              <CardTitle>Review Overview</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div className="p-4 bg-gray-50 rounded-lg">
                  <p className="text-sm text-gray-500">Period</p>
                  <p className="font-medium">{review.period_start} to {review.period_end}</p>
                </div>
                <div className="p-4 bg-gray-50 rounded-lg">
                  <p className="text-sm text-gray-500">Overall Progress</p>
                  <div className="flex items-center gap-2">
                    <Progress value={calculateOverallProgress()} className="flex-1" />
                    <span className="font-medium">{calculateOverallProgress()}%</span>
                  </div>
                </div>
                <div className="p-4 bg-gray-50 rounded-lg">
                  <p className="text-sm text-gray-500">Goals</p>
                  <p className="font-medium">{review.goals.length} defined</p>
                </div>
                <div className="p-4 bg-gray-50 rounded-lg">
                  <p className="text-sm text-gray-500">KPIs</p>
                  <p className="font-medium">{review.kpis.length} tracked</p>
                </div>
              </div>
              {review.board_decision && (
                <div className="mt-4 p-4 bg-purple-50 border border-purple-200 rounded-lg">
                  <p className="text-sm font-medium text-purple-800">Board Decision</p>
                  <p className="text-purple-700 capitalize">{review.board_decision.replace('_', ' ')}</p>
                </div>
              )}
            </CardContent>
          </Card>

          <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {/* Goals */}
            <div className="lg:col-span-2 space-y-6">
              <Card>
                <CardHeader>
                  <CardTitle className="flex items-center gap-2">
                    <Target className="w-5 h-5" />
                    Performance Goals
                  </CardTitle>
                  <CardDescription>Goals by strategic pillar</CardDescription>
                </CardHeader>
                <CardContent>
                  {review.goals.length > 0 ? (
                    <div className="space-y-4">
                      {review.goals.map((goal) => (
                        <div key={goal.id} className="p-4 border rounded-lg">
                          <div className="flex items-start justify-between mb-2">
                            <div>
                              <Badge variant="outline" className="mb-2">{getPillarLabel(goal.pillar)}</Badge>
                              <p className="font-medium">{goal.goal_description}</p>
                            </div>
                            <Badge className={getGoalStatusColor(goal.status)}>
                              {goal.status.replace('_', ' ')}
                            </Badge>
                          </div>
                          <div className="mt-3">
                            <div className="flex items-center justify-between text-sm text-gray-500 mb-1">
                              <span>Progress</span>
                              <span>{goal.actual_score || 0} / {goal.target_score} (Weight: {goal.weight}%)</span>
                            </div>
                            <Progress
                              value={((goal.actual_score || 0) / goal.target_score) * 100}
                              className={cn(
                                goal.status === 'achieved' && '[&>div]:bg-green-500',
                                goal.status === 'missed' && '[&>div]:bg-red-500',
                              )}
                            />
                          </div>
                          {goal.evidence_summary && (
                            <p className="mt-2 text-sm text-gray-600">{goal.evidence_summary}</p>
                          )}
                        </div>
                      ))}
                    </div>
                  ) : (
                    <p className="text-gray-500 text-sm">No goals defined for this review.</p>
                  )}
                </CardContent>
              </Card>

              {/* KPIs */}
              {review.kpis.length > 0 && (
                <Card>
                  <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                      <TrendingUp className="w-5 h-5" />
                      Key Performance Indicators
                    </CardTitle>
                  </CardHeader>
                  <CardContent>
                    <div className="space-y-3">
                      {review.kpis.map((kpi) => (
                        <div key={kpi.id} className="flex items-center justify-between p-3 border rounded-lg">
                          <div>
                            <p className="font-medium">{kpi.kpi_name}</p>
                            <p className="text-sm text-gray-500">
                              Target: {kpi.target_value} {kpi.unit}
                              {kpi.is_automated && <Badge variant="outline" className="ml-2 text-xs">Auto</Badge>}
                            </p>
                          </div>
                          <div className="text-right">
                            <p className="text-xl font-bold">
                              {kpi.actual_value !== null ? kpi.actual_value : '-'}
                            </p>
                            <p className="text-xs text-gray-500">{kpi.unit}</p>
                          </div>
                        </div>
                      ))}
                    </div>
                  </CardContent>
                </Card>
              )}
            </div>

            {/* Sidebar */}
            <div className="space-y-6">
              {/* Timeline */}
              <Card>
                <CardHeader>
                  <CardTitle>Review Timeline</CardTitle>
                </CardHeader>
                <CardContent className="space-y-4">
                  <div className="flex items-center gap-3">
                    <div className={cn(
                      'w-8 h-8 rounded-full flex items-center justify-center',
                      review.self_assessment_submitted_at ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-400'
                    )}>
                      <User className="w-4 h-4" />
                    </div>
                    <div>
                      <p className="text-sm font-medium">Self Assessment</p>
                      <p className="text-xs text-gray-500">
                      {review.self_assessment_submitted_at || 'Pending'}
                      </p>
                    </div>
                  </div>
                  <div className="flex items-center gap-3">
                    <div className={cn(
                      'w-8 h-8 rounded-full flex items-center justify-center',
                      review.status === 'board_review' || review.status === 'completed'
                        ? 'bg-green-100 text-green-600'
                        : 'bg-gray-100 text-gray-400'
                    )}>
                      <Star className="w-4 h-4" />
                    </div>
                    <div>
                      <p className="text-sm font-medium">Board Assessment</p>
                      <p className="text-xs text-gray-500">
                      {review.status === 'board_review' || review.status === 'completed' ? 'Submitted' : 'Pending'}
                      </p>
                    </div>
                  </div>
                  <div className="flex items-center gap-3">
                    <div className={cn(
                      'w-8 h-8 rounded-full flex items-center justify-center',
                      review.status === 'completed' ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-400'
                    )}>
                      <Award className="w-4 h-4" />
                    </div>
                    <div>
                      <p className="text-sm font-medium">Completed</p>
                      <p className="text-xs text-gray-500">
                        {review.status === 'completed' ? 'Done' : 'Pending'}
                      </p>
                    </div>
                  </div>
                </CardContent>
              </Card>

              {/* Rating Summary */}
              {review.overall_rating && (
                <Card className={cn(
                  review.overall_rating === 'exceeds' && 'border-green-200 bg-green-50',
                  review.overall_rating === 'meets' && 'border-blue-200 bg-blue-50',
                  review.overall_rating === 'needs_improvement' && 'border-yellow-200 bg-yellow-50',
                  review.overall_rating === 'unsatisfactory' && 'border-red-200 bg-red-50',
                )}>
                  <CardHeader>
                    <CardTitle>Overall Rating</CardTitle>
                  </CardHeader>
                  <CardContent>
                    <div className="text-center">
                      <Star className={cn(
                        'w-12 h-12 mx-auto mb-2',
                        review.overall_rating === 'exceeds' && 'text-green-500',
                        review.overall_rating === 'meets' && 'text-blue-500',
                        review.overall_rating === 'needs_improvement' && 'text-yellow-500',
                        review.overall_rating === 'unsatisfactory' && 'text-red-500',
                      )} />
                      <p className="text-lg font-bold">{getRatingLabel(review.overall_rating)}</p>
                    </div>
                  </CardContent>
                </Card>
              )}
            </div>
          </div>
      </div>
      <Dialog open={assessmentOpen} onOpenChange={setAssessmentOpen}>
        <DialogContent className="max-w-3xl">
          <DialogHeader>
            <DialogTitle>Continue Review</DialogTitle>
          </DialogHeader>
          <form onSubmit={submitAssessment} className="space-y-6">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="overall_rating">Overall Rating</Label>
                <Select
                  value={data.overall_rating}
                  onValueChange={(value) => setData('overall_rating', value)}
                >
                  <SelectTrigger id="overall_rating">
                    <SelectValue placeholder="Select rating" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="exceeds">Exceeds Expectations</SelectItem>
                    <SelectItem value="meets">Meets Expectations</SelectItem>
                    <SelectItem value="needs_improvement">Needs Improvement</SelectItem>
                    <SelectItem value="unsatisfactory">Unsatisfactory</SelectItem>
                  </SelectContent>
                </Select>
                {errors.overall_rating && <p className="text-sm text-red-600">{errors.overall_rating}</p>}
              </div>
              <div className="space-y-2">
                <Label htmlFor="board_decision">Board Decision</Label>
                <Select
                  value={data.board_decision}
                  onValueChange={(value) => setData('board_decision', value)}
                >
                  <SelectTrigger id="board_decision">
                    <SelectValue placeholder="Select decision" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="remuneration_increase">Remuneration increase</SelectItem>
                    <SelectItem value="maintain">Maintain</SelectItem>
                    <SelectItem value="development_plan">Development plan</SelectItem>
                    <SelectItem value="performance_improvement">Performance improvement</SelectItem>
                  </SelectContent>
                </Select>
                {errors.board_decision && <p className="text-sm text-red-600">{errors.board_decision}</p>}
              </div>
            </div>

            <div className="space-y-2">
              <Label htmlFor="decision_notes">Decision Notes</Label>
              <Textarea
                id="decision_notes"
                value={data.decision_notes}
                onChange={(e) => setData('decision_notes', e.target.value)}
                rows={3}
              />
            </div>

            <div className="space-y-4">
              <h3 className="text-lg font-semibold">Goal Assessments</h3>
              {review.goals.map((goal) => (
                <div key={goal.id} className="rounded-lg border p-4 space-y-3">
                  <div className="flex items-center justify-between">
                    <div>
                      <Badge variant="outline" className="mb-2">{getPillarLabel(goal.pillar)}</Badge>
                      <p className="font-medium">{goal.goal_description}</p>
                      <p className="text-sm text-gray-500">Target: {goal.target_score}</p>
                    </div>
                    <div className="w-24">
                      <Label className="text-xs">Score (1-5)</Label>
                      <Input
                        type="number"
                        min={1}
                        max={5}
                        step={1}
                        value={data.goal_assessments[String(goal.id)]?.score ?? ''}
                        onChange={(e) => updateGoalAssessment(goal.id, 'score', e.target.value)}
                      />
                      {errors[`goal_assessments.${goal.id}.score`] && (
                        <p className="text-xs text-red-600">{errors[`goal_assessments.${goal.id}.score`]}</p>
                      )}
                    </div>
                  </div>
                  <div className="space-y-1">
                    <Label className="text-xs">Comments</Label>
                    <Textarea
                      value={data.goal_assessments[String(goal.id)]?.comments ?? ''}
                      onChange={(e) => updateGoalAssessment(goal.id, 'comments', e.target.value)}
                      rows={2}
                    />
                    {errors[`goal_assessments.${goal.id}.comments`] && (
                      <p className="text-xs text-red-600">{errors[`goal_assessments.${goal.id}.comments`]}</p>
                    )}
                  </div>
                </div>
              ))}
            </div>

            <div className="flex justify-end gap-2">
              <Button type="button" variant="outline" onClick={() => setAssessmentOpen(false)}>
                Cancel
              </Button>
              <Button type="submit" disabled={processing}>
                {processing ? 'Submitting...' : 'Submit Assessment'}
              </Button>
            </div>
          </form>
        </DialogContent>
      </Dialog>
    </AppLayout>
  );
}
