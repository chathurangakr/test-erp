<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AccountForm from '@/components/finance/AccountForm.vue';
import Heading from '@/components/Heading.vue';
import { useT } from '@/lib/i18n';
import { index } from '@/routes/accounts';
import type { AccountFormValues, AccountOption, StatusOption } from '@/types';

const props = defineProps<{
    account: AccountFormValues & { id: number };
    types: StatusOption[];
    normalBalances: StatusOption[];
    parents: AccountOption[];
    defaultNormalBalances: Record<string, string>;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Chart of accounts', href: index() }] } });

const { t } = useT();
</script>

<template>
    <Head :title="t('Edit :code', { code: props.account.code })" />
    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading :title="t('Edit :code', { code: props.account.code })" :description="props.account.name" />
        <div class="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl rounded-xl border p-4 sm:p-6">
            <AccountForm
                method="put"
                :action="`${index().url}/${props.account.id}`"
                submit-label="Save changes"
                :cancel-href="`${index().url}/${props.account.id}`"
                :types="types"
                :normal-balances="normalBalances"
                :parents="parents"
                :default-normal-balances="defaultNormalBalances"
                :initial="props.account"
            />
        </div>
    </div>
</template>
