import { useForm } from '@inertiajs/react';

export type DailyNoteFormValues = {
    type: 'daily_note' | 'quick' | 'communication';
    category: string;
    subject: string;
    goal: string;
    body: string;
    occurred_at: string;
    shift_id: string;
    mood_rating: number | '';
    behaviour_tags: string[];
    concerns_flags: string[];
    follow_up_action: string;
    follow_up_due_at: string;
    visibility: 'internal' | 'portal';
    appears_on_timeline: boolean;
    is_draft: boolean;
    is_flagged: boolean;
    flagged_reason: string;
    contact_person: string;
    contact_relationship: string;
    contact_method: string;
    attachments: Array<{ name: string; size: number }>;
};

export function defaultDailyNoteValues(
    type: DailyNoteFormValues['type'] = 'daily_note',
): DailyNoteFormValues {
    return {
        type,
        category: type === 'communication' ? 'communication' : 'other',
        subject: '',
        goal: '',
        body: '',
        occurred_at: '',
        shift_id: '',
        mood_rating: '',
        behaviour_tags: [],
        concerns_flags: [],
        follow_up_action: '',
        follow_up_due_at: '',
        visibility: 'internal',
        appears_on_timeline: true,
        is_draft: false,
        is_flagged: false,
        flagged_reason: '',
        contact_person: '',
        contact_relationship: '',
        contact_method: '',
        attachments: [],
    };
}

export function useDailyNoteForm(
    type: DailyNoteFormValues['type'] = 'daily_note',
) {
    return useForm<DailyNoteFormValues>(defaultDailyNoteValues(type));
}
