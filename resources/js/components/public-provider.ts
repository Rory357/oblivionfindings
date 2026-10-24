export type PublicProvider = {
    name: string;
    phone: string;
    response_hours: string;
    intake_enabled: boolean;
    notice: string;
    notice_version: string;
    services: { id: string; label: string }[];
    /** Contact us page details (Lane CU, Settings › Referrals › Intake).
     *  Blank when not set; the website hides a row that isn't set. */
    email?: string | null;
    address?: string | null;
    opening_hours?: string | null;
};

export function enquiryUrl(purpose = 'support', service?: string) {
    const query = new URLSearchParams({ purpose });
    if (service) query.set('service', service);
    return `/referrals/new?${query.toString()}`;
}

export function phoneUrl(phone: string) {
    return `tel:${phone.replace(/[^\d+]/g, '')}`;
}
