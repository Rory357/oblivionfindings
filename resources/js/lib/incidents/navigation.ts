import { exposureReturn } from '@/lib/health-safety/chemical-exposure-navigation';
import { firstAidReturn } from '@/lib/health-safety/first-aid-navigation';
import { loneWorkerReturn } from '@/lib/health-safety/lone-worker-navigation';
import { restraintReturn } from '@/lib/health-safety/restraint-navigation';

function hasControlCharacters(value: string, includeSpace = false): boolean {
    return [...value].some((character) => {
        const code = character.charCodeAt(0);
        return code < (includeSpace ? 33 : 32) || code === 127;
    });
}
/** Only client incident lists and the incident tab are supported read destinations. */
function clientIncidentReturn(value: string | null | undefined): string | null {
    if (
        !value ||
        new TextEncoder().encode(value).length > 1500 ||
        /[\\#]/.test(value) ||
        hasControlCharacters(value, true)
    )
        return null;
    const path = value.split('?')[0];
    const match = path.match(
        /^\/(?:operations\/)?clients\/([1-9]\d*)(\/incidents)?$/,
    );
    if (!match || !Number.isSafeInteger(Number(match[1]))) return null;
    const target = new URL(value, 'https://incident-return.invalid');
    const query = new URLSearchParams();
    if (!match[2]) {
        if (
            target.searchParams.getAll('tab').length !== 1 ||
            target.searchParams.get('tab') !== 'incidents_accidents'
        )
            return null;
        query.set('tab', 'incidents_accidents');
    } else {
        const enums: Record<string, string[]> = {
            view: ['all', 'drafts', 'submitted', 'high', 'followup'],
            severity: ['all', 'low', 'medium', 'high', 'critical'],
        };
        for (const key of ['view', 'q', 'type', 'severity']) {
            const item = target.searchParams.get(key);
            if (
                !item ||
                target.searchParams.getAll(key).length !== 1 ||
                new TextEncoder().encode(item).length > 200 ||
                hasControlCharacters(item) ||
                (enums[key] && !enums[key].includes(item))
            )
                continue;
            query.set(key, item);
        }
    }
    return path + (query.size ? `?${query}` : '');
}

function positiveReadIdentity(value: string): boolean {
    return /^[1-9]\d*$/.test(value) && Number.isSafeInteger(Number(value));
}

/** These two live source workspaces admit read context only, never action state. */
function eventOrFleetReturn(value: string | null | undefined): string | null {
    if (!value || new TextEncoder().encode(value).length > 1500 ||
        /[\p{Cc}\p{Cf}\\#]/u.test(value) || hasControlCharacters(value, true))
        return null;
    const path = value.split('?')[0];
    const event = path.match(/^\/health-safety\/events\/([1-9]\d*)$/);
    const fleet = path === '/fleet-assets/incidents';
    if ((!event && !fleet) || (event && !positiveReadIdentity(event[1]))) return null;
    const target = new URL(value, 'https://incident-return.invalid');
    if (target.origin !== 'https://incident-return.invalid' || target.pathname !== path)
        return null;
    for (const [key, item] of target.searchParams) {
        if (/[\p{Cc}\p{Cf}\\#]/u.test(key + item)) return null;
    }
    const query = new URLSearchParams();
    if (event) {
        const sections = ['overview', 'handover', 'investigation', 'actions', 'risk', 'timeline', 'evidence'];
        const section = target.searchParams.get('section');
        if (section && target.searchParams.getAll('section').length === 1 && sections.includes(section))
            query.set('section', section);
    } else {
        const incident = target.searchParams.get('incident');
        if (!incident || target.searchParams.getAll('incident').length !== 1 || !positiveReadIdentity(incident))
            return null;
        query.set('incident', incident);
        // Actual Fleet index filters and paginator; report/export/asset_id are not read filters.
        for (const key of ['vehicle_id', 'driver_id', 'site_id', 'page']) {
            const item = target.searchParams.get(key);
            if (item === null) continue;
            if (target.searchParams.getAll(key).length !== 1) return null;
            if (item === '') continue;
            if (!positiveReadIdentity(item)) return null;
            query.set(key, item);
        }
        const enums: Record<string, string[]> = {
            tab: ['all', 'open', 'under_investigation', 'police_report_due', 'injury_acc', 'insurance_claims', 'off_road', 'near_misses', 'closed'],
            severity: ['minor', 'moderate', 'major', 'critical'],
            incident_type: ['collision', 'damage', 'theft', 'vandalism', 'breakdown', 'near_miss', 'other'],
            status: ['reported', 'investigating', 'resolved', 'closed'],
        };
        for (const [key, options] of Object.entries(enums)) {
            const item = target.searchParams.get(key);
            if (item && target.searchParams.getAll(key).length === 1 && options.includes(item)) query.set(key, item);
        }
        const search = target.searchParams.get('search');
        if (search && target.searchParams.getAll('search').length === 1 && new TextEncoder().encode(search).length <= 200)
            query.set('search', search);
        for (const key of ['date_from', 'date_to']) {
            const item = target.searchParams.get(key);
            if (!item || target.searchParams.getAll(key).length !== 1 || !/^\d{4}-\d{2}-\d{2}$/.test(item)) continue;
            const [year, month, day] = item.split('-').map(Number);
            const leap = year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0);
            const days = [31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
            if (year > 0 && month >= 1 && month <= 12 && day >= 1 && day <= days[month - 1]) query.set(key, item);
        }
    }
    return path + (query.size ? `?${query}` : '');
}

/** Preserve supported source context without accepting an arbitrary redirect. */
export function incidentRegisterReturn(
    value: string | null | undefined,
): string {
    if (value === '/my-day') return '/my-day';
    const eventOrFleet = eventOrFleetReturn(value);
    if (eventOrFleet) return eventOrFleet;
    const client = clientIncidentReturn(value);
    if (client) return client;
    if (
        /^\/health-safety\/restraints(?:\/(?:events|plans)\/[1-9]\d*(?:\/(?:review|approval))?)?(?:\?|$)/.test(
            value ?? '',
        )
    )
        return restraintReturn(value);
    if (
        /^\/health-safety\/chemical-exposures(?:\/[1-9]\d*)?(?:\?|$)/.test(
            value ?? '',
        )
    )
        return exposureReturn(value);
    if (/^\/health-safety\/injuries(?:\/[1-9]\d*)?(?:\?|$)/.test(value ?? ''))
        return firstAidReturn(value);
    if (/^\/health-safety\/first-aid\/[1-9]\d*(?:\?|$)/.test(value ?? ''))
        return firstAidReturn(value);
    if (
        /^\/health-safety\/lone-workers\/sessions\/[1-9]\d*(?:\?|$)/.test(
            value ?? '',
        )
    )
        return loneWorkerReturn(value);
    if (!value || !value.startsWith('/incidents') || value.startsWith('//'))
        return '/incidents';
    const target = new URL(value, 'https://incident-return.invalid');
    if (
        target.origin !== 'https://incident-return.invalid' ||
        target.pathname !== '/incidents'
    )
        return '/incidents';
    const allowed = new Set([
        'q',
        'tab',
        'severity',
        'client_id',
        'site_id',
        'source',
        'kind',
        'owner',
        'from',
        'to',
        'page',
    ]);
    const params = new URLSearchParams();
    target.searchParams.forEach((value, key) => {
        if (
            allowed.has(key) &&
            (key !== 'kind' ||
                ['all', 'incidents', 'near_misses'].includes(value)) &&
            (key !== 'owner' || ['all', 'me', 'needs_owner'].includes(value))
        )
            params.set(key, value);
    });
    return `/incidents${params.size ? `?${params}` : ''}`;
}

export function incidentReturnLabel(value: string): string {
    const destination = incidentRegisterReturn(value);
    if (destination === '/my-day') return 'My Day';
    if (/^\/(?:operations\/)?clients\//.test(destination))
        return 'Client incidents';
    if (destination.startsWith('/health-safety/events/')) return 'Safety reviews';
    if (destination.startsWith('/fleet-assets/incidents')) return 'Fleet incidents';
    if (destination.startsWith('/health-safety/injuries'))
        return 'Workplace injuries';
    if (destination.startsWith('/health-safety/restraints'))
        return 'Restrictive interventions';
    if (destination.startsWith('/health-safety/chemical-exposures'))
        return 'Chemical exposures';
    if (destination.startsWith('/health-safety/first-aid')) return 'First aid';
    if (destination.startsWith('/health-safety/lone-workers'))
        return 'Lone worker safety';
    return 'Incidents';
}

export function incidentWorkspaceHref(id: number, returnTo?: string): string {
    const params = new URLSearchParams();
    const destination = incidentRegisterReturn(returnTo);
    if (destination !== '/incidents') params.set('return_to', destination);
    return `/incidents/${id}${params.size ? `?${params}` : ''}`;
}
