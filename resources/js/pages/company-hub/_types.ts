export type HubLink = {
    id: string;
    title: string;
    url: string;
    description?: string;
};
export type HubInterests = {
    version: number;
    topics: string[];
    teams: number[];
    available_topics: string[];
    available_teams: Array<{ id: number; name: string }>;
};
export type HubUpdate = {
    key: string;
    type:
        | 'news'
        | 'resources'
        | 'teams'
        | 'campaigns'
        | 'events'
        | 'shoutouts'
        | 'announcements';
    title: string;
    summary: string;
    owner: string;
    at: string;
    url: string;
    cover_url?: string | null;
    event_id?: string;
    starts_at?: string;
    page_id?: number;
    topics: string[];
    teams: number[];
    required?: boolean;
    unread: boolean;
    following: boolean;
    bookmark?: { subject_type: string; subject_id: number };
};
export type HubBlock = {
    id: string;
    type: string;
    title: string;
    body?: string;
    limit?: number;
    category?: string;
    links?: HubLink[];
    media_id?: number | null;
    alt?: string;
    url?: string;
    question?: string;
    options?: string[];
    closes_at?: string;
    width?: number;
};
export type HubSection = {
    id: string;
    title?: string;
    columns: number;
    layout?: 'equal' | 'wide-left' | 'wide-right';
    tone?: 'plain' | 'soft' | 'brand';
    spacing?: 'compact' | 'comfortable' | 'spacious';
    blocks: HubBlock[];
};
export type HubHeroSettings = {
    style: 'brand' | 'image';
    height: 'compact' | 'comfortable';
    focal_x: number;
    focal_y: number;
    overlay: number;
    action_label: string;
    action_url: string;
    alignment?: 'left' | 'center';
    icon?:
        | 'heart-handshake'
        | 'newspaper'
        | 'sparkles'
        | 'building-2'
        | 'flag'
        | 'door-open';
    show_search?: boolean;
    show_summary?: boolean;
    show_metrics?: boolean;
};
export type HubHeroSummary = Array<{
    label: string;
    value: number;
    url: string;
}>;
export type HubContent = {
    checklist?: Array<{ id: string; title: string }>;
    title: string;
    summary: string;
    category: string;
    topics?: string[];
    team_ids?: number[];
    hero_label: string;
    collection?: string | null;
    resource_type?: string | null;
    cover_id: number | null;
    hero?: HubHeroSettings;
    featured: boolean;
    audience: { type: 'all' | 'sites' | 'departments'; ids: number[] };
    comments_enabled: boolean;
    reactions_enabled: boolean;
    requires_approval: boolean;
    show_in_navigation: boolean;
    navigation_order: number;
    review_on: string | null;
    planned_for?: string | null;
    campaign?: {
        ends_on: string | null;
        steps: Array<{ id: string; title: string; url?: string }>;
    } | null;
    sections: HubSection[];
};
export type HubPageSummary = {
    team_ids?: number[];
    id: number;
    slug: string;
    kind: string;
    title: string;
    summary: string;
    category: string;
    cover_url: string | null;
    featured: boolean;
    published_at: string;
    review_on?: string | null;
    owner?: string;
    resource_key?: string;
    resource_url?: string;
    resource_type?: string | null;
    collection?: string | null;
    show_in_navigation: boolean;
    navigation_order: number;
};
export type HubKudos = {
    team_ids?: number[];
    id: number;
    message: string;
    from: string;
    to: string;
    to_id: number;
    category: string;
    created_at: string;
    can_reply: boolean;
    reactions: Record<string, { count: number; mine: boolean }>;
    replies: Array<{ id: number; name: string; body: string }>;
};
export type HubEvent = {
    id: string;
    title: string;
    start: string;
    end: string;
    allDay: boolean;
    editable?: boolean;
    extendedProps: {
        eventId: number;
        hubContent?: {
            cover: {
                id: number;
                name: string;
                url: string;
                alt: string;
            } | null;
            host: { id: number; name: string } | null;
            agenda: string;
            recap: string;
            gallery: { id: number; name: string; url: string }[];
            story: { title: string; url: string } | null;
            past: boolean;
            editor:
                | import('@/components/hr/calendar/event-hub-fields').EventHubContentInput
                | null;
        } | null;
        location: string | null;
        description: string | null;
        createdBy: string;
        myRsvp: string | null;
        recurring: boolean;
        site: string | null;
        recurrenceParentId: number | null;
        occurrenceDate: string | null;
        category?: string;
        siteId?: number | null;
        departmentId?: number | null;
        rrule?: string | null;
        recurrenceUntil?: string | null;
        seriesStart?: string;
        editVersion: number;
        recurrenceTimezone?: 'UTC' | 'Pacific/Auckland';
        seriesEnd?: string;
        audienceType?: 'org' | 'site' | 'department' | 'team' | 'people' | null;
        audienceRef?: string | null;
        attendeeUserIds?: number[];
        reminders?: Array<{ offset_minutes: number; channel: string }>;
        attachments?: import('@/components/hr/calendar/event-wizard-dialog').EventAttachment[];
        registration?: {
            can_configure?: boolean;
            enabled: boolean;
            capacity: number | null;
            version: number;
            going: number;
            attended?: number;
            waiting: number;
            mine: string | null;
            queue_position?: number | null;
            attendance?: 'attended' | 'no_show' | null;
            date: string;
        };
    };
};
export type HubMilestone = {
    user_id: number;
    user_name: string;
    type: string;
    days_away: number;
    date: string;
    years?: number;
};
export type HubProps = {
    department?: HubDepartment | null;
    knowledge?: HubKnowledge | null;
    heroSummary?: HubHeroSummary;
    announcementId?: number | null;
    searchServices?: Array<{
        id: number | string;
        name: string;
        description: string | null;
        category: string;
        url?: string;
    }>;
    interests?: HubInterests;
    updates?: HubUpdate[];
    sharedNavigation?: import('./_navigation').NavigationItem[];
    recentPages?: number[];
    resourceItems?: HubPageSummary[];
    editorial?: import('./_editorial').EditorialPage[];
    services?: import('./_services').HubServiceData;
    people?: HubPerson[];
    selectedPerson?: HubPerson | null;
    canViewOrg?: boolean;
    ideas?: HubIdea[];
    followedPages?: number[];
    lastSeenAt?: string | null;
    demo?: boolean;
    participation: {
        page_id: number;
        campaign?: Array<{
            id: string;
            title: string;
            url?: string;
            key: string;
            done: boolean;
            count: number;
        }>;
        helpful?: { count: number; mine: boolean };
        polls: Record<
            string,
            {
                key: string;
                total: number;
                mine: number | null;
                counts: number[];
                closed: boolean;
            }
        >;
        reactions: Record<string, { count: number; mine: boolean }>;
        comments: Array<{
            id: number;
            parent_id?: number | null;
            name: string;
            body: string;
            created_at: string;
            can_remove: boolean;
        }>;
    } | null;
    birthdaySettings: Array<{ id: number; name: string; enabled: boolean }>;
    home: HubContent;
    homeId: number | null;
    pages: HubPageSummary[];
    navigation: HubPageSummary[];
    editablePages: Array<{
        id: number;
        title: string;
        kind: string;
        version: number;
        archived: boolean;
        review_on: string | null;
    }>;
    kudos: HubKudos[];
    events: HubEvent[];
    milestones: {
        birthdays: HubMilestone[];
        anniversaries: HubMilestone[];
        new_hires: HubMilestone[];
    };
    announcements: Array<{
        id: number;
        title: string;
        content: string;
        requires_acknowledgement: boolean;
        viewer_acknowledged?: boolean;
        priority: string;
    }>;
    capabilities: Record<string, boolean>;
    employees: Array<{ id: number; name: string }>;
    kudosCategories: Record<string, string>;
    kudosImpacts: Record<string, string>;
    shortcuts: HubLink[];
    notificationPreferences: Record<string, boolean>;
    bookmarks: Array<{ subject_type: string; subject_id: number }>;
    search: string;
    view: string;
    selectedPage: {
        revision_id?: number;
        id: number;
        kind?: string;
        slug: string;
        content: HubContent;
        published_at: string;
        can_edit: boolean;
    } | null;
};

