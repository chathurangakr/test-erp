<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import JournalEntryForm from '@/components/finance/JournalEntryForm.vue';
import { index } from '@/routes/journals';
import type { AccountOption, JournalFormValues } from '@/types';

const props = defineProps<{
    entry: JournalFormValues & { id: number; journal_number: string };
    accounts: AccountOption[];
    openPeriods: { id: number; name: string; start_date: string; end_date: string }[];
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Journal entries', href: index() }] } });
</script>

<template>
    <Head :title="`Edit ${props.entry.journal_number}`" />
    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            :title="`Edit ${props.entry.journal_number}`"
            description="Only a draft journal entry can be edited."
        />
        <div class="border-sidebar-border/70 dark:border-sidebar-border max-w-6xl rounded-xl border p-4 sm:p-6">
            <JournalEntryForm
                method="put"
                :action="`${index().url}/${props.entry.id}`"
                submit-label="Save changes"
                :cancel-href="`${index().url}/${props.entry.id}`"
                :accounts="accounts"
                :open-periods="openPeriods"
                :currency="currency"
                :initial="props.entry"
            />
        </div>
    </div>
</template>
