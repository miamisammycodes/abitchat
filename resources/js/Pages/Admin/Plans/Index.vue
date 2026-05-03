<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link, router } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import { useRoute } from '@/composables/useRoute'
import { debounce } from 'lodash'
import { Card, CardContent } from '@/Components/ui/card'
import { Button } from '@/Components/ui/button'
import { Input } from '@/Components/ui/input'
import { Badge } from '@/Components/ui/badge'
import { Switch } from '@/Components/ui/switch'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/Components/ui/table'
import { Plus, Pencil } from 'lucide-vue-next'

const route = useRoute()

const props = defineProps({
    plans: Object,
    counts: Object,
    filters: Object,
})

const search = ref(props.filters.search)
const status = ref(props.filters.status)

const applyFilters = debounce(() => {
    router.get(route('admin.plans.index'), {
        search: search.value,
        status: status.value,
    }, {
        preserveState: true,
        replace: true,
    })
}, 300)

watch(search, applyFilters)

const filterByStatus = (newStatus) => {
    status.value = newStatus
    router.get(route('admin.plans.index'), {
        search: search.value,
        status: newStatus,
    }, {
        preserveState: true,
    })
}

const togglePlanStatus = (plan) => {
    router.patch(route('admin.plans.toggle', plan.id), {}, {
        preserveState: true,
    })
}

const formatCurrency = (amount) => {
    if (amount === 0 || amount === '0.00') {
        return 'Free'
    }
    return 'Nu. ' + Number(amount || 0).toLocaleString('en-US')
}

const formatLimit = (limit) => {
    if (limit === -1) {
        return 'Unlimited'
    }
    return Number(limit).toLocaleString('en-US')
}
</script>

<template>
    <AdminLayout title="Plans">
        <!-- Header -->
        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-2xl font-bold text-foreground">Subscription Plans</h1>
                <p class="text-muted-foreground">Manage pricing plans and features</p>
            </div>
            <Link :href="route('admin.plans.create')">
                <Button>
                    <Plus class="w-4 h-4 mr-2" />
                    Create Plan
                </Button>
            </Link>
        </div>

        <!-- Tabs -->
        <div class="mb-6 border-b">
            <nav class="flex space-x-8">
                <button
                    @click="filterByStatus('all')"
                    :class="[
                        status === 'all' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground',
                        'py-4 px-1 border-b-2 font-medium text-sm'
                    ]"
                >
                    All ({{ counts.all }})
                </button>
                <button
                    @click="filterByStatus('active')"
                    :class="[
                        status === 'active' ? 'border-emerald-500 text-emerald-500' : 'border-transparent text-muted-foreground hover:text-foreground',
                        'py-4 px-1 border-b-2 font-medium text-sm'
                    ]"
                >
                    Active ({{ counts.active }})
                </button>
                <button
                    @click="filterByStatus('inactive')"
                    :class="[
                        status === 'inactive' ? 'border-red-500 text-red-500' : 'border-transparent text-muted-foreground hover:text-foreground',
                        'py-4 px-1 border-b-2 font-medium text-sm'
                    ]"
                >
                    Inactive ({{ counts.inactive }})
                </button>
            </nav>
        </div>

        <!-- Search -->
        <div class="mb-6">
            <Input
                v-model="search"
                type="text"
                placeholder="Search by plan name or slug..."
                class="w-full max-w-md"
            />
        </div>

        <!-- Table -->
        <Card>
            <CardContent class="p-0">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Order</TableHead>
                            <TableHead>Name</TableHead>
                            <TableHead>Slug</TableHead>
                            <TableHead class="text-right">Price</TableHead>
                            <TableHead>Period</TableHead>
                            <TableHead class="text-right">Tokens</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>Active</TableHead>
                            <TableHead class="text-right">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="plan in plans.data" :key="plan.id">
                            <TableCell class="text-muted-foreground">
                                {{ plan.sort_order }}
                            </TableCell>
                            <TableCell>
                                <div>
                                    <span class="font-medium text-foreground">{{ plan.name }}</span>
                                    <Badge v-if="plan.is_contact_sales" variant="outline" class="ml-2">Contact Sales</Badge>
                                </div>
                                <p class="text-sm text-muted-foreground truncate max-w-xs">{{ plan.description }}</p>
                            </TableCell>
                            <TableCell class="font-mono text-muted-foreground">
                                {{ plan.slug }}
                            </TableCell>
                            <TableCell class="text-right font-medium text-foreground">
                                <template v-if="plan.is_contact_sales">
                                    <span class="text-primary">Contact Us</span>
                                </template>
                                <template v-else>
                                    {{ formatCurrency(plan.price) }}
                                </template>
                            </TableCell>
                            <TableCell class="text-muted-foreground capitalize">
                                {{ plan.billing_period }}
                            </TableCell>
                            <TableCell class="text-right text-muted-foreground">
                                {{ formatLimit(plan.tokens_limit) }}
                            </TableCell>
                            <TableCell>
                                <Badge :variant="plan.is_active ? 'success' : 'secondary'">
                                    {{ plan.is_active ? 'Active' : 'Inactive' }}
                                </Badge>
                            </TableCell>
                            <TableCell>
                                <Switch
                                    :checked="plan.is_active"
                                    @update:checked="togglePlanStatus(plan)"
                                />
                            </TableCell>
                            <TableCell class="text-right">
                                <Link :href="route('admin.plans.edit', plan.id)">
                                    <Button variant="ghost" size="sm">
                                        <Pencil class="w-4 h-4" />
                                    </Button>
                                </Link>
                            </TableCell>
                        </TableRow>
                        <TableRow v-if="!plans.data?.length">
                            <TableCell colspan="9" class="text-center py-12 text-muted-foreground">
                                No plans found
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </CardContent>
        </Card>

        <!-- Pagination -->
        <div v-if="plans.links?.length > 3" class="mt-4 flex justify-center">
            <nav class="flex space-x-2">
                <template v-for="link in plans.links" :key="link.label">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        :class="[
                            'px-3 py-2 text-sm rounded-md',
                            link.active ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground hover:bg-accent'
                        ]"
                        v-html="link.label"
                    />
                </template>
            </nav>
        </div>
    </AdminLayout>
</template>
