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
    | 'today'
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
    | 'clinical_override_locked'
    | 'manager_override_required'
    | 'update_and_resubmit'
    | 'update_and_resubmit_title'
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
    | 'stop_voice_input'
    // Desktop /my-day redesign — added in the PR that wired the page through
    // the i18n hook (was previously hardcoded English).
    | 'staff_header_search'
    | 'hero_on_shift_at'
    | 'hero_on_shift_with'
    | 'hero_live_since'
    | 'hero_live_shift'
    | 'hero_not_clocked_in'
    | 'hero_clocked'
    | 'hero_meds'
    | 'hero_open'
    | 'hero_complete'
    | 'hero_on_track'
    | 'hero_items'
    | 'hero_greeting_site'
    | 'hero_greeting_no_site'
    | 'hero_quick_actions'
    | 'qa_give_medication'
    | 'qa_care_note'
    | 'qa_vitals_obs'
    | 'qa_report_incident'
    | 'qa_set_availability'
    | 'qa_care_plan'
    | 'qa_submit_timesheet'
    | 'qa_write_handover'
    | 'btn_clock_in'
    | 'btn_end_shift'
    | 'btn_start_break'
    | 'btn_end_break'
    | 'btn_todays_timesheet'
    | 'btn_current_shift_timesheet'
    | 'btn_report_incident'
    | 'all_residents'
    | 'resident_at_site'
    | 'res_open_profile'
    | 'res_give_meds'
    | 'res_care_note'
    | 'res_care_plan'
    | 'res_vitals'
    | 'res_incident'
    | 'open_emar'
    | 'digest_handover'
    | 'digest_needs_you'
    | 'digest_updates'
    | 'digest_new_badge'
    | 'digest_unread'
    | 'digest_previous_shift'
    | 'digest_no_handover'
    | 'digest_nothing_needs'
    | 'digest_nothing_new'
    | 'digest_confirm_read'
    | 'digest_open'
    | 'digest_acknowledge'
    | 'digest_snooze_15m'
    | 'digest_alert'
    | 'digest_incident'
    | 'digest_followup'
    | 'paperwork_title'
    | 'paperwork_x_due'
    | 'ts_send_for_approval'
    | 'ts_fix_and_resubmit'
    | 'hr_open'
    | 'tomorrow_title'
    | 'upcoming_shift'
    | 'read_full_briefing'
    | 'whats_next'
    | 'todays_care_for'
    | 'todays_care_all'
    | 'see_full_care_plan'
    | 'no_tasks_or_meds'
    | 'right_click_tip'
    | 'care_task'
    | 'happening_now'
    | 'mark_complete'
    | 'mark_incomplete'
    | 'mark_as_given'
    | 'already_given'
    | 'add_note'
    | 'more'
    | 'overdue_badge'
    | 'given'
    | 'refuse_or_not_given'
    | 'snooze_15m'
    | 'snooze_15_min'
    | 'coming_soon'
    | 'ctx_medication'
    | 'ctx_complete_task'
    | 'ctx_open_care_plan'
    | 'ctx_open_in_emar'
    | 'ctx_new_task_here'
    | 'ctx_reschedule'
    | 'ctx_skip_task'
    | 'ctx_why_this_dose'
    | 'ctx_dictate_update'
    | 'confirm_refuse_dose'
    | 'prompt_refuse_dose_reason'
    | 'default_refuse_dose_reason'
    | 'toast_marking_dose_given'
    | 'toast_dose_left_as_due'
    | 'toast_timesheet_sending'
    | 'toast_timesheet_in_draft'
    | 'toast_dose_record_failed';

