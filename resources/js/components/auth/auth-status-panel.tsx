import { type LucideIcon } from 'lucide-react';
import { type ReactNode } from 'react';

/**
 * A whole-form-half message on the sign-in pages: round badge, title, text
 * and actions ("Your account is waiting for approval", "Check your email",
 * "You've signed out", …).
 */
export function AuthStatusPanel({
    icon: Icon,
    title,
    children,
    actions,
}: {
    icon: LucideIcon;
    title: string;
    children: ReactNode;
    actions?: ReactNode;
}) {
    return (
        <div role="status" className="flex flex-col gap-4">
            <span className="journey-badge" aria-hidden="true">
                <Icon className="size-5" />
            </span>
            <h1 className="journey-title">{title}</h1>
            <div className="journey-subtitle flex flex-col gap-2">
                {children}
            </div>
            {actions && (
                <div className="mt-1 flex flex-col gap-3">{actions}</div>
            )}
        </div>
    );
}
