import { CsatStars } from '@/components/it/csat-stars';
import { Button } from '@/components/ui/button';
import {
    TicketRatingDialog,
    type RatingProps,
} from '@/pages/it/tickets/_dialogs';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { Star } from 'lucide-react';
import { useState } from 'react';

export { CsatStars } from '@/components/it/csat-stars';

/** Compact prompt; editing and recovery have room in the canonical dialog. */
export function CsatRater(props: RatingProps) {
    const actorId = usePage<SharedData>().props.auth.user?.id;
    return (
        <RatingPrompt
            key={`${actorId ?? 'none'}:${props.ticketId}`}
            {...props}
        />
    );
}

function RatingPrompt(props: RatingProps) {
    const [open, setOpen] = useState(false);
    return (
        <div className="flex flex-col items-start gap-2">
            {props.score ? (
                <CsatStars score={props.score} />
            ) : (
                <p className="text-sm text-muted-foreground">
                    How was the help you received?
                </p>
            )}
            <Button variant="outline" size="sm" onClick={() => setOpen(true)}>
                <Star className="size-4" aria-hidden="true" />
                {props.score ? 'Edit rating' : 'Rate IT’s help'}
            </Button>
            <TicketRatingDialog
                {...props}
                isOpen={open}
                onClose={() => setOpen(false)}
            />
        </div>
    );
}
