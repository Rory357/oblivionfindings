/* eslint-disable no-restricted-syntax -- the star rater is a radiogroup built
 * from styled native buttons (a <Button> component can't be a bare star
 * toggle); every colour is a semantic design token. */
/* CSAT (§K) — the requester rates how IT did on a resolved ticket. One shared
 * surface for the My-tickets prompt card and the workspace rail: 1–5 stars +
 * an optional comment, posted to it.tickets.csat, confetti on a perfect five.
 * Editable until the ticket closes; a score already given shows back. */
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { fireConfetti } from '@/lib/confetti';
import { router } from '@inertiajs/react';
import { Star } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

const LIT = { color: 'var(--status-warning)', fill: 'var(--status-warning)' };

/** Read-only star row for a score already given (the rail / row chip). */
export function CsatStars({
    score,
    size = 'h-4 w-4',
}: {
    score: number;
    size?: string;
}) {
    return (
        <span
            className="inline-flex items-center gap-0.5"
            role="img"
            aria-label={`Rated ${score} out of 5 stars`}
        >
            {[1, 2, 3, 4, 5].map((n) => (
                <Star
                    key={n}
                    aria-hidden
                    className={
                        n <= score ? size : `${size} text-muted-foreground/40`
                    }
                    style={n <= score ? LIT : undefined}
                />
            ))}
        </span>
    );
}

/**
 * Interactive rater: pick a star (keyboard-operable radiogroup) then Submit —
 * two clicks, per §K. A comment is optional. Editable while resolved, so a
 * previously given `score` pre-fills and a re-rate just re-posts.
 */
export function CsatRater({
    ticketId,
    score = null,
    comment: initialComment = '',
    onDone,
}: {
    ticketId: number;
    score?: number | null;
    comment?: string | null;
    onDone?: () => void;
}) {
    const [hover, setHover] = useState(0);
    const [picked, setPicked] = useState<number>(score ?? 0);
    const [comment, setComment] = useState(initialComment ?? '');
    const [saving, setSaving] = useState(false);

    const shown = hover || picked;

    const submit = () => {
        if (!picked) return;
        setSaving(true);
        router.post(
            `/it/tickets/${ticketId}/csat`,
            { score: picked, comment: comment.trim() || null },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Thanks — your feedback helps IT improve.');
                    if (picked === 5) fireConfetti();
                    onDone?.();
                },
                onError: () =>
                    toast.error('Could not save your rating — try again.'),
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <div className="flex flex-col gap-2">
            <div
                className="flex items-center gap-0.5"
                role="radiogroup"
                aria-label="Rate IT's help from 1 to 5 stars"
            >
                {[1, 2, 3, 4, 5].map((n) => (
                    <button
                        key={n}
                        type="button"
                        role="radio"
                        aria-checked={picked === n}
                        aria-label={`${n} star${n > 1 ? 's' : ''}`}
                        disabled={saving}
                        onMouseEnter={() => setHover(n)}
                        onMouseLeave={() => setHover(0)}
                        onFocus={() => setHover(n)}
                        onBlur={() => setHover(0)}
                        onClick={() => setPicked(n)}
                        className="rounded p-0.5 transition-transform hover:scale-110 focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none motion-reduce:transition-none motion-reduce:hover:scale-100"
                    >
                        <Star
                            className={
                                n <= shown
                                    ? 'h-6 w-6'
                                    : 'h-6 w-6 text-muted-foreground/40'
                            }
                            style={n <= shown ? LIT : undefined}
                        />
                    </button>
                ))}
            </div>
            {picked ? (
                <>
                    <Textarea
                        value={comment}
                        onChange={(e) => setComment(e.target.value)}
                        placeholder="Anything to add? (optional)"
                        rows={2}
                        maxLength={1000}
                    />
                    <div className="flex justify-end">
                        <Button size="sm" onClick={submit} disabled={saving}>
                            {score ? 'Update rating' : 'Submit rating'}
                        </Button>
                    </div>
                </>
            ) : null}
        </div>
    );
}
