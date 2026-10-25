<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useT } from '@/lib/i18n';
import type { Paginated } from '@/types';

/** Under every list: how many records match, which are shown, and the page links. */
defineProps<{ page: Paginated<unknown> }>();

const { t } = useT();
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
        <p class="text-muted-foreground">
            {{ page.total === 0 ? t('No records match.') : t('Showing :from–:to of :total', { from: page.from ?? 0, to: page.to ?? 0, total: page.total }) }}
        </p>
        <div v-if="page.last_page > 1" class="flex flex-wrap gap-1">
            <template v-for="link in page.links" :key="link.label">
                <Link v-if="link.url" :href="link.url" class="rounded-md border px-3 py-1" :class="link.active ? 'bg-muted font-medium' : ''" preserve-state preserve-scroll>
                    <span v-html="link.label" />
                </Link>
                <span v-else class="text-muted-foreground rounded-md border px-3 py-1 opacity-50" v-html="link.label" />
            </template>
        </div>
    </div>
</template>
