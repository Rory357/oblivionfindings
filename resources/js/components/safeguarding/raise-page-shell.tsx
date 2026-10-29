import { WizardPageShell } from '@/components/wizard/page-shell';
import { WizardShell } from '@/components/wizard/shell';
import { ShieldAlert } from 'lucide-react';
import { type ComponentProps } from 'react';

/**
 * Raise a safeguarding concern as a full page (approved drawings pg-hsn-23 …
 * 23e). The layout is the shared reporting page shell; see WizardPageShell.
 */
export function SafeguardingRaisePageShell(
    props: ComponentProps<typeof WizardShell>,
) {
    return (
        <WizardPageShell
            {...props}
            icon={ShieldAlert}
            successLabel="Concern recorded"
        />
    );
}
