/**
 * Q1 + answer 23 (Stephan, 31 Oct / 1 Nov 2026; board M5b): the safety review for a team lead who reads what happened.
 * Served only when the person can't read the review normally but can read the incident's story
 * (HsIncidentStoryReadService): the steps, their own actions (start, finish, add files), the reporter's "What happened"
 * and "What we did straight away", and how close it is to closing. No investigation, other people's actions, linked
 * incident, History or WorkSafe controls — this view never counts as reading the incident or the review.
 */
import { Head, router } from '@inertiajs/react';
import { CheckCircle2, ClipboardList, Play, ShieldAlert } from 'lucide-react';
import { useState } from 'react';

import {
    ActionEvidencePanel,
    CompleteActionPane,
    type EventCorrectiveAction,
} from '@/components/health-safety/event-detail-dialog';
import { PageHeader, PageHeaderStatusChip, PageLayout } from '@/components/page';
import { PageHeaderFacts, PageHeaderSteps, type PageHeaderStep } from '@/components/page/page-header-strip';
import { Surface } from '@/components/signature/blocks';
import { RecordProgress } from '@/components/signature/record-progress';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatDateOnly, formatDateTime } from '@/lib/datetime';
import { type BreadcrumbItem } from '@/types';

export type StoryReview = {
    id: number;
    reference_number: string;
    title: string;
    status_label: string;
    severity_label: string | null;
    category_label: string;
    /** "from a near miss" / "from an incident". */
    from_label: string;
    site_name: string | null;
    owner_name: string | null;
    steps: Array<{ key: string; label: string; meta: string | null; date: string | null; state: PageHeaderStep['state'] }>;
    story: {
        what_happened: string | null;
        did_straight_away: string | null;
        reported_by: string | null;
        report_label: string;
    };
    actions: EventCorrectiveAction[];
    activity: Array<{ at: string; who: string | null; you: boolean; what: string }>;
    closure: { left: number; done: number; closed_by: string };
    worksafe_with: string;
};

function actionStatus(action: EventCorrectiveAction): { label: string; variant: 'neutral' | 'info' | 'warning' | 'success' | 'critical' } {
    if (action.status === 'open') return { label: 'Not started', variant: 'neutral' };
    if (action.status === 'in_progress') return { label: 'In progress', variant: 'info' };
    if (action.status === 'completed') return { label: 'Done — waiting for check', variant: 'warning' };
    return action.effectiveness_confirmed === false
        ? { label: 'Checked — it didn’t work', variant: 'critical' }
        : { label: 'Checked — it worked', variant: 'success' };
}

