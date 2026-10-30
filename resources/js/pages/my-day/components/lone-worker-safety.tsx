import {
    deliveryLabel,
    monitorLabel,
    observationLabel,
    recordedAt,
    SessionFact,
} from '@/components/health-safety/lone-workers/session-panels';
import { SessionStartForm } from '@/components/health-safety/lone-workers/session-start-form';
import type { SessionPickers } from '@/components/health-safety/lone-workers/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    SafetyFormDraftContext,
    useSafetyWorkspaceDrafts,
} from '@/hooks/use-safety-workspace-drafts';
import { loneWorkerHref } from '@/lib/health-safety/lone-worker-navigation';
import { Link } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useState } from 'react';
import type { MyDayLoneWorkerSession } from '../lib/types';

type Options = { pickers: SessionPickers; can: { start_own: boolean } };

export function MyDayLoneWorkerSafety({
    session,
    shiftId,
    workerId,
    onWorkingChange,
}: {
    session: MyDayLoneWorkerSession | null;
    shiftId: number | null;
    workerId: number | null;
    onWorkingChange: (working: boolean) => void;
}) {
    const drafts = useSafetyWorkspaceDrafts();
    const [options, setOptions] = useState<Options | null>(null);
    const [error, setError] = useState(false);
    const [retry, setRetry] = useState(0);
    const [creating, setCreating] = useState(false);
    const sessionId = session?.id ?? null;
    useEffect(() => {
        onWorkingChange(creating || drafts.dirty || drafts.busy);
    }, [creating, drafts.dirty, drafts.busy, onWorkingChange]);
    useEffect(() => () => onWorkingChange(false), [onWorkingChange]);
    useEffect(() => {
        if (creating || sessionId || !shiftId || !workerId) return;
        const controller = new AbortController();
        setError(false);
        setOptions(null);
        axios
            .get<Options>('/health-safety/lone-workers/options', {
                signal: controller.signal,
                headers: { Accept: 'application/json' },
            })
            .then((response) => {
                if (!controller.signal.aborted) setOptions(response.data);
            })
            .catch(() => {
                if (!controller.signal.aborted) setError(true);
            });
        return () => controller.abort();
    }, [creating, sessionId, shiftId, workerId, retry]);
    if (!creating && !session && (!shiftId || !workerId)) return null;
    if (!session && options && !options.can.start_own && !creating) return null;
    const ownPickers = options
        ? {
              ...options.pickers,
              shifts: options.pickers.shifts.filter(
                  (shift) => shift.user_id === workerId,
              ),
          }
        : null;
    return (
        <SafetyFormDraftContext.Provider value={drafts.register}>
            <Card>
                <CardContent className="space-y-4 p-4 sm:p-5">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h2 className="text-section-title">
                            Lone-worker safety
                        </h2>
                        {session && (
                            <StatusBadge
                                variant={
                                    session.status === 'emergency'
                                        ? 'critical'
                                        : session.is_check_in_overdue
                                          ? 'warning'
                                          : 'neutral'
                                }
                            >
                                {session.status === 'emergency'
                                    ? 'Help requested'
                                    : session.is_check_in_overdue
                                      ? 'Check-in overdue'
                                      : 'Session active'}
                            </StatusBadge>
                        )}
                    </div>
                    {creating && ownPickers ? (
                        <SessionStartForm
                            pickers={ownPickers}
                            own
                            returnTo="/my-day"
                            onCancel={() =>
                                drafts.request(() => setCreating(false))
                            }
                        />
                    ) : session ? (
                        <>
                            <p className="text-sm">
                                {session.site?.name ?? 'Site not available'} ·
                                LW-{session.id}
                            </p>
                            <dl className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                                <SessionFact label="Check-in">
                                    {observationLabel(
                                        session.observation.state,
                                    )}
                                    {session.observation.real_check_in_at && (
                                        <span className="block">
                                            {recordedAt(
                                                session.observation
                                                    .real_check_in_at,
                                            )}
                                        </span>
                                    )}
                                </SessionFact>
                                <SessionFact label="Next checkpoint">
                                    {recordedAt(
                                        session.observation.next_due_at,
                                    )}
                                </SessionFact>
                                <SessionFact label="Expected end">
                                    {recordedAt(session.expected_end_at)}
                                </SessionFact>
                                <SessionFact label="Monitor">
                                    {monitorLabel(
                                        session.observation.monitor.state,
                                    )}
                                    <span className="block">
                                        {recordedAt(
                                            session.observation.monitor
                                                .checked_at,
                                        )}
                                    </span>
                                </SessionFact>
                            </dl>
                            {(session.status === 'emergency' ||
                                session.source_delivery) && (
                                <p className="text-sm">
                                    Help request:{' '}
                                    {deliveryLabel(session.source_delivery)}.
                                    Delivery does not confirm that someone has
                                    accepted the response. Open your session to
                                    review response details.
                                </p>
                            )}
                            <div className="flex flex-wrap gap-3">
                                {session.can.check_in && (
                                    <Button asChild className="frontline-tap">
                                        <Link
                                            href={loneWorkerHref(session.id, {
                                                command: 'check-in',
                                                section: 'check-ins',
                                                returnTo: '/my-day',
                                            })}
                                        >
                                            Check in
                                        </Link>
                                    </Button>
                                )}
                                {session.can.panic && (
                                    <Button
                                        asChild
                                        variant="destructive"
                                        className="frontline-tap"
                                    >
                                        <Link
                                            href={loneWorkerHref(session.id, {
                                                command: 'panic',
                                                section: 'response',
                                                returnTo: '/my-day',
                                            })}
                                        >
                                            I need help
                                        </Link>
                                    </Button>
                                )}
                                {session.can.end && (
                                    <Button
                                        asChild
                                        variant="outline"
                                        className="frontline-tap"
                                    >
                                        <Link
                                            href={loneWorkerHref(session.id, {
                                                command: 'end',
                                                returnTo: '/my-day',
                                            })}
                                        >
                                            End safety session
                                        </Link>
                                    </Button>
                                )}
                                <Button
                                    asChild
                                    variant="outline"
                                    className="frontline-tap"
                                >
                                    <Link
                                        href={loneWorkerHref(session.id, {
                                            returnTo: '/my-day',
                                        })}
                                    >
                                        Open safety session
                                    </Link>
                                </Button>
                            </div>
                            <p className="text-subtle">
                                Record your check-in on the session page. Follow
                                your emergency procedure if immediate help is
                                needed.
                            </p>
                        </>
                    ) : error ? (
                        <div role="status" className="space-y-2">
                            <p className="text-sm">
                                Safety session options could not be loaded.
                            </p>
                            <Button
                                variant="outline"
                                className="frontline-tap"
                                onClick={() => setRetry((value) => value + 1)}
                            >
                                Try again
                            </Button>
                        </div>
                    ) : !options ? (
                        <p role="status" className="text-subtle">
                            Checking safety session options…
                        </p>
                    ) : (
                        <>
                            <p className="text-sm">
                                No live safety session is recorded for you.
                                Start one for an eligible active shift when
                                required by your work plan.
                            </p>
                            <Button
                                className="frontline-tap"
                                disabled={
                                    !options.can.start_own ||
                                    !ownPickers?.shifts.length
                                }
                                onClick={() => setCreating(true)}
                            >
                                Start safety session
                            </Button>
                        </>
                    )}
                    <Link
                        href="/my-day/safety-reports"
                        className="frontline-tap frontline-focus inline-flex items-center text-sm text-primary underline"
                    >
                        My safety history
                    </Link>
                </CardContent>
            </Card>
            {drafts.confirmation}
        </SafetyFormDraftContext.Provider>
    );
}
