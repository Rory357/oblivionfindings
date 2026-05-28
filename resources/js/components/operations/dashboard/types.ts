export type AttentionTone = 'critical' | 'warning' | 'info' | 'success';

export type HoverRow = {
    time: string;
    site: string;
    detail: string;
    tag?: { text: string; cls: 'critical' | 'warning' | 'success' | 'info' } | null;
};

export type HoverPopoverContent = {
    icon: string;
    tone: AttentionTone;
    title: string;
    sub: string;
    rows: HoverRow[];
    cta: string;
    href: string;
};

export type AttentionItem = {
    count: number;
    overdue?: number;
    urgent?: number;
    context: string;
    tag: string;
    tag_tone: AttentionTone;
    tone: AttentionTone;
    popover: HoverPopoverContent;
};

export type AttentionPayload = {
    unassigned: AttentionItem;
    timesheets: AttentionItem;
    conflicts: AttentionItem;
    incidents: AttentionItem;
};

export type TimelineBar = {
    left: number;
    width: number;
    label: string;
    type: 'overnight' | 'day' | 'evening' | 'community' | 'open';
    unassigned: boolean;
    time_label: string;
};

export type TimelineRow = {
    key: string;
    label: string;
    sublabel: string;
    icon?: string;
    avatar?: string | null;
    is_open?: boolean;
    type?: string;
    href?: string;
    bars: TimelineBar[];
};

export type TimelineData = {
    sites: TimelineRow[];
    staff: TimelineRow[];
    shift_types: TimelineRow[];
    now_pct: number;
    now_label: string;
};

export type TopSite = {
    id: number;
    slug: string;
    name: string;
    region?: string | null;
    city?: string | null;
    client_count: number;
    hours: number;
    pct: number;
};

export type ShiftsPerDay = {
    date: string;
    date_short: string;
    date_num: number;
    iso: string;
    count: number;
    scheduled: number;
    delivered: number | null;
    target: number;
    staff: number;
    is_today: boolean;
    is_forecast: boolean;
};

export type Week = {
    number: number;
    start: string;
    end: string;
    start_label: string;
    end_label: string;
    prev: string;
    prev_number: number;
    prev_label: string;
    next: string;
    next_number: number;
    next_label: string;
};

export type Hero = {
    coverage_pct: number;
    shifts_today: number;
    staff_on_shift: number;
    unassigned_open_24h: number;
    on_leave: number;
    sites_count: number;
    regions_count: number;
    rostered_today: number;
};

export type ActivityItem = {
    id: string | number;
    type: string;
    status: string;
    client?: string | null;
    staff?: string | null;
    action?: string | null;
    title?: string | null;
    severity?: string | null;
    incident_type?: string | null;
    starts_at?: string;
    work_date?: string;
    updated_at?: string;
};

export type SiteOption = {
    id: number;
    name: string;
    description?: string | null;
};
