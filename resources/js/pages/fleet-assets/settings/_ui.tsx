import {
    TierTwoTabs,
    type GroupedProfileNavTab,
} from '@/components/page/grouped-profile-nav';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Info } from 'lucide-react';
import type { ComponentProps, ReactNode } from 'react';

export function Notice({ children }: { children: ReactNode }) {
    return (
        <Alert>
            <Info className="size-4" />
            <AlertDescription>{children}</AlertDescription>
        </Alert>
    );
}
export function Sections({
    tabs,
    value,
    onChange,
}: {
    tabs: GroupedProfileNavTab[];
    value: string;
    onChange: (value: string) => void;
}) {
    return (
        <TierTwoTabs
            tabs={tabs}
            activeTab={value}
            onTab={onChange}
            testIdPrefix="fleet-settings"
            ariaLabel="Settings sections"
            renderLink={(tab, className, inner, accessibility) => (
                <Button
                    variant="ghost"
                    key={tab.key}
                    className={className}
                    {...accessibility}
                    onClick={() => onChange(tab.key)}
                >
                    {inner}
                </Button>
            )}
        />
    );
}
export function Modal({
    title,
    description,
    children,
    footer,
    onClose,
    onCloseAutoFocus,
}: {
    title: string;
    description: string;
    children: ReactNode;
    footer?: ReactNode;
    onClose: () => void;
    onCloseAutoFocus?: ComponentProps<typeof DialogContent>['onCloseAutoFocus'];
}) {
    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent
                className="flex max-h-[88vh] flex-col overflow-hidden p-0 sm:max-w-[480px]"
                onCloseAutoFocus={onCloseAutoFocus}
            >
                <div className="shrink-0 border-b p-5 pr-12">
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription className="mt-2">
                        {description}
                    </DialogDescription>
                </div>
                <div className="min-h-0 space-y-4 overflow-y-auto p-5">
                    {children}
                </div>
                <DialogFooter className="shrink-0 border-t bg-muted/30 p-4">
                    {footer ?? (
                        <Button variant="outline" onClick={onClose}>
                            Close
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export class SettingsError extends Error {
    constructor(
        message: string,
        public status: number,
        public latest?: unknown,
    ) {
        super(message);
    }
}
export async function api<T>(
    path: string,
    method = 'GET',
    body?: unknown,
    signal?: AbortSignal,
): Promise<T> {
    const xsrf = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
        ?.slice(11);
    const csrf = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;
    const response = await fetch('/fleet-assets/settings/' + path, {
        method,
        signal,
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
            ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
        },
        ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
    });
    const data = await response
        .json()
        .catch(() => ({
            message:
                'The server could not complete this request. Your draft is retained.',
        }));
    if (!response.ok)
        throw new SettingsError(
            data.message ?? 'Could not save. Please retry.',
            response.status,
            data.latest,
        );
    return data;
}
