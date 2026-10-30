export type HubDraftRecovery = {
    actor_id: number;
    page_id: number;
    version: number;
    has_saved_draft: boolean;
    can_discard: boolean;
    blocked_reason:
        | 'review'
        | 'schedule'
        | 'no_saved_draft'
        | 'unavailable'
        | 'published_audience'
        | 'layout'
        | null;
    recovery: {
        id: number;
        title: string;
        discarded_at: string;
        can_restore: boolean;
    } | null;
};
export type HubStarterTemplate = {
    key: string;
    label: string;
    kind: 'page' | 'news' | 'department' | 'knowledge' | 'campaign';
    icon: string;
    purpose: string;
    block_types: string[];
};
export type HubStarterLayout = Pick<HubStarterTemplate, 'key' | 'label'> &
    Partial<Omit<HubStarterTemplate, 'key' | 'label'>> & {
        sections: HubSection[];
    };

export type HubLink = {
    id: string;
    title: string;
    url: string;
    description?: string;
};
export type HubInterests = {
    actor_id: number;
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
    rich_text?: import('./_rich-text').HubRichText | null;
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
    hidden?: boolean;
    min_height?: number;
    mode?: 'cards' | 'finder';
    team_ids?: number[];
    tone?: 'plain' | 'soft';
    layout?: 'mosaic' | 'equal' | 'single' | 'cards' | 'list';
    story_id?: number | null;
    action_label?: string;
};
export type HubSection = {
    id: string;
    hidden?: boolean;
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
        | 'door-open'
        | 'users'
        | 'heart'
        | 'book-open';
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
    collection_id?: number | null;
    resource_type?: string | null;
    cover_id: number | null;
    hero?: HubHeroSettings;
    featured: boolean;
    audience: { type: 'all' | 'sites' | 'departments'; ids: number[] };
    show_in_news?: boolean;
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
        milestones?: Array<{
            id: string;
            title: string;
            status: 'done' | 'active' | 'next';
        }>;
        story_ids?: number[];
        resource_ids?: number[];
        event_keys?: string[];
    } | null;
    sections: HubSection[];
};
export type HubPageSummary = {
    campaign_progress?: {
        total: number;
        done: number;
        ends_on: string | null;
    } | null;
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
    author?: string;
    topics?: string[];
    team_names?: string[];
    read_minutes?: number;
    comments_enabled?: boolean;
    comment_count?: number;
    bookmarked?: boolean;
    review_overdue?: boolean;
    show_in_news?: boolean;
    resource_key?: string;
    document_id?: number;
    document_file_version?: number;
    media_id?: number;
    media_version?: number;
    filename?: string;
    mime?: string;
    size?: number;
    page_title?: string;
    block_id?: string;
    reader_url?: string;
    preview_url?: string | null;
    download_url?: string;
    bookmark_subject_type?: string;
    bookmark_subject_id?: number;
    resource_url?: string;
    resource_type?: string | null;
    collection?: string | null;
    collection_id?: number | null;
    show_in_navigation: boolean;
    navigation_order: number;
};
export type HubKudos = {
    author_id?: number;
    author_url?: string | null;
    avatar_url?: string | null;
    recipient_avatar_url?: string | null;
    mentions?: HubRecognitionMention[];
    images?: HubRecognitionImage[];
    social_available?: boolean;
    team_ids?: number[];
    recipient_type?: 'person' | 'team';
    to_department_id?: number | null;
    team_name?: string | null;
    recipient_url?: string | null;
    id: number;
    message: string;
    from: string;
    to: string;
    to_id: number | null;
    category: string | null;
    category_key?: string | null;
    impact?: string;
    impact_label?: string;
    created_at: string;
    can_reply: boolean;
    reactions: Record<string, { count: number; mine: boolean }>;
    replies: Array<{
        id: number;
        name: string;
        body: string | null;
        user_id?: number;
        parent_id?: number | null;
        edited_at?: string | null;
        removed?: boolean;
        hidden?: boolean;
        can_edit?: boolean;
        can_moderate?: boolean;
        moderation_reason?: string | null;
        author_url?: string | null;
        avatar_url?: string | null;
        mentions?: HubRecognitionMention[];
        images?: HubRecognitionImage[];
        created_at?: string;
        version?: number;
        can_remove?: boolean;
    }>;
};
export type HubRecognitionMention = {
    id: number;
    name: string;
    profile_url?: string | null;
};
export type HubRecognitionImage = {
    id: number;
    url: string;
    name?: string;
    mime?: string;
    size?: number;
};
export type HubBrowse<T> = {
    data: T[];
    pagination: {
        page: number;
        per_page: number;
        has_more: boolean;
        next_page: number | null;
    };
    filters: Record<string, string | number | null>;
};
export type HubRecognition = {
    actor_id: number;
    version: number;
    can_feature: boolean;
    spotlight: HubKudos | null;
    selection_unavailable: boolean;
};
export type HubEventPhoto = {
    id: number;
    name: string;
    url: string;
    title?: string;
    alt?: string;
    caption?: string;
    album?: string;
    shared_by?: { name: string; profile_url: string | null } | null;
    shared_at?: string | null;
};
export type HubEvent = {
    id: string;
    title: string;
    start: string;
    end: string;
    allDay: boolean;
    editable?: boolean;
    extendedProps: {
        savedSubjectId?: number;
        eventId: number;
        hubContent?: {
            cover: {
                id: number;
                name: string;
                url: string;
                alt: string;
            } | null;
            host: {
                id: number;
                name: string;
                profile_url?: string | null;
            } | null;
            agenda: string;
            recap: string;
            recap_title?: string;
            gallery: HubEventPhoto[];
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
        cancellation?: import('./_event-cancellation').EventCancellationState;
        recurrenceTimezone?: 'UTC' | 'Pacific/Auckland';
        seriesEnd?: string;
        audienceType?: 'org' | 'site' | 'department' | 'team' | 'people' | null;
        audienceRef?: string | null;
        attendeeUserIds?: number[];
        reminders?: Array<{ offset_minutes: number; channel: string }>;
        attachments?: import('@/components/hr/calendar/event-wizard-dialog').EventAttachment[];
        registration?: {
            cancelled?: boolean;
            can_respond?: boolean;
            can_decline?: boolean;
            cutoff_supported?: boolean;
            closes_before_minutes?: number | null;
            closes_at?: string | null;
            personal_reminder?: {
                supported: boolean;
                enabled: boolean;
                version: number;
                can_enable: boolean;
                can_disable: boolean;
                due_at: string | null;
                inapp_allowed: boolean;
            };
            place_reserved?: boolean;
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
export type HubCollection = {
    id: number;
    name: string;
    version: number;
    count: number;
    can_rename: boolean;
};
export type HubCollectionContext = {
    ready: boolean;
    actor_id: number;
    can_create: boolean;
    items: HubCollection[];
    options: Array<Pick<HubCollection, 'id' | 'name' | 'version'>>;
    legacy_groups: Array<{
        key: string;
        name: string;
        count: number;
        can_adopt: boolean;
        adoption_key: string | null;
    }>;
};
export type HubProps = {
    document_text_incomplete?: boolean;
    resourceCollections?: HubCollectionContext;
    starterTemplates?: HubStarterTemplate[];
    selectedEvent?: HubEvent | null;
    featuredMedia?: Array<{ id: number; url: string; alt: string }>;
    recognition?: HubRecognition;
    recognitionTeams?: Array<{
        id: number;
        name: string;
        member_count: number;
    }>;
    blockData?: Record<
        string,
        {
            people?: HubPerson[];
            teams?: HubPageSummary[];
            services?: NonNullable<HubProps['searchServices']>;
            featured?: {
                lead: HubPageSummary | null;
                supporting: HubPageSummary[];
            };
            news?: HubPageSummary[];
            resources?: HubPageSummary[];
            campaigns?: HubPageSummary[];
        }
    >;
    campaign?: HubCampaign | null;
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
        icon?: string;
    }>;
    interests?: HubInterests;
    updates?: HubUpdate[];
    sharedNavigation?: import('./_navigation').NavigationItem[];
    recentPages?: number[];
    recentResources?: string[];
    resourceItems?: HubPageSummary[];
    editorial?: import('./_editorial').EditorialPage[];
    services?: import('./_services').HubServiceData;
    people?: HubPerson[];
    selectedPerson?: HubPerson | null;
    canViewOrg?: boolean;
    ideas?: HubIdea[];
    ideaBrowse?: HubBrowse<HubIdea> | null;
    selectedIdea?: HubIdea | null;
    ideaId?: number | null;
    ideaUnavailable?: boolean;
    ideaProgressReady?: boolean;
    ideaActorId?: number | null;
    ideaTeams?: Array<{ id: number; name: string }>;
    ideaOwners?: Array<Omit<HubIdeaOwner, 'url'>>;
    followedPages?: number[];
    lastSeenAt?: string | null;
    demo?: boolean;
    showcaseEventRosters?: Record<string, import('./_event-attendance').Roster>;
    resourceReports?:
        | import('./_resource-reports').ResourceReportContext
        | null;
    participation: {
        page_id: number;
        actor_id?: number;
        revision_id?: number;
        can_moderate?: boolean;
        hidden_count?: number;
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
                total: number | null;
                mine: number | null;
                counts: number[] | null;
                closed: boolean;
                closes_at?: string | null;
                timezone?: string;
                version?: number;
                results_visible?: boolean;
                can_manage?: boolean;
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
            can_hide?: boolean;
            version?: number;
            can_appreciate?: boolean;
            appreciation?: { count: number; mine: boolean };
        }>;
    } | null;
    birthdaySettings: Array<{
        id: number;
        name: string;
        enabled: boolean;
        version?: number | null;
    }>;
    birthdayPreferenceContext?: { ready: boolean; actor_id: number };
    home: HubContent;
    homeId: number | null;
    pages: HubPageSummary[];
    navigation: HubPageSummary[];
    discardedDrafts?: Array<{
        id: number;
        title: string;
        draft_recovery: HubDraftRecovery;
    }>;
    editablePages: Array<{
        id: number;
        title: string;
        kind: string;
        version: number;
        archived: boolean;
        review_on: string | null;
        has_saved_draft?: boolean;
    }>;
    kudos: HubKudos[];
    recognitionBrowse?: HubBrowse<HubKudos> | null;
    selectedRecognition?: HubKudos | null;
    recognitionId?: number | null;
    recognitionUnavailable?: boolean;
    events: HubEvent[];
    savedEvents?: HubEvent[];
    milestones: {
        birthdays: HubMilestone[];
        anniversaries: HubMilestone[];
        new_hires: HubMilestone[];
    };
    readingAttention?: {
        pending_count: number;
        first: { id: number; title: string } | null;
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
    employees: Array<{
        id: number;
        name: string;
        position?: string | null;
        site?: string | null;
        department?: string | null;
        department_id?: number | null;
    }>;
    kudosCategories: Record<string, string>;
    kudosImpacts: Record<string, string>;
    shortcuts: HubLink[];
    notificationPreferences: Record<string, boolean>;
    notificationPreferenceContext?: Omit<HubNotificationState, 'preferences'>;
    bookmarks: Array<{
        subject_type: string;
        subject_id: number;
        version?: string | null;
    }>;
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

export type HubNotificationChoices = Record<
    'recognition' | 'replies' | 'events' | 'news' | 'reviews',
    boolean
>;
export type HubNotificationState = {
    ready: boolean;
    actor_id: number;
    version: number | null;
    preferences: HubNotificationChoices;
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
    review_overdue?: boolean;
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

export type HubCampaign = {
    total: number;
    done: number;
    ends_on: string | null;
    milestones: NonNullable<HubContent['campaign']>['milestones'];
    stories: HubPageSummary[];
    resources: HubPageSummary[];
    events: HubEvent[];
    owner: HubKnowledgeContact | null;
};

export type HubPerson = {
    avatar_url?: string | null;
    can_recognise?: boolean;
    recognition_user_id?: number | null;
    chat_url?: string | null;
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
export type HubIdeaOwner = {
    id: number;
    profile_id: number;
    name: string;
    position: string | null;
    url: string;
};
export type HubIdeaUpdate = {
    id: number;
    status: string;
    note: string;
    created_at: string | null;
    author: { id: number; name: string } | null;
    owner: HubIdeaOwner | null;
    team: { id: number; name: string } | null;
    legacy: boolean;
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
    team?: { id: number; name: string } | null;
    owner?: HubIdeaOwner | null;
    updates?: HubIdeaUpdate[];
    can_update?: boolean;
    comments: Array<{
        id: number;
        body: string;
        author: string;
        created_at: string;
        can_remove: boolean;
    }>;
};
