import { Link, usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import {
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
} from 'lucide-react';

interface NavItem {
  label: string;
  href: string;
  icon: React.ReactNode;
  matcher?: (url: string) => boolean;
}

export default function GovernanceNav() {
  const page = usePage<any>();
  const { url } = page;
  const canManage = page.props?.auth?.can?.governance?.meetings?.manage;

  const navItems: NavItem[] = [
    {
      label: 'Dashboard',
      href: '/governance/dashboard',
      icon: <LayoutDashboard className="w-5 h-5" />,
    },
    {
      label: 'Meetings',
      href: '/governance/meetings',
      icon: <Calendar className="w-5 h-5" />,
      matcher: (currentUrl) => currentUrl === '/governance/meetings' || currentUrl.startsWith('/governance/meetings?'),
    },
    {
      label: 'Meeting Calendar',
      href: '/governance/meetings/calendar',
      icon: <CalendarDays className="w-5 h-5" />,
      matcher: (currentUrl) => currentUrl.startsWith('/governance/meetings/calendar'),
    },
    ...(canManage
      ? [
          {
            label: 'Admin',
            href: '/governance/admin/board-members',
            icon: <Users className="w-5 h-5" />,
          },
        ]
      : []),
    {
      label: 'Risks',
      href: '/governance/risks',
      icon: <ShieldAlert className="w-5 h-5" />,
    },
    {
      label: 'Compliance',
      href: '/governance/compliance',
      icon: <FileCheck className="w-5 h-5" />,
    },
    {
      label: 'Strategy',
      href: '/governance/strategy',
      icon: <Compass className="w-5 h-5" />,
    },
    {
      label: 'Budgets',
      href: '/governance/budgets',
      icon: <Wallet className="w-5 h-5" />,
    },
    {
      label: 'Performance',
      href: '/governance/performance',
      icon: <Target className="w-5 h-5" />,
    },
    {
      label: 'Resolutions',
      href: '/governance/resolutions',
      icon: <Vote className="w-5 h-5" />,
    },
    {
      label: 'Actions',
      href: '/governance/actions',
      icon: <CheckSquare className="w-5 h-5" />,
    },
    {
      label: 'Policies',
      href: '/governance/policies',
      icon: <BookOpen className="w-5 h-5" />,
    },
    {
      label: 'CEO Reports',
      href: '/governance/ceo-reports',
      icon: <FileText className="w-5 h-5" />,
    },
    {
      label: 'Interests Register',
      href: '/governance/interests',
      icon: <ClipboardList className="w-5 h-5" />,
    },
    {
      label: 'Board Evaluations',
      href: '/governance/evaluations',
      icon: <Star className="w-5 h-5" />,
    },
    {
      label: 'Documents',
      href: '/governance/documents',
      icon: <FolderOpen className="w-5 h-5" />,
    },
    {
      label: 'Clinical Governance',
      href: '/governance/clinical',
      icon: <HeartPulse className="w-5 h-5" />,
    },
    {
      label: 'Te Tiriti',
      href: '/governance/te-tiriti',
      icon: <Landmark className="w-5 h-5" />,
    },
  ];

  return (
    <nav className="space-y-1">
      {navItems.map((item) => {
        const isActive = item.matcher ? item.matcher(url) : url.startsWith(item.href);
        return (
          <Link
            key={item.href}
            href={item.href}
            className={cn(
              'flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition-colors',
              isActive
                ? 'bg-blue-50 text-blue-700'
                : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'
            )}
          >
            {item.icon}
            {item.label}
          </Link>
        );
      })}
    </nav>
  );
}
