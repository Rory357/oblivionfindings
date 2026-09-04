<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema-wide index audit (2026-09-04, performanceLoadTime branch).
 *
 * Adds single-column indexes for every unindexed foreign-key-style *_id
 * column and every unindexed `status` column found by auditing
 * database/schema/mysql-schema.sql (tenant_id is excluded: the app runs
 * single-tenant and those indexes were dropped deliberately). Also drops
 * exact-duplicate secondary indexes (same columns, same order) that were
 * paying write cost for nothing.
 *
 * Every operation is guarded, so the migration is safe to run on a
 * database that has drifted from the schema dump and is re-runnable.
 */
return new class extends Migration
{
    /**
     * table => [column => index name]
     *
     * @var array<string, array<string, string>>
     */
    private const ADD = [
        'action_items' => ['source_id' => 'action_items_source_id_index'],
        'anonymization_logs' => ['model_id' => 'anonymization_logs_model_id_index'],
        'asset_alerts' => ['status' => 'asset_alerts_status_index'],
        'asset_assignments' => ['assignee_id' => 'asset_assignments_assignee_id_index'],
        'asset_categories' => ['policy_profile_id' => 'asset_categories_policy_profile_id_index'],
        'asset_ownerships' => ['owner_id' => 'asset_ownerships_owner_id_index'],
        'asset_qr_tags' => ['status' => 'asset_qr_tags_status_index'],
        'asset_scan_events' => ['scanned_by_id' => 'asset_scan_events_scanned_by_id_index'],
        'asset_trackers' => ['status' => 'asset_trackers_status_index'],
        'asset_values' => ['insurance_policy_id' => 'asset_values_insurance_policy_id_index'],
        'audit_logs' => ['auditable_id' => 'audit_logs_auditable_id_index'],
        'billing_entries' => ['timesheet_id' => 'billing_entries_timesheet_id_index', 'shift_id' => 'billing_entries_shift_id_index', 'line_item_id' => 'billing_entries_line_item_id_index', 'status' => 'billing_entries_status_index'],
        'board_evaluations' => ['status' => 'board_evaluations_status_index'],
        'budget_adjustments' => ['status' => 'budget_adjustments_status_index'],
        'budget_allocations' => ['site_budget_line_id' => 'budget_allocations_site_budget_line_id_index'],
        'budget_line_items' => ['gl_account_id' => 'budget_line_items_gl_account_id_index'],
        'budgets' => ['status' => 'budgets_status_index'],
        'calendar_sync_busy_blocks' => ['external_event_id' => 'calendar_sync_busy_blocks_external_event_id_index'],
        'calendar_sync_connections' => ['status' => 'calendar_sync_connections_status_index'],
        'calendar_sync_event_links' => ['external_event_id' => 'calendar_sync_event_links_external_event_id_index'],
        'calendar_sync_mappings' => ['external_calendar_id' => 'calendar_sync_mappings_external_calendar_id_index'],
        'calendar_syncs' => ['calendar_id' => 'calendar_syncs_calendar_id_index'],
        'care_plan_goals' => ['status' => 'care_plan_goals_status_index'],
        'ceo_board_reports' => ['status' => 'ceo_board_reports_status_index'],
        'client_appointments' => ['status' => 'client_appointments_status_index'],
        'client_break_glass_accesses' => ['incident_report_id' => 'client_break_glass_accesses_incident_report_id_index'],
        'client_controlled_drug_discrepancies' => ['status' => 'client_controlled_drug_discrepancies_status_index'],
        'client_documents' => ['openai_file_id' => 'client_documents_openai_file_id_index'],
        'client_fund_transactions' => ['journal_id' => 'client_fund_transactions_journal_id_index'],
        'client_meal_logs' => ['status' => 'client_meal_logs_status_index'],
        'client_onboarding_steps' => ['status' => 'client_onboarding_steps_status_index'],
        'client_onboarding_workflows' => ['status' => 'client_onboarding_workflows_status_index'],
        'client_personal_assets' => ['status' => 'client_personal_assets_status_index'],
        'client_photos' => ['status' => 'client_photos_status_index'],
        'client_transport_bookings' => ['status' => 'client_transport_bookings_status_index'],
        'clients' => ['price_book_id' => 'clients_price_book_id_index', 'status' => 'clients_status_index', 'openai_vector_store_id' => 'clients_openai_vector_store_id_index'],
        'clinical_attachments' => ['attachable_id' => 'clinical_attachments_attachable_id_index'],
        'clinical_events' => ['linked_hs_event_id' => 'clinical_events_linked_hs_event_id_index', 'linked_incident_id' => 'clinical_events_linked_incident_id_index', 'status' => 'clinical_events_status_index'],
        'clinical_observations' => ['protocol_schedule_id' => 'clinical_observations_protocol_schedule_id_index'],
        'clinical_protocol_schedules' => ['status' => 'clinical_protocol_schedules_status_index', 'clinical_observation_id' => 'clinical_protocol_schedules_clinical_observation_id_index', 'shift_task_id' => 'clinical_protocol_schedules_shift_task_id_index'],
        'compliance_obligations' => ['backup_owner_id' => 'compliance_obligations_backup_owner_id_index', 'status' => 'compliance_obligations_status_index'],
        'compliance_reminders' => ['status' => 'compliance_reminders_status_index'],
        'consent_reminders' => ['status' => 'consent_reminders_status_index'],
        'consent_requests' => ['triggering_subject_id' => 'consent_requests_triggering_subject_id_index'],
        'consent_withdrawal_requests' => ['status' => 'consent_withdrawal_requests_status_index'],
        'control_room_alert_tasks' => ['status' => 'control_room_alert_tasks_status_index'],
        'control_room_alerts' => ['playbook_run_id' => 'control_room_alerts_playbook_run_id_index'],
        'control_room_devices' => ['client_id' => 'control_room_devices_client_id_index', 'asset_id' => 'control_room_devices_asset_id_index'],
        'control_room_evidence_packs' => ['status' => 'control_room_evidence_packs_status_index'],
        'control_room_maintenance_windows' => ['site_id' => 'control_room_maintenance_windows_site_id_index', 'asset_id' => 'control_room_maintenance_windows_asset_id_index'],
        'control_room_playbook_run_steps' => ['status' => 'control_room_playbook_run_steps_status_index'],
        'control_room_signals' => ['client_id' => 'control_room_signals_client_id_index', 'device_id' => 'control_room_signals_device_id_index', 'correlated_alert_id' => 'control_room_signals_correlated_alert_id_index'],
        'control_room_triage_queues' => ['escalate_to_queue_id' => 'control_room_triage_queues_escalate_to_queue_id_index'],
        'custom_form_submissions' => ['shift_id' => 'custom_form_submissions_shift_id_index', 'status' => 'custom_form_submissions_status_index'],
        'device_assignments' => ['assignable_id' => 'device_assignments_assignable_id_index'],
        'device_command_attempts' => ['status' => 'device_command_attempts_status_index'],
        'device_command_requests' => ['signing_key_id' => 'device_command_requests_signing_key_id_index'],
        'device_configuration_profiles' => ['status' => 'device_configuration_profiles_status_index'],
        'devices' => ['status' => 'devices_status_index'],
        'family_notes' => ['status' => 'family_notes_status_index'],
        'family_visit_requests' => ['status' => 'family_visit_requests_status_index'],
        'fin_accounts' => ['funding_stream_id' => 'fin_accounts_funding_stream_id_index', 'xero_account_id' => 'fin_accounts_xero_account_id_index', 'myob_account_id' => 'fin_accounts_myob_account_id_index', 'default_tax_rate_id' => 'fin_accounts_default_tax_rate_id_index'],
        'fin_audit_exports' => ['status' => 'fin_audit_exports_status_index'],
        'fin_bank_accounts' => ['gl_account_id' => 'fin_bank_accounts_gl_account_id_index'],
        'fin_bank_feed_logs' => ['status' => 'fin_bank_feed_logs_status_index'],
        'fin_bank_reconciliation_lines' => ['journal_line_id' => 'fin_bank_reconciliation_lines_journal_line_id_index'],
        'fin_bank_reconciliations' => ['status' => 'fin_bank_reconciliations_status_index'],
        'fin_bank_transactions' => ['reconciliation_id' => 'fin_bank_transactions_reconciliation_id_index', 'matched_journal_line_id' => 'fin_bank_transactions_matched_journal_line_id_index', 'external_id' => 'fin_bank_transactions_external_id_index', 'status' => 'fin_bank_transactions_status_index'],
        'fin_bill_lines' => ['account_id' => 'fin_bill_lines_account_id_index', 'cost_centre_id' => 'fin_bill_lines_cost_centre_id_index', 'funding_stream_id' => 'fin_bill_lines_funding_stream_id_index'],
        'fin_bills' => ['purchase_order_id' => 'fin_bills_purchase_order_id_index', 'status' => 'fin_bills_status_index', 'journal_id' => 'fin_bills_journal_id_index', 'xero_invoice_id' => 'fin_bills_xero_invoice_id_index', 'myob_invoice_id' => 'fin_bills_myob_invoice_id_index'],
        'fin_cash_flow_forecasts' => ['status' => 'fin_cash_flow_forecasts_status_index'],
        'fin_consolidation_runs' => ['status' => 'fin_consolidation_runs_status_index'],
        'fin_cost_allocations' => ['shift_id' => 'fin_cost_allocations_shift_id_index'],
        'fin_credit_note_lines' => ['account_id' => 'fin_credit_note_lines_account_id_index'],
        'fin_credit_notes' => ['vendor_id' => 'fin_credit_notes_vendor_id_index', 'client_id' => 'fin_credit_notes_client_id_index', 'bill_id' => 'fin_credit_notes_bill_id_index', 'invoice_id' => 'fin_credit_notes_invoice_id_index', 'status' => 'fin_credit_notes_status_index', 'journal_id' => 'fin_credit_notes_journal_id_index'],
        'fin_donor_fund_reports' => ['status' => 'fin_donor_fund_reports_status_index'],
        'fin_donor_funds' => ['status' => 'fin_donor_funds_status_index'],
        'fin_eftpos_batches' => ['status' => 'fin_eftpos_batches_status_index'],
        'fin_eftpos_terminals' => ['terminal_id' => 'fin_eftpos_terminals_terminal_id_index'],
        'fin_eftpos_transactions' => ['status' => 'fin_eftpos_transactions_status_index'],
        'fin_financial_events' => ['source_id' => 'fin_financial_events_source_id_index', 'cost_centre_id' => 'fin_financial_events_cost_centre_id_index', 'funding_stream_id' => 'fin_financial_events_funding_stream_id_index', 'staff_id' => 'fin_financial_events_staff_id_index', 'shift_id' => 'fin_financial_events_shift_id_index', 'status' => 'fin_financial_events_status_index'],
        'fin_fiscal_periods' => ['status' => 'fin_fiscal_periods_status_index'],
        'fin_fixed_asset_depreciations' => ['journal_id' => 'fin_fixed_asset_depreciations_journal_id_index'],
        'fin_fixed_assets' => ['gl_asset_account_id' => 'fin_fixed_assets_gl_asset_account_id_index', 'gl_depreciation_account_id' => 'fin_fixed_assets_gl_depreciation_account_id_index', 'gl_expense_account_id' => 'fin_fixed_assets_gl_expense_account_id_index', 'status' => 'fin_fixed_assets_status_index', 'linked_asset_id' => 'fin_fixed_assets_linked_asset_id_index'],
        'fin_funding_streams' => ['default_revenue_account_id' => 'fin_funding_streams_default_revenue_account_id_index'],
        'fin_fx_revaluations' => ['status' => 'fin_fx_revaluations_status_index'],
        'fin_gst_return_lines' => ['journal_line_id' => 'fin_gst_return_lines_journal_line_id_index', 'account_id' => 'fin_gst_return_lines_account_id_index', 'tax_rate_id' => 'fin_gst_return_lines_tax_rate_id_index'],
        'fin_gst_returns' => ['status' => 'fin_gst_returns_status_index'],
        'fin_intercompany_transactions' => ['status' => 'fin_intercompany_transactions_status_index', 'eliminated_in_run_id' => 'fin_intercompany_transactions_eliminated_in_run_id_index'],
        'fin_invoice_lines' => ['tax_rate_id' => 'fin_invoice_lines_tax_rate_id_index', 'account_id' => 'fin_invoice_lines_account_id_index'],
        'fin_invoices' => ['source_id' => 'fin_invoices_source_id_index', 'status' => 'fin_invoices_status_index'],
        'fin_ird_filings' => ['status' => 'fin_ird_filings_status_index'],
        'fin_journal_lines' => ['cost_centre_id' => 'fin_journal_lines_cost_centre_id_index', 'funding_stream_id' => 'fin_journal_lines_funding_stream_id_index', 'tax_rate_id' => 'fin_journal_lines_tax_rate_id_index'],
        'fin_journals' => ['source_id' => 'fin_journals_source_id_index', 'fiscal_period_id' => 'fin_journals_fiscal_period_id_index', 'status' => 'fin_journals_status_index', 'reversed_by_journal_id' => 'fin_journals_reversed_by_journal_id_index', 'xero_journal_id' => 'fin_journals_xero_journal_id_index', 'myob_journal_id' => 'fin_journals_myob_journal_id_index', 'posted_payroll_source_id' => 'fin_journals_posted_payroll_source_id_index'],
        'fin_payment_allocations' => ['allocatable_id' => 'fin_payment_allocations_allocatable_id_index', 'source_id' => 'fin_payment_allocations_source_id_index', 'journal_id' => 'fin_payment_allocations_journal_id_index'],
        'fin_payment_matches' => ['matchable_id' => 'fin_payment_matches_matchable_id_index', 'status' => 'fin_payment_matches_status_index'],
        'fin_payment_run_items' => ['bill_id' => 'fin_payment_run_items_bill_id_index', 'status' => 'fin_payment_run_items_status_index'],
        'fin_payment_runs' => ['bank_account_id' => 'fin_payment_runs_bank_account_id_index', 'status' => 'fin_payment_runs_status_index', 'journal_id' => 'fin_payment_runs_journal_id_index'],
        'fin_petty_cash_funds' => ['gl_account_id' => 'fin_petty_cash_funds_gl_account_id_index'],
        'fin_petty_cash_transactions' => ['account_id' => 'fin_petty_cash_transactions_account_id_index', 'journal_id' => 'fin_petty_cash_transactions_journal_id_index'],
        'fin_purchase_order_lines' => ['account_id' => 'fin_purchase_order_lines_account_id_index'],
        'fin_purchase_orders' => ['status' => 'fin_purchase_orders_status_index', 'cost_centre_id' => 'fin_purchase_orders_cost_centre_id_index', 'funding_stream_id' => 'fin_purchase_orders_funding_stream_id_index'],
        'fin_vendors' => ['default_expense_account_id' => 'fin_vendors_default_expense_account_id_index', 'xero_contact_id' => 'fin_vendors_xero_contact_id_index', 'myob_contact_id' => 'fin_vendors_myob_contact_id_index'],
        'fleet_driver_sessions' => ['status' => 'fleet_driver_sessions_status_index'],
        'fleet_incidents' => ['status' => 'fleet_incidents_status_index', 'supervisor_user_id' => 'fleet_incidents_supervisor_user_id_index'],
        'fleet_integration_cursors' => ['last_message_id' => 'fleet_integration_cursors_last_message_id_index', 'status' => 'fleet_integration_cursors_status_index'],
        'fleet_medication_transit_logs' => ['outing_id' => 'fleet_medication_transit_logs_outing_id_index', 'medication_id' => 'fleet_medication_transit_logs_medication_id_index'],
        'fleet_outings' => ['status' => 'fleet_outings_status_index'],
        'fleet_personal_trips' => ['shift_id' => 'fleet_personal_trips_shift_id_index'],
        'fleet_reports' => ['status' => 'fleet_reports_status_index'],
        'fleet_resident_transports' => ['resident_id' => 'fleet_resident_transports_resident_id_index', 'status' => 'fleet_resident_transports_status_index'],
        'fleet_shift_handovers' => ['status' => 'fleet_shift_handovers_status_index'],
        'fleet_telemetry_events' => ['vendor_message_id' => 'fleet_telemetry_events_vendor_message_id_index'],
        'fleet_vehicle_bookings' => ['pickup_site_id' => 'fleet_vehicle_bookings_pickup_site_id_index', 'return_site_id' => 'fleet_vehicle_bookings_return_site_id_index', 'pre_trip_inspection_id' => 'fleet_vehicle_bookings_pre_trip_inspection_id_index', 'post_trip_inspection_id' => 'fleet_vehicle_bookings_post_trip_inspection_id_index'],
        'funding_claim_items' => ['shift_id' => 'funding_claim_items_shift_id_index', 'timesheet_id' => 'funding_claim_items_timesheet_id_index'],
        'funding_claims' => ['status' => 'funding_claims_status_index', 'journal_id' => 'funding_claims_journal_id_index'],
        'geofence_zones' => ['site_id' => 'geofence_zones_site_id_index'],
        'governance_audit_log' => ['resource_id' => 'governance_audit_log_resource_id_index'],
        'governance_change_log' => ['entity_id' => 'governance_change_log_entity_id_index'],
        'governance_feedback_escalations' => ['source_id' => 'governance_feedback_escalations_source_id_index'],
        'governance_meetings' => ['recurring_schedule_id' => 'governance_meetings_recurring_schedule_id_index'],
        'hr_announcements' => ['recurrence_parent_id' => 'hr_announcements_recurrence_parent_id_index'],
        'hr_applications' => ['status' => 'hr_applications_status_index'],
        'hr_approval_instances' => ['approvable_id' => 'hr_approval_instances_approvable_id_index'],
        'hr_assets' => ['status' => 'hr_assets_status_index'],
        'hr_audit_log' => ['auditable_id' => 'hr_audit_log_auditable_id_index'],
        'hr_automation_runs' => ['status' => 'hr_automation_runs_status_index'],
        'hr_benefit_enrollments' => ['status' => 'hr_benefit_enrollments_status_index'],
        'hr_compensation_review_items' => ['status' => 'hr_compensation_review_items_status_index'],
        'hr_compliance_reminder_deliveries' => ['source_id' => 'hr_compliance_reminder_deliveries_source_id_index'],
        'hr_compliance_renewal_snoozes' => ['entity_id' => 'hr_compliance_renewal_snoozes_entity_id_index'],
        'hr_compliance_requirements' => ['reference_id' => 'hr_compliance_requirements_reference_id_index'],
        'hr_course_assignments' => ['status' => 'hr_course_assignments_status_index'],
        'hr_course_enrollments' => ['status' => 'hr_course_enrollments_status_index'],
        'hr_development_goals' => ['status' => 'hr_development_goals_status_index'],
        'hr_documents' => ['template_id' => 'hr_documents_template_id_index'],
        'hr_eap_referrals' => ['status' => 'hr_eap_referrals_status_index'],
        'hr_employee_profiles' => ['offer_id' => 'hr_employee_profiles_offer_id_index', 'candidate_id' => 'hr_employee_profiles_candidate_id_index'],
        'hr_engagement_action_plans' => ['source_id' => 'hr_engagement_action_plans_source_id_index', 'status' => 'hr_engagement_action_plans_status_index'],
        'hr_engagement_surveys' => ['status' => 'hr_engagement_surveys_status_index'],
        'hr_expense_claims' => ['journal_id' => 'hr_expense_claims_journal_id_index'],
        'hr_expense_items' => ['source_id' => 'hr_expense_items_source_id_index'],
        'hr_feed_reactions' => ['subject_id' => 'hr_feed_reactions_subject_id_index'],
        'hr_feed_replies' => ['subject_id' => 'hr_feed_replies_subject_id_index'],
        'hr_feedback_requests' => ['status' => 'hr_feedback_requests_status_index'],
        'hr_goals' => ['status' => 'hr_goals_status_index'],
        'hr_interviews' => ['status' => 'hr_interviews_status_index'],
        'hr_key_results' => ['status' => 'hr_key_results_status_index'],
        'hr_leave_balance_ledgers' => ['source_id' => 'hr_leave_balance_ledgers_source_id_index'],
        'hr_offboarding_checklists' => ['status' => 'hr_offboarding_checklists_status_index'],
        'hr_offboarding_tasks' => ['status' => 'hr_offboarding_tasks_status_index'],
        'hr_offers' => ['template_id' => 'hr_offers_template_id_index'],
        'hr_onboarding_checklists' => ['status' => 'hr_onboarding_checklists_status_index'],
        'hr_onboarding_tasks' => ['status' => 'hr_onboarding_tasks_status_index'],
        'hr_payroll_runs' => ['journal_id' => 'hr_payroll_runs_journal_id_index', 'payment_journal_id' => 'hr_payroll_runs_payment_journal_id_index'],
        'hr_performance_improvement_plans' => ['status' => 'hr_performance_improvement_plans_status_index'],
        'hr_performance_reviews' => ['status' => 'hr_performance_reviews_status_index'],
        'hr_pip_milestones' => ['status' => 'hr_pip_milestones_status_index'],
        'hr_probation_reviews' => ['status' => 'hr_probation_reviews_status_index'],
        'hr_reference_checks' => ['status' => 'hr_reference_checks_status_index'],
        'hr_review_goals' => ['status' => 'hr_review_goals_status_index'],
        'hr_staff_compliance_status' => ['evidence_id' => 'hr_staff_compliance_status_evidence_id_index'],
        'hr_supervision_notes' => ['status' => 'hr_supervision_notes_status_index'],
        'hr_time_entries' => ['source_id' => 'hr_time_entries_source_id_index'],
        'hr_timesheets' => ['status' => 'hr_timesheets_status_index'],
        'hr_webhook_deliveries' => ['status' => 'hr_webhook_deliveries_status_index'],
        'hs_attachments' => ['attachable_id' => 'hs_attachments_attachable_id_index'],
        'hs_events' => ['source_id' => 'hs_events_source_id_index', 'control_room_alert_id' => 'hs_events_control_room_alert_id_index'],
        'hs_risk_assessments' => ['assessable_id' => 'hs_risk_assessments_assessable_id_index'],
        'identities' => ['provider_user_id' => 'identities_provider_user_id_index'],
        'incident_governance_escalations' => ['client_incident_id' => 'incident_governance_escalations_client_incident_id_index'],
        'integration_alerts' => ['incident_id' => 'integration_alerts_incident_id_index', 'source_event_id' => 'integration_alerts_source_event_id_index'],
        'integration_events' => ['source_event_id' => 'integration_events_source_event_id_index'],
        'integration_site_configs' => ['status' => 'integration_site_configs_status_index', 'mapped_external_site_id' => 'integration_site_configs_mapped_external_site_id_index'],
        'integration_sync_logs' => ['status' => 'integration_sync_logs_status_index'],
        'integration_tenant_secrets' => ['status' => 'integration_tenant_secrets_status_index'],
        'integrations' => ['status' => 'integrations_status_index'],
        'invoice_items' => ['billing_entry_id' => 'invoice_items_billing_entry_id_index'],
        'it_attachments' => ['attachable_id' => 'it_attachments_attachable_id_index'],
        'it_automation_runs' => ['status' => 'it_automation_runs_status_index'],
        'it_catalog_submissions' => ['result_id' => 'it_catalog_submissions_result_id_index'],
        'it_mailbox_connections' => ['status' => 'it_mailbox_connections_status_index'],
        'it_provisioning_requests' => ['canonical_target_id' => 'it_provisioning_requests_canonical_target_id_index'],
        'it_provisioning_workflows' => ['source_id' => 'it_provisioning_workflows_source_id_index', 'status' => 'it_provisioning_workflows_status_index'],
        'it_ticket_approvals' => ['approver_id' => 'it_ticket_approvals_approver_id_index', 'status' => 'it_ticket_approvals_status_index'],
        'it_ticket_events' => ['subject_id' => 'it_ticket_events_subject_id_index'],
        'it_ticket_links' => ['linkable_id' => 'it_ticket_links_linkable_id_index'],
        'it_work_tasks' => ['status' => 'it_work_tasks_status_index'],
        'legal_holds' => ['holdable_id' => 'legal_holds_holdable_id_index'],
        'location_hardware' => ['status' => 'location_hardware_status_index', 'linked_person_id' => 'location_hardware_linked_person_id_index'],
        'lone_worker_check_ins' => ['status' => 'lone_worker_check_ins_status_index'],
        'medication_competency_assessments' => ['status' => 'medication_competency_assessments_status_index'],
        'medication_covert_authorisations' => ['status' => 'medication_covert_authorisations_status_index'],
        'medication_errors' => ['status' => 'medication_errors_status_index'],
        'medication_mar_attachments' => ['attachable_id' => 'medication_mar_attachments_attachable_id_index'],
        'medication_pharmacy_orders' => ['status' => 'medication_pharmacy_orders_status_index'],
        'medication_prescriber_orders' => ['status' => 'medication_prescriber_orders_status_index'],
        'medication_reviews' => ['status' => 'medication_reviews_status_index'],
        'medication_scheduled_stock_counts' => ['status' => 'medication_scheduled_stock_counts_status_index'],
        'medication_self_admin_assessments' => ['status' => 'medication_self_admin_assessments_status_index'],
        'medication_syringe_drivers' => ['status' => 'medication_syringe_drivers_status_index'],
        'meeting_attendances' => ['status' => 'meeting_attendances_status_index'],
        'mileage_claims' => ['status' => 'mileage_claims_status_index'],
        'monitoring_dead_letters' => ['message_id' => 'monitoring_dead_letters_message_id_index'],
        'monitoring_flow_exporter_states' => ['source_id' => 'monitoring_flow_exporter_states_source_id_index'],
        'monitoring_inbox' => ['message_id' => 'monitoring_inbox_message_id_index'],
        'monitoring_maintenance_windows' => ['status' => 'monitoring_maintenance_windows_status_index'],
        'monitoring_topology_snapshots' => ['source_envelope_id' => 'monitoring_topology_snapshots_source_envelope_id_index', 'status' => 'monitoring_topology_snapshots_status_index'],
        'notifiable_incidents' => ['related_incident_id' => 'notifiable_incidents_related_incident_id_index', 'status' => 'notifiable_incidents_status_index'],
        'notifications' => ['notifiable_id' => 'notifications_notifiable_id_index'],
        'ops_messages' => ['client_id' => 'ops_messages_client_id_index', 'shift_id' => 'ops_messages_shift_id_index'],
        'payroll_exports' => ['status' => 'payroll_exports_status_index'],
        'performance_goals' => ['status' => 'performance_goals_status_index'],
        'privacy_attachments' => ['attachable_id' => 'privacy_attachments_attachable_id_index'],
        'procedure_runs' => ['subject_id' => 'procedure_runs_subject_id_index', 'status' => 'procedure_runs_status_index'],
        'procedure_tasks' => ['status' => 'procedure_tasks_status_index'],
        'queclink_devices' => ['current_session_id' => 'queclink_devices_current_session_id_index'],
        'queclink_pending_commands' => ['sent_session_id' => 'queclink_pending_commands_sent_session_id_index'],
        'queclink_raw_frames' => ['session_id' => 'queclink_raw_frames_session_id_index'],
        'quotes' => ['converted_to_agreement_id' => 'quotes_converted_to_agreement_id_index', 'converted_to_invoice_id' => 'quotes_converted_to_invoice_id_index'],
        'recurring_charges' => ['price_book_item_id' => 'recurring_charges_price_book_item_id_index'],
        'recurring_meeting_schedules' => ['board_committee_id' => 'recurring_meeting_schedules_board_committee_id_index', 'default_chair_id' => 'recurring_meeting_schedules_default_chair_id_index', 'default_secretary_id' => 'recurring_meeting_schedules_default_secretary_id_index'],
        'respite_audit_logs' => ['auditable_id' => 'respite_audit_logs_auditable_id_index', 'session_id' => 'respite_audit_logs_session_id_index'],
        'respite_booking_requests' => ['status' => 'respite_booking_requests_status_index'],
        'respite_bookings' => ['status' => 'respite_bookings_status_index'],
        'respite_complaints' => ['status' => 'respite_complaints_status_index'],
        'respite_daily_notes' => ['linked_incident_id' => 'respite_daily_notes_linked_incident_id_index'],
        'respite_evidence_packs' => ['status' => 'respite_evidence_packs_status_index'],
        'respite_linked_refs' => ['subject_id' => 'respite_linked_refs_subject_id_index', 'ref_id' => 'respite_linked_refs_ref_id_index'],
        'respite_procedure_runs' => ['subject_id' => 'respite_procedure_runs_subject_id_index'],
        'respite_referrals' => ['status' => 'respite_referrals_status_index', 'linked_booking_request_id' => 'respite_referrals_linked_booking_request_id_index'],
        'respite_resource_allocations' => ['resource_id' => 'respite_resource_allocations_resource_id_index', 'status' => 'respite_resource_allocations_status_index'],
        'respite_risk_plan_activations' => ['risk_assessment_id' => 'respite_risk_plan_activations_risk_assessment_id_index', 'status' => 'respite_risk_plan_activations_status_index'],
        'respite_stays' => ['status' => 'respite_stays_status_index'],
        'respite_tasks' => ['subject_id' => 'respite_tasks_subject_id_index'],
        'risk_event_links' => ['event_id' => 'risk_event_links_event_id_index'],
        'risk_treatments' => ['status' => 'risk_treatments_status_index'],
        'roadmap_assurance_evidence_plans' => ['evidence_source_id' => 'roadmap_assurance_evidence_plans_evidence_source_id_index'],
        'roadmap_change_log_entries' => ['entity_id' => 'roadmap_change_log_entries_entity_id_index', 'correlation_id' => 'roadmap_change_log_entries_correlation_id_index'],
        'roadmap_decision_requests' => ['source_id' => 'roadmap_decision_requests_source_id_index'],
        'roadmap_initiative_benefits' => ['status' => 'roadmap_initiative_benefits_status_index'],
        'roadmap_initiative_budgets' => ['status' => 'roadmap_initiative_budgets_status_index'],
        'roadmap_initiative_dependencies' => ['status' => 'roadmap_initiative_dependencies_status_index'],
        'roadmap_initiative_milestones' => ['status' => 'roadmap_initiative_milestones_status_index'],
        'roadmap_initiative_quality_links' => ['source_id' => 'roadmap_initiative_quality_links_source_id_index', 'status' => 'roadmap_initiative_quality_links_status_index'],
        'roadmap_initiative_site_scope_sites' => ['status' => 'roadmap_initiative_site_scope_sites_status_index'],
        'roadmap_initiative_tasks' => ['status' => 'roadmap_initiative_tasks_status_index'],
        'roadmap_quarterly_plans' => ['status' => 'roadmap_quarterly_plans_status_index'],
        'roadmap_vendor_contract_refs' => ['status' => 'roadmap_vendor_contract_refs_status_index'],
        'roster_template_shifts' => ['service_context_id' => 'roster_template_shifts_service_context_id_index'],
        'safeguarding_action_plans' => ['status' => 'safeguarding_action_plans_status_index'],
        'safeguarding_alerts' => ['alertable_id' => 'safeguarding_alerts_alertable_id_index'],
        'safeguarding_concerns' => ['subject_id' => 'safeguarding_concerns_subject_id_index', 'alleged_perpetrator_id' => 'safeguarding_concerns_alleged_perpetrator_id_index'],
        'safeguarding_investigations' => ['status' => 'safeguarding_investigations_status_index'],
        'service_agreements' => ['status' => 'service_agreements_status_index'],
        'shift_series' => ['status' => 'shift_series_status_index'],
        'site_calendar_events' => ['checklist_run_id' => 'site_calendar_events_checklist_run_id_index', 'hazard_id' => 'site_calendar_events_hazard_id_index', 'status' => 'site_calendar_events_status_index'],
        'site_certifications' => ['organization_id' => 'site_certifications_organization_id_index', 'status' => 'site_certifications_status_index'],
        'site_checklist_assignments' => ['assigned_to_role_id' => 'site_checklist_assignments_assigned_to_role_id_index'],
        'site_checklist_responses' => ['created_hazard_id' => 'site_checklist_responses_created_hazard_id_index', 'created_damage_id' => 'site_checklist_responses_created_damage_id_index'],
        'site_checklist_runs' => ['status' => 'site_checklist_runs_status_index'],
        'site_compliance_checks' => ['organization_id' => 'site_compliance_checks_organization_id_index', 'status' => 'site_compliance_checks_status_index'],
        'site_coverage_requirements' => ['organization_id' => 'site_coverage_requirements_organization_id_index'],
        'site_damages' => ['status' => 'site_damages_status_index'],
        'site_emergency_plans' => ['status' => 'site_emergency_plans_status_index'],
        'site_feedback' => ['organization_id' => 'site_feedback_organization_id_index', 'status' => 'site_feedback_status_index'],
        'site_hazard_actions' => ['status' => 'site_hazard_actions_status_index'],
        'site_hazards' => ['linked_inspection_id' => 'site_hazards_linked_inspection_id_index', 'linked_checklist_run_id' => 'site_hazards_linked_checklist_run_id_index'],
        'site_inspection_records' => ['linked_hazard_id' => 'site_inspection_records_linked_hazard_id_index', 'linked_checklist_run_id' => 'site_inspection_records_linked_checklist_run_id_index'],
        'site_meal_inventory_movements' => ['reference_id' => 'site_meal_inventory_movements_reference_id_index'],
        'site_meal_shopping_lists' => ['status' => 'site_meal_shopping_lists_status_index'],
        'site_rooms' => ['linked_room_id' => 'site_rooms_linked_room_id_index'],
        'site_staff_requirements' => ['organization_id' => 'site_staff_requirements_organization_id_index'],
        'site_type_plan_pins' => ['room_ref_id' => 'site_type_plan_pins_room_ref_id_index'],
        'site_type_plans' => ['status' => 'site_type_plans_status_index'],
        'spend_approvals' => ['source_id' => 'spend_approvals_source_id_index', 'site_id' => 'spend_approvals_site_id_index', 'cost_centre_id' => 'spend_approvals_cost_centre_id_index', 'funding_stream_id' => 'spend_approvals_funding_stream_id_index', 'donor_fund_id' => 'spend_approvals_donor_fund_id_index', 'budget_id' => 'spend_approvals_budget_id_index', 'budget_line_item_id' => 'spend_approvals_budget_line_item_id_index'],
        'sso_group_mappings' => ['external_group_id' => 'sso_group_mappings_external_group_id_index'],
        'staff_inductions' => ['status' => 'staff_inductions_status_index'],
        'staff_qualification_requirements' => ['service_context_id' => 'staff_qualification_requirements_service_context_id_index'],
        'strategic_goals' => ['status' => 'strategic_goals_status_index'],
        'strategic_initiatives' => ['status' => 'strategic_initiatives_status_index'],
        'task_escalations' => ['item_id' => 'task_escalations_item_id_index', 'assignee_id' => 'task_escalations_assignee_id_index'],
        'task_watchers' => ['item_id' => 'task_watchers_item_id_index'],
        'timesheets' => ['hr_time_entry_id' => 'timesheets_hr_time_entry_id_index'],
        'user_push_subscriptions' => ['device_id' => 'user_push_subscriptions_device_id_index'],
    ];

    /**
     * table => duplicate index names to drop (an identical index remains).
     *
     * @var array<string, string[]>
     */
    private const DROP_DUPLICATES = [
        'assets' => ['idx_assets_status', 'idx_assets_inspection_due', 'idx_assets_maintenance_due'],
        'audit_logs' => ['idx_audit_logs_user_created', 'idx_audit_logs_auditable'],
        'client_incidents' => ['idx_incidents_client_occurred', 'idx_incidents_status'],
        'notifications' => ['idx_notifications_notifiable_read'],
        'shifts' => ['idx_shifts_user_starts', 'idx_shifts_client_starts'],
        'staff_credentials' => ['idx_staff_creds_user_expires'],
    ];

    public function up(): void
    {
        foreach (self::ADD as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $add = [];
            foreach ($indexes as $column => $name) {
                if (Schema::hasColumn($table, $column)
                    && ! Schema::hasIndex($table, $name)
                    && ! Schema::hasIndex($table, [$column])) {
                    $add[$column] = $name;
                }
            }

            if ($add !== []) {
                Schema::table($table, function (Blueprint $t) use ($add) {
                    foreach ($add as $column => $name) {
                        $t->index($column, $name);
                    }
                });
            }
        }

        foreach (self::DROP_DUPLICATES as $table => $names) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $drop = array_values(array_filter(
                $names,
                fn (string $name) => Schema::hasIndex($table, $name),
            ));

            if ($drop !== []) {
                Schema::table($table, function (Blueprint $t) use ($drop) {
                    foreach ($drop as $name) {
                        $t->dropIndex($name);
                    }
                });
            }
        }
    }

    public function down(): void
    {
        // The dropped duplicates are intentionally not restored — an
        // identical index still exists for each of them.
        foreach (self::ADD as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $drop = array_values(array_filter(
                $indexes,
                fn (string $name) => Schema::hasIndex($table, $name),
            ));

            if ($drop !== []) {
                Schema::table($table, function (Blueprint $t) use ($drop) {
                    foreach ($drop as $name) {
                        $t->dropIndex($name);
                    }
                });
            }
        }
    }
};
