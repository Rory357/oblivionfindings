/* eslint-disable no-restricted-syntax -- Interview scorecard entry: a single-form
 * dialog posting to the live scoreInterview endpoint (hr_interview_scores).
 * Native rating/recommendation buttons; semantic tokens only. */
import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';

import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

export type ScoreTarget = {
    id: number;
    candidate: string;
    kit_name?: string | null;
    criteria: { label: string; weight: number }[];
};

const RECOMMENDATIONS: { value: string; label: string }[] = [
    { value: 'strong_yes', label: 'Strong yes' },
    { value: 'yes', label: 'Yes' },
    { value: 'maybe', label: 'Maybe' },
    { value: 'no', label: 'No' },
    { value: 'strong_no', label: 'Strong no' },
];

export function ScoreDialog({
    open,
    onClose,
    interview,
}: {
    open: boolean;
    onClose: () => void;
    interview: ScoreTarget;
}) {
    const [ratings, setRatings] = useState<Record<string, number>>(() =>
        Object.fromEntries(interview.criteria.map((c) => [c.label, 3])),
    );
    const [overall, setOverall] = useState('70');
    const [recommendation, setRecommendation] = useState('yes');
    const [notes, setNotes] = useState('');
    const form = useForm({});

    const hasCriteria = interview.criteria.length > 0;

    const submit = () => {
        const payload: Record<string, unknown> = { recommendation, notes: notes.trim() || null };
        if (hasCriteria) {
            // Scores are 0–100; the 1–5 rating maps to 20/40/60/80/100.
            payload.criteria_scores = interview.criteria.map((c) => ({
                label: c.label,
                score: (ratings[c.label] ?? 3) * 20,
                // Preserve a deliberate 0 weight (?? not ||) — the backend allows min:0.
                weight: c.weight ?? undefined,
            }));
        } else {
            payload.overall_score = Number(overall) || 0;
        }
        form.transform(() => payload);
        form.post(`/hr/recruitment/interviews/${interview.id}/score`, {
            preserveScroll: true,
            onSuccess: (page) => {
                const f = (page.props as { flash?: { error?: string } }).flash;
                if (f?.error) {
                    toast.error('Could not save scorecard', { description: f.error });
                    return;
                }
                toast.success(`Scorecard saved for ${interview.candidate}`);
                onClose();
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Score interview — {interview.candidate}</DialogTitle>
                    <DialogDescription>
                        {interview.kit_name ? `${interview.kit_name} · rate each criterion` : 'Record an overall score and recommendation.'}
                    </DialogDescription>
                </DialogHeader>

                <div className="flex flex-col gap-4">
                    {hasCriteria ? (
                        <div className="flex flex-col gap-3">
                            {interview.criteria.map((c) => (
                                <div key={c.label} className="flex items-center gap-3">
                                    <span className="flex-1 text-[13px] font-semibold">{c.label}<span className="ml-1 text-[11px] font-normal text-muted-foreground">{c.weight}%</span></span>
                                    <div className="flex gap-1.5">
                                        {[1, 2, 3, 4, 5].map((n) => (
                                            <button
                                                key={n}
                                                type="button"
                                                onClick={() => setRatings((r) => ({ ...r, [c.label]: n }))}
                                                className={cn(
                                                    'h-8 w-8 rounded-md border text-[13px] font-bold transition-colors',
                                                    (ratings[c.label] ?? 3) === n ? 'border-primary bg-primary text-primary-foreground' : 'border-border bg-card hover:border-primary/50',
                                                )}
                                            >
                                                {n}
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <div>
                            <Label className="mb-1.5 block text-sm font-semibold">Overall score (0–100)</Label>
                            <input
                                value={overall}
                                onChange={(e) => setOverall(e.target.value)}
                                inputMode="numeric"
                                className="h-9 w-28 rounded-md border border-border bg-card px-3 text-[13px] outline-none focus:border-primary"
                            />
                        </div>
                    )}

                    <div>
                        <Label className="mb-1.5 block text-sm font-semibold">Recommendation</Label>
                        <div className="flex flex-wrap gap-2">
                            {RECOMMENDATIONS.map((r) => (
                                <button
                                    key={r.value}
                                    type="button"
                                    onClick={() => setRecommendation(r.value)}
                                    className={cn(
                                        'rounded-full border px-3 py-1.5 text-[13px] font-medium transition-colors',
                                        recommendation === r.value ? 'border-primary bg-primary/10 text-primary' : 'border-border bg-card hover:border-primary/50',
                                    )}
                                >
                                    {r.label}
                                </button>
                            ))}
                        </div>
                    </div>

                    <div>
                        <Label className="mb-1.5 block text-sm font-semibold">Notes <span className="font-normal text-muted-foreground">(optional)</span></Label>
                        <textarea
                            value={notes}
                            onChange={(e) => setNotes(e.target.value)}
                            rows={3}
                            placeholder="Strengths, concerns, evidence…"
                            className="w-full resize-y rounded-md border border-border bg-card p-2.5 text-[13px] outline-none focus:border-primary"
                        />
                    </div>
                </div>

                <DialogFooter>
                    <button type="button" onClick={onClose} className="h-9 rounded-md border border-border bg-card px-4 text-[13px] font-semibold hover:bg-muted">
                        Cancel
                    </button>
                    <button
                        type="button"
                        onClick={submit}
                        disabled={form.processing}
                        className="h-9 rounded-md bg-primary px-4 text-[13px] font-bold text-primary-foreground disabled:opacity-50"
                    >
                        Save scorecard
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default ScoreDialog;
