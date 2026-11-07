import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { Loader2 } from 'lucide-react';
import type { ChatSelectedFile } from '../chat-selected-file';

/**
 * Your message while it is on its way (C3.27): a faded bubble that says Sending…, then Not sent
 * with Retry. The check that stops a retry posting it twice happens behind the scenes.
 */
export function ThreadPendingSend({
    body,
    files,
    captions,
    sending,
    checking,
    onRetry,
}: {
    body: FormData;
    files: ChatSelectedFile[];
    captions: string[];
    sending: boolean;
    checking: boolean;
    /** Absent when the person can no longer send here (the composer offers a check instead). */
    onRetry?: () => void;
}) {
    const busy = sending || checking;
    return (
        <div
            role="group"
            aria-label="Pending outgoing message"
            className="mt-3 ml-auto flex w-fit max-w-[78%] flex-col items-end"
        >
            <div
                className={cn(
                    'rounded-2xl bg-primary/10 px-3 py-2',
                    busy && 'opacity-60',
                )}
            >
                <p className="text-sm leading-relaxed [overflow-wrap:anywhere] whitespace-pre-wrap">
                    {String(body.get('content') ?? '')}
                </p>
                {files.length > 0 && (
                    <ul
                        aria-label="Pending message attachments"
                        className="mt-1 max-h-28 overflow-y-auto text-xs"
                    >
                        {files.map((file, index) => (
                            <li
                                key={`${file.name}:${index}`}
                                className="py-0.5 [overflow-wrap:anywhere]"
                            >
                                {file.name}
                                {captions[index] && (
                                    <p className="text-muted-foreground">
                                        {captions[index]}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
            <p className="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground">
                {busy && (
                    <Loader2
                        className="size-3 shrink-0 animate-spin"
                        aria-hidden="true"
                    />
                )}
                {checking ? 'Checking…' : sending ? 'Sending…' : 'Not sent'}
                {!busy && onRetry && (
                    <>
                        <span aria-hidden="true">·</span>
                        <Button
                            type="button"
                            variant="link"
                            size="xs"
                            className="h-auto p-0 text-xs"
                            onClick={onRetry}
                        >
                            Retry
                        </Button>
                    </>
                )}
            </p>
        </div>
    );
}
