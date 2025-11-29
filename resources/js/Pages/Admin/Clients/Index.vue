<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link, router } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import { useRoute } from '@/composables/useRoute'
import { debounce } from 'lodash'
import { Card, CardContent } from '@/Components/ui/card'
import { Input } from '@/Components/ui/input'
import { Badge } from '@/Components/ui/badge'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/Components/ui/table'

const route = useRoute()

const props = defineProps({
    clients: Object,
    plans: Array,
    filters: Object,
})

const search = ref(props.filters.search)
const status = ref(props.filters.status)
const plan = ref(props.filters.plan)

const applyFilters = debounce(() => {
    router.get(route('admin.clients.index'), {
        search: search.value,
        status: status.value,
        plan: plan.value,
    }, {
        preserveState: true,
        replace: true,
    })
}, 300)

watch([search, status, plan], applyFilters)

const getStatusVariant = (status) => {
    const variants = {
        active: 'success',
        inactive: 'secondary',
        suspended: 'destructive',
    }
    return variants[status] || 'secondary'
}

const formatDate = (date) => {
    return new Date(date).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    })
}
</script>

<template>
    <AdminLayout title="Clients">
        <!-- Filters -->
        <div class="mb-6 flex flex-col sm:flex-row gap-4">
            <div class="flex-1">
                <Input
                    v-model="search"
                    type="text"
                    placeholder="Search clients..."
                    class="bg-zinc-700 border-zinc-600 text-white placeholder:text-zinc-500"
                />
            </div>
            <select
                v-model="status"
                class="h-9 rounded-md bg-zinc-700 border-zinc-600 text-white px-3 text-sm focus:border-indigo-500 focus:ring-indigo-500"
            >
                <option value="all">All Status</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="suspended">Suspended</option>
            </select>
            <select
                v-model="plan"
                class="h-9 rounded-md bg-zinc-700 border-zinc-600 text-white px-3 text-sm focus:border-indigo-500 focus:ring-indigo-500"
            >
                <option value="all">All Plans</option>
                <option v-for="p in plans" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
        </div>

        <!-- Table -->
        <Card class="bg-zinc-800 border-zinc-700">
            <CardContent class="p-0">
                <Table>
                    <TableHeader>
                        <TableRow class="border-zinc-700 hover:bg-zinc-700/50">
                            <TableHead class="text-zinc-300">Client</TableHead>
                            <TableHead class="text-zinc-300">Plan</TableHead>
                            <TableHead class="text-zinc-300">Status</TableHead>
                            <TableHead class="text-zinc-300 text-center">Users</TableHead>
                            <TableHead class="text-zinc-300 text-center">Conversations</TableHead>
                            <TableHead class="text-zinc-300 text-center">Leads</TableHead>
                            <TableHead class="text-zinc-300">Created</TableHead>
                            <TableHead class="text-zinc-300 text-right">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="client in clients.data" :key="client.id" class="border-zinc-700 hover:bg-zinc-700/50">
                            <TableCell>
                                <div>
                                    <Link :href="route('admin.clients.show', client.id)" class="text-sm font-medium text-white hover:text-indigo-400">
                                        {{ client.name }}
                                    </Link>
                                    <p class="text-xs text-zinc-400">{{ client.slug }}</p>
                                </div>
                            </TableCell>
                            <TableCell class="text-zinc-300">
                                {{ client.current_plan?.name || 'Free' }}
                            </TableCell>
                            <TableCell>
                                <Badge :variant="getStatusVariant(client.status)" class="capitalize">
                                    {{ client.status }}
                                </Badge>
                            </TableCell>
                            <TableCell class="text-center text-zinc-300">
                                {{ client.users_count }}
                            </TableCell>
                            <TableCell class="text-center text-zinc-300">
                                {{ client.conversations_count }}
                            </TableCell>
                            <TableCell class="text-center text-zinc-300">
                                {{ client.leads_count }}
                            </TableCell>
                            <TableCell class="text-zinc-400">
                                {{ formatDate(client.created_at) }}
                            </TableCell>
                            <TableCell class="text-right">
                                <Link :href="route('admin.clients.show', client.id)" class="text-sm text-indigo-400 hover:text-indigo-300">
                                    View
                                </Link>
                            </TableCell>
                        </TableRow>
                        <TableRow v-if="!clients.data?.length" class="border-zinc-700">
                            <TableCell colspan="8" class="text-center py-12 text-zinc-400">
                                No clients found
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </CardContent>
        </Card>

        <!-- Pagination -->
        <div v-if="clients.links?.length > 3" class="mt-4 flex justify-center">
            <nav class="flex space-x-2">
                <template v-for="link in clients.links" :key="link.label">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        :class="[
                            'px-3 py-2 text-sm rounded-md',
                            link.active ? 'bg-indigo-600 text-white' : 'bg-zinc-700 text-zinc-300 hover:bg-zinc-600'
                        ]"
                        v-html="link.label"
                    />
                    <span
                        v-else
                        class="px-3 py-2 text-sm text-zinc-500"
                        v-html="link.label"
                    />
                </template>
            </nav>
        </div>
    </AdminLayout>
</template>
