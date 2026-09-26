<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Pencil, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import RoleController from '@/actions/App/Http/Controllers/Administration/RoleController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { edit, index } from '@/routes/roles';
import { show as userShow } from '@/routes/users';
import type { PermissionGroup, RoleDetail } from '@/types';

const props = defineProps<{
    role: RoleDetail;
    users: { id: number; name: string; email: string; is_active: boolean }[];
    groups: PermissionGroup[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Roles & permissions', href: index() }] } });

const { can } = usePermissions();

const held = computed(() => new Set(props.role.permission_ids));

const heldGroups = computed(() =>
    props.groups
        .map((group) => ({ ...group, permissions: group.permissions.filter((permission) => held.value.has(permission.id)) }))
        .filter((group) => group.permissions.length > 0),
);
</script>

<template>
    <Head :title="role.name" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <Heading :title="role.name" :description="role.description ?? role.code" />
            <div v-if="can('roles.manage')" class="flex gap-2">
                <Button variant="outline" as-child>
                    <Link :href="edit(role.id)"><Pencil class="size-4" />Edit</Link>
                </Button>
                <Form
                    v-if="!role.is_administrator && users.length === 0"
                    v-bind="RoleController.destroy.form(role.id)"
                    v-slot="{ processing, errors }"
                >
                    <Button type="submit" variant="outline" :disabled="processing"><Trash2 class="size-4" />Delete</Button>
                    <InputError :message="errors.role" />
                </Form>
            </div>
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border grid gap-4 rounded-xl border p-4 sm:grid-cols-3">
            <div>
                <p class="text-muted-foreground text-xs font-medium">Code</p>
                <p class="mt-1 font-mono text-sm">{{ role.code }}</p>
            </div>
            <div>
                <p class="text-muted-foreground text-xs font-medium">Status</p>
                <Badge class="mt-1" :variant="role.is_active ? 'default' : 'outline'">
                    {{ role.is_active ? 'Active' : 'Inactive — grants nothing' }}
                </Badge>
            </div>
            <div>
                <p class="text-muted-foreground text-xs font-medium">Permissions</p>
                <p class="mt-1 text-sm">{{ role.permission_ids.length }}</p>
            </div>
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border">
            <div class="border-sidebar-border/70 dark:border-sidebar-border border-b px-4 py-3">
                <h3 class="text-sm font-medium">Users holding this role ({{ users.length }})</h3>
                <p v-if="users.length > 0 && !role.is_administrator" class="text-muted-foreground text-xs">
                    A role still held by users cannot be deleted — deactivate it instead.
                </p>
            </div>
            <ul class="divide-sidebar-border/70 dark:divide-sidebar-border divide-y">
                <li v-for="user in users" :key="user.id" class="flex items-center justify-between px-4 py-2 text-sm">
                    <component :is="can('users.view') ? Link : 'span'" :href="can('users.view') ? userShow(user.id).url : undefined" class="hover:underline">
                        {{ user.name }} <span class="text-muted-foreground text-xs">{{ user.email }}</span>
                    </component>
                    <Badge v-if="!user.is_active" variant="outline">Inactive</Badge>
                </li>
                <li v-if="users.length === 0" class="text-muted-foreground px-4 py-4 text-sm">Nobody holds this role.</li>
            </ul>
        </div>

        <div v-for="group in heldGroups" :key="group.module" class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border">
            <div class="bg-muted/50 rounded-t-xl px-4 py-2 text-sm font-medium">{{ group.module }}</div>
            <ul class="grid gap-1 p-4 sm:grid-cols-2">
                <li v-for="permission in group.permissions" :key="permission.id" class="text-sm">
                    {{ permission.name }}
                    <span class="text-muted-foreground block font-mono text-xs">{{ permission.code }}</span>
                </li>
            </ul>
        </div>
        <p v-if="heldGroups.length === 0" class="text-muted-foreground text-sm">This role grants no permissions.</p>
    </div>
</template>
