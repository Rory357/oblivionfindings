/**
 * Public reference/storage/SDS contract. Source preparation; all checks UNRUN.
 * Mirrors ChemicalRegisterReadService, ChemicalCommandService/Values and the
 * public HTTP composition mounted in isolated H&S source. The original patch
 * is retained as a historical proposal; no runtime acceptance or freeze.
 * No identifiable exposure DTO.
 * Null counts/facts are unknown or restricted, never zero. Types neither
 * authorize commands nor validate JSON; current source checks remain server-side.
 */
export type ChemicalSourceIntegrity = 'valid' | 'legacy_unknown' | 'repair_required';
export type ChemicalAttribution = 'recorded' | 'legacy_unknown' | 'repair_required';
export type ChemicalSourceType = 'catalogue' | 'sds' | 'storage';
export type ChemicalPhysicalForm = 'solid' | 'liquid' | 'gas' | 'powder' | 'aerosol' | 'paste' | 'other';
export type ChemicalLifecycleStatus = 'active' | 'inactive' | 'removed';
export type ChemicalTab = 'all' | 'active' | 'controlled' | 'sds_expiring' | 'sds_missing' | 'inactive';
export type ChemicalControlledFilter = 'controlled' | 'standard';
export type ChemicalSdsState = 'current' | 'expiring' | 'expired' | 'missing' | 'unknown'
    | 'repair_required' | 'unavailable' | 'not_yet_effective';
export type ChemicalSdsFilter = ChemicalSdsState | 'ambiguous';
export type ChemicalSheetState = 'current' | 'expiring' | 'expired' | 'unknown'
    | 'superseded' | 'repair_required' | 'unavailable' | 'not_yet_effective';
export type ChemicalCommand = 'create_catalogue' | 'update_catalogue' | 'change_status'
    | 'create_storage' | 'update_storage' | 'audit_inventory' | 'archive_storage' | 'restore_storage'
    | 'upload_sds' | 'correct_sds_metadata' | 'set_current_sds';

/** YYYY-MM-DD in configured worker/app/UTC civil zone, not an instant. */
export type ChemicalDay = string;
/** Serialized ISO instant; accepted history retains UTC microseconds. */
export type ChemicalInstant = string;
/** Accepted UUID is lower case; source normalizes upper-case input. */
export type ChemicalUuid = string;
/** JSON integer or canonical integer text for multipart; server validates bounds. */
export type ChemicalIntegerInput = number | string;
export type ChemicalBooleanInput = boolean | 0 | 1 | '0' | '1';
/** Non-negative <=99,999,999.99 with <=two decimals; empty string is invalid. */
export type ChemicalQuantityInput = number | string | null;