export default function SafetyReviewStory({ review }: { review: StoryReview }) {
    const [completing, setCompleting] = useState<number | null>(null);
    const [starting, setStarting] = useState(false);
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Home', href: '/dashboard' },
        { title: 'Health and safety', href: '/health-safety' },
        { title: 'Safety reviews', href: '/health-safety/events' },
        { title: review.title, href: `/health-safety/events/${review.id}` },
    ];
    // Their next step is their own first unfinished action (the review's own next step isn't theirs to take).
    const mine = review.actions.find((a) => a.status === 'open' || a.status === 'in_progress') ?? null;
    const completingAction = review.actions.find((a) => a.id === completing) ?? null;
    const start = (action: EventCorrectiveAction) => {
        setStarting(true);
        router.post(
            `/health-safety/events/${review.id}/corrective-actions/${action.id}/start`,
            {},
            { preserveScroll: true, onFinish: () => setStarting(false) },
        );
    };
    const done = review.actions.filter((a) => ['completed', 'verified', 'closed'].includes(a.status)).length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${review.title} · ${review.reference_number}`} />
            <PageLayout
                hero={
                    <PageHeader
                        variant="profile"
                        wrapTitle
                        backHref="/health-safety/events"
                        icon={ShieldAlert}
                        title={review.title}
                        titleChip={<PageHeaderStatusChip variant="info">{review.status_label}</PageHeaderStatusChip>}
                        subline={[
                            `Safety review ${review.reference_number}`,
                            review.from_label,
                            review.site_name,
                            review.owner_name ? `Owner ${review.owner_name}` : 'No owner yet',
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                        facts={
                            <PageHeaderFacts
                                items={[
                                    ...(review.severity_label
                                        ? [{ value: review.severity_label, label: 'severity', tone: 'warning' as const }]
                                        : []),
                                    { value: review.category_label },
                                ]}
                            />
                        }
                        strip={
                            <PageHeaderSteps
                                steps={review.steps.map((step) => ({
                                    key: step.key,
                                    label: step.label,
                                    state: step.state,
                                    meta: step.meta ?? (step.date ? formatDate(step.date, '') : undefined),
                                }))}
                                ariaLabel="Safety review steps"
                            />
                        }
                    />
                }
            >
                <div className="grid min-w-0 gap-5">
                    <RecordProgress
                        next={
                            mine
                                ? {
                                      title: `Finish “${mine.title}”`,
                                      text: `Your action${mine.due_date ? `, due ${formatDateOnly(mine.due_date)}` : ''}. ${review.worksafe_with}`,
                                      action:
                                          mine.status === 'open' ? (
                                              <Button type="button" disabled={starting} onClick={() => start(mine)}>
                                                  <Play className="size-4" aria-hidden="true" />
                                                  Start
                                              </Button>
                                          ) : (
                                              <Button type="button" onClick={() => setCompleting(mine.id)}>
                                                  <CheckCircle2 className="size-4" aria-hidden="true" />
                                                  Mark done
                                              </Button>
                                          ),
                                  }
                                : {
                                      title: 'Nothing for you to do here',
                                      text: `Your actions are done. ${review.worksafe_with}`,
                                  }
                        }
                        pane={
                            completingAction
                                ? {
                                      title: 'Mark done',
                                      meta: completingAction.title,
                                      children: (
                                          <div className="px-[18px] pb-4">
                                              <CompleteActionPane
                                                  d={{ id: review.id }}
                                                  ca={completingAction}
                                                  onDone={() => setCompleting(null)}
                                              />
                                          </div>
                                      ),
                                  }
                                : {
                                      title: 'Implemented',
                                      meta: `${done} of ${review.actions.length} of your actions done`,
                                      children: (
                                          <ul className="grid">
                                              {review.actions.map((action) => {
                                                  const status = actionStatus(action);
                                                  return (
                                                      <li key={action.id} className="grid gap-2 border-t border-border px-[18px] py-3 first:border-t-0">
                                                          <div className="flex flex-wrap items-center gap-2">
                                                              <b className="font-semibold">{action.title}</b>
                                                              <StatusBadge variant={status.variant} size="sm">
                                                                  {status.label}
                                                              </StatusBadge>
                                                              <span className="text-caption">
                                                                  {action.reference_number}
                                                                  {action.due_date && action.status !== 'verified' && action.status !== 'closed'
                                                                      ? ` · due ${formatDateOnly(action.due_date)}`
                                                                      : ''}
                                                              </span>
                                                          </div>
                                                          {action.evidence.can_upload || action.evidence.attachments.length ? (
                                                              <ActionEvidencePanel
                                                                  d={{ id: review.id }}
                                                                  ca={action}
                                                                  allowUpload={action.evidence.can_upload}
                                                                  allowRemove={false}
                                                              />
                                                          ) : null}
                                                      </li>
                                                  );
                                              })}
                                          </ul>
                                      ),
                                      empty: 'You have no actions on this safety review.',
                                      activity: {
                                          state: 'ready',
                                          items: review.activity.slice(0, 4).map((entry, index) => ({
                                              key: index,
                                              time: formatDateTime(entry.at),
                                              who: entry.you ? 'You' : (entry.who ?? 'Someone'),
                                              what: entry.what,
                                          })),
                                      },
                                      note: `You can add files to your own action. Changing an action’s owner or due date is for health and safety managers${review.site_name ? ` at ${review.site_name}` : ''}.`,
                                  }
                        }
                    />
                    <div className="grid min-w-0 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(18rem,22rem)]">
                        <Surface title="Incident summary" aria-label="Incident summary">
                            <dl className="sg-pane-items">
                                <div>
                                    <dt>What happened</dt>
                                    <dd>{review.story.what_happened || 'Not written in the report.'}</dd>
                                    <span />
                                </div>
                                <div>
                                    <dt>What we did straight away</dt>
                                    <dd>{review.story.did_straight_away || 'Not written in the report.'}</dd>
                                    <span />
                                </div>
                            </dl>
                            <p className="sg-pnote">
                                <ClipboardList aria-hidden="true" />
                                <span>
                                    {review.story.reported_by ? `In ${review.story.reported_by}’s words, from the ${review.story.report_label}. ` : `From the ${review.story.report_label}. `}
                                    The rest of the report stays with managers{review.site_name ? ` at ${review.site_name}` : ''}.
                                </span>
                            </p>
                        </Surface>
                        <section className="sg-surface" aria-label="Closure readiness">
                            <div className="sg-ready" role="presentation">
                                <CheckCircle2 aria-hidden="true" />
                                <div className="min-w-0">
                                    <b>Closure readiness</b>
                                    <span>
                                        {review.closure.left
                                            ? `${review.closure.left} ${review.closure.left === 1 ? 'thing' : 'things'} left · ${review.closure.done} done`
                                            : `Nothing left · all ${review.closure.done} done`}{' '}
                                        · {review.closure.closed_by}
                                    </span>
                                </div>
                            </div>
                        </section>
                    </div>
                </div>
            </PageLayout>
        </AppLayout>
    );
}
