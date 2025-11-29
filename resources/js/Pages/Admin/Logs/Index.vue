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
    if (actionType.includes('approve') || actionType === 'login') return 'text-emerald-400'
    if (actionType.includes('reject') || actionType === 'logout') return 'text-red-400'
    return 'text-blue-400'
}
</script>

<template>
    <AdminLayout title="Activity Logs">
        <!-- Filters -->
        <div class="mb-6 flex flex-col sm:flex-row gap-4">
            <select
                v-model="action"
                class="h-9 rounded-md bg-zinc-700 border-zinc-600 text-white px-3 text-sm focus:border-indigo-500 focus:ring-indigo-500"
            >
                <option value="all">All Actions</option>
                <option v-for="actionType in actionTypes" :key="actionType" :value="actionType">
                    {{ getActionLabel(actionType) }}
                </option>
            </select>
            <div class="flex items-center gap-2">
                <Label class="text-sm text-zinc-400">From:</Label>
                <Input
                    v-model="from"
                    type="date"
                    class="bg-zinc-700 border-zinc-600 text-white"
                />
            </div>
            <div class="flex items-center gap-2">
                <Label class="text-sm text-zinc-400">To:</Label>
                <Input
                    v-model="to"
                    type="date"
                    class="bg-zinc-700 border-zinc-600 text-white"
                />
            </div>
        </div>

        <!-- Table -->
        <Card class="bg-zinc-800 border-zinc-700">
            <CardContent class="p-0">
                <Table>
                    <TableHeader>
                        <TableRow class="border-zinc-700 hover:bg-zinc-700/50">
                            <TableHead class="text-zinc-300">Time</TableHead>
                            <TableHead class="text-zinc-300">Admin</TableHead>
                            <TableHead class="text-zinc-300">Action</TableHead>
                            <TableHead class="text-zinc-300">Target</TableHead>
                            <TableHead class="text-zinc-300">Details</TableHead>
                            <TableHead class="text-zinc-300">IP Address</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="log in logs.data" :key="log.id" class="border-zinc-700 hover:bg-zinc-700/50">
                            <TableCell class="text-zinc-400">
                                {{ formatDate(log.created_at) }}
                            </TableCell>
                            <TableCell class="text-white">
                                {{ log.admin?.name || 'Unknown' }}
                            </TableCell>
                            <TableCell>
                                <span :class="getActionColor(log.action_type)" class="font-medium">
                                    {{ getActionLabel(log.action_type) }}
                                </span>
                            </TableCell>
                            <TableCell class="text-zinc-300">
                                <template v-if="log.target">
                                    {{ log.target_type?.split('\\').pop() }} #{{ log.target_id }}
                                </template>
                                <span v-else class="text-zinc-500">-</span>
                            </TableCell>
                            <TableCell class="text-zinc-300">
                                <template v-if="log.details">
                                    <span v-if="log.details.before && log.details.after" class="text-xs">
                                        {{ log.details.before }} → {{ log.details.after }}
                                    </span>
                                    <span v-else class="text-xs">
                                        {{ JSON.stringify(log.details).substring(0, 50) }}
                                    </span>
                                </template>
                                <span v-else class="text-zinc-500">-</span>
                            </TableCell>
                            <TableCell class="text-zinc-400 font-mono">
                                {{ log.ip_address || '-' }}
                            </TableCell>
                        </TableRow>
                        <TableRow v-if="!logs.data?.length" class="border-zinc-700">
                            <TableCell colspan="6" class="text-center py-12 text-zinc-400">
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
                            link.active ? 'bg-indigo-600 text-white' : 'bg-zinc-700 text-zinc-300 hover:bg-zinc-600'
                        ]"
                        v-html="link.label"
                    />
                </template>
            </nav>
        </div>
    </AdminLayout>
</template>
