import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { HandHelping } from 'lucide-react';
import { useState } from 'react';
import { taskRequest } from '../lib/task-api';
import type { MyDayShiftTask } from '../lib/types';

export function TaskHelp({
    task,
    actorId,
    onSaved,
}: {
    task: MyDayShiftTask;
    actorId?: number;
    onSaved: (task: MyDayShiftTask) => void;
}) {
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [people, setPeople] = useState<{ id: number; name: string }[]>([]);
    const [recipient, setRecipient] = useState('');
    const [reason, setReason] = useState(task.help?.reason ?? '');
    const help = task.help;
    const recipientIsMe = help?.recipient_id === actorId;
    const owner = task.assigned_to === actorId;
    const acceptanceLapsed =
        help?.status === 'accepted' && task.follow_through !== 'accepted_help';
    const canRequest =
        owner &&
        task.can_complete !== false &&
        !task.is_completed &&
        (help?.status !== 'accepted' || acceptanceLapsed);
    const showForm = async () => {
        setOpen(true);
        setBusy(true);
        setError('');
        try {
            const result = await taskRequest<{
                recipients: { id: number; name: string }[];
            }>(`/my-day/tasks/${task.id}/help-recipients`);
            setPeople(result.recipients);
            setRecipient(
                result.recipients.some(
                    (person) => person.id === help?.recipient_id,
                )
                    ? String(help?.recipient_id)
                    : '',
            );
        } catch (cause) {
            setError(
                cause instanceof Error
                    ? cause.message
                    : 'Could not load the people who can help.',
            );
        } finally {
            setBusy(false);
        }
    };
    const send = async (response?: 'accepted' | 'declined') => {
        if (busy) return;
        setBusy(true);
        setError('');
        try {
            const result = await taskRequest<{ task: MyDayShiftTask }>(
                `/my-day/tasks/${task.id}/${response ? 'help-response' : 'help'}`,
                'PUT',
                {
                    expected_version: task.version ?? 0,
                    ...(response
                        ? { response }
                        : {
                              recipient_id: Number(recipient),
                              reason: reason.trim(),
                          }),
                },
            );
            onSaved({
                ...task,
                ...result.task,
                can_complete: recipientIsMe
                    ? response === 'accepted'
                    : task.can_complete,
            });
            setOpen(false);
        } catch (cause) {
            setError(
                cause instanceof Error
                    ? cause.message
                    : 'Could not save the request. Your message is still here.',
            );
        } finally {
            setBusy(false);
        }
    };
    if (!help && !canRequest) return null;
    return (
        <section
            aria-label="Help with this task"
            className="space-y-3 rounded-lg border p-4"
        >
            <h3 className="flex items-center gap-2 font-semibold">
                <HandHelping className="size-4" /> Help with this task
            </h3>
            {help && (
                <div className="space-y-2 text-sm">
                    <p role="status" className="font-medium">
                        {task.is_completed
                            ? 'Task completed'
                            : acceptanceLapsed
                              ? 'Help needs a new owner'
                              : help.status === 'accepted'
                                ? `${recipientIsMe ? 'You have' : `${help.recipient_name} has`} accepted responsibility`
                                : help.status === 'declined'
                                  ? `${help.recipient_name} could not take this on`
                                  : `Waiting for ${help.recipient_name} to accept`}
                    </p>
                    <p className="break-words whitespace-pre-wrap">
                        {help.reason}
                    </p>
                    {!task.is_completed && (
                        <p className="text-muted-foreground">
                            {acceptanceLapsed
                                ? 'The previous colleague is no longer eligible to follow this up. Update the request or finish the task yourself.'
                                : help.status === 'accepted'
                                  ? 'The task stays open until the work is finished.'
                                  : recipientIsMe
                                    ? 'Accept only if you can take responsibility for following this up.'
                                    : 'This is still your responsibility until someone accepts. For urgent support, contact your coordinator directly.'}
                        </p>
                    )}
                </div>
            )}
            {recipientIsMe &&
                help?.status === 'requested' &&
                !task.is_completed && (
                    <div className="flex gap-2">
                        <Button
                            disabled={busy}
                            onClick={() => void send('accepted')}
                        >
                            Accept responsibility
                        </Button>
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={() => void send('declined')}
                        >
                            I cannot take this on
                        </Button>
                    </div>
                )}
            {canRequest && !open && (
                <Button
                    variant="outline"
                    disabled={busy}
                    onClick={() => void showForm()}
                >
                    {help ? 'Update help request' : 'Ask someone for help'}
                </Button>
            )}
            {canRequest && open && (
                <form
                    className="space-y-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        void send();
                    }}
                >
                    <div className="space-y-1.5">
                        <Label htmlFor="task-helper">Who can help?</Label>
                        <select
                            id="task-helper"
                            value={recipient}
                            disabled={busy}
                            onChange={(event) =>
                                setRecipient(event.target.value)
                            }
                            className="min-h-11 w-full rounded-md border bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            <option value="">Choose a colleague…</option>
                            {people.map((person) => (
                                <option key={person.id} value={person.id}>
                                    {person.name}
                                </option>
                            ))}
                        </select>
                        {!busy && !people.length && !error && (
                            <p className="text-sm text-muted-foreground">
                                No eligible colleague is available in this site
                                roster. Contact your coordinator to arrange
                                support.
                            </p>
                        )}
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="task-help-reason">
                            What is stopping the task?
                        </Label>
                        <Textarea
                            id="task-help-reason"
                            maxLength={1000}
                            value={reason}
                            disabled={busy}
                            onChange={(event) => setReason(event.target.value)}
                            placeholder="Explain what help is needed."
                        />
                    </div>
                    <p className="text-sm text-muted-foreground">
                        The colleague will see this request in My Day. It is not
                        an emergency alert.
                    </p>
                    <div className="flex gap-2">
                        <Button
                            type="submit"
                            disabled={busy || !recipient || !reason.trim()}
                        >
                            {busy ? 'Saving…' : 'Request help'}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={busy}
                            onClick={() => setOpen(false)}
                        >
                            Keep task open
                        </Button>
                    </div>
                </form>
            )}
            {error && (
                <p role="alert" className="text-sm text-status-critical">
                    {error}{' '}
                    {open && (
                        <Button
                            variant="link"
                            disabled={busy}
                            onClick={() => void showForm()}
                        >
                            Reload colleagues
                        </Button>
                    )}
                </p>
            )}
        </section>
    );
}
