import { Head, useForm, router } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import { Play, Lock, CheckCircle } from 'lucide-react';
import { PageHero, PageLayout } from '@/components/page';
import { cn } from '@/lib/utils';
import { governanceStatusColor } from '@/lib/governance-status';

interface Question {
  text: string;
  type: 'rating' | 'text' | 'yes_no';
}

interface Response {
  id: number;
  board_member: { user: { name: string } };
  is_complete: boolean;
  submitted_at: string | null;
}

interface Evaluation {
  id: number;
  title: string;
  evaluation_type: string;
  status: string;
  period_start: string;
  period_end: string;
  due_date: string;
  questions: Question[];
  responses: Response[];
}

interface Props extends PageProps {
  evaluation: Evaluation;
  boardMembers: Array<{ id: number; user: { name: string } }>;
  myResponse: { answers: Record<string, string>; overall_comments: string } | null;
  responseRate: { total: number; completed: number };
}

export default function EvaluationShow({ auth, evaluation, boardMembers, myResponse, responseRate }: Props) {
  const { data, setData, post, processing } = useForm({
    answers: myResponse?.answers || {} as Record<string, string>,
    overall_comments: myResponse?.overall_comments || '',
  });

  const handleRespond = (e: React.FormEvent) => {
    e.preventDefault();
    post(`/governance/evaluations/${evaluation.id}/respond`);
  };

  const handleLaunch = () => router.post(`/governance/evaluations/${evaluation.id}/launch`);
  const handleClose = () => router.post(`/governance/evaluations/${evaluation.id}/close`);

  const getStatusColor = (status: string) => governanceStatusColor(status);

  return (
    <AppLayout>
      <Head title={evaluation.title} />
      <PageLayout
        hero={
          <PageHero
            variant="compact"
            backHref="/governance/evaluations"
            title={
              <span className="flex flex-wrap items-center gap-3" dusk="evaluation-title">
                {evaluation.title}
                <Badge className={cn('text-xs', getStatusColor(evaluation.status))}>{evaluation.status}</Badge>
              </span>
            }
            description={`Period: ${new Date(evaluation.period_start).toLocaleDateString('en-NZ')} - ${new Date(evaluation.period_end).toLocaleDateString('en-NZ')}`}
            actions={
              <>
                {evaluation.status === 'draft' && <Button onClick={handleLaunch}><Play className="w-4 h-4 mr-2" /> Launch</Button>}
                {evaluation.status === 'active' && <Button variant="outline" onClick={handleClose}><Lock className="w-4 h-4 mr-2" /> Close</Button>}
              </>
            }
          />
        }
      >
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <div className="lg:col-span-2">
            {evaluation.status === 'active' && (
              <Card>
                <CardHeader>
                  <CardTitle>Your Response</CardTitle>
                  <CardDescription>Answer each question below</CardDescription>
                </CardHeader>
                <CardContent>
                  <form onSubmit={handleRespond} className="space-y-6">
                    {evaluation.questions.map((q, i) => (
                      <div key={i}>
                        <Label className="text-base font-medium">{i + 1}. {q.text}</Label>
                        {q.type === 'rating' && (
                          <div className="flex gap-2 mt-2">
                            {[1, 2, 3, 4, 5].map(n => (
                              <Button
                                key={n}
                                dusk={`rating-${i}-${n}`}
                                type="button"
                                variant={data.answers[String(i)] === String(n) ? 'default' : 'outline'}
                                size="sm"
                                onClick={() => setData('answers', { ...data.answers, [String(i)]: String(n) })}
                              >{n}</Button>
                            ))}
                          </div>
                        )}
                        {q.type === 'text' && (
                          <Textarea
                            dusk={`answer-${i}`}
                            className="mt-2"
                            value={data.answers[String(i)] || ''}
                            onChange={e => setData('answers', { ...data.answers, [String(i)]: e.target.value })}
                          />
                        )}
                        {q.type === 'yes_no' && (
                          <div className="flex gap-2 mt-2">
                            {['Yes', 'No'].map(v => (
                              <Button
                                key={v}
                                dusk={`answer-${i}-${v.toLowerCase()}`}
                                type="button"
                                variant={data.answers[String(i)] === v ? 'default' : 'outline'}
                                size="sm"
                                onClick={() => setData('answers', { ...data.answers, [String(i)]: v })}
                              >{v}</Button>
                            ))}
                          </div>
                        )}
                      </div>
                    ))}
                    <div>
                      <Label>Overall Comments</Label>
                      <Textarea dusk="overall-comments" value={data.overall_comments} onChange={e => setData('overall_comments', e.target.value)} rows={3} />
                    </div>
                    <Button type="submit" disabled={processing} dusk="submit-evaluation-response">Submit Response</Button>
                  </form>
                </CardContent>
              </Card>
            )}
          </div>

          <div className="space-y-6">
            <Card>
              <CardHeader><CardTitle>Response Rate</CardTitle></CardHeader>
              <CardContent>
                <div className="text-center mb-3">
                  <span className="text-3xl font-bold">{responseRate.completed}</span>
                  <span className="text-muted-foreground"> / {responseRate.total}</span>
                </div>
                <div className="w-full bg-muted rounded-full h-2">
                  <div
                    className="bg-status-info h-2 rounded-full"
                    style={{ width: `${responseRate.total > 0 ? (responseRate.completed / responseRate.total) * 100 : 0}%` }}
                  />
                </div>
                <div className="mt-4 space-y-2">
                  {evaluation.responses.map(r => (
                    <div key={r.id} className="flex items-center gap-2 text-sm">
                      <CheckCircle className={cn('w-4 h-4', r.is_complete ? 'text-status-success' : 'text-muted-foreground')} />
                      <span>{r.board_member?.user?.name}</span>
                    </div>
                  ))}
                </div>
              </CardContent>
            </Card>
          </div>
        </div>
      </PageLayout>
    </AppLayout>
  );
}
