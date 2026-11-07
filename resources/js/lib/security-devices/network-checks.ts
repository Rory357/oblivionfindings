/**
 * Obli checks a Site's network (S&D Phase I, pop-up 97): what the server sends for the pop-up, and
 * the calls it makes. Times arrive as instants and are written in NZ time through lib/datetime.
 */
import type { StatusVariant } from '@/components/ui/status-badge';
import axios from 'axios';

export type NetworkCheckStepState = 'queued' | 'sent' | 'done' | 'not_available';

export type NetworkCheckStep = {
    key: string;
    title: string;
    state: NetworkCheckStepState;
    finding: string | null;
    at: string | null;
};

export type NetworkCheckSession = {
    uuid: string;
    state: 'planning' | 'checking' | 'answering' | 'answered' | 'failed';
    open: boolean;
    badge: { label: string; tone: StatusVariant };
    site: { id: number; name: string };
    hub: string;
    hub_at_site: boolean;
    asked_by: string;
    mine: boolean;
    asked_at: string | null;
    title: string;
    question: string;
    intro: string | null;
    plan: string[];
    planned_at: string | null;
    answer: string | null;
    answered_at: string | null;
    unchecked_numbers: boolean;
    failure: string | null;
    steps: NetworkCheckStep[];
    focus: { id: number; name: string; href: string } | null;
    unifi_href: string | null;
    ticket: { id: number; reference: string; href: string } | null;
    can_ticket: boolean;
    note: string;
};

export type NetworkCheckPanel = {
    site: { id: number; name: string };
    ready: { ok: boolean; reason: string | null; hub: string | null };
    session: NetworkCheckSession | null;
};

export function loadPanel(siteId: number, session?: string | null) {
    return axios.get<NetworkCheckPanel>(`/security-devices/sites/${siteId}/network-checks`, { params: session ? { session } : undefined }).then((response) => response.data);
}

export function askObli(siteId: number, question: string, deviceId: number | null) {
    return axios
        .post<{ session: NetworkCheckSession }>(`/security-devices/sites/${siteId}/network-checks`, { question, device_id: deviceId })
        .then((response) => response.data.session);
}

export function followSession(uuid: string) {
    return axios.get<{ session: NetworkCheckSession }>(`/security-devices/network-checks/${uuid}`).then((response) => response.data.session);
}

export function raiseItJob(uuid: string) {
    return axios.post<{ ticket: { id: number; reference: string; href: string } }>(`/security-devices/network-checks/${uuid}/ticket`).then((response) => response.data.ticket);
}

/** Plain words for a failed call; the server's own words win when it sent some. */
export function networkCheckError(failure: unknown): string {
    if (axios.isAxiosError(failure)) {
        const data = failure.response?.data as { errors?: Record<string, string[]>; message?: string } | undefined;
        const first = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined;
        if (first) return first;
        switch (failure.response?.status) {
            case 403:
                return 'Only IT people who can run scans can ask Obli to check a network.';
            case 404:
                return 'That Site or session isn’t there any more.';
            case 429:
                return 'Too many tries just now. Wait a minute and try again.';
        }
    }
    return 'Oblivion couldn’t reach the server. Check your connection and try again.';
}

/** "**bold**" in Obli's answer → parts; nothing else is read as markup. */
export function boldParts(text: string): { text: string; bold: boolean }[] {
    return text
        .split(/(\*\*[^*]+\*\*)/g)
        .filter((part) => part !== '')
        .map((part) => (part.startsWith('**') && part.endsWith('**') && part.length > 4 ? { text: part.slice(2, -2), bold: true } : { text: part, bold: false }));
}
