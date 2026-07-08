// Shared Finance design-spine primitives. Import from '@/components/finance'.
//
// Reuse over fork: the genuinely shared primitives — TabStrip (rostering),
// WizardShell kit (wizard), StatusBadge (hr) — are reused, not duplicated, so
// Finance tabs/modals/badges are visually identical to HR + Rostering. Only the
// finance-specific pieces (hero category, money field, posting preview) are new.
export * from './audit-export-dialog';
export * from './bank-account-dialog';
export * from './banking-hub';
export * from './cash-flow-forecast-dialog';
export * from './confirm-dialog';
export * from './credit-note-dialog';
export * from './donor-fund-dialog';
export * from './donor-fund-transaction-dialog';
export * from './finance-hero';
export * from './finance-tabs';
export * from './fixed-asset-dialog';
export * from './fixed-asset-dispose-dialog';
export * from './funding-stream-dialog';
export * from './ledger-hub';
export * from './money';
export * from './new-account-dialog';
export * from './new-bill-dialog';
export * from './new-invoice-dialog';
export * from './new-journal-dialog';
export * from './new-po-dialog';
export * from './new-vendor-dialog';
export * from './payables-hub';
export * from './petty-cash-fund-dialog';
export * from './price-book-dialog';
export * from './quote-dialog';
export * from './recurring-charge-dialog';
export * from './posting-preview';
export * from './receivables-hub';
export * from './record-receipt-dialog';
export * from './reports-hub';
export * from './tax-hub';
export * from './wizard';

// Reuse HR's legible StatusBadge (already covers paid/posted/overdue/approved/…)
// rather than forking a second status pill.
export {
    StatusBadge,
    statusTone,
    type StatusTone,
} from '@/components/hr/status-badge';

// Reuse HR's generic row context-menu hook (portal, cursor-positioned, token-
// styled, keyboard-navigable) for right-click actions on finance list rows — the
// same mould HR hubs use, so the interaction is identical across modules. Build a
// per-row RowCtxItem[] mirroring the page's existing inline actions (Open first).
export {
    useRowContextMenu,
    type RowCtxItem,
} from '@/components/hr/row-context-menu';
