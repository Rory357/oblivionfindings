/**
 * Fixed choices for the Add / Edit site pop-up (moved here from the retired
 * full-page wizard, pages/sites/_wizard.tsx, with to-do #11).
 */
import { Building2, Home, Warehouse } from 'lucide-react';
import type { ComponentType } from 'react';

export type SiteType = 'head_office' | 'house' | 'facility' | 'residential';

export const SITE_TYPES: Array<{
    value: SiteType;
    label: string;
    icon: ComponentType<{ className?: string }>;
    description: string;
}> = [
    {
        value: 'head_office',
        label: 'Head office',
        icon: Building2,
        description: 'Administrative HQ with meeting rooms',
    },
    {
        value: 'house',
        label: 'House',
        icon: Home,
        description: 'Residential home with client bedrooms',
    },
    {
        value: 'facility',
        label: 'Facility',
        icon: Warehouse,
        description: 'Workshop, café or day programme space',
    },
    {
        value: 'residential',
        label: 'Residential',
        icon: Home,
        description: 'Client home for residential / home-support visits',
    },
];

/** The resource kinds a Site can store (site_ho_resources.resource_type). */
export const RESOURCE_TYPES = [
    { value: 'meeting_room', label: 'Meeting room' },
    { value: 'boardroom', label: 'Boardroom' },
    { value: 'training_room', label: 'Training room' },
    { value: 'other', label: 'Other (office, desk, parking…)' },
];

export const ZONE_TYPES = [
    { value: 'workshop', label: 'Workshop' },
    { value: 'cafe', label: 'Café' },
    { value: 'day_programme', label: 'Day programme' },
    { value: 'storage', label: 'Storage' },
    { value: 'other', label: 'Other' },
];

export const NZ_REGION_OPTIONS = [
    'Northland',
    'Auckland',
    'Waikato',
    'Bay of Plenty',
    'Gisborne',
    "Hawke's Bay",
    'Taranaki',
    'Manawatū-Whanganui',
    'Wellington',
    'Tasman',
    'Nelson',
    'Marlborough',
    'West Coast',
    'Canterbury',
    'Otago',
    'Southland',
];

const CITY_REGION_LOOKUP: Record<string, string> = {
    auckland: 'Auckland',
    manukau: 'Auckland',
    'north shore': 'Auckland',
    waitakere: 'Auckland',
    papakura: 'Auckland',
    devonport: 'Auckland',
    'grey lynn': 'Auckland',
    ponsonby: 'Auckland',
    'mt eden': 'Auckland',
    henderson: 'Auckland',
    takapuna: 'Auckland',
    albany: 'Auckland',
    hamilton: 'Waikato',
    cambridge: 'Waikato',
    'te awamutu': 'Waikato',
    tauranga: 'Bay of Plenty',
    rotorua: 'Bay of Plenty',
    wellington: 'Wellington',
    'lower hutt': 'Wellington',
    porirua: 'Wellington',
    'upper hutt': 'Wellington',
    'te aro': 'Wellington',
    christchurch: 'Canterbury',
    rangiora: 'Canterbury',
    dunedin: 'Otago',
    queenstown: 'Otago',
    whangarei: 'Northland',
    gisborne: 'Gisborne',
    napier: "Hawke's Bay",
    hastings: "Hawke's Bay",
    'new plymouth': 'Taranaki',
    'palmerston north': 'Manawatū-Whanganui',
    whanganui: 'Manawatū-Whanganui',
    nelson: 'Nelson',
    blenheim: 'Marlborough',
    greymouth: 'West Coast',
    invercargill: 'Southland',
};

export function deriveNzRegion(city: string): string | null {
    const needle = city.trim().toLowerCase();
    if (!needle) return null;
    if (CITY_REGION_LOOKUP[needle]) return CITY_REGION_LOOKUP[needle];
    const match = Object.entries(CITY_REGION_LOOKUP).find(([key]) =>
        needle.includes(key),
    );
    return match?.[1] ?? null;
}
