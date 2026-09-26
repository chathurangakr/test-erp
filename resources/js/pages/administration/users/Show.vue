<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { show as roleShow } from '@/routes/roles';
import { edit, index } from '@/routes/users';
import type { AdminUser } from '@/types';

defineProps<{ user: AdminUser; permissions: string[] }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Users', href: index() }] } });

const { can } = usePermissions();
</script>

<template>
    <Head :title="user.name" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <Heading :title="user.name" :description="user.email" />
            <Button v-if="can('users.manage')" variant="outline" as-child>
                <Link :href="edit(user.id)"><Pencil class="size-4" />Edit access</Link>
            </Button>
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border grid gap-4 rounded-xl border p-4 sm:grid-cols-3">
            <div>
                <p class="text-muted-foreground text-xs font-medium">Status</p>
                <Badge class="mt-1" :variant="user.is_active ? 'default' : 'outline'">
                    {{ user.is_active ? 'Active' : 'Inactive — cannot sign in' }}
                </Badge>
            </div>
            <div>
                <p class="text-muted-foreground text-xs font-medium">Roles</p>
                <div class="mt-1 flex flex-wrap gap-1">
                    <component
                        :is="can('roles.view') ? Link : 'span'"
                        v-for="role in user.roles"
                        :key="role.id"
                        :href="can('roles.view') ? roleShow(role.id).url : undefined"
                    >
                        <Badge :variant="role.is_active ? 'secondary' : 'outline'" :class="role.is_active ? '' : 'line-through'">
                            {{ role.name }}
                        </Badge>
                    </component>
                    <span v-if="user.roles.length === 0" class="text-muted-foreground text-sm">None — no access</span>
                </div>
            </div>
            <div>
                <p class="text-muted-foreground text-xs font-medium">Registered</p>
                <p class="mt-1 text-sm">{{ user.created_at ?? '—' }}</p>
            </div>
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border">
            <div class="border-sidebar-border/70 dark:border-sidebar-border border-b px-4 py-3">
                <h3 class="text-sm font-medium">Effective permissions ({{ permissions.length }})</h3>
                <p class="text-muted-foreground text-xs">Everything granted by this user's active roles, combined.</p>
            </div>
            <div class="flex flex-wrap gap-1 p-4">
                <code
                    v-for="permission in permissions"
                    :key="permission"
                    class="bg-muted rounded px-1.5 py-0.5 font-mono text-xs"
                >
                    {{ permission }}
                </code>
                <span v-if="permissions.length === 0" class="text-muted-foreground text-sm">None.</span>
            </div>
        </div>
    </div>
</template>