const FALLBACKS: Record<MyDayLabelKey, string> = {
    today: 'Today',
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
    clinical_override_locked:
        'Unsigned medication or draft incident blockers need a manager override.',
    manager_override_required: 'Manager override required',
    update_and_resubmit: 'Update & resubmit',
    update_and_resubmit_title: 'Update and resubmit',
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
    staff_header_search: 'Search…',
    hero_on_shift_at: 'On shift at',
    hero_on_shift_with: 'On shift with',
    hero_live_since: 'Live shift · since :time',
    hero_live_shift: 'Live shift',
    hero_not_clocked_in: 'Not clocked in',
    hero_clocked: 'Clocked',
    hero_meds: 'Meds',
    hero_open: 'Open',
    hero_complete: 'complete',
    hero_on_track: 'on track',
    hero_items: 'items',
    hero_greeting_site:
        "Kia ora :name. You're supporting :count resident(s) at :site today.",
    hero_greeting_no_site: "Kia ora :name. Here's your day at a glance.",
    hero_quick_actions: 'Quick actions',
    qa_give_medication: 'Give medication',
    qa_care_note: 'Care note',
    qa_vitals_obs: 'Vitals & obs',
    qa_report_incident: 'Report incident',
    qa_set_availability: 'Set availability',
    qa_care_plan: 'Care plan',
    qa_submit_timesheet: 'Submit timesheet',
    qa_write_handover: 'Write handover',
    btn_clock_in: 'Clock in',
    btn_end_shift: 'End shift',
    btn_start_break: 'Start break',
    btn_end_break: 'End break',
    btn_todays_timesheet: "Today's timesheet",
    btn_current_shift_timesheet: 'Current shift timesheet',
    btn_report_incident: 'Report incident',
    all_residents: 'All residents',
    resident_at_site: 'Resident · :site',
    res_open_profile: 'Open profile',
    res_give_meds: 'Give meds',
    res_care_note: 'Care note',
    res_care_plan: 'Care plan',
    res_vitals: 'Vitals',
    res_incident: 'Incident',
    open_emar: 'Open eMAR',
    digest_handover: 'Handover',
    digest_needs_you: 'Needs you',
    digest_updates: 'Updates',
    digest_new_badge: 'New',
    digest_unread: 'Unread',
    digest_previous_shift: 'Previous shift',
    digest_no_handover: 'No handover for this shift.',
    digest_nothing_needs: 'Nothing needs you right now.',
    digest_nothing_new: 'Nothing new.',
    digest_confirm_read: 'Confirm read',
    digest_open: 'Open',
    digest_acknowledge: 'Acknowledge',
    digest_snooze_15m: 'Snooze 15m',
    digest_alert: 'Alert',
    digest_incident: 'Incident',
    digest_followup: 'Follow-up',
    paperwork_title: 'Paperwork',
    paperwork_x_due: ':count due',
    ts_send_for_approval: 'Send for approval',
    ts_fix_and_resubmit: 'Fix and resubmit',
    hr_open: 'Open',
    tomorrow_title: 'Tomorrow',
    upcoming_shift: 'Upcoming shift',
    read_full_briefing: 'Read full briefing',
    whats_next: "What's next",
    todays_care_for: "today's care for :name",
    todays_care_all: "today's care across all residents, in order",
    see_full_care_plan: 'See full care plan →',
    no_tasks_or_meds: 'No scheduled tasks or meds for this view.',
    right_click_tip: 'Tip · right-click any row for more options',
    care_task: 'Care task',
    happening_now: 'Happening now',
    mark_complete: 'Mark complete',
    mark_incomplete: 'Mark incomplete',
    mark_as_given: 'Mark as given',
    already_given: 'Already given',
    add_note: 'Add note',
    more: 'More',
    overdue_badge: 'Overdue',
    given: 'Given',
    refuse_or_not_given: 'Refuse / not given',
    snooze_15m: 'Snooze 15m',
    snooze_15_min: 'Snooze 15 min',
    coming_soon: 'Coming soon',
    ctx_medication: 'Medication',
    ctx_complete_task: 'Complete task',
    ctx_open_care_plan: 'Open care plan',
    ctx_open_in_emar: 'Open in eMAR',
    ctx_new_task_here: 'New task here',
    ctx_reschedule: 'Reschedule',
    ctx_skip_task: 'Skip task',
    ctx_why_this_dose: 'Why this dose?',
    ctx_dictate_update: 'Dictate update',
    confirm_refuse_dose: 'Mark this dose as refused / not given?',
    prompt_refuse_dose_reason: 'Why was this dose refused or not given?',
    default_refuse_dose_reason: 'Resident declined',
    toast_marking_dose_given: 'Marking dose given…',
    toast_dose_left_as_due: 'Dose left as due.',
    toast_timesheet_sending: 'Timesheet sending…',
    toast_timesheet_in_draft: 'Timesheet still in draft.',
    toast_dose_record_failed:
        "Couldn't record this dose. Open the eMAR to complete it.",
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
