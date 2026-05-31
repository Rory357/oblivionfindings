import { Check } from 'lucide-react';

import type { QueueToast } from './use-conflict-queue';

/**
 * Bottom-right toast stack. Dark pill with a success check chip, the action's
 * confirmation title and a muted "{type} · {who}" sub-line. Auto-dismissal is
 * owned by the queue hook; this component only renders + animates entry.
 */
export function ConflictToasts({ toasts }: { toasts: QueueToast[] }) {
    if (toasts.length === 0) return null;

    return (
        <div className="pointer-events-none fixed right-5 bottom-5 z-50 flex flex-col gap-2">
            {toasts.map((toast) => (
                <div
                    key={toast.id}
                    role="status"
                    className="pointer-events-auto flex animate-in items-center gap-3 rounded-xl bg-foreground px-3.5 py-2.5 text-white shadow-lg duration-200 fade-in slide-in-from-bottom-2"
                >
                    <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-status-success text-white">
                        <Check className="h-3.5 w-3.5" strokeWidth={2.5} />
                    </span>
                    <div className="min-w-0">
                        <p className="text-[13px] font-semibold">
                            {toast.title}
                        </p>
                        <p className="text-[11.5px] text-white/60">
                            {toast.sub}
                        </p>
                    </div>
                </div>
            ))}
        </div>
    );
}

export default ConflictToasts;
