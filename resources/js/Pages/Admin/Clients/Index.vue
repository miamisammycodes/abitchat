<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link, router } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import { useRoute } from '@/composables/useRoute'
import { debounce } from 'lodash'

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

const getStatusColor = (status) => {
    const colors = {
        active: 'bg-emerald-900 text-emerald-200',
        inactive: 'bg-gray-700 text-gray-300',
        suspended: 'bg-red-900 text-red-200',
    }
    return colors[status] || 'bg-gray-700 text-gray-300'
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
                <input
                    v-model="search"
                    type="text"
                    placeholder="Search clients..."
                    class="w-full bg-gray-700 border-gray-600 text-white rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                />
            </div>
            <select
                v-model="status"
                class="bg-gray-700 border-gray-600 text-white rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            >
                <option value="all">All Status</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="suspended">Suspended</option>
            </select>
            <select
                v-model="plan"
                class="bg-gray-700 border-gray-600 text-white rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            >
                <option value="all">All Plans</option>
                <option v-for="p in plans" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
        </div>

        <!-- Table -->
        <div class="bg-gray-800 shadow rounded-lg overflow-hidden">
            <table class="min-w-full divide-y divide-gray-700">
                <thead class="bg-gray-700">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Client</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Plan</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-300 uppercase tracking-wider">Users</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-300 uppercase tracking-wider">Conversations</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-300 uppercase tracking-wider">Leads</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Created</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-300 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-gray-800 divide-y divide-gray-700">
                    <tr v-for="client in clients.data" :key="client.id">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div>
                                <Link :href="route('admin.clients.show', client.id)" class="text-sm font-medium text-white hover:text-indigo-400">
                                    {{ client.name }}
                                </Link>
                                <p class="text-xs text-gray-400">{{ client.slug }}</p>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-300">
                            {{ client.current_plan?.name || 'Free' }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span :class="[getStatusColor(client.status), 'px-2 py-1 text-xs rounded-full']">
                                {{ client.status }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-300">
                            {{ client.users_count }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-300">
                            {{ client.conversations_count }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-300">
                            {{ client.leads_count }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-400">
                            {{ formatDate(client.created_at) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                            <Link :href="route('admin.clients.show', client.id)" class="text-indigo-400 hover:text-indigo-300">
                                View
                            </Link>
                        </td>
                    </tr>
                    <tr v-if="!clients.data?.length">
                        <td colspan="8" class="px-6 py-12 text-center text-gray-400">
                            No clients found
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div v-if="clients.links?.length > 3" class="mt-4 flex justify-center">
            <nav class="flex space-x-2">
                <template v-for="link in clients.links" :key="link.label">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        :class="[
                            'px-3 py-2 text-sm rounded-md',
                            link.active ? 'bg-indigo-600 text-white' : 'bg-gray-700 text-gray-300 hover:bg-gray-600'
                        ]"
                        v-html="link.label"
                    />
                    <span
                        v-else
                        class="px-3 py-2 text-sm text-gray-500"
                        v-html="link.label"
                    />
                </template>
            </nav>
        </div>
    </AdminLayout>
</template>
