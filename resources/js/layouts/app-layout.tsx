import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import { type BreadcrumbItem } from '@/types';
import { type ReactNode } from 'react';

interface AppLayoutProps {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
    user?: unknown;
    [key: string]: unknown;
}

export default ({ children, breadcrumbs }: AppLayoutProps) => (
    <AppLayoutTemplate breadcrumbs={breadcrumbs}>
        {children}
    </AppLayoutTemplate>
);
