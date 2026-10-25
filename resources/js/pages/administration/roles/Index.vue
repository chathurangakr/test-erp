<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Plus, Search } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import ClientPagination from '@/components/lists/ClientPagination.vue';
import ListControls from '@/components/lists/ListControls.vue';
import SortHeader from '@/components/lists/SortHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useClientList } from '@/composables/useClientList';
import { usePermissions } from '@/composables/usePermissions';
import { create, index, show } from '@/routes/roles';
import type { RoleRow } from '@/types';
import { useT } from '@/lib/i18n';

const props = defineProps<{ roles: RoleRow[]; permissionTotal: number }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Roles & permissions', href: index() }] } });

const { can } = usePermissions();

const { t } = useT();

const { filters, visible, pageCount, range, sortBy, clear } = useClientList(() => props.roles, {
    search: (role) => `${role.code} ${role.name} ${t(role.name)} ${role.description ?? ''}`,
    sorts: {
        code: (role) => role.code,
        name: (role) => t(role.name),
        status: (role) => (role.is_active ? 0 : 1),
        users: (role) => role.users_count,
        permissions: (role) => role.permissions_count,
    },
    defaultSort: 'code',
});
</script>

<template>
    <Head :title="t('Roles & permissions')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <Heading
                title="Roles &amp; permissions"
                description="A role is a named set of permissions. A user's access is everything their active roles grant."
            />
            <Button v-if="can('roles.manage')" as-child class="w-full sm:w-auto">
                <Link :href="create()"><Plus class="size-4" />{{ t('New role') }}</Link>
            </Button>
        </div>

        <ListControls v-model:per-page="filters.per_page" v-model:from="filters.from" v-model:to="filters.to" :period="false" @clear="clear">
            <div class="relative w-full sm:w-72">
                <Search class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                <Input v-model="filters.search" type="search" :placeholder="t('Search code or name…')" class="pl-9" />
            </div>
        </ListControls>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <SortHeader label="Code" column="code" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Name" column="name" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Status" column="status" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Users" column="users" align="right" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Permissions" column="permissions" align="right" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="role in visible"
                        :key="role.id"
                        class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t"
                    >
                        <td class="px-4 py-3 font-mono text-xs font-medium">
                            <Link :href="show(role.id)" class="hover:underline">{{ role.code }}</Link>
                        </td>
                        <td class="px-4 py-3">
                            {{ t(role.name) }}
                            <span v-if="role.description" class="text-muted-foreground block text-xs">{{ t(role.description) }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <Badge :variant="role.is_active ? 'default' : 'outline'">
                                {{ role.is_active ? t('Active') : t('Inactive') }}
                            </Badge>
                        </td>
                        <td class="px-4 py-3 text-right">{{ role.users_count }}</td>
                        <td class="px-4 py-3 text-right">{{ role.permissions_count }} / {{ permissionTotal }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ClientPagination v-model:page="filters.page" :range="range" :page-count="pageCount" />

        <p class="text-muted-foreground max-w-3xl text-xs">
            {{ t('Permissions are capabilities built into the application, so they cannot be created here — only assigned. The seeded roles are starting points and can be changed freely, except Administrator, which always holds everything.') }}
        </p>
    </div>
</template>