export interface ChemicalSiteOption { id: number; name: string }
/** Exactly the current flags emitted by public reader, no invented action grants. */
export interface ChemicalCan { create: boolean; manage: boolean }
export interface ChemicalSourceMetadata {
    source_integrity: ChemicalSourceIntegrity;
    attribution_state: ChemicalAttribution;
    source_revision: number | null;
    expected_version: number | null;
    parent_expected_version: number | null;
    source_version_id: number | null;
}
export interface ChemicalCurrentSds {
    state: 'none' | 'legacy_unknown' | 'recorded' | 'repair_required' | 'unavailable' | 'not_yet_effective';
    id: number | null;
    available: boolean;
    currency: 'unknown' | 'current' | 'expiring' | 'overdue' | 'not_yet_effective';
}
/** Legacy status/form strings remain honest read facts; command/filter unions are stricter. */
export interface ChemicalDefinitionFacts {
    id: number;
    name: string | null;
    common_name: string | null;
    un_number: string | null;
    hsno_approval: string | null;
    hsno_classification: string | null;
    hazard_classifications: string[] | null;
    ghs_pictograms: string[] | null;
    signal_word: string | null;
    hazard_statements: string | null;
    precautionary_statements: string | null;
    physical_form: string | null;
    requires_tracking: boolean | null;
    is_controlled_substance: boolean | null;
    status: string | null;
    status_reason: string | null;
    ppe_required: string | null;
    storage_requirements: string | null;
    handling_precautions: string | null;
    first_aid_measures: string | null;
    firefighting_measures: string | null;
    spill_procedures: string | null;
    exposure_limit_type: string | null;
    exposure_limit_value: string | null;
}
export interface ChemicalHistoryItem {
    id: number;
    source_type: ChemicalSourceType;
    source_id: number;
    site_id: number | null;
    version: number;
    expected_version: number | null;
    parent_expected_version: number | null;
    command: ChemicalCommand;
    recorded_at: ChemicalInstant;
    actor: { id: number; name: string };
    reason: string | null;
}
export interface ChemicalHistory {
    source_integrity: ChemicalSourceIntegrity;
    /** No concealed original-Site count. Null is repair, not empty history. */
    items: ChemicalHistoryItem[] | null;
}
export interface ChemicalSdsRecord extends ChemicalSourceMetadata {
    id: number;
    version: string | null;
    issue_date: ChemicalDay | null;
    review_date: ChemicalDay | null;
    supplier_name: string | null;
    supplier_contact: string | null;
    status: string | null;
    state: ChemicalSheetState;
    available: boolean;
    file_name: string | null;
    uploaded_by: string | null;
    created_at: ChemicalInstant | null;
    download_url: string | null;
}
export interface ChemicalStorageLocation extends ChemicalSourceMetadata {
    id: number;
    site: ChemicalSiteOption;
    location_description: string | null;
    current_quantity: number | null;
    quantity_unit: string | null;
    maximum_quantity: number | null;
    container_type: string | null;
    properly_labelled: boolean | null;
    segregation_compliant: boolean | null;
    last_audit_date: ChemicalDay | null;
    storage_notes: string | null;
    inventory_state: 'unknown';
    can: ChemicalCan;
    history: ChemicalHistory | null;
}
export interface ChemicalArchivedStorageLocation extends ChemicalStorageLocation { archived_at: ChemicalInstant | null }
export interface ChemicalArchivedStorage {
    /** Original and current Site admission precede discovery; denied records are not counted. */
    items: ChemicalArchivedStorageLocation[] | null;
    recorded_count: number | null;
    physical_record_count: number | null;
    repair_count: number | null;
    truncated: boolean | null;
}
interface ChemicalDetailBase extends ChemicalDefinitionFacts, ChemicalSourceMetadata {
    sds_state: ChemicalSdsState;
    current_sds: ChemicalCurrentSds;
    current_sds_id: number | null;
    inventory_state: 'unknown';
    exposure_access: 'restricted';
    exposure_records: null;
    created_by: string | null;
    created_at: ChemicalInstant | null;
    updated_at: ChemicalInstant | null;
    can: ChemicalCan;
    archived_storage: ChemicalArchivedStorage;
}
export type ChemicalDetail =
    | (ChemicalDetailBase & {
        source_integrity: 'valid' | 'legacy_unknown';
        sds_records: ChemicalSdsRecord[];
        storage_locations: ChemicalStorageLocation[];
        history: ChemicalHistory;
        counts: { sds: number | null; storage: number | null; exposures: null };
        physical_counts: { sds: number; storage: number };
        repair_counts: { sds: number; storage: number };
        truncated: { sds: boolean; storage: boolean };
    })
    | (ChemicalDetailBase & {
        source_integrity: 'repair_required';
        sds_records: null;
        storage_locations: null;
        history: null;
        counts: { sds: null; storage: null; exposures: null };
        can: { create: false; manage: false };
        // Repair branch omits these keys; never synthesize physical zero.
        physical_counts?: never;
        repair_counts?: never;
        truncated?: never;
    });
