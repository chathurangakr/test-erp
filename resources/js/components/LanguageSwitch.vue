<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Languages } from '@lucide/vue';
import { useT } from '@/lib/i18n';
import { update } from '@/routes/locale';

/**
 * One tap between English and Sinhala. The choice is saved on the person (and in a cookie on
 * the sign-in screens), so it follows them to every device.
 */
const { locale } = useT();

function toggle(): void {
    router.post(update().url, { locale: locale.value === 'si' ? 'en' : 'si' }, { preserveScroll: true });
}
</script>

<template>
    <button
        type="button"
        class="border-sidebar-border/70 hover:bg-muted inline-flex h-9 items-center gap-2 rounded-md border px-3 text-sm font-medium"
        data-test="language-switch"
        @click="toggle"
    >
        <Languages class="size-4" />
        <!-- Each language is always named in itself, so either can be found. -->
        {{ locale === 'si' ? 'English' : 'සිංහල' }}
    </button>
</template>
