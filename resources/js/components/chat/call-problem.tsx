import { AlertCircle } from 'lucide-react';

export type CallProblem = { title: string; message: string };

export function describeCallProblem(error: unknown): CallProblem {
    const failure = error as Error;
    if (['NotAllowedError', 'SecurityError'].includes(failure?.name))
        return {
            title: 'Microphone blocked',
            message:
                'Allow microphone access in your browser settings, then try again.',
        };
    if (failure?.name === 'NotFoundError')
        return {
            title: 'No microphone found',
            message:
                'Connect a microphone and try again. Your messages remain available.',
        };
    if (failure?.name === 'NotReadableError')
        return {
            title: 'Microphone unavailable',
            message:
                'Check whether another app is using your microphone, then try again.',
        };
    return {
        title: 'Could not connect',
        message: failure?.message || 'Check your connection and try again.',
    };
}

/** Persistent, readable recovery copy shared by incoming and outgoing controls. */
export function CallProblemNotice({ problem }: { problem: CallProblem }) {
    return (
        <div
            role="alert"
            className="space-y-1 rounded-xl border border-status-critical/30 bg-status-critical-bg p-3 text-sm text-status-critical-foreground"
        >
            <p className="flex items-center gap-2 font-semibold">
                <AlertCircle className="size-4 shrink-0" />
                {problem.title}
            </p>
            <p className="break-words">{problem.message}</p>
        </div>
    );
}
