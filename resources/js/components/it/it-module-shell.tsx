import type { ReactNode } from 'react';

/** The app shell owns navigation and the outer gutter. IT views own their tabs. */
export function ItModuleShell({ children }: { children: ReactNode }) {
    return <div className="min-w-0">{children}</div>;
}
