import { ReactNode } from 'react';

export default function PageShell({ children }: { children: ReactNode }) {
    return (
        <div className="w-full space-y-8">{children}</div>
    );
}
