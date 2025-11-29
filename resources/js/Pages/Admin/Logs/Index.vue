<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link, router } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import { useRoute } from '@/composables/useRoute'
import { debounce } from 'lodash'

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
                class="bg-gray-700 border-gray-600 text-white rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            >
                <option value="all">All Actions</option>
                <option v-for="actionType in actionTypes" :key="actionType" :value="actionType">
                    {{ getActionLabel(actionType) }}
                </option>
            </select>
            <div class="flex items-center gap-2">
                <label class="text-sm text-gray-400">From:</label>
                <input
                    v-model="from"
                    type="date"
                    class="bg-gray-700 border-gray-600 text-white rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                />
            </div>
            <div class="flex items-center gap-2">
                <label class="text-sm text-gray-400">To:</label>
                <input
                    v-model="to"
                    type="date"
                    class="bg-gray-700 border-gray-600 text-white rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                />
            </div>
        </div>

        <!-- Table -->
        <div class="bg-gray-800 shadow rounded-lg overflow-hidden">
            <table class="min-w-full divide-y divide-gray-700">
                <thead class="bg-gray-700">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Time</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Admin</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Action</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Target</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Details</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">IP Address</th>
                    </tr>
                </thead>
                <tbody class="bg-gray-800 divide-y divide-gray-700">
                    <tr v-for="log in logs.data" :key="log.id">
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-400">
                            {{ formatDate(log.created_at) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-white">
                            {{ log.admin?.name || 'Unknown' }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span :class="getActionColor(log.action_type)" class="text-sm font-medium">
                                {{ getActionLabel(log.action_type) }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-300">
                            <template v-if="log.target">
                                {{ log.target_type?.split('\\').pop() }} #{{ log.target_id }}
                            </template>
                            <span v-else class="text-gray-500">-</span>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-300">
                            <template v-if="log.details">
                                <span v-if="log.details.before && log.details.after" class="text-xs">
                                    {{ log.details.before }} → {{ log.details.after }}
                                </span>
                                <span v-else class="text-xs">
                                    {{ JSON.stringify(log.details).substring(0, 50) }}
                                </span>
                            </template>
                            <span v-else class="text-gray-500">-</span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-400 font-mono">
                            {{ log.ip_address || '-' }}
                        </td>
                    </tr>
                    <tr v-if="!logs.data?.length">
                        <td colspan="6" class="px-6 py-12 text-center text-gray-400">
                            No activity logs found
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div v-if="logs.links?.length > 3" class="mt-4 flex justify-center">
            <nav class="flex space-x-2">
                <template v-for="link in logs.links" :key="link.label">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        :class="[
                            'px-3 py-2 text-sm rounded-md',
                            link.active ? 'bg-indigo-600 text-white' : 'bg-gray-700 text-gray-300 hover:bg-gray-600'
                        ]"
                        v-html="link.label"
                    />
                </template>
            </nav>
        </div>
    </AdminLayout>
</template>