export type HubChecklistProgress = {
    actor_id: number;
    page_id: number;
    revision_id: number;
    steps: Array<{ id: string; title: string; key: string; done: boolean }>;
};
export type HubKnowledgeContact = Pick<
    HubPerson,
    'id' | 'name' | 'position' | 'department' | 'work_email' | 'work_phone'
>;
export type HubKnowledge = HubChecklistProgress & {
    version: number;
    owner: HubKnowledgeContact | null;
    teams: Array<{ id: number; name: string }>;
    contacts: HubKnowledgeContact[];
    related: HubPageSummary[];
    spaces: HubPageSummary[];
};

export type HubDepartment = {
    actor_id: number;
    page_id: number;
    revision_id: number;
    version: number;
    teams: Array<{ id: number; name: string }>;
    owner: HubKnowledgeContact | null;
    updates: HubPageSummary[];
    resources: HubPageSummary[];
    people: HubPerson[];
    can_create_update: boolean;
};

export type HubPerson = {
    staff_record_url?: string | null;
    department_id?: number | null;
    id: number;
    user_id: number;
    name: string;
    position: string | null;
    department: string | null;
    site: string | null;
    work_email: string | null;
    work_phone: string | null;
    first_aider: boolean;
    fire_warden: boolean;
    manager_id?: number | null;
    can_edit_profile?: boolean;
    shared_profile?: {
        about: string;
        help_with: string[];
        version: number;
        updated_at: string | null;
    };
};
export type HubIdea = {
    id: number;
    title: string;
    body: string;
    category: string;
    status: string;
    status_note: string | null;
    version: number;
    author: string;
    created_at: string;
    can_remove: boolean;
    support_count: number;
    supported: boolean;
    comments: Array<{
        id: number;
        body: string;
        author: string;
        created_at: string;
        can_remove: boolean;
    }>;
};
