<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link, router } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import { useRoute } from '@/composables/useRoute'
import { debounce } from 'lodash'
import { formatDate } from '@/utils/transactions'
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
const trashed = ref(props.filters.trashed || '')
const sort = ref(props.filters.sort || 'created_at')
const direction = ref(props.filters.direction || 'desc')

const applyFilters = debounce(() => {
    router.get(route('admin.clients.index'), {
        search: search.value,
        status: status.value,
        plan: plan.value,
        trashed: trashed.value,
        sort: sort.value,
        direction: direction.value,
    }, {
        preserveState: true,
        replace: true,
    })
}, 300)

watch([search, status, plan, trashed, sort, direction], applyFilters)

const toggleSort = (field) => {
    if (sort.value === field) {
        direction.value = direction.value === 'asc' ? 'desc' : 'asc'
    } else {
        sort.value = field
        direction.value = 'asc'
    }
}

const sortIndicator = (field) => {
    if (sort.value !== field) {
        return ''
    }
    return direction.value === 'asc' ? ' ▲' : ' ▼'
}

const restore = (id) => {
    router.post(route('admin.clients.restore', id), {}, {
        preserveScroll: true,
    })
}

const getStatusVariant = (status) => {
    const variants = {
        active: 'success',
        inactive: 'secondary',
        suspended: 'destructive',
    }
    return variants[status] || 'secondary'
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
                />
            </div>
            <select
                v-model="status"
                class="h-9 rounded-md bg-background border px-3 text-sm text-foreground focus:border-primary focus:ring-primary"
            >
                <option value="all">All Status</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="suspended">Suspended</option>
            </select>
            <select
                v-model="plan"
                class="h-9 rounded-md bg-background border px-3 text-sm text-foreground focus:border-primary focus:ring-primary"
            >
                <option value="all">All Plans</option>
                <option v-for="p in plans" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
            <select
                v-model="trashed"
                class="h-9 rounded-md bg-background border px-3 text-sm text-foreground focus:border-primary focus:ring-primary"
            >
                <option value="">Active only</option>
                <option value="with">Include deleted</option>
                <option value="only">Deleted only</option>
            </select>
        </div>

        <!-- Table -->
        <Card>
            <CardContent class="p-0">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead class="cursor-pointer select-none" @click="toggleSort('name')">
                                Client{{ sortIndicator('name') }}
                            </TableHead>
                            <TableHead>Plan</TableHead>
                            <TableHead class="cursor-pointer select-none" @click="toggleSort('status')">
                                Status{{ sortIndicator('status') }}
                            </TableHead>
                            <TableHead class="text-center">Users</TableHead>
                            <TableHead class="text-center cursor-pointer select-none" @click="toggleSort('conversations_count')">
                                Conversations{{ sortIndicator('conversations_count') }}
                            </TableHead>
                            <TableHead class="text-center cursor-pointer select-none" @click="toggleSort('leads_count')">
                                Leads{{ sortIndicator('leads_count') }}
                            </TableHead>
                            <TableHead class="cursor-pointer select-none" @click="toggleSort('created_at')">
                                Created{{ sortIndicator('created_at') }}
                            </TableHead>
                            <TableHead class="text-right">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="client in clients.data" :key="client.id">
                            <TableCell>
                                <div>
                                    <Link :href="route('admin.clients.show', client.id)" class="text-sm font-medium text-foreground hover:text-primary">
                                        {{ client.name }}
                                    </Link>
                                    <p class="text-xs text-muted-foreground">{{ client.slug }}</p>
                                </div>
                            </TableCell>
                            <TableCell class="text-muted-foreground">
                                {{ client.current_plan?.name || 'Free' }}
                            </TableCell>
                            <TableCell>
                                <Badge :variant="getStatusVariant(client.status)" class="capitalize">
                                    {{ client.status }}
                                </Badge>
                                <Badge v-if="client.deleted_at" variant="destructive" class="ml-1">
                                    Deleted
                                </Badge>
                            </TableCell>
                            <TableCell class="text-center text-muted-foreground">
                                {{ client.users_count }}
                            </TableCell>
                            <TableCell class="text-center text-muted-foreground">
                                {{ client.conversations_count }}
                            </TableCell>
                            <TableCell class="text-center text-muted-foreground">
                                {{ client.leads_count }}
                            </TableCell>
                            <TableCell class="text-muted-foreground">
                                {{ formatDate(client.created_at) }}
                            </TableCell>
                            <TableCell class="text-right">
                                <button
                                    v-if="client.deleted_at"
                                    type="button"
                                    class="text-sm text-primary hover:text-primary/80"
                                    @click="restore(client.id)"
                                >
                                    Restore
                                </button>
                                <Link v-else :href="route('admin.clients.show', client.id)" class="text-sm text-primary hover:text-primary/80">
                                    View
                                </Link>
                            </TableCell>
                        </TableRow>
                        <TableRow v-if="!clients.data?.length">
                            <TableCell colspan="8" class="text-center py-12 text-muted-foreground">
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
                            link.active ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground hover:bg-accent'
                        ]"
                        v-html="link.label"
                    />
                    <span
                        v-else
                        class="px-3 py-2 text-sm text-muted-foreground"
                        v-html="link.label"
                    />
                </template>
            </nav>
        </div>
    </AdminLayout>
</template>
