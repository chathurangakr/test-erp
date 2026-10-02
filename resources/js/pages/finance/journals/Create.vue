<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import JournalEntryForm from '@/components/finance/JournalEntryForm.vue';
import { index } from '@/routes/journals';
import type { AccountOption } from '@/types';
import { useT } from '@/lib/i18n';

defineProps<{
    accounts: AccountOption[];
    openPeriods: { id: number; name: string; start_date: string; end_date: string }[];
    currency: string;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Journal entries', href: index() }, { title: 'New', href: index() }] },
});

const { t } = useT();
</script>

<template>
    <Head :title="t('New journal entry')" />
    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="New journal entry"
            description="Saved as a draft. Nothing reaches the books until you post it."
        />
        <div class="border-sidebar-border/70 dark:border-sidebar-border max-w-6xl rounded-xl border p-4 sm:p-6">
            <JournalEntryForm
                method="post"
                :action="index().url"
                submit-label="Save draft"
                :cancel-href="index().url"
                :accounts="accounts"
                :open-periods="openPeriods"
                :currency="currency"
            />
        </div>
    </div>
</template>
