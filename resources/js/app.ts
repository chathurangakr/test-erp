import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import { initializeBeautyMode } from '@/composables/useBeautyMode';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import KioskLayout from '@/layouts/KioskLayout.vue';
import PrintLayout from '@/layouts/PrintLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';
import { initializePrinting } from '@/lib/printing';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('kiosk/'):
                return KioskLayout;
            case name.endsWith('/Print'):
                return PrintLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// Beauty mode, when this device has it switched on...
initializeBeautyMode();

// This will listen for flash toast data from the server...
initializeFlashToast();

// Printouts are always printed in the light theme...
initializePrinting();
