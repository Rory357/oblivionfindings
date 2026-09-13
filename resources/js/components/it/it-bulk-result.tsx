import { Button } from '@/components/ui/button';

export interface ItBulkResult {
    resource: 'tickets' | 'provisioning';
    action: string;
    selected: number;
    updated: number;
    unchanged: number;
    rejected: number;
    items: {
        id: number;
        status:
            | 'updated'
            | 'unchanged'
            | 'stale'
            | 'unavailable'
            | 'blocked'
            | 'failed';
        message: string;
    }[];
}

/** An outcome is evidence from the server, never inferred from a 200 redirect. */
export function readItBulkResult(
    value: unknown,
    resource: ItBulkResult['resource'],
    command?: { action: string; ids: readonly number[] },
): ItBulkResult | null {
    if (!value || typeof value !== 'object') return null;
    const result = value as ItBulkResult;
    if (
        result.resource !== resource ||
        typeof result.action !== 'string' ||
        !['selected', 'updated', 'unchanged', 'rejected'].every(
            (key) =>
                Number.isInteger(result[key as 'selected']) &&
                result[key as 'selected'] >= 0,
        ) ||
        !Array.isArray(result.items)
    )
        return null;
    if (
        result.items.length !== result.selected ||
        result.updated + result.unchanged + result.rejected !==
            result.selected ||
        !result.items.every(
            (item) =>
                !!item &&
                typeof item === 'object' &&
                Number.isSafeInteger(item.id) &&
                item.id > 0 &&
                [
                    'updated',
                    'unchanged',
                    'stale',
                    'unavailable',
                    'blocked',
                    'failed',
                ].includes(item.status) &&
                typeof item.message === 'string',
        )
    )
        return null;
    const ids = new Set(result.items.map((item) => item.id));
    const updated = result.items.filter(
        (item) => item.status === 'updated',
    ).length;
    const unchanged = result.items.filter(
        (item) => item.status === 'unchanged',
    ).length;
    if (
        ids.size !== result.selected ||
        updated !== result.updated ||
        unchanged !== result.unchanged ||
        result.selected - updated - unchanged !== result.rejected ||
        (command &&
            (result.action !== command.action ||
                command.ids.length !== result.selected ||
                new Set(command.ids).size !== command.ids.length ||
                !command.ids.every((id) => ids.has(id))))
    )
        return null;
    return result;
}

export function ItBulkResultPanel({
    result,
    error,
    onReview,
    reviewing = false,
}: {
    result?: ItBulkResult | null;
    error?: string | null;
    onReview?: () => void;
    reviewing?: boolean;
}) {
    if (!result && !error) return null;
    return (
        <section
            aria-label="Bulk action outcome"
            aria-live="polite"
            className="space-y-3 rounded-[14px] border border-border bg-card p-4"
        >
            <p className="font-semibold">
                {result
                    ? `${result.updated} updated · ${result.unchanged} unchanged · ${result.rejected} not applied`
                    : 'Bulk outcome not confirmed'}
            </p>
            {error && (
                <p role="alert" className="text-sm text-status-critical">
                    {error}
                </p>
            )}
            {result && result.rejected > 0 && (
                <ul className="space-y-1 text-sm">
                    {result.items
                        .filter(
                            (item) =>
                                !['updated', 'unchanged'].includes(item.status),
                        )
                        .map((item) => (
                            <li key={item.id}>
                                Selected record{' '}
                                {result.items.findIndex(
                                    (entry) => entry.id === item.id,
                                ) + 1}
                                : {item.message}
                            </li>
                        ))}
                </ul>
            )}
            {(error || (result?.rejected ?? 0) > 0) && (
                <p className="text-sm text-muted-foreground">
                    Review the current permitted list, then select the records
                    you still want to change. This does not retry the action.
                </p>
            )}
            {onReview && (error || (result?.rejected ?? 0) > 0) && (
                <Button
                    type="button"
                    variant="outline"
                    disabled={reviewing}
                    onClick={onReview}
                >
                    {reviewing ? 'Reviewing…' : 'Review current list'}
                </Button>
            )}
        </section>
    );
}
