<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import RoleForm from '@/components/administration/RoleForm.vue';
import Heading from '@/components/Heading.vue';
import { index, show, update } from '@/routes/roles';
import type { PermissionGroup, RoleDetail } from '@/types';

defineProps<{ role: RoleDetail; groups: PermissionGroup[] }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Roles & permissions', href: index() }] } });
</script>

<template>
    <Head :title="`Edit ${role.name}`" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading :title="`Edit ${role.name}`" description="Changes apply to every user holding this role on their next click." />

        <div class="border-sidebar-border/70 dark:border-sidebar-border max-w-4xl rounded-xl border p-4 sm:p-6">
            <RoleForm
                :action="update(role.id)"
                :role="role"
                :groups="groups"
                submit-label="Save role"
                :cancel-href="show(role.id).url"
            />
        </div>
    </div>
</template>