export interface ChemicalRegisterRow extends ChemicalSourceMetadata {
    id: number;
    name: string | null;
    common_name: string | null;
    un_number: string | null;
    hsno_classification: string | null;
    hazard_classifications: string[] | null;
    ghs_pictograms: string[] | null;
    physical_form: string | null;
    is_controlled_substance: boolean | null;
    requires_tracking: boolean | null;
    status: string | null;
    sds_state: ChemicalSdsState;
    current_sds_id: number | null;
    current_sds: ChemicalCurrentSds;
    storage_count: number | null;
    physical_storage_count: number | null;
    storage_repair_count: number | null;
    inventory_state: 'unknown' | 'repair_required';
    can: ChemicalCan;
}
export interface ChemicalRegisterFilters {
    q: string;
    tab: ChemicalTab;
    site_id: number | null;
    physical_form: ChemicalPhysicalForm | null;
    status: ChemicalLifecycleStatus | null;
    controlled: ChemicalControlledFilter | null;
    sds_state: ChemicalSdsFilter | null;
    period: number;
}
/** Query only: page is not echoed in normalized filters; period is 7..365. */
export type ChemicalRegisterQuery = Partial<ChemicalRegisterFilters> & { page?: number };
export interface ChemicalPagination<T> {
    data: T[];
    current_page: number;
    per_page: 20;
    total: number;
    last_page: number;
    // Actual new reader has no Laravel links array.
}
export interface ChemicalIndex {
    filters: ChemicalRegisterFilters;
    tab: ChemicalTab;
    tabCounts: Record<ChemicalTab, number | null>;
    rows: ChemicalPagination<ChemicalRegisterRow>;
    source_integrity: ChemicalSourceIntegrity;
    physical_record_count: number;
    repair_counts: { catalogue: number; sds: number; storage: number };
    hero: {
        live: { active: number | null; controlled: number | null; sds_current: number | null;
            storage_locations: number | null; inventory_state: 'unknown' };
        attention: { sds_expiring: number | null; sds_missing: number | null; sds_unknown: number | null;
            awaiting_review: null; exposures: null };
    };
    badges: { sds_to_action: number | null; worksafe_awaiting: null };
    exposure_access: 'restricted';
    /** Exposure numbers for anyone who sees the list (Stephan, 26 Oct), at the Sites shown; counts only. */
    exposure_summary?: { exposures: number; awaiting_outcome: number; worksafe_awaiting: number; period_days: number; exposures_href: string | null };
    sites: ChemicalSiteOption[];
    can: ChemicalCan;
    detail: null;
}
export interface ChemicalStorageForSite {
    site_id: number;
    storage_locations: (ChemicalStorageLocation & { substance: { id: number; name: string | null; status: string | null } })[];
    recorded_location_count: number | null;
    physical_record_count: number;
    repair_count: number;
    source_integrity: ChemicalSourceIntegrity;
    inventory_state: 'unknown';
    can: ChemicalCan;
}
interface ChemicalReceiptBase {
    accepted: true;
    actor_id: number;
    idempotency_key: ChemicalUuid;
    root_id: number;
    source_id: number;
    expected_version: number | null;
    version: number;
    source_version_id: number | null;
    /** Persisted immutable bool; null version identity only a real accepted no-op. */
    changed: boolean;
}
export type ChemicalReceipt =
    | (ChemicalReceiptBase & { root_type: 'catalogue'; source_type: 'catalogue'; parent_id: null;
        parent_expected_version: null; command: 'create_catalogue' | 'update_catalogue' | 'change_status' })
    | (ChemicalReceiptBase & { root_type: 'catalogue'; source_type: 'sds'; parent_id: number;
        parent_expected_version: null; expected_version: number;
        command: 'upload_sds' | 'correct_sds_metadata' | 'set_current_sds' })
    | (ChemicalReceiptBase & { root_type: 'storage'; source_type: 'storage'; parent_id: number;
        parent_expected_version: number;
        command: 'create_storage' | 'update_storage' | 'audit_inventory' | 'archive_storage' | 'restore_storage' });
