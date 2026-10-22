import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput } from '@/components/wizard/primitives';
import { useId } from 'react';

export type ContactReview = {
    phone: string | null;
    email: string | null;
    channel: 'phone' | 'email' | 'none';
    safe_time: string | null;
    leave_message: boolean;
    communication: string | null;
    verified_at?: string;
};
export type ContactProfile = {
    phone: string | null;
    email: string | null;
    preferences: ContactReview | null;
    review_current: boolean;
    can_add: boolean;
    fingerprint: string | null;
};
export type ContactDraft = {
    phone: string;
    email: string;
    channel: string;
    safe_time: string;
    leave_message: string;
    communication: string;
    verification_note: string;
    confirmed: boolean;
};
export function contactDraft(profile?: ContactProfile): ContactDraft {
    return {
        phone: profile?.phone ?? '',
        email: profile?.email ?? '',
        channel: profile?.preferences?.channel ?? '',
        safe_time: profile?.preferences?.safe_time ?? '',
        leave_message: profile?.preferences
            ? profile.preferences.leave_message
                ? 'yes'
                : 'no'
            : '',
        communication: profile?.preferences?.communication ?? '',
        verification_note: '',
        confirmed: false,
    };
}
export function contactReviewValid(draft: ContactDraft) {
    return (
        ['phone', 'email', 'none'].includes(draft.channel) &&
        (draft.channel === 'none' ||
            !!draft[draft.channel as 'phone' | 'email'].trim()) &&
        ['yes', 'no'].includes(draft.leave_message) &&
        (draft.channel !== 'none' || draft.leave_message === 'no') &&
        draft.verification_note.trim().length >= 10 &&
        draft.confirmed
    );
}
export function contactReviewPayload(draft: ContactDraft) {
    return {
        ...draft,
        phone: draft.phone.trim() || null,
        email: draft.email.trim() || null,
        safe_time: draft.safe_time.trim() || null,
        communication: draft.communication.trim() || null,
        verification_note: draft.verification_note.trim(),
        leave_message: draft.leave_message === 'yes',
    };
}
export function ContactReviewFields({
    draft,
    onChange,
    existing,
}: {
    draft: ContactDraft;
    onChange: (draft: ContactDraft) => void;
    existing?: ContactProfile;
}) {
    const safeTimeHint = useId();
    function change(key: keyof ContactDraft, value: string | boolean) {
        onChange({
            ...draft,
            [key]: value,
            confirmed: key === 'confirmed' ? value === true : false,
        });
    }
    return (
        <div className="space-y-4">
            <p className="text-subtle">
                Verify the person’s own contact details and instructions with
                them or their authorised representative. A website callback
                contact may belong to someone else. This does not send a message
                or grant portal access.
            </p>
            {(existing?.phone || existing?.email) && (
                <p className="text-subtle">
                    Existing contact values are kept. Review corrections through
                    the person’s profile.
                </p>
            )}
            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    label="Person’s phone"
                    hint={existing?.phone ? 'Kept' : undefined}
                >
                    <Input
                        className="min-h-[44px] sm:min-h-0"
                        type="tel"
                        autoComplete="off"
                        value={draft.phone}
                        readOnly={!!existing?.phone}
                        maxLength={50}
                        onChange={(e) => change('phone', e.target.value)}
                    />
                </Field>
                <Field
                    label="Person’s email"
                    hint={existing?.email ? 'Kept' : undefined}
                >
                    <Input
                        className="min-h-[44px] sm:min-h-0"
                        type="email"
                        autoComplete="off"
                        value={draft.email}
                        readOnly={!!existing?.email}
                        maxLength={255}
                        onChange={(e) => change('email', e.target.value)}
                    />
                </Field>
                <Field label="Preferred contact channel" required>
                    <SelectInput
                        className="min-h-[44px] sm:min-h-0"
                        value={draft.channel}
                        placeholder="Choose the agreed contact channel"
                        options={[
                            { value: 'phone', label: 'Phone' },
                            { value: 'email', label: 'Email' },
                            { value: 'none', label: 'No direct contact' },
                        ]}
                        onChange={(value) => {
                            onChange({
                                ...draft,
                                channel: value,
                                leave_message:
                                    value === 'none'
                                        ? 'no'
                                        : draft.leave_message,
                                confirmed: false,
                            });
                        }}
                    />
                </Field>
                <Field label="Permission to leave a message" required>
                    <SelectInput
                        className="min-h-[44px] sm:min-h-0"
                        value={draft.leave_message}
                        placeholder="Choose message permission"
                        options={[
                            { value: 'no', label: 'Do not leave a message' },
                            { value: 'yes', label: 'Messages are permitted' },
                        ]}
                        onChange={(value) => change('leave_message', value)}
                    />
                </Field>
            </div>
            <Field label="Safe times and contact instructions">
                <Textarea
                    aria-describedby={safeTimeHint}
                    value={draft.safe_time}
                    maxLength={500}
                    onChange={(e) => change('safe_time', e.target.value)}
                />
            </Field>
            <p id={safeTimeHint} className="text-caption text-muted-foreground">
                Leave blank if not yet known; staff must check before
                contacting.
            </p>
            <Field label="Communication and interpreter needs">
                <Textarea
                    value={draft.communication}
                    maxLength={1000}
                    onChange={(e) => change('communication', e.target.value)}
                />
            </Field>
            <Field
                label="How were contact details and instructions verified?"
                required
            >
                <Textarea
                    value={draft.verification_note}
                    maxLength={2000}
                    onChange={(e) =>
                        change('verification_note', e.target.value)
                    }
                />
            </Field>
            <label className="flex min-h-[44px] items-start gap-3 text-sm">
                <Checkbox
                    className="mt-1"
                    checked={draft.confirmed}
                    onCheckedChange={(checked) =>
                        change('confirmed', checked === true)
                    }
                />
                <span>
                    These contact details belong to the person. They or their
                    authorised representative have agreed to these contact
                    instructions and their inclusion in the person’s profile.
                </span>
            </label>
        </div>
    );
}
