import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Field } from '@/components/wizard/primitives';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { ChevronDown, FileText } from 'lucide-react';
import { useId, useState } from 'react';
import type { ReferralDocument } from './evidence-picker';

type Review = {
    evidence_id: number;
    title: string;
    purpose: string;
    confirmed: boolean;
};

export function useDocumentHandovers() {
    const [selected, setSelected] = useState<ReferralDocument[]>([]);
    const [reviews, setReviews] = useState<Review[]>([]);
    return {
        selected,
        reviews,
        setReviews,
        select(ids: number[], changed?: ReferralDocument) {
            setSelected((current) => {
                const next = current.filter((document) =>
                    ids.includes(document.id),
                );
                if (
                    changed &&
                    ids.includes(changed.id) &&
                    !next.some((document) => document.id === changed.id)
                )
                    next.push(changed);
                return next;
            });
            setReviews((current) =>
                current.filter((review) => ids.includes(review.evidence_id)),
            );
        },
        valid:
            reviews.length <= 10 &&
            reviews.every(
                (review) =>
                    review.title.trim().length >= 3 &&
                    review.purpose.trim().length >= 10 &&
                    review.confirmed,
            ),
    };
}

export function DocumentHandoverFields({
    documents,
    reviews,
    onChange,
}: {
    documents: ReferralDocument[];
    reviews: Review[];
    onChange: (reviews: Review[]) => void;
}) {
    const id = useId();
    const change = (evidenceId: number, fields: Partial<Review>) =>
        onChange(
            reviews.map((review) =>
                review.evidence_id === evidenceId
                    ? { ...review, confirmed: false, ...fields }
                    : review,
            ),
        );
    return (
        <details className="group/release rounded-lg bg-muted p-4">
            <summary className="flex cursor-pointer items-center justify-between gap-3 font-medium focus-visible:ring-2 focus-visible:ring-ring">
                Documents for the person’s profile · optional
                <ChevronDown
                    className="size-4 shrink-0 group-open/release:rotate-180"
                    aria-hidden="true"
                />
            </summary>
            <div className="space-y-4 pt-3">
                <p className="text-subtle">
                    Evidence selected above stays private to the referral unless
                    you explicitly release it here. Release only whole files
                    needed by authorized care staff. Funding documents and
                    family portal sharing are excluded. A fresh review renews an
                    existing link.
                </p>
                {!documents.length && (
                    <p className="text-subtle">
                        Select assessment evidence above to review a document
                        for the profile.
                    </p>
                )}
                {documents.map((document) => {
                    const review = reviews.find(
                        (row) => row.evidence_id === document.id,
                    );
                    return (
                        <div
                            key={document.id}
                            className="space-y-4 rounded-lg border border-border bg-card p-4"
                        >
                            <div className="flex items-start gap-3">
                                <Checkbox
                                    id={`${id}-${document.id}`}
                                    checked={!!review}
                                    disabled={!review && reviews.length >= 10}
                                    onCheckedChange={(checked) =>
                                        onChange(
                                            checked === true
                                                ? [
                                                      ...reviews,
                                                      {
                                                          evidence_id:
                                                              document.id,
                                                          title: '',
                                                          purpose: '',
                                                          confirmed: false,
                                                      },
                                                  ]
                                                : reviews.filter(
                                                      (row) =>
                                                          row.evidence_id !==
                                                          document.id,
                                                  ),
                                        )
                                    }
                                />
                                <Label
                                    htmlFor={`${id}-${document.id}`}
                                    className="flex min-w-0 items-center break-words"
                                >
                                    Release {document.title} to care staff
                                </Label>
                            </div>
                            {review && (
                                <>
                                    <Field label="Title on the person’s profile">
                                        <Input
                                            className="min-h-[44px] sm:min-h-0"
                                            value={review.title}
                                            maxLength={200}
                                            onChange={(event) =>
                                                change(document.id, {
                                                    title: event.target.value,
                                                })
                                            }
                                        />
                                    </Field>
                                    <Field label="Why is the whole document needed for care?">
                                        <Textarea
                                            value={review.purpose}
                                            maxLength={2000}
                                            onChange={(event) =>
                                                change(document.id, {
                                                    purpose: event.target.value,
                                                })
                                            }
                                        />
                                    </Field>
                                    <div className="flex items-start gap-3">
                                        <Checkbox
                                            id={`${id}-${document.id}-confirm`}
                                            checked={review.confirmed}
                                            onCheckedChange={(checked) =>
                                                change(document.id, {
                                                    confirmed: checked === true,
                                                })
                                            }
                                        />
                                        <Label
                                            htmlFor={`${id}-${document.id}-confirm`}
                                            className="flex items-center"
                                        >
                                            I reviewed every page, the title and
                                            current authority. The whole file is
                                            appropriate for authorized care
                                            staff, including any information
                                            about other people.
                                        </Label>
                                    </div>
                                </>
                            )}
                        </div>
                    );
                })}
                {!!documents.length && (
                    <p className="text-subtle">
                        {reviews.length} of 10 document releases selected.
                        Removing evidence above also removes its release from
                        this review.
                    </p>
                )}
            </div>
        </details>
    );
}

export function DocumentHandoverSummary({ reviews }: { reviews: Review[] }) {
    return (
        <ReviewCard title="Documents for care staff" icon={FileText}>
            {!reviews.length ? (
                <p className="text-subtle">
                    No documents will be added or renewed on the person’s
                    profile.
                </p>
            ) : (
                reviews.map((review) => (
                    <ReviewRow
                        key={review.evidence_id}
                        label={review.title}
                        value={review.purpose}
                    />
                ))
            )}
            {!!reviews.length && (
                <p className="text-subtle">
                    Staff-only access remains subject to the source document and
                    current authority. Original referral files are retained.
                </p>
            )}
        </ReviewCard>
    );
}
