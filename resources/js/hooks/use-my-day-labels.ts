import { usePage } from '@inertiajs/react';

/**
 * Frontline /my-day translation keys, sourced from `lang/{en,mi}/my-day.php`
 * via `Lang::get('my-day')` and shared as the `my_day_labels` Inertia prop
 * by the controllers that render the lifecycle pages (MyTasksController,
 * RosterController). Components living inside those pages can call
 * `useMyDayLabels()` to resolve a key with optional placeholder substitution
 * and a sensible English fallback when the prop is absent (e.g. when the
 * component is mounted on a page that doesn't render the lifecycle).
 */
export type MyDayLabelKey =
    | 'tasks'
    | 'handover'
    | 'before_you_start'
    | 'what_to_know'
    | 'tasks_planned'
    | 'meds_during_shift'
    | 'scheduled_count'
    | 'none_scheduled'
    | 'incoming_handover'
    | 'read_handover_hint'
    | 'start_shift'
    | 'starts_later'
    | 'starts_in_minutes'
    | 'started_minutes_late'
    | 'view_roster'
    | 'open_related_page'
    | 'end_shift'
    | 'end_shift_anyway'
    | 'end_shift_for'
    | 'ending'
    | 'save_handover_and_end'
    | 'ready_to_end_shift'
    | 'ready_subtitle'
    | 'clear_required_or_reason'
    | 'confirm_break_minutes'
    | 'shift_tasks'
    | 'all_complete'
    | 'still_to_do'
    | 'break_minutes'
    | 'reason_to_end_anyway'
    | 'brief_reason'
    | 'optional_notes'
    | 'optional_notes_placeholder'
    | 'override_audit_title'
    | 'override_audit_subtitle'
    | 'update_and_resubmit'
    | 'update_and_resubmit_action'
    | 'fix_returned_timesheet'
    | 'manager_note'
    | 'locked_for_payroll'
    | 'couldnt_resubmit'
    | 'sending'
    | 'start'
    | 'finish'
    | 'mileage_km'
    | 'notes'
    | 'timesheet_notes_label'
    | 'needs_your_changes'
    | 'timesheet_quick_fix'
    | 'timesheet_review_again'
    | 'manager_asked'
    | 'dictate'
    | 'listening'
    | 'start_voice_input'
    | 'stop_voice_input';

const FALLBACKS: Record<MyDayLabelKey, string> = {
    tasks: 'Tasks',
    handover: 'Handover',
    before_you_start: 'Before you start',
    what_to_know: 'What to know',
    tasks_planned: ':count planned for this shift',
    meds_during_shift: 'Meds during shift',
    scheduled_count: ':count scheduled',
    none_scheduled: 'None scheduled',
    incoming_handover: 'Incoming handover ready',
    read_handover_hint: 'Read the handover before clocking in.',
    start_shift: 'Start shift',
    starts_later: 'Starts later',
    starts_in_minutes: 'Starts in :minutes m',
    started_minutes_late: 'Started :minutes min late',
    view_roster: 'View roster',
    open_related_page: 'Open related page',
    end_shift: 'End shift',
    end_shift_anyway: 'End shift anyway',
    end_shift_for: 'End shift for :name',
    ending: 'Ending...',
    save_handover_and_end: 'Save handover and end shift',
    ready_to_end_shift: 'Ready to end shift',
    ready_subtitle:
        'Required tasks, handover, incidents, and medication records are clear.',
    clear_required_or_reason:
        'Clear the required items, or provide a reason to end anyway.',
    confirm_break_minutes: 'Confirm break minutes and wrap the shift.',
    shift_tasks: 'Shift tasks',
    all_complete: 'All complete',
    still_to_do: ':open of :total still to do',
    break_minutes: 'Break minutes',
    reason_to_end_anyway: 'Reason to end anyway',
    brief_reason: 'Brief reason',
    optional_notes: 'Optional notes',
    optional_notes_placeholder:
        'Anything payroll or your manager should know.',
    override_audit_title: 'Override will be audit logged',
    override_audit_subtitle:
        'You can end the shift now if needed, but the reason and outstanding items will be recorded.',
    update_and_resubmit: 'Update & resubmit',
    update_and_resubmit_action: 'Save and resubmit',
    fix_returned_timesheet:
        'Fix the returned timesheet without leaving My Day.',
    manager_note: 'Manager note',
    locked_for_payroll:
        'This timesheet is locked for payroll or no longer editable.',
    couldnt_resubmit: "Couldn't resubmit",
    sending: 'Sending...',
    start: 'Start',
    finish: 'Finish',
    mileage_km: 'Mileage km',
    notes: 'Notes',
    timesheet_notes_label: 'Timesheet notes',
    needs_your_changes: 'Needs your changes',
    timesheet_quick_fix: 'Your timesheet needs a quick fix',
    timesheet_review_again:
        'Your manager asked for a quick change before this is ready for payroll.',
    manager_asked: 'Your manager asked for a change.',
    dictate: 'Dictate',
    listening: 'Listening',
    start_voice_input: 'Start voice input for :field',
    stop_voice_input: 'Stop voice input',
};

function interpolate(template: string, params?: Record<string, string | number>) {
    if (!params) return template;
    return template.replace(/:([a-zA-Z_]+)/g, (match, key: string) =>
        Object.prototype.hasOwnProperty.call(params, key)
            ? String(params[key])
            : match,
    );
}

export function useMyDayLabels() {
    const page = usePage<{ my_day_labels?: Record<string, string> }>();
    const labels = page.props.my_day_labels ?? {};

    return (key: MyDayLabelKey, params?: Record<string, string | number>) =>
        interpolate(labels[key] ?? FALLBACKS[key], params);
}
