import { InertiaLinkProps } from '@inertiajs/react';
import { LucideIcon } from 'lucide-react';

export interface AuthPermissions {
    sites?: {
        viewAny?: boolean;
        create?: boolean;
        update?: boolean;
        archive?: boolean;
        types?: Record<string, boolean>;
    };
    staff?: {
        viewAny?: boolean;
        create?: boolean;
        update?: boolean;
        invite?: boolean;
    };
    assets?: {
        viewAny?: boolean;
        viewAssigned?: boolean;
        create?: boolean;
        update?: boolean;
        delete?: boolean;
    };
    clients?: {
        viewAny?: boolean;
        viewAssigned?: boolean;
        create?: boolean;
        update?: boolean;
    };
    shifts?: {
        viewAny?: boolean;
        viewAssigned?: boolean;
        create?: boolean;
        update?: boolean;
        manageAny?: boolean;
    };
    calendar?: {
        viewAny?: boolean;
        view?: boolean;
        create?: boolean;
        manage?: boolean;
        approve?: boolean;
        manageRecurring?: boolean;
    };
    medications?: {
        view?: boolean;
        breakGlass?: boolean;
        audit?: { view?: boolean };
        orders?: { manage?: boolean };
        administer?: { record?: boolean; correct?: boolean };
    };
    incidents?: {
        viewAny?: boolean;
        viewAssigned?: boolean;
        create?: boolean;
        update?: boolean;
        submit?: boolean;
        approve?: boolean;
        templatesManage?: boolean;
    };
    governance?: {
        view?: boolean;
        meetings?: { view?: boolean; manage?: boolean };
        resolutions?: { view?: boolean; vote?: boolean; manage?: boolean };
        risks?: { view?: boolean; manage?: boolean; create?: boolean };
        budgets?: {
            view?: boolean;
            create?: boolean;
            submit?: boolean;
            approve?: boolean;
        };
        compliance?: { view?: boolean; manage?: boolean; create?: boolean };
        performance?: { view?: boolean; manage?: boolean; create?: boolean };
        strategy?: { view?: boolean; manage?: boolean };
        documents?: { view?: boolean; manage?: boolean };
        packs?: { view?: boolean; manage?: boolean };
        actions?: { view?: boolean; manage?: boolean };
        policies?: { view?: boolean; manage?: boolean };
        'ceo-reports'?: { view?: boolean; manage?: boolean };
        interests?: { view?: boolean; manage?: boolean };
        evaluations?: { view?: boolean; manage?: boolean };
        clinical?: { view?: boolean; manage?: boolean };
        'te-tiriti'?: { view?: boolean; manage?: boolean };
        evidence?: { view?: boolean; manage?: boolean };
        audit?: { view?: boolean };
        spend?: { view?: boolean; request?: boolean; approve?: boolean };
        settings?: { view?: boolean; manage?: boolean };
    };
    finance?: {
        dashboard?: boolean;
        ledger?: { view?: boolean; manage?: boolean };
        ap?: { view?: boolean };
    };
    hr?: {
        recruitment?: { view?: boolean; manage?: boolean };
        employees?: { viewAny?: boolean; manage?: boolean };
    };
    [key: string]: unknown;
}

export interface Auth {
    user: User;
    can?: AuthPermissions;
    impersonating?: boolean;
    impersonator?: { id: number; name: string } | null;
}

export interface BreadcrumbItem {
    title: string;
    href?: NonNullable<InertiaLinkProps['href']>;
}

export interface NavGroup {
    id: string;
    label: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
    isActive?: boolean;
    badge?: number;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    sidebarOpen: boolean;
    /** Per-user calendar subscribe (.ics) URL; null until the user generates a token. */
    calendarFeedUrl?: string | null;
    [key: string]: unknown;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = SharedData & T;

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
}