export interface ChemicalCommandResponse { chemical_confirmation: ChemicalReceipt; chemical_href: string | null }
export interface ChemicalRecoveryResponse { chemical_confirmation: ChemicalReceipt | null; chemical_href: string | null }
export interface ChemicalConfirmationHint { source_type: ChemicalSourceType; source_id: number; idempotency_key: ChemicalUuid }
export interface ChemicalCapture {
    sites: ChemicalSiteOption[];
    options: { physical_forms: ChemicalPhysicalForm[]; lifecycle_statuses: ChemicalLifecycleStatus[];
        sds_file_extensions: ('pdf' | 'doc' | 'docx')[]; sds_max_kb: 10240 };
    can: { create: true };
}
export interface ChemicalShowProps extends ChemicalRecoveryResponse { detail: ChemicalDetail; return_to: string }
export interface ChemicalCreateProps extends ChemicalRecoveryResponse { capture: ChemicalCapture; return_to: string }
export interface ChemicalHistoryResponse { history: ChemicalHistory }
export interface ChemicalHistorySelection { source_type: ChemicalSourceType; source_id: number }
export interface ChemicalValidationResponse { message: string; errors: Record<string, string[]> }

/** Exact domain bodies: route selects the command, not an input command key. */
export interface ChemicalCreateEnvelope { idempotency_key: ChemicalUuid }
export interface ChemicalChangeEnvelope extends ChemicalCreateEnvelope {
    /** Reviewed root revision 0..4,294,967,294; never refresh silently on uncertainty. */
    expected_version: ChemicalIntegerInput;
}
export interface ChemicalReferenceOptionalFields {
    common_name?: string | null;
    un_number?: string | null;
    hsno_approval?: string | null;
    hsno_classification?: string | null;
    hazard_classifications?: string[] | null;
    ghs_pictograms?: string[] | null;
    signal_word?: string | null;
    hazard_statements?: string | null;
    precautionary_statements?: string | null;
    ppe_required?: string | null;
    storage_requirements?: string | null;
    handling_precautions?: string | null;
    first_aid_measures?: string | null;
    firefighting_measures?: string | null;
    spill_procedures?: string | null;
    exposure_limit_type?: string | null;
    exposure_limit_value?: string | null;
    requires_tracking?: ChemicalBooleanInput;
    is_controlled_substance?: ChemicalBooleanInput;
}
export interface ChemicalCreateCatalogueCommand extends ChemicalCreateEnvelope, ChemicalReferenceOptionalFields {
    name: string;
    physical_form: ChemicalPhysicalForm;
    expected_version?: never;
}
export interface ChemicalUpdateCatalogueCommand extends ChemicalChangeEnvelope, ChemicalReferenceOptionalFields {
    name?: string;
    physical_form?: ChemicalPhysicalForm;
}
export interface ChemicalStatusCommand extends ChemicalChangeEnvelope { status: ChemicalLifecycleStatus; reason: string }
export interface ChemicalStorageOptionalFields {
    current_quantity?: ChemicalQuantityInput;
    maximum_quantity?: ChemicalQuantityInput;
    quantity_unit?: string | null;
    container_type?: string | null;
    properly_labelled?: ChemicalBooleanInput;
    segregation_compliant?: ChemicalBooleanInput;
    last_audit_date?: ChemicalDay | null;
    storage_notes?: string | null;
}
export interface ChemicalCreateStorageCommand extends ChemicalCreateEnvelope, ChemicalStorageOptionalFields {
    expected_version?: null;
    parent_expected_version: ChemicalIntegerInput;
    site_id: ChemicalIntegerInput;
    location_description: string;
}
export interface ChemicalUpdateStorageCommand extends ChemicalChangeEnvelope, ChemicalStorageOptionalFields {
    parent_expected_version: ChemicalIntegerInput;
    site_id?: ChemicalIntegerInput;
    location_description?: string;
}
export interface ChemicalInventoryAuditCommand extends ChemicalChangeEnvelope {
    parent_expected_version: ChemicalIntegerInput;
    /** Key must be present; null records unknown quantity without claiming zero. */
    current_quantity: ChemicalQuantityInput;
    properly_labelled: ChemicalBooleanInput;
    segregation_compliant: ChemicalBooleanInput;
    last_audit_date: ChemicalDay;
    storage_notes?: string | null;
}
export interface ChemicalStorageArchiveCommand extends ChemicalChangeEnvelope { parent_expected_version: ChemicalIntegerInput; reason: string }
export type ChemicalStorageRestoreCommand = ChemicalStorageArchiveCommand;
export interface ChemicalSdsOptionalFields {
    review_date?: ChemicalDay | null;
    supplier_name?: string | null;
    supplier_contact?: string | null;
}
/** Multipart HTTP; actual File passes separately to domain, never a stored path. */
export interface ChemicalUploadSdsCommand extends ChemicalChangeEnvelope, ChemicalSdsOptionalFields {
    file: File;
    version: string;
    issue_date: ChemicalDay;
}
export interface ChemicalCorrectSdsMetadataCommand extends ChemicalChangeEnvelope, ChemicalSdsOptionalFields {
    version?: string;
    issue_date?: ChemicalDay;
    reason: string;
}
export interface ChemicalSetCurrentSdsCommand extends ChemicalChangeEnvelope { reason: string }
export interface ChemicalCommandBodies {
    create_catalogue: ChemicalCreateCatalogueCommand;
    update_catalogue: ChemicalUpdateCatalogueCommand;
    change_status: ChemicalStatusCommand;
    create_storage: ChemicalCreateStorageCommand;
    update_storage: ChemicalUpdateStorageCommand;
    audit_inventory: ChemicalInventoryAuditCommand;
    archive_storage: ChemicalStorageArchiveCommand;
    restore_storage: ChemicalStorageRestoreCommand;
    upload_sds: ChemicalUploadSdsCommand;
    correct_sds_metadata: ChemicalCorrectSdsMetadataCommand;
    set_current_sds: ChemicalSetCurrentSdsCommand;
}
/** Only known framing is stripped; arbitrary domain keys remain rejected. */
export interface ChemicalHttpFraming { _token?: string; _method?: string; return_to?: string }

