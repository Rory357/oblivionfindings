/**
 * Right-click / ⋯ context menu for announcement cards, rows, recipients and the
 * tab strip. A thin re-export of the proven Leave context-menu mechanics (cursor
 * positioning, viewport-nudge, Esc/scroll close, arrow-key roving) so every HR
 * surface shares one menu behaviour. `open(items)` returns an event handler for
 * both `onContextMenu` and a ⋯ button `onClick`; render `element` once.
 */
export {
    useLeaveContextMenu as useAnnouncementContextMenu,
    type LeaveCtxItem as AnnouncementCtxItem,
} from '@/components/hr/leave-context-menu';
