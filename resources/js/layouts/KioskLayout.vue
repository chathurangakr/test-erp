<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Home, Languages, LayoutGrid, LogOut } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useKioskText } from '@/kiosk/text';
import { dashboard, logout } from '@/routes';
import { home, language } from '@/routes/kiosk';

/**
 * The frame around every simple-mode screen: no sidebar, no menus, just big buttons.
 *
 * On shared tablets it signs the user out after `kiosk.idle_minutes` without a touch, with a
 * 30-second warning first.
 */
const page = usePage();
const { t, locale } = useKioskText();

const kiosk = computed(() => (page.props.kiosk ?? { idle_minutes: 0 }) as { idle_minutes: number });
const userName = computed(() => page.props.auth?.user?.name ?? '');

const WARNING_SECONDS = 30;
const warning = ref(false);
let idleTimer: ReturnType<typeof setTimeout> | undefined;
let signOutTimer: ReturnType<typeof setTimeout> | undefined;

function signOut(): void {
    router.flushAll();
    router.post(logout().url);
}

function resetIdle(): void {
    warning.value = false;
    clearTimeout(idleTimer);
    clearTimeout(signOutTimer);

    const minutes = kiosk.value.idle_minutes;

    if (!minutes || minutes <= 0) {
        return;
    }

    idleTimer = setTimeout(() => {
        warning.value = true;
        signOutTimer = setTimeout(signOut, WARNING_SECONDS * 1000);
    }, Math.max(minutes * 60 - WARNING_SECONDS, 10) * 1000);
}

const activity = ['pointerdown', 'keydown', 'touchstart'] as const;

onMounted(() => {
    activity.forEach((event) => window.addEventListener(event, resetIdle, { passive: true }));
    resetIdle();
});

onBeforeUnmount(() => {
    activity.forEach((event) => window.removeEventListener(event, resetIdle));
    clearTimeout(idleTimer);
    clearTimeout(signOutTimer);
});

function switchLanguage(): void {
    router.post(language().url, { locale: locale.value === 'si' ? 'en' : 'si' }, { preserveScroll: true });
}
</script>

<template>
    <div class="bg-muted/40 flex min-h-svh flex-col text-lg">
        <Head :title="t('app.title')" />

        <header class="bg-background border-sidebar-border/70 sticky top-0 z-10 flex items-center gap-2 border-b px-3 py-2 sm:px-6">
            <Link :href="home()" class="flex min-h-12 items-center gap-2 rounded-xl px-3 font-semibold">
                <Home class="size-6" />
                <span class="hidden sm:inline">{{ t('app.home') }}</span>
            </Link>

            <span class="text-muted-foreground ml-1 truncate text-base">{{ userName }}</span>

            <div class="ml-auto flex items-center gap-2">
                <button
                    type="button"
                    class="border-sidebar-border/70 flex min-h-12 items-center gap-2 rounded-xl border px-4 font-medium"
                    @click="switchLanguage"
                >
                    <Languages class="size-5" />
                    {{ t('app.language') }}
                </button>
                <Link
                    :href="dashboard()"
                    class="text-muted-foreground hidden min-h-12 items-center gap-2 rounded-xl px-3 text-base md:flex"
                >
                    <LayoutGrid class="size-5" />
                    {{ t('app.fullErp') }}
                </Link>
                <button
                    type="button"
                    class="bg-foreground text-background flex min-h-12 items-center gap-2 rounded-xl px-4 font-medium"
                    @click="signOut"
                >
                    <LogOut class="size-5" />
                    <span class="hidden sm:inline">{{ t('app.signOut') }}</span>
                </button>
            </div>
        </header>

        <main class="mx-auto flex w-full max-w-4xl flex-1 flex-col p-3 sm:p-6">
            <slot />
        </main>

        <div
            v-if="warning"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-6"
            role="alertdialog"
        >
            <div class="bg-background flex max-w-md flex-col gap-6 rounded-2xl p-8 text-center">
                <p class="text-xl font-medium">{{ t('app.idle') }}</p>
                <button type="button" class="min-h-16 rounded-xl bg-green-600 px-6 text-xl font-semibold text-white" @click="resetIdle">
                    {{ t('app.stayHere') }}
                </button>
            </div>
        </div>
    </div>
</template>
