import {
    Briefcase,
    HeartHandshake,
    HeartPulse,
    MoreHorizontal,
    Siren,
    User,
    UserCheck,
    Users,
    Wrench,
} from 'lucide-react';
import type { ComponentType } from 'react';

export type ContactTypeKey =
    | 'site_contact'
    | 'site_lead'
    | 'team_lead'
    | 'emergency'
    | 'manager'
    | 'clinical'
    | 'family'
    | 'next_of_kin'
    | 'maintenance'
    | 'other';

export type ContactTypeDef = {
    key: ContactTypeKey;
    label: string;
    description: string;
    icon: ComponentType<{ className?: string }>;
    accent: string;
};

export const CONTACT_TYPES: ContactTypeDef[] = [
    {
        key: 'site_contact',
        label: 'Site Contact',
        description: 'Day-to-day point of contact',
        icon: User,
        accent: 'text-status-info',
    },
    {
        key: 'site_lead',
        label: 'Site Lead',
        description: 'Responsible lead for this site',
        icon: User,
        accent: 'text-primary',
    },
    {
        key: 'team_lead',
        label: 'Team Lead',
        description: 'Shift / on-floor lead',
        icon: Users,
        accent: 'text-status-info',
    },
    {
        key: 'emergency',
        label: 'Emergency',
        description: '24/7 on-call response',
        icon: Siren,
        accent: 'text-status-critical',
    },
    {
        key: 'manager',
        label: 'Manager',
        description: 'Service / house manager',
        icon: Briefcase,
        accent: 'text-status-warning',
    },
    {
        key: 'clinical',
        label: 'Clinical',
        description: 'RN, clinical lead',
        icon: HeartPulse,
        accent: 'text-status-success',
    },
    {
        key: 'family',
        label: 'Family / Whānau',
        description: "Client's family contact",
        icon: HeartHandshake,
        accent: 'text-status-info',
    },
    {
        key: 'next_of_kin',
        label: 'Next of Kin',
        description: 'Primary nominated relative',
        icon: UserCheck,
        accent: 'text-status-warning',
    },
    {
        key: 'maintenance',
        label: 'Maintenance',
        description: 'Trades / property contact',
        icon: Wrench,
        accent: 'text-status-warning',
    },
    {
        key: 'other',
        label: 'Other',
        description: 'Anything else',
        icon: MoreHorizontal,
        accent: 'text-muted-foreground',
    },
];

const FALLBACK_TYPE: ContactTypeDef = {
    key: 'other',
    label: 'Other',
    description: '',
    icon: MoreHorizontal,
    accent: 'text-muted-foreground',
};

export function getContactType(type?: string | null): ContactTypeDef {
    if (!type) return FALLBACK_TYPE;
    const normalised = type.toLowerCase().trim().replace(/[\s-]+/g, '_');
    return (
        CONTACT_TYPES.find((t) => t.key === normalised) ?? {
            ...FALLBACK_TYPE,
            label:
                type.charAt(0).toUpperCase() +
                type.slice(1).replaceAll('_', ' '),
        }
    );
}