/**
 * LOCAL uncertainty workflow, not a server DTO or invented endpoint.
 * Unknown new catalogue/storage IDs have no read-only UUID-discovery API.
 * Retry exact original POST/UUID/reviewed revisions/facts. SDS has known parent
 * and /{parent}/sds/recover/{key}; null remains unconfirmed, retry retains same
 * file bytes. Named confirmation needs known source ID/current original grant.
 */
export type ChemicalRecoveryContext =
    | { kind: 'new_catalogue'; source_id: null; original: ChemicalCreateCatalogueCommand; check: 'retry_exact_post' }
    | { kind: 'new_storage'; parent_id: number; source_id: null; original: ChemicalCreateStorageCommand; check: 'retry_exact_post' }
    | { kind: 'sds_upload'; parent_id: number; original: ChemicalUploadSdsCommand; check: 'sds_recovery_then_exact_retry' }
    | { kind: 'known_source'; parent_id: number; source_type: ChemicalSourceType; source_id: number;
        idempotency_key: ChemicalUuid; check: 'named_confirmation' };

/** Stable root-page import names; aliases preserve the actual public DTO shapes. */
export type ChemicalIndexProps = ChemicalIndex;
export type ChemicalStorage = ChemicalStorageLocation;
export type ChemicalSds = ChemicalSdsRecord;
export type ChemicalCommandName = ChemicalCommand;
