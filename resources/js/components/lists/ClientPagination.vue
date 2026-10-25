<script setup lang="ts">
import { useT } from '@/lib/i18n';

/** Under a list the page holds whole: how many records match, which are shown, and page buttons. */
defineProps<{ range: { from: number; to: number; total: number }; pageCount: number }>();

const page = defineModel<number>('page', { required: true });

const { t } = useT();
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
        <p class="text-muted-foreground">
            {{ range.total === 0 ? t('No records match.') : t('Showing :from–:to of :total', { from: range.from, to: range.to, total: range.total }) }}
        </p>
        <div v-if="pageCount > 1" class="flex flex-wrap gap-1">
            <button type="button" class="rounded-md border px-3 py-1 disabled:opacity-50" :disabled="page <= 1" @click="page--">{{ t('Previous') }}</button>
            <button v-for="n in pageCount" :key="n" type="button" class="rounded-md border px-3 py-1" :class="n === page ? 'bg-muted font-medium' : ''" @click="page = n">{{ n }}</button>
            <button type="button" class="rounded-md border px-3 py-1 disabled:opacity-50" :disabled="page >= pageCount" @click="page++">{{ t('Next') }}</button>
        </div>
    </div>
</template>
