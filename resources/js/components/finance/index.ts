// Shared Finance primitives. Import from '@/components/finance'.
//
// Reuse over fork: finance builds on the app-wide primitives rather than its
// own copies — the Event Horizon PageHeader and its rail (via
// FinanceSectionRail), the EntityTable/EntityCard list contracts, the
// WizardShell kit, the one ConfirmDialog and the one StatusBadge. What lives
// here is only what is genuinely finance-specific: the hub rail bound to
// lib/finance-sections.ts, the entity dialogs, the money field, the posting
// preview and the report composition.
export * from './audit-export-dialog';
export * from './bank-account-dialog';
export * from './cash-flow-forecast-dialog';
export * from './credit-note-dialog';
export * from './donor-fund-dialog';
export * from './donor-fund-transaction-dialog';
export * from './finance-period-filter';
export {
    FinanceSectionRail,
    FinanceTierTwoNav,
    sectionRailLabel,
    type FinanceHubCounts,
} from './finance-section-rail';
export * from './fixed-asset-dialog';
export * from './fixed-asset-dispose-dialog';
export * from './funding-stream-dialog';
export * from './money';
export * from './new-account-dialog';
export * from './new-bill-dialog';
export * from './new-invoice-dialog';
export * from './new-journal-dialog';
export * from './new-po-dialog';
export * from './new-vendor-dialog';
export * from './payment-run-dialog';
export * from './petty-cash-fund-dialog';
export * from './posting-preview';
export * from './price-book-dialog';
export * from './quote-dialog';
export * from './record-receipt-dialog';
export * from './recurring-charge-dialog';
export * from './report-page';
export * from './start-reconciliation-dialog';
export * from './wizard';

// The app's one confirmation dialog and one status pill (DESIGN.md) — finance
// used to carry forks of both.
export { ConfirmDialog } from '@/components/confirm-dialog';
export {
    StatusBadge,
    type StatusBadgeProps,
    type StatusVariant,
} from '@/components/ui/status-badge';
