import { Loader2 } from 'lucide-react';
import type { ChatSelectedFile } from '../chat-selected-file';

/** A send whose result isn't confirmed yet, kept in view until the check settles it. */
export function ThreadPendingSend({
    body,
    files,
    captions,
    sending,
    checking,
}: {
    body: FormData;
    files: ChatSelectedFile[];
    captions: string[];
    sending: boolean;
    checking: boolean;
}) {
    return (
        <div
            role="group"
            aria-label="Pending outgoing message"
            className="mb-4 ml-auto w-fit max-w-[90%] space-y-2 rounded-2xl border border-dashed border-primary/40 bg-primary/5 px-4 py-3 sm:max-w-[75%]"
        >
            <p className="text-xs font-medium">You</p>
            <p className="text-sm leading-relaxed [overflow-wrap:anywhere] whitespace-pre-wrap">
                {String(body.get('content') ?? '')}
            </p>
            {files.length > 0 && (
                <ul
                    aria-label="Pending message attachments"
                    className="max-h-28 overflow-y-auto text-xs"
                >
                    {files.map((file, index) => (
                        <li
                            key={`${file.name}:${index}`}
                            className="py-1 [overflow-wrap:anywhere]"
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
            <p className="flex items-center gap-2 text-xs text-muted-foreground">
                {sending && (
                    <Loader2
                        className="size-3 shrink-0 animate-spin"
                        aria-hidden="true"
                    />
                )}
                {checking
                    ? 'Checking whether it sent…'
                    : sending
                      ? 'Sending…'
                      : 'Not confirmed · Use the send check below'}
            </p>
        </div>
    );
}
