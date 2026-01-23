import { ReactNode } from 'react';

export default function PageShell({ children }: { children: ReactNode }) {
    return (
        // Global page padding is handled by the sidebar layout so pages stay full-width.
        <div className="space-y-6">{children}</div>
    );
}
