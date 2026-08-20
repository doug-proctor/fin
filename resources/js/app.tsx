import { createInertiaApp, router } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

/*
 * Back and forward rebuild the page from the history entry, without asking the
 * server, so a page left before a write comes back showing the old data. Ask
 * for it again on arrival.
 *
 * The refetch is deferred to the navigate event because Inertia restores the
 * history entry asynchronously; by the time navigate fires the restored page
 * is current, so router.reload() targets the right URL. It visits the same
 * URL, which Inertia replaces rather than pushes, so the history stack is left
 * alone and no second navigate event fires.
 */
let restoringFromHistory = false;

window.addEventListener('popstate', () => {
    restoringFromHistory = true;
});

router.on('navigate', () => {
    if (restoringFromHistory) {
        restoringFromHistory = false;
        router.reload();
    }
});

// This will set light / dark mode on load...
initializeTheme();
