<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { index, show } from '@/routes/users';
import type { AdminUser, Paginated } from '@/types';

const props = defineProps<{
    users: Paginated<AdminUser>;
    filters: { search: string };
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Users', href: index() }] } });

const search = ref(props.filters.search ?? '');
let debounce: ReturnType<typeof setTimeout> | undefined;

watch(search, () => {
    clearTimeout(debounce);
    debounce = setTimeout(
        () => router.get(index().url, { search: search.value || undefined }, { preserveState: true, replace: true }),
        300,
    );
});
</script>

<template>
    <Head title="Users" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="Users"
            description="Who may sign in, and which roles they hold. New registrations have no access until given a role."
        />

        <div class="relative max-w-md">
            <Search class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
            <Input v-model="search" type="search" placeholder="Search by name or email…" class="pl-9" aria-label="Search users" />
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">Name</th>
                        <th class="px-4 py-3 font-medium">Email</th>
                        <th class="px-4 py-3 font-medium">Roles</th>
                        <th class="px-4 py-3 font-medium">Status</th>
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
                                    {{ role.name }}
                                </Badge>
                                <span v-if="user.roles.length === 0" class="text-muted-foreground text-xs">No access</span>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <Badge :variant="user.is_active ? 'default' : 'outline'">
                                {{ user.is_active ? 'Active' : 'Inactive' }}
                            </Badge>
                        </td>
                    </tr>
                    <tr v-if="users.data.length === 0">
                        <td colspan="4" class="text-muted-foreground px-4 py-8 text-center">No users found.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="users.last_page > 1" class="flex flex-wrap gap-1">
            <template v-for="link in users.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    class="rounded-md border px-3 py-1 text-sm"
                    :class="link.active ? 'bg-muted font-medium' : ''"
                    preserve-state
                >
                    <span v-html="link.label" />
                </Link>
            </template>
        </div>
    </div>
</template>
