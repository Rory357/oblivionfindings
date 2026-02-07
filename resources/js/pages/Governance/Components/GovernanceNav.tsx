import { Link, usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import {
  LayoutDashboard,
  Calendar,
  ShieldAlert,
  FileCheck,
  Target,
  Vote,
  CheckSquare,
  Wallet,
  Compass,
  Users,
} from 'lucide-react';

interface NavItem {
  label: string;
  href: string;
  icon: React.ReactNode;
  active?: boolean;
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
  ];

  return (
    <nav className="space-y-1">
      {navItems.map((item) => {
        const isActive = url.startsWith(item.href);
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
