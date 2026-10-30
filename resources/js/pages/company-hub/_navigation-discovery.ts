import {
    Bell,
    Bookmark,
    CalendarDays,
    FileText,
    FolderOpen,
    House,
    Lightbulb,
    Link2,
    Megaphone,
    PanelsTopLeft,
    Settings2,
} from 'lucide-react';
import type { ComponentType } from 'react';
import { heroIcons } from './_hero-icons';
import type { NavigationItem } from './_navigation';
import type { HubUpdate } from './_types';

const destinations = {
    home: [House, 'Your shared company home'],
    news: [heroIcons.newspaper, 'Stories from across our company'],
    shoutouts: [heroIcons['heart-handshake'], 'Recognise someone’s good work'],
    events: [CalendarDays, 'What’s coming up, and who’s going'],
    resources: [heroIcons['book-open'], 'Guides, policies and useful answers'],
    guides: [heroIcons['book-open'], 'Company guides and supporting documents'],
    pages: [PanelsTopLeft, 'Explore shared company pages'],
    saved: [Bookmark, 'Your stories, documents and events for later'],
    people: [heroIcons.users, 'Find a colleague or expertise'],
    teams: [heroIcons['building-2'], 'Meet the teams behind our work'],
    campaigns: [heroIcons.flag, 'Shared priorities and ways to take part'],
    ideas: [Lightbulb, 'Share an idea and follow its progress'],
    following: [
        heroIcons.heart,
        'Updates from the topics and teams you follow',
    ],
    updates: [Bell, 'Company notices and your latest updates'],
    services: [Link2, 'Everyday tools and support'],
    publishing: [Settings2, 'Plan, review and manage company content'],
    announcements: [Megaphone, 'Company notices and required reading'],
} as const;

export function navigationDiscovery(
    item: Pick<NavigationItem, 'type' | 'target'>,
    updates: HubUpdate[] = [],
) {
    const [icon, description]: readonly [
        ComponentType<{ className?: string }>,
        string,
    ] =
        item.type === 'group'
            ? [FolderOpen, 'Explore this collection of company links']
            : item.type === 'link'
              ? [Link2, 'Open this useful link']
              : item.type === 'view' && item.target in destinations
                ? destinations[item.target as keyof typeof destinations]
                : [FileText, 'Open this company page'];
    const unread = updates.some(
        (update) =>
            update.unread &&
            (item.type === 'page'
                ? String(update.page_id) === item.target
                : item.type === 'view' &&
                  (item.target === 'updates' ||
                      (item.target === 'following' && update.following) ||
                      update.type === item.target)),
    );
    return { icon, description, unread };
}
