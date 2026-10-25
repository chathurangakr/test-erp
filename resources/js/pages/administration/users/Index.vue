<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Plus, Search } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import ListControls from '@/components/lists/ListControls.vue';
import ListPagination from '@/components/lists/ListPagination.vue';
import SortHeader from '@/components/lists/SortHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { type ListControls as ListControlValues, useListFilters } from '@/composables/useListFilters';
import { usePermissions } from '@/composables/usePermissions';
import { create, index, show } from '@/routes/users';
import type { AdminUser, Paginated } from '@/types';
import { useT } from '@/lib/i18n';

const props = defineProps<{
    users: Paginated<AdminUser>;
    filters: { search: string; status: string } & ListControlValues;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Users', href: index() }] } });

const { filters, sortBy, clear } = useListFilters(() => index().url, { ...props.filters });

const { can } = usePermissions();

const { t } = useT();
</script>

<template>
    <Head :title="t('Users')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <Heading
                title="Users"
                description="Who may sign in, and which roles they hold. New registrations have no access until given a role."
            />
            <Button v-if="can('users.manage')" as-child class="w-full sm:w-auto">
                <Link :href="create()"><Plus class="size-4" />{{ t('Add user') }}</Link>
            </Button>
        </div>

        <ListControls v-model:per-page="filters.per_page" :period="false" @clear="clear">
            <div class="relative w-full sm:w-80">
                <Search class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                <Input v-model="filters.search" type="search" :placeholder="t('Search by name or email…')" class="pl-9" :aria-label="t('Search users')" />
            </div>
            <select v-model="filters.status" class="border-input bg-background h-9 rounded-md border px-3 text-sm" :aria-label="t('Filter by status')">
                <option value="all">{{ t('All') }}</option>
                <option value="active">{{ t('Active') }}</option>
                <option value="inactive">{{ t('Inactive') }}</option>
            </select>
        </ListControls>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <SortHeader label="Name" column="name" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Email" column="email" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <th class="px-4 py-3 font-medium">{{ t('Roles') }}</th>
                        <SortHeader label="Status" column="status" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="user in users.data"
                        :key="user.id"
                        class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t"
                    >
                        <td class="px-4 py-3">
                            <Link :href="show(user.id)" class="font-medium hover:underline">{{ user.name }}</Link>
                        </td>
                        <td class="px-4 py-3">{{ user.email }}</td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-1">
                                <Badge
                                    v-for="role in user.roles"
                                    :key="role.id"
                                    :variant="role.is_active ? 'secondary' : 'outline'"
                                    :class="role.is_active ? '' : 'line-through'"
                                >
                                    {{ t(role.name) }}
                                </Badge>
                                <span v-if="user.roles.length === 0" class="text-muted-foreground text-xs">{{ t('No access') }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <Badge :variant="user.is_active ? 'default' : 'outline'">
                                {{ user.is_active ? t('Active') : t('Inactive') }}
                            </Badge>
                        </td>
                    </tr>
                    <tr v-if="users.data.length === 0">
                        <td colspan="4" class="text-muted-foreground px-4 py-8 text-center">{{ t('No users found.') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ListPagination :page="users" />
    </div>
</template>
