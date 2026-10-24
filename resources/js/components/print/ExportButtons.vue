<script setup lang="ts">
import { FileSpreadsheet, FileText } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import type { RouteQueryOptions } from '@/wayfinder';

/**
 * Download the report on screen as PDF or Excel, with the filters it is showing now. The server
 * builds both from the same figures as the screen (docs/REPORT_EXPORTS.md).
 */
const props = defineProps<{
    /** The report's export route: its wayfinder `exportMethod.url`. */
    url: (options?: RouteQueryOptions) => string;
    /** The filters applied on screen. */
    query?: Record<string, string | number | boolean | null | undefined>;
}>();

const { t } = useT();

const filters = computed(() =>
    Object.fromEntries(Object.entries(props.query ?? {}).filter(([, value]) => value !== null && value !== undefined && value !== '' && value !== false)),
);

function href(format: 'pdf' | 'xlsx'): string {
    return props.url({ query: { ...filters.value, format } as RouteQueryOptions['query'] });
}
</script>

<template>
    <div class="flex gap-2 print:hidden">
        <Button as="a" variant="outline" :href="href('pdf')" download>
            <FileText class="size-4" />
            {{ t('PDF') }}
        </Button>
        <Button as="a" variant="outline" :href="href('xlsx')" download>
            <FileSpreadsheet class="size-4" />
            {{ t('Excel') }}
        </Button>
    </div>
</template>
