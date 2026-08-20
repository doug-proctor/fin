import { Link } from '@inertiajs/react';
import {
    Apple,
    LayoutGrid,
    Receipt,
    RefreshCw,
    SlidersHorizontal,
    Tags,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as categories } from '@/routes/categories';
import { index as categoryRules } from '@/routes/category-rules';
import { index as syncReports } from '@/routes/sync-reports';
import { index as transactions } from '@/routes/transactions';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Transactions',
        href: transactions(),
        icon: Receipt,
    },
    {
        title: 'Categories',
        href: categories(),
        icon: Tags,
    },
    {
        title: 'Rules',
        href: categoryRules(),
        icon: SlidersHorizontal,
    },
    {
        title: 'Syncs',
        href: syncReports(),
        icon: RefreshCw,
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'In app purchases',
        href: 'https://reportaproblem.apple.com/',
        icon: Apple,
    },
];

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch="click">
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
