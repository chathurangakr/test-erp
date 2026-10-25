<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { canStepBack } from '@/lib/backHistory';
import { useT } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';

/**
 * "Back" in the top bar of every page that sits below a section's list (a user, an invoice, a
 * form). It returns to the page you came from — the list keeps its filters and page — or, when
 * the page was opened directly, to the nearest breadcrumb above it. Hidden on the lists
 * themselves, where the breadcrumb is the page.
 */
const props = defineProps<{ breadcrumbs: BreadcrumbItem[] }>();

const { t } = useT();
const page = usePage();

const pathOf = (url: string): string => new URL(url, window.location.origin).pathname.replace(/\/$/, '');

/** The nearest breadcrumb that is not this page. */
const parent = computed<string | null>(() => {
    const here = pathOf(page.url);

    for (const item of [...props.breadcrumbs].reverse()) {
        const path = pathOf(typeof item.href === 'string' ? item.href : item.href.url);

        if (path !== here) {
            return path;
        }
    }

    return null;
});

function back(): void {
    if (canStepBack()) {
        window.history.back();
    } else if (parent.value) {
        router.visit(parent.value);
    }
}
</script>

<template>
    <Button v-if="parent" variant="ghost" size="sm" class="h-8 gap-1 px-2" :title="t('Back')" @click="back">
        <ArrowLeft class="size-4" /><span class="hidden sm:inline">{{ t('Back') }}</span>
    </Button>
</template>
