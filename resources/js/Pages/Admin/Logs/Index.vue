<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link, router } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import { useRoute } from '@/composables/useRoute'
import { debounce } from 'lodash'
import { Card, CardContent } from '@/Components/ui/card'
import { Input } from '@/Components/ui/input'
import { Label } from '@/Components/ui/label'
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
    logs: Object,
    actionTypes: Array,
    filters: Object,
})

const action = ref(props.filters.action)
const from = ref(props.filters.from)
const to = ref(props.filters.to)

const applyFilters = debounce(() => {
    router.get(route('admin.logs.index'), {
        action: action.value,
        from: from.value,
        to: to.value,
    }, {
        preserveState: true,
        replace: true,
    })
}, 300)

watch([action, from, to], applyFilters)

const formatDate = (date) => {
    return new Date(date).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    })
}

const getActionLabel = (actionType) => {
    const labels = {
        login: 'Logged in',
        logout: 'Logged out',
        approve_transaction: 'Approved transaction',
        reject_transaction: 'Rejected transaction',
        update_client_status: 'Updated client status',
        update_client_plan: 'Updated client plan',
    }
    return labels[actionType] || actionType.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())
}

const getActionColor = (actionType) => {
    if (actionType.includes('approve') || actionType === 'login') return 'text-emerald-500'
    if (actionType.includes('reject') || actionType === 'logout') return 'text-red-500'
    return 'text-blue-500'
}
</script>

<template>
    <AdminLayout title="Activity Logs">
        <!-- Filters -->
        <div class="mb-6 flex flex-col sm:flex-row gap-4">
            <select
                v-model="action"
                class="h-9 rounded-md bg-background border px-3 text-sm text-foreground focus:border-primary focus:ring-primary"
            >
                <option value="all">All Actions</option>
                <option v-for="actionType in actionTypes" :key="actionType" :value="actionType">
                    {{ getActionLabel(actionType) }}
                </option>
            </select>
            <div class="flex items-center gap-2">
                <Label class="text-sm text-muted-foreground">From:</Label>
                <Input
                    v-model="from"
                    type="date"
                />
            </div>
            <div class="flex items-center gap-2">
                <Label class="text-sm text-muted-foreground">To:</Label>
                <Input
                    v-model="to"
                    type="date"
                />
            </div>
        </div>

        <!-- Table -->
        <Card>
            <CardContent class="p-0">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Time</TableHead>
                            <TableHead>Admin</TableHead>
                            <TableHead>Action</TableHead>
                            <TableHead>Target</TableHead>
                            <TableHead>Details</TableHead>
                            <TableHead>IP Address</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="log in logs.data" :key="log.id">
                            <TableCell class="text-muted-foreground">
                                {{ formatDate(log.created_at) }}
                            </TableCell>
                            <TableCell class="text-foreground">
                                {{ log.admin?.name || 'Unknown' }}
                            </TableCell>
                            <TableCell>
                                <span :class="getActionColor(log.action_type)" class="font-medium">
                                    {{ getActionLabel(log.action_type) }}
                                </span>
                            </TableCell>
                            <TableCell class="text-muted-foreground">
                                <template v-if="log.target">
                                    {{ log.target_type?.split('\\').pop() }} #{{ log.target_id }}
                                </template>
                                <span v-else class="text-muted-foreground">-</span>
                            </TableCell>
                            <TableCell class="text-muted-foreground">
                                <template v-if="log.details">
                                    <span v-if="log.details.before && log.details.after" class="text-xs">
                                        {{ log.details.before }} → {{ log.details.after }}
                                    </span>
                                    <span v-else class="text-xs">
                                        {{ JSON.stringify(log.details).substring(0, 50) }}
                                    </span>
                                </template>
                                <span v-else class="text-muted-foreground">-</span>
                            </TableCell>
                            <TableCell class="text-muted-foreground font-mono">
                                {{ log.ip_address || '-' }}
                            </TableCell>
                        </TableRow>
                        <TableRow v-if="!logs.data?.length">
                            <TableCell colspan="6" class="text-center py-12 text-muted-foreground">
                                No activity logs found
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </CardContent>
        </Card>

        <!-- Pagination -->
        <div v-if="logs.links?.length > 3" class="mt-4 flex justify-center">
            <nav class="flex space-x-2">
                <template v-for="link in logs.links" :key="link.label">
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
