import { Alert, AlertDescription } from '@/components/ui/alert';
import { Info } from 'lucide-react';
import type { ReactNode } from 'react';

/**
 * The settings-page notice (promoted from Fleet Settings, eMAR P11 Q1): an
 * info alert for guidance, blocked states and save failures. Use
 * `role="note"` for guidance and the default `alert` for errors.
 */
export function SettingsNotice({
    children,
    role = 'alert',
}: {
    children: ReactNode;
    role?: 'alert' | 'note';
}) {
    return (
        <Alert role={role}>
            <Info className="size-4" />
            <AlertDescription>{children}</AlertDescription>
        </Alert>
    );
}
