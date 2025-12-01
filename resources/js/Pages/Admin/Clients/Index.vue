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
        </div>

        <!-- Table -->
        <Card>
            <CardContent class="p-0">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Client</TableHead>
                            <TableHead>Plan</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead class="text-center">Users</TableHead>
                            <TableHead class="text-center">Conversations</TableHead>
                            <TableHead class="text-center">Leads</TableHead>
                            <TableHead>Created</TableHead>
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
                                <Link :href="route('admin.clients.show', client.id)" class="text-sm text-primary hover:text-primary/80">
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
