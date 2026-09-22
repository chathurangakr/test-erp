<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AccountForm from '@/components/finance/AccountForm.vue';
import Heading from '@/components/Heading.vue';
import { index } from '@/routes/accounts';
import type { AccountOption, StatusOption } from '@/types';

defineProps<{
    types: StatusOption[];
    normalBalances: StatusOption[];
    parents: AccountOption[];
    defaultNormalBalances: Record<string, string>;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Chart of accounts', href: index() }, { title: 'New', href: index() }] },
});
</script>

<template>
    <Head title="New account" />
    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading title="New account" description="Add an account to the chart." />
        <div class="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl rounded-xl border p-4 sm:p-6">
            <AccountForm
                method="post"
                :action="index().url"
                submit-label="Create account"
                :cancel-href="index().url"
                :types="types"
                :normal-balances="normalBalances"
                :parents="parents"
                :default-normal-balances="defaultNormalBalances"
            />
        </div>
    </div>
</template>
