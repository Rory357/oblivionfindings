import { Button } from '@/components/ui/button';
import { MoreVertical } from 'lucide-react';
import type { KeyboardEvent, MouseEvent } from 'react';
import type { RoundSummary } from './types';

type Open = (event: MouseEvent, round: RoundSummary) => void;
export function roundMenuAt(element: Element, round: RoundSummary, open: Open) {
    const rect = element.getBoundingClientRect();
    open(
        {
            preventDefault() {},
            clientX: rect.left,
            clientY: rect.bottom,
        } as MouseEvent,
        round,
    );
}
export function roundMenuKey(
    event: KeyboardEvent,
    round: RoundSummary,
    open: Open,
) {
    if (
        event.key === 'ContextMenu' ||
        (event.shiftKey && event.key === 'F10')
    ) {
        event.preventDefault();
        event.stopPropagation();
        roundMenuAt(event.currentTarget, round, open);
    }
}
export function RoundActions({
    round,
    open,
}: {
    round: RoundSummary;
    open: Open;
}) {
    return (
        <Button
            variant="ghost"
            size="icon"
            aria-label={`Actions for ${round.name}`}
            onClick={(event) => {
                event.stopPropagation();
                roundMenuAt(event.currentTarget, round, open);
            }}
            onKeyDown={(event) => roundMenuKey(event, round, open)}
        >
            <MoreVertical className="size-4" />
        </Button>
    );
}
