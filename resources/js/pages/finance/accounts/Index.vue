<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import ListControls from '@/components/lists/ListControls.vue';
import ListPagination from '@/components/lists/ListPagination.vue';
import SortHeader from '@/components/lists/SortHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { type ListControls as ListControlValues, useListFilters } from '@/composables/useListFilters';
import { usePermissions } from '@/composables/usePermissions';
import { useT } from '@/lib/i18n';
import { index } from '@/routes/accounts';
import type { AccountRow, Paginated, StatusOption } from '@/types';

const props = defineProps<{
    accounts: Paginated<AccountRow>;
    filters: { search: string; type: string; status: string } & ListControlValues;
    types: StatusOption[];
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Chart of accounts', href: index() }] } });

const { can } = usePermissions();
const { t } = useT();

const { filters, sortBy, clear } = useListFilters(() => index().url, { ...props.filters });

const controlClass =
    'border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none';
</script>

<template>
    <Head :title="t('Chart of accounts')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <Heading
                title="Chart of accounts"
                :description="t('The accounts the books are kept in. Base currency :currency.', { currency })"
            />
            <Button v-if="can('finance.accounts_manage')" as-child class="w-full sm:w-auto">
                <Link :href="`${index().url}/create`"><Plus class="size-4" />{{ t('New account') }}</Link>
            </Button>
        </div>

        <ListControls v-model:per-page="filters.per_page" :period="false" @clear="clear">
            <label class="grid gap-1 text-xs">
                <span class="text-muted-foreground">{{ t('Search') }}</span>
                <input v-model="filters.search" type="search" :placeholder="t('Code or name')" :class="controlClass" class="w-56" />
            </label>
            <label class="grid gap-1 text-xs">
                <span class="text-muted-foreground">{{ t('Type') }}</span>
                <SearchableSelect
                    v-model="filters.type"
                    class="w-48"
                    :options="[{ value: '', label: t('All types') }, ...types.map((option) => ({ value: option.value, label: option.label }))]"
                />
            </label>
            <label class="grid gap-1 text-xs">
                <span class="text-muted-foreground">{{ t('Status') }}</span>
                <SearchableSelect
                    v-model="filters.status"
                    class="w-40"
                    :options="[{ value: 'all', label: t('All') }, { value: 'active', label: t('Active') }, { value: 'inactive', label: t('Inactive') }]"
                />
            </label>
        </ListControls>

        <div
            v-if="accounts.data.length === 0"
            class="border-sidebar-border/70 dark:border-sidebar-border flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-12 text-center"
        >
            <p class="font-medium">{{ t('No accounts found') }}</p>
        </div>

        <div v-else class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <SortHeader label="Code" column="code" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Name" column="name" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Type" column="type" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <th class="px-4 py-3 font-medium">{{ t('Normal balance') }}</th>
                        <SortHeader label="Control" column="control" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <th class="px-4 py-3 font-medium">{{ t('Manual posting') }}</th>
                        <SortHeader label="Status" column="status" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in accounts.data"
                        :key="row.id"
                        class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t"
                    >
                        <td class="px-4 py-3 font-mono text-xs font-medium">
                            <Link :href="`${index().url}/${row.id}`" class="hover:underline">{{ row.code }}</Link>
                        </td>
                        <td class="px-4 py-3">
                            {{ row.name }}
                            <span v-if="row.parent_code" class="text-muted-foreground block text-xs">
                                {{ t('under :code :name', { code: row.parent_code, name: row.parent_name ?? '' }) }}
                            </span>
                        </td>
                        <td class="px-4 py-3">{{ row.account_type_label }}</td>
                        <td class="px-4 py-3">{{ row.normal_balance_label }}</td>
                        <td class="px-4 py-3">
                            <Badge v-if="row.is_control_account" variant="secondary">{{ t('Control') }}</Badge>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="px-4 py-3">
                            <span :class="row.accepts_manual_posting ? '' : 'text-muted-foreground'">
                                {{ row.accepts_manual_posting ? t('Allowed') : t('Blocked') }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <Badge :variant="row.is_active ? 'default' : 'destructive'">
                                {{ row.is_active ? t('Active') : t('Inactive') }}
                            </Badge>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>


        <ListPagination :page="accounts" />

        <p class="text-muted-foreground max-w-3xl text-xs">
            {{ t('Accounts are deactivated, never deleted — historic journal lines must keep referring to something meaningful. A control account is reconciled from a subsidiary ledger, so it is posted to by the system rather than by hand.') }}
        </p>
    </div>
</template>
