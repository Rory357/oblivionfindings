import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Field } from '@/components/wizard/primitives';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { formatDateTimeLong } from '@/lib/datetime';
import { Users } from 'lucide-react';
import { useId, useState } from 'react';
import type { PeopleContact } from './people-contacts';
import { label } from './types';

type Review = {
    contact_id: string;
    contact_version: number;
    purpose: string;
    confirmed: boolean;
};

export function useSupportContactHandovers() {
    const [reviews, setReviews] = useState<Review[]>([]);
    return {
        reviews,
        setReviews,
        valid:
            reviews.length <= 20 &&
            reviews.every(
                (row) => row.purpose.trim().length >= 10 && row.confirmed,
            ),
    };
}

export function SupportContactHandoverFields({
    contacts,
    reviews,
    onChange,
}: {
    contacts: PeopleContact[];
    reviews: Review[];
    onChange: (rows: Review[]) => void;
}) {
    const id = useId();
    const change = (contactId: string, fields: Partial<Review>) =>
        onChange(
            reviews.map((row) =>
                row.contact_id === contactId
                    ? { ...row, confirmed: false, ...fields }
                    : row,
            ),
        );
    return (
        <details className="rounded-lg bg-muted p-4">
            <summary className="cursor-pointer font-medium">
                People involved in ongoing care · optional
            </summary>
            <div className="space-y-4 pt-3">
                <p className="text-subtle">
                    Select only contacts whose role and safe-contact
                    instructions have been reviewed for ongoing care. This
                    creates or renews a staff-only support contact. It does not
                    change emergency contacts, create portal access or grant
                    representative powers. Existing person contacts remain
                    unchanged.
                </p>
                {!contacts.length && (
                    <p className="text-subtle">
                        Add and review contacts in People involved before
                        including them in a care handover.
                    </p>
                )}
                {contacts.map((contact) => {
                    const review = reviews.find(
                        (row) => row.contact_id === contact.id,
                    );
                    return (
                        <div
                            key={contact.id}
                            className="space-y-3 rounded-lg bg-muted p-4"
                        >
                            <div className="flex items-start gap-3">
                                <Checkbox
                                    id={`${id}-${contact.id}`}
                                    checked={!!review}
                                    disabled={
                                        !contact.review_current ||
                                        (!review && reviews.length >= 20)
                                    }
                                    onCheckedChange={(checked) =>
                                        onChange(
                                            checked === true
                                                ? [
                                                      ...reviews,
                                                      {
                                                          contact_id:
                                                              contact.id,
                                                          contact_version:
                                                              contact.version,
                                                          purpose: '',
                                                          confirmed: false,
                                                      },
                                                  ]
                                                : reviews.filter(
                                                      (row) =>
                                                          row.contact_id !==
                                                          contact.id,
                                                  ),
                                        )
                                    }
                                />
                                <Label
                                    className="flex min-w-0 items-center break-words"
                                    htmlFor={`${id}-${contact.id}`}
                                >
                                    Include {contact.details.name} in ongoing
                                    care
                                </Label>
                            </div>
                            <p className="text-subtle">
                                {label(contact.details.role)} ·{' '}
                                {contact.details.relationship}
                                {!contact.review_current &&
                                    ' · Review needed before handover'}
                            </p>
                            {review && (
                                <>
                                    <ReviewCard
                                        title="Reviewed contact instructions"
                                        icon={Users}
                                    >
                                        <ReviewRow
                                            label="Phone"
                                            value={
                                                contact.details.phone ||
                                                'Not recorded'
                                            }
                                        />
                                        <ReviewRow
                                            label="Email"
                                            value={
                                                contact.details.email ||
                                                'Not recorded'
                                            }
                                        />
                                        <ReviewRow
                                            label="Contact permission"
                                            value={
                                                contact.details.may_contact
                                                    ? `Permitted by ${contact.details.channel}`
                                                    : 'Do not make direct contact'
                                            }
                                        />
                                        <ReviewRow
                                            label="Messages"
                                            value={
                                                contact.details.may_contact &&
                                                contact.details.leave_message
                                                    ? 'Phone message permitted'
                                                    : 'Do not leave messages'
                                            }
                                        />
                                        <ReviewRow
                                            label="Safe contact"
                                            value={
                                                contact.details.safe_time ||
                                                'Check with the person before contact'
                                            }
                                        />
                                        <ReviewRow
                                            label="Communication support"
                                            value={
                                                contact.details.communication ||
                                                'Not recorded'
                                            }
                                        />
                                        <ReviewRow
                                            label="Role and limits"
                                            value={
                                                contact.details
                                                    .authority_note ||
                                                'No representative powers recorded'
                                            }
                                        />
                                        <ReviewRow
                                            label="Review due"
                                            value={
                                                contact.details.review_due_at
                                                    ? formatDateTimeLong(
                                                          contact.details
                                                              .review_due_at,
                                                      )
                                                    : 'Not recorded'
                                            }
                                        />
                                    </ReviewCard>
                                    <Field label="Why is this contact needed for ongoing care?">
                                        <Textarea
                                            value={review.purpose}
                                            maxLength={2000}
                                            onChange={(event) =>
                                                change(contact.id, {
                                                    purpose: event.target.value,
                                                })
                                            }
                                        />
                                    </Field>
                                    <div className="flex items-start gap-3">
                                        <Checkbox
                                            id={`${id}-${contact.id}-confirmed`}
                                            checked={review.confirmed}
                                            onCheckedChange={(checked) =>
                                                change(contact.id, {
                                                    confirmed: checked === true,
                                                })
                                            }
                                        />
                                        <Label
                                            className="flex items-center"
                                            htmlFor={`${id}-${contact.id}-confirmed`}
                                        >
                                            I checked the existing person
                                            contacts, this person's role,
                                            details and current authority.
                                            Sharing these instructions with
                                            authorized care staff is appropriate
                                            for the stated care purpose.
                                        </Label>
                                    </div>
                                </>
                            )}
                        </div>
                    );
                })}
            </div>
        </details>
    );
}

export function SupportContactHandoverSummary({
    contacts,
    reviews,
}: {
    contacts: PeopleContact[];
    reviews: Review[];
}) {
    return (
        <ReviewCard title="Support contacts for care staff" icon={Users}>
            {!reviews.length ? (
                <p className="text-subtle">
                    No support contacts will be added or renewed.
                </p>
            ) : (
                reviews.map((row) => (
                    <ReviewRow
                        key={row.contact_id}
                        label={
                            contacts.find(
                                (contact) => contact.id === row.contact_id,
                            )?.details.name || 'Selected contact'
                        }
                        value={row.purpose}
                    />
                ))
            )}
            {!!reviews.length && (
                <p className="text-subtle">
                    The reviewed roles and safe-contact instructions will be
                    shared. Changes or withdrawal on the source referral will
                    require a fresh care handover before contact details can be
                    used again.
                </p>
            )}
        </ReviewCard>
    );
}
