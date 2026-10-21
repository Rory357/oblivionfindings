import { Button } from '@/components/ui/button';
import { ChevronDown } from 'lucide-react';
import type {
    ChatComposerSession,
    ComposerRecoveryState,
} from './chat-composer-session';
import type { PrivateChatDraft } from './chat-private-draft';

function DraftComparison({
    title,
    value,
}: {
    title: string;
    value: PrivateChatDraft | null;
}) {
    return (
        <section
            aria-label={title}
            className="min-w-0 rounded-md border bg-background p-2"
        >
            <h4 className="mb-1 font-medium">{title}</h4>
            <p className="max-h-24 overflow-y-auto break-words whitespace-pre-wrap">
                {value?.content || 'No message text'}
            </p>
            <p className="mt-1 text-xs text-muted-foreground">
                {value?.uploads.length
                    ? `${value.uploads.length} saved ${value.uploads.length === 1 ? 'attachment' : 'attachments'}`
                    : 'No saved attachments'}
                {value?.reply_to_id ? ' · Reply included' : ''}
            </p>
            {!!value?.uploads.length && (
                <ul className="mt-1 max-h-20 overflow-y-auto text-xs">
                    {value.uploads.map((upload, index) => (
                        <li className="break-words" key={upload.id}>
                            Attachment {index + 1}:{' '}
                            {upload.caption || 'No caption'}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

export function ChatComposerRecovery({
    session,
    state,
    onReturnFocus,
}: {
    session: ChatComposerSession;
    state: ComposerRecoveryState;
    onReturnFocus?: () => void;
}) {
    const button = (
        label: string,
        action: () => void,
        disabled = state.busy,
    ) => (
        <Button
            type="button"
            variant="outline"
            className="h-auto min-h-[44px] whitespace-normal"
            disabled={disabled}
            onClick={action}
        >
            {label}
        </Button>
    );
    const draft = state.draft;
    const send = state.send;
    const pending =
        !!send.reference ||
        ['other_tab', 'storage_error', 'revoked'].includes(send.phase);
    const progress: Partial<Record<typeof send.phase, string>> = {
        preparing: 'Preparing your message…',
        checking: 'Checking whether your message was sent…',
        sending: 'Sending your message…',
        cancelling: 'Stopping this send. Waiting for confirmation.',
    };
    let text = '';
    let actions: React.ReactNode = null;
    if (pending) {
        text =
            send.error ||
            progress[send.phase] ||
            (send.phase === 'ready'
                ? 'This message has not been confirmed as sent. Review it before continuing.'
                : send.reference?.cancelling
                  ? 'Stopping this send. Check again to confirm the result.'
                  : 'Checking the previous message. Nothing is sent automatically.');
        actions = (
            <>
                {button(
                    'Check previous send',
                    () => void session.attempt('check'),
                )}
                {send.phase === 'ready' &&
                    !send.reference?.cancelling &&
                    button(
                        'Send this message',
                        () => void session.attempt('send'),
                    )}
                {send.reference &&
                    button(
                        send.reference.cancelling
                            ? 'Retry stop'
                            : 'Stop this send',
                        () => void session.attempt('cancel'),
                        send.phase === 'cancelling',
                    )}
            </>
        );
    } else if (draft.phase === 'revoked') text = draft.error;
    else if (state.choice === 'loading') {
        text = draft.error || 'Checking your saved draft…';
        if (draft.error)
            actions = button('Check draft', () => void session.check());
    } else if (draft.phase === 'scope_changed') {
        text =
            'The people or client linked to this conversation have changed. The earlier draft cannot be restored here.';
        actions = button('Discard earlier draft', () => void session.discard());
    } else if (draft.phase === 'conflict') {
        text =
            'A different draft was saved elsewhere. Choose which version to continue with.';
        actions = draft.remote ? (
            <details className="group w-full min-w-0">
                <summary className="flex min-h-[44px] cursor-pointer items-center gap-2 rounded-md px-2 font-medium focus-visible:outline-2 focus-visible:outline-primary">
                    <ChevronDown
                        aria-hidden
                        className="size-4 shrink-0 -rotate-90 group-open:rotate-0"
                    />{' '}
                    Review drafts
                </summary>
                <div className="grid min-w-0 gap-2 sm:grid-cols-2">
                    <DraftComparison
                        title="Saved elsewhere"
                        value={draft.remote.draft}
                    />
                    <DraftComparison title="This tab" value={draft.local} />
                </div>
                <p className="my-2 text-xs text-muted-foreground">
                    Use saved replaces this tab’s text and files. Keep this tab
                    retains local files. Neither choice sends a message.
                </p>
                <div className="flex flex-wrap gap-2">
                    {button('Use saved draft', () => session.resume('saved'))}
                    {button('Keep this tab’s draft', () => {
                        session.resume('this_tab');
                        requestAnimationFrame(() => onReturnFocus?.());
                    })}
                </div>
            </details>
        ) : (
            button('Check saved draft', () => void session.check())
        );
    } else if (state.choice === 'resume') {
        text = 'You have an unfinished draft. It has not been sent.';
        actions = (
            <>
                {button('Resume draft', () => session.resume())}
                {button('Discard draft', () => void session.discard())}
            </>
        );
    } else if (draft.error) {
        text = draft.error;
        actions =
            draft.phase === 'uncertain'
                ? button('Retry draft save', () => void session.save())
                : button('Check draft', () => void session.check());
    }
    if (!text) return null;
    return (
        <div
            className="mb-2 rounded-lg border bg-muted/40 p-3 text-sm"
            aria-label="Draft and send recovery"
        >
            <p role="status">{text}</p>
            {pending && send.attempt?.body && (
                <p className="mt-2 max-h-28 overflow-y-auto rounded-md bg-background p-2 break-words whitespace-pre-wrap">
                    {send.attempt.body.content || 'Attachment message'}
                    {send.attempt.body.upload_ids.length > 0 && (
                        <span className="block text-xs text-muted-foreground">
                            {send.attempt.body.upload_ids.length}{' '}
                            {send.attempt.body.upload_ids.length === 1
                                ? 'attachment'
                                : 'attachments'}
                        </span>
                    )}
                </p>
            )}
            {actions && (
                <div className="mt-2 flex flex-wrap gap-2">{actions}</div>
            )}
        </div>
    );
}
