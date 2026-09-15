/**
 * Shared pieces for the Policies pages (register, Policies to confirm, policy
 * record): the view toggle in each header's filter row and the plain
 * "Read and confirm" wording (vocabulary.md — never "attest").
 */
import { PageHeaderViewToggle } from '@/components/page';
import type { StatusVariant } from '@/components/ui/status-badge';
import { formatDateLong, formatDateOnly } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import { BookOpen, ClipboardCheck } from 'lucide-react';

export type PolicyView = 'policies' | 'confirm';

const POLICY_VIEW_HREFS: Record<PolicyView, string> = {
    policies: '/governance/policies',
    confirm: '/governance/policies/attestations',
};

/** All policies · Policies to confirm. */
export function PolicyViewToggle({ value }: { value: PolicyView }) {
    return (
        <PageHeaderViewToggle<PolicyView>
            ariaLabel="Policies view"
            value={value}
            onChange={(next) => {
                if (next !== value) router.visit(POLICY_VIEW_HREFS[next]);
            }}
            options={[
                { value: 'policies', label: 'All policies', icon: BookOpen },
                {
                    value: 'confirm',
                    label: 'Policies to confirm',
                    icon: ClipboardCheck,
                },
            ]}
        />
    );
}

export type ConfirmationState =
    | 'not_required'
    | 'not_approved'
    | 'replaced'
    | 'not_yet_in_effect'
    | 'to_confirm'
    | 'due_again'
    | 'confirmed';

export interface MyConfirmation {
    version: number;
    confirmed_at: string;
    due_again_on: string | null;
}

/** One chip per read-and-confirm state. */
export function confirmationChip(
    state: ConfirmationState,
    effectiveFrom?: string | null,
): { label: string; variant: StatusVariant } {
    switch (state) {
        case 'confirmed':
            return { label: 'Confirmed', variant: 'success' };
        case 'due_again':
            return { label: 'Due again', variant: 'warning' };
        case 'to_confirm':
            return { label: 'To confirm', variant: 'warning' };
        case 'not_yet_in_effect':
            return {
                label: effectiveFrom
                    ? `Comes into effect on ${formatDateOnly(effectiveFrom)}`
                    : 'Not in effect yet',
                variant: 'neutral',
            };
        case 'replaced':
            return { label: 'Replaced by a newer version', variant: 'neutral' };
        case 'not_approved':
            return { label: 'Not approved yet', variant: 'neutral' };
        default:
            return { label: 'No confirmation needed', variant: 'neutral' };
    }
}

/** "You confirmed version 3 on 7 September 2026." */
export function confirmationReceipt(confirmation: MyConfirmation): string {
    return `You confirmed version ${confirmation.version} on ${formatDateLong(confirmation.confirmed_at)}.`;
}

/** "3 of 7" — never more confirmations than board members. */
export function confirmedOf(confirmed: number, total: number): string {
    return `${Math.min(confirmed, total)} of ${total}`;
}

export function plural(count: number, one: string, many = `${one}s`): string {
    return `${count} ${count === 1 ? one : many}`;
}
