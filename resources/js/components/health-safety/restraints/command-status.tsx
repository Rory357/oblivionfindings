import { Button } from '@/components/ui/button';
import { CheckCircle2 } from 'lucide-react';

export function SourceCommandStatus({
    receipt,
    failure,
    stale,
    checking,
    allowed,
    canCheck,
    reviewAvailable,
    currentFacts,
    onCheck,
    onReview,
    onDone,
}: {
    receipt: unknown;
    failure: string | null;
    stale: boolean;
    checking: boolean;
    allowed: boolean;
    canCheck: boolean;
    reviewAvailable: boolean;
    currentFacts?: { label: string; value: string | null }[];
    onCheck: () => void;
    onReview: () => void;
    onDone: () => void;
}) {
    if (receipt) {
        const result = receipt as Record<string, unknown>;
        return (
            <div className="space-y-4" role="status">
                <p className="flex items-center gap-2 text-status-success">
                    <CheckCircle2 aria-hidden="true" className="size-5" />
                    {result.changed === false ? 'No change needed · confirmed' : 'Saved and confirmed'}
                </p>
                {result.disposition === 'approval_pending' && (
                    <p className="text-subtle text-status-warning">
                        Approval is still needed. This request has not approved
                        or activated the plan.
                    </p>
                )}
                {result.disposition === 'approved_not_published' && (
                    <p className="text-subtle">
                        The decision is recorded. The plan has not been
                        activated.
                    </p>
                )}
                <Button
                    type="button"
                    onClick={onDone}
                    className="frontline-tap"
                >
                    Done
                </Button>
            </div>
        );
    }
    return (
        <div className="space-y-3">
            {!allowed && !checking && (
                <p role="alert" className="text-subtle text-status-critical">
                    This change is currently unavailable. Your unsaved entries
                    remain here.
                </p>
            )}
            {failure && (
                <p role="alert" className="text-subtle text-status-critical">
                    {failure}
                </p>
            )}
            {stale && (
                <p role="alert" className="text-subtle text-status-warning">
                    This record or its approval policy changed. Check the
                    current work, then review it before saving your retained
                    entries.
                </p>
            )}
            {canCheck && (
                <div className="flex flex-wrap gap-3">
                    <Button
                        type="button"
                        variant="outline"
                        className="frontline-tap"
                        disabled={checking || !!receipt}
                        onClick={onCheck}
                    >
                        {checking
                            ? 'Checking current work…'
                            : 'Check current work'}
                    </Button>
                    {stale && (
                        <Button
                            type="button"
                            variant="outline"
                            className="frontline-tap"
                            disabled={checking || !allowed || !reviewAvailable}
                            onClick={onReview}
                        >
                            Use reviewed current version
                        </Button>
                    )}
                </div>
            )}
            {reviewAvailable && currentFacts && (
                <details className="rounded-xl border border-border p-4">
                    <summary className="frontline-hit text-subtle cursor-pointer font-medium focus-visible:ring-2 focus-visible:ring-ring">
                        Read the current record before using this version
                    </summary>
                    <dl className="mt-4 space-y-3">
                        {currentFacts.map((item) => (
                            <div key={item.label}>
                                <dt className="text-caption text-muted-foreground">
                                    {item.label}
                                </dt>
                                <dd className="text-subtle break-words whitespace-pre-wrap">
                                    {item.value ?? 'Not recorded'}
                                </dd>
                            </div>
                        ))}
                    </dl>
                </details>
            )}
        </div>
    );
}
