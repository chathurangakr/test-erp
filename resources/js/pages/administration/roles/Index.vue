<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { create, index, show } from '@/routes/roles';
import type { RoleRow } from '@/types';

defineProps<{ roles: RoleRow[]; permissionTotal: number }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Roles & permissions', href: index() }] } });

const { can } = usePermissions();
</script>

<template>
    <Head title="Roles & permissions" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <Heading
                title="Roles &amp; permissions"
                description="A role is a named set of permissions. A user's access is everything their active roles grant."
            />
            <Button v-if="can('roles.manage')" as-child class="w-full sm:w-auto">
                <Link :href="create()"><Plus class="size-4" />New role</Link>
            </Button>
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">Code</th>
                        <th class="px-4 py-3 font-medium">Name</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 text-right font-medium">Users</th>
                        <th class="px-4 py-3 text-right font-medium">Permissions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="role in roles"
                        :key="role.id"
                        class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t"
                    >
                        <td class="px-4 py-3 font-mono text-xs font-medium">
                            <Link :href="show(role.id)" class="hover:underline">{{ role.code }}</Link>
                        </td>
                        <td class="px-4 py-3">
                            {{ role.name }}
                            <span v-if="role.description" class="text-muted-foreground block text-xs">{{ role.description }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <Badge :variant="role.is_active ? 'default' : 'outline'">
                                {{ role.is_active ? 'Active' : 'Inactive' }}
                            </Badge>
                        </td>
                        <td class="px-4 py-3 text-right">{{ role.users_count }}</td>
                        <td class="px-4 py-3 text-right">{{ role.permissions_count }} / {{ permissionTotal }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="text-muted-foreground max-w-3xl text-xs">
            Permissions are capabilities built into the application, so they cannot be created
            here — only assigned. The seeded roles are starting points and can be changed freely,
            except Administrator, which always holds everything.
        </p>
    </div>
</template>
