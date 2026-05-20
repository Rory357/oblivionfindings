import { Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { cn } from '@/lib/utils';
import type { SharedData } from '@/types';
import {
  ChevronDown,
  LayoutDashboard,
  Calendar,
  CalendarDays,
  ShieldAlert,
  FileCheck,
  Target,
  Vote,
  CheckSquare,
  Wallet,
  Compass,
  Users,
  BookOpen,
  FileText,
  ClipboardList,
  Star,
  FolderOpen,
  HeartPulse,
  Landmark,
  HandCoins,
  Receipt,
  Building2,
  Wallet2,
  Library,
  ListChecks,
  History,
  Settings as SettingsIcon,
  AlertTriangle,
} from 'lucide-react';

interface NavItem {
  label: string;
  href: string;
  icon: React.ReactNode;
  matcher?: (url: string) => boolean;
  permission?: string;
}

interface NavGroup {
  key: string;
  label: string;
  icon: React.ReactNode;
  items: NavItem[];
}

const STORAGE_KEY = 'governance.nav.expanded';

export default function GovernanceNav() {
  const page = usePage<SharedData>();
  const url: string = page.url;
  const can = page.props?.auth?.can?.governance ?? {};

  const groups: NavGroup[] = [
    {
      key: 'overview',
      label: 'Overview',
      icon: <LayoutDashboard className="h-4 w-4" />,
      items: [
        {
          label: 'Dashboard',
          href: '/governance/dashboard',
          icon: <LayoutDashboard className="h-5 w-5" />,
        },
      ],
    },
    {
      key: 'board',
      label: 'Board & Meetings',
      icon: <Landmark className="h-4 w-4" />,
      items: [
        {
          label: 'Meetings',
          href: '/governance/meetings',
          icon: <Calendar className="h-5 w-5" />,
          matcher: (u) => u === '/governance/meetings' || u.startsWith('/governance/meetings?') || u.match(/^\/governance\/meetings\/(?!calendar)/i) !== null,
        },
        {
          label: 'Meeting Calendar',
          href: '/governance/meetings/calendar',
          icon: <CalendarDays className="h-5 w-5" />,
          matcher: (u) => u.startsWith('/governance/meetings/calendar'),
        },
        {
          label: 'Resolutions & Voting',
          href: '/governance/resolutions',
          icon: <Vote className="h-5 w-5" />,
        },
        {
          label: 'Action Items',
          href: '/governance/actions',
          icon: <CheckSquare className="h-5 w-5" />,
        },
        {
          label: 'Board Admin',
          href: '/governance/admin/board-members',
          icon: <Users className="h-5 w-5" />,
          permission: 'meetings.manage',
        },
      ],
    },
    {
      key: 'risk',
      label: 'Risk & Compliance',
      icon: <ShieldAlert className="h-4 w-4" />,
      items: [
        {
          label: 'Risk Register',
          href: '/governance/risks',
          icon: <ShieldAlert className="h-5 w-5" />,
        },
        {
          label: 'Compliance Register',
          href: '/governance/compliance',
          icon: <FileCheck className="h-5 w-5" />,
        },
        {
          label: 'Te Tiriti Obligations',
          href: '/governance/te-tiriti',
          icon: <Landmark className="h-5 w-5" />,
        },
      ],
    },
    {
      key: 'policies',
      label: 'Policies & Evidence',
      icon: <BookOpen className="h-4 w-4" />,
      items: [
        {
          label: 'Policies',
          href: '/governance/policies',
          icon: <BookOpen className="h-5 w-5" />,
        },
        {
          label: 'Governance Documents',
          href: '/governance/documents',
          icon: <FolderOpen className="h-5 w-5" />,
        },
        {
          label: 'Audit Log',
          href: '/governance/audit-log',
          icon: <History className="h-5 w-5" />,
          permission: 'audit.view',
        },
      ],
    },
    {
      key: 'strategy',
      label: 'Strategy & Performance',
      icon: <Compass className="h-4 w-4" />,
      items: [
        {
          label: 'Strategic Plan',
          href: '/governance/strategy',
          icon: <Compass className="h-5 w-5" />,
        },
        {
          label: 'CEO Reports',
          href: '/governance/ceo-reports',
          icon: <FileText className="h-5 w-5" />,
        },
        {
          label: 'Performance Reviews',
          href: '/governance/performance',
          icon: <Target className="h-5 w-5" />,
        },
        {
          label: 'Board Interests',
          href: '/governance/interests',
          icon: <ClipboardList className="h-5 w-5" />,
        },
        {
          label: 'Board Evaluations',
          href: '/governance/evaluations',
          icon: <Star className="h-5 w-5" />,
        },
        {
          label: 'Clinical Governance',
          href: '/governance/clinical',
          icon: <HeartPulse className="h-5 w-5" />,
        },
      ],
    },
    {
      key: 'financial',
      label: 'Financial Governance',
      icon: <Wallet className="h-4 w-4" />,
      items: [
        {
          label: 'Budgets',
          href: '/governance/budgets',
          icon: <Wallet className="h-5 w-5" />,
        },
        {
          label: 'Spend Approvals',
          href: '/governance/spend-approvals',
          icon: <HandCoins className="h-5 w-5" />,
          permission: 'spend.view',
        },
      ],
    },
    {
      key: 'settings',
      label: 'Settings',
      icon: <SettingsIcon className="h-4 w-4" />,
      items: [
        {
          label: 'Governance Settings',
          href: '/governance/settings',
          icon: <SettingsIcon className="h-5 w-5" />,
          permission: 'settings.view',
        },
      ],
    },
  ];

  // Filter items by permission (visible by default; hidden only if explicit perm is false).
  const visibleGroups = groups
    .map((g) => ({
      ...g,
      items: g.items.filter((item) => {
        if (!item.permission) return true;
        const [section, action] = item.permission.split('.');
        const sec = can?.[section];
        if (sec === undefined || sec === null) return true; // can map missing → show by default
        if (typeof sec === 'boolean') return sec;
        if (action && typeof sec === 'object') return Boolean(sec[action]);
        return true;
      }),
    }))
    .filter((g) => g.items.length > 0);

  const activeGroupKey =
    visibleGroups.find((g) => g.items.some((it) => (it.matcher ? it.matcher(url) : url.startsWith(it.href))))?.key ??
    'overview';

  const [expanded, setExpanded] = useState<Record<string, boolean>>(() => {
    if (typeof window === 'undefined') return { [activeGroupKey]: true };
    try {
      const stored = window.localStorage.getItem(STORAGE_KEY);
      if (stored) {
        const parsed = JSON.parse(stored);
        return { ...parsed, [activeGroupKey]: true };
      }
    } catch {
      /* ignore */
    }
    return { [activeGroupKey]: true };
  });

  useEffect(() => {
    setExpanded((prev) => ({ ...prev, [activeGroupKey]: true }));
  }, [activeGroupKey]);

  useEffect(() => {
    if (typeof window === 'undefined') return;
    try {
      window.localStorage.setItem(STORAGE_KEY, JSON.stringify(expanded));
    } catch {
      /* ignore */
    }
  }, [expanded]);

  const toggle = (key: string) =>
    setExpanded((prev) => ({ ...prev, [key]: !prev[key] }));

  return (
    <nav className="space-y-3" aria-label="Governance navigation">
      {visibleGroups.map((group) => {
        const isOpen = !!expanded[group.key];
        const isActiveGroup = group.key === activeGroupKey;

        return (
          <div key={group.key} className="space-y-1">
            <button
              type="button"
              aria-expanded={isOpen}
              aria-controls={`gov-nav-group-${group.key}`}
              onClick={() => toggle(group.key)}
              className={cn(
                'group flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-xs font-semibold uppercase tracking-wider transition-colors',
                isActiveGroup ? 'text-primary' : 'text-muted-foreground hover:text-foreground'
              )}
            >
              <span className="text-current">{group.icon}</span>
              <span>{group.label}</span>
              <ChevronDown
                className={cn(
                  'ml-auto h-4 w-4 transition-transform',
                  isOpen ? 'rotate-0' : '-rotate-90'
                )}
              />
            </button>

            {isOpen && (
              <ul id={`gov-nav-group-${group.key}`} className="space-y-0.5">
                {group.items.map((item) => {
                  const isActive = item.matcher ? item.matcher(url) : url === item.href || url.startsWith(item.href + '/') || url.startsWith(item.href + '?');
                  return (
                    <li key={item.href}>
                      <Link
                        href={item.href}
                        className={cn(
                          'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                          isActive
                            ? 'bg-status-info-bg text-status-info'
                            : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                        )}
                      >
                        {item.icon}
                        {item.label}
                      </Link>
                    </li>
                  );
                })}
              </ul>
            )}
          </div>
        );
      })}
    </nav>
  );
}
