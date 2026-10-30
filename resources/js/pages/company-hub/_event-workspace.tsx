import { EventWizardDialog } from '@/components/hr/calendar/event-wizard-dialog';
import { Button } from '@/components/ui/button';
import { router, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useEffect, useState } from 'react';
import { hubRequest } from './_api';
import { EventDraftWorkspace } from './_event-drafts';
import { eventInitial, type EventOptions } from './_events';
import type { HubEvent } from './_types';

export function EventWorkspace({
    mode,
    event,
}: {
    mode: string;
    event?: HubEvent | null;
}) {
    const actor = (
        usePage().props.auth as { user?: { id: number } } | undefined
    )?.user?.id;
    return (
        <WorkspaceBody
            key={String(actor) + ':' + mode + ':' + (event?.id ?? '')}
            mode={mode}
            event={event}
            actor={actor}
        />
    );
}
function WorkspaceBody({
    mode,
    event,
    actor,
}: {
    mode: string;
    event?: HubEvent | null;
    actor?: number;
}) {
    const [options, setOptions] = useState<EventOptions | null>(null);
    const [error, setError] = useState('');
    const [refresh, setRefresh] = useState(0);
    const [scope, setScope] = useState<'this' | 'following' | 'all' | null>(
        event?.extendedProps.recurring &&
            !event.extendedProps.recurrenceParentId
            ? null
            : 'all',
    );
    const close = () =>
        router.visit('/company-hub?view=events&event_workspace=drafts', {
            preserveScroll: false,
        });
    useEffect(() => {
        if (mode === 'drafts') return;
        let current = true;
        setOptions(null);
        setError('');
        hubRequest<EventOptions>('/company-hub/events/options', 'GET')
            .then((result) => {
                if (!current) return;
                if (result.actor_id !== undefined && result.actor_id !== actor)
                    throw new Error(
                        'Your account has changed. Reopen the event workspace.',
                    );
                setOptions(result);
            })
            .catch((e) => {
                if (current) setError((e as Error).message);
            });
        return () => {
            current = false;
        };
    }, [mode, actor, refresh]);
    if (mode === 'drafts') return <EventDraftWorkspace />;
    if (
        mode === 'edit' &&
        (!event ||
            !event.editable ||
            event.extendedProps.cancellation?.cancelled)
    )
        return (
            <div role="alert">
                This event is no longer available to edit.
                <Button variant="link" onClick={close}>
                    Back to event drafts
                </Button>
            </div>
        );
    if (error)
        return (
            <div role="alert" className="space-y-4">
                <p>{error}</p>
                <Button
                    variant="outline"
                    onClick={() => setRefresh((n) => n + 1)}
                >
                    Try again
                </Button>
                <Button variant="ghost" onClick={close}>
                    Back to event drafts
                </Button>
            </div>
        );
    if (!options)
        return (
            <p role="status" className="py-10 text-sm text-muted-foreground">
                Opening the event workspace…
            </p>
        );
    if (!scope)
        return (
            <section className="max-w-3xl space-y-6">
                <Button variant="ghost" onClick={close}>
                    <ArrowLeft className="size-4" />
                    Back to events
                </Button>
                <h1 className="text-2xl font-semibold">
                    Edit a recurring event
                </h1>
                <p className="text-sm text-muted-foreground">
                    Choose which dates you want to change. Registrations stay
                    linked to their dates.
                </p>
                <div className="grid gap-3">
                    {options.can_manage_recurring && (
                        <>
                            <Button
                                variant="outline"
                                className="justify-start"
                                onClick={() => setScope('this')}
                            >
                                This date only
                            </Button>
                            <Button
                                variant="outline"
                                className="justify-start"
                                onClick={() => setScope('following')}
                            >
                                This and following dates
                            </Button>
                        </>
                    )}
                    <Button
                        variant="outline"
                        className="justify-start"
                        onClick={() => setScope('all')}
                    >
                        Whole series
                    </Button>
                </div>
            </section>
        );
    return (
        <EventWizardDialog
            key={scope}
            open
            presentation="page"
            {...options}
            initial={
                mode === 'edit' && event ? eventInitial(event, scope) : null
            }
            publishing={{
                can_publish: !!options.can_publish,
                can_schedule: !!options.can_schedule,
                cutoff_supported: !!options.cutoff_supported,
                actor_id: options.actor_id,
            }}
            onClose={close}
            onSaved={() => {}}
        />
    );
}
