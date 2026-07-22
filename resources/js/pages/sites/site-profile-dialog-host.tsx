import type { ReactNode } from 'react';

export type SiteProfileDialogIntent =
    | { type: 'edit-site' }
    | { type: 'create-client' }
    | { type: 'place-client' }
    | { type: 'edit-room'; roomId: number }
    | { type: 'edit-plan' }
    | { type: 'upload-document' }
    | { type: 'open-canonical'; href: string };

/**
 * One typed owner for Site Profile modal state. Canonical module dialogs are
 * mounted here as each full-depth tab is restored.
 */
export function SiteProfileDialogHost({ children }: { children?: ReactNode }) {
    return <>{children}</>;
}
