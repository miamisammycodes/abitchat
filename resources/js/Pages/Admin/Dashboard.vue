<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link } from '@inertiajs/vue3'
import { useRoute } from '@/composables/useRoute'

const route = useRoute()

defineProps({
    stats: Object,
    recentTenants: Array,
    recentTransactions: Array,
    topClients: Array,
})

const formatNumber = (num) => {
    if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M'
    if (num >= 1000) return (num / 1000).toFixed(1) + 'K'
    return num?.toLocaleString() || '0'
}

const formatCurrency = (amount) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(amount || 0)
}

const formatDate = (date) => {
    return new Date(date).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    })
}

const getStatusColor = (status) => {
    const colors = {
        active: 'bg-emerald-900 text-emerald-200',
        inactive: 'bg-gray-700 text-gray-300',
        suspended: 'bg-red-900 text-red-200',
        pending: 'bg-amber-900 text-amber-200',
        approved: 'bg-emerald-900 text-emerald-200',
        rejected: 'bg-red-900 text-red-200',
    }
    return colors[status] || 'bg-gray-700 text-gray-300'
}
</script>

<template>
    <AdminLayout title="Dashboard">
        <!-- Stats Grid -->
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
            <!-- Tenants -->
            <div class="bg-gray-800 overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <svg class="h-6 w-6 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                            </svg>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-400 truncate">Total Clients</dt>
                                <dd class="flex items-baseline">
                                    <span class="text-2xl font-semibold text-white">{{ stats.tenants.total }}</span>
                                    <span class="ml-2 text-sm text-emerald-400">{{ stats.tenants.active }} active</span>
                                </dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Conversations -->
            <div class="bg-gray-800 overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <svg class="h-6 w-6 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                            </svg>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-400 truncate">Conversations</dt>
                                <dd class="flex items-baseline">
                                    <span class="text-2xl font-semibold text-white">{{ formatNumber(stats.conversations.total) }}</span>
                                    <span class="ml-2 text-sm text-blue-400">{{ stats.conversations.today }} today</span>
                                </dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Revenue -->
            <div class="bg-gray-800 overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <svg class="h-6 w-6 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-400 truncate">Revenue</dt>
                                <dd class="flex items-baseline">
                                    <span class="text-2xl font-semibold text-white">{{ formatCurrency(stats.revenue.thisMonth) }}</span>
                                    <span class="ml-2 text-xs text-gray-400">this month</span>
                                </dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pending Transactions -->
            <div class="bg-gray-800 overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <svg class="h-6 w-6 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-400 truncate">Pending Approvals</dt>
                                <dd class="flex items-baseline">
                                    <span class="text-2xl font-semibold text-white">{{ stats.pendingTransactions }}</span>
                                    <Link
                                        v-if="stats.pendingTransactions > 0"
                                        :href="route('admin.transactions.index', { status: 'pending' })"
                                        class="ml-2 text-sm text-amber-400 hover:text-amber-300"
                                    >
                                        Review
                                    </Link>
                                </dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Secondary Stats -->
        <div class="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-3">
            <!-- Leads -->
            <div class="bg-gray-800 overflow-hidden shadow rounded-lg p-5">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-400">Total Leads</p>
                        <p class="text-xl font-semibold text-white">{{ formatNumber(stats.leads.total) }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm text-gray-400">This Week</p>
                        <p class="text-lg font-medium text-emerald-400">+{{ stats.leads.thisWeek }}</p>
                    </div>
                </div>
            </div>

            <!-- Tokens -->
            <div class="bg-gray-800 overflow-hidden shadow rounded-lg p-5">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-400">Total Tokens Used</p>
                        <p class="text-xl font-semibold text-white">{{ formatNumber(stats.tokens.total) }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm text-gray-400">This Month</p>
                        <p class="text-lg font-medium text-blue-400">{{ formatNumber(stats.tokens.thisMonth) }}</p>
                    </div>
                </div>
            </div>

            <!-- Total Revenue -->
            <div class="bg-gray-800 overflow-hidden shadow rounded-lg p-5">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-400">Total Revenue</p>
                        <p class="text-xl font-semibold text-white">{{ formatCurrency(stats.revenue.total) }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm text-gray-400">Users</p>
                        <p class="text-lg font-medium text-indigo-400">{{ stats.users }}</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tables -->
        <div class="mt-8 grid grid-cols-1 gap-6 lg:grid-cols-2">
            <!-- Recent Clients -->
            <div class="bg-gray-800 shadow rounded-lg">
                <div class="px-4 py-5 sm:px-6 border-b border-gray-700 flex justify-between items-center">
                    <h3 class="text-lg font-medium text-white">Recent Clients</h3>
                    <Link :href="route('admin.clients.index')" class="text-sm text-indigo-400 hover:text-indigo-300">
                        View all
                    </Link>
                </div>
                <ul class="divide-y divide-gray-700">
                    <li v-for="tenant in recentTenants" :key="tenant.id" class="px-4 py-4">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-white">{{ tenant.name }}</p>
                                <p class="text-xs text-gray-400">{{ formatDate(tenant.created_at) }}</p>
                            </div>
                            <span :class="[getStatusColor(tenant.status), 'px-2 py-1 text-xs rounded-full']">
                                {{ tenant.status }}
                            </span>
                        </div>
                    </li>
                    <li v-if="!recentTenants?.length" class="px-4 py-8 text-center text-gray-400">
                        No clients yet
                    </li>
                </ul>
            </div>

            <!-- Recent Transactions -->
            <div class="bg-gray-800 shadow rounded-lg">
                <div class="px-4 py-5 sm:px-6 border-b border-gray-700 flex justify-between items-center">
                    <h3 class="text-lg font-medium text-white">Recent Transactions</h3>
                    <Link :href="route('admin.transactions.index')" class="text-sm text-indigo-400 hover:text-indigo-300">
                        View all
                    </Link>
                </div>
                <ul class="divide-y divide-gray-700">
                    <li v-for="txn in recentTransactions" :key="txn.id" class="px-4 py-4">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-white">{{ txn.tenant?.name }}</p>
                                <p class="text-xs text-gray-400">{{ txn.plan?.name }} - {{ formatCurrency(txn.amount) }}</p>
                            </div>
                            <span :class="[getStatusColor(txn.status), 'px-2 py-1 text-xs rounded-full']">
                                {{ txn.status }}
                            </span>
                        </div>
                    </li>
                    <li v-if="!recentTransactions?.length" class="px-4 py-8 text-center text-gray-400">
                        No transactions yet
                    </li>
                </ul>
            </div>
        </div>

        <!-- Top Clients -->
        <div class="mt-6 bg-gray-800 shadow rounded-lg">
            <div class="px-4 py-5 sm:px-6 border-b border-gray-700">
                <h3 class="text-lg font-medium text-white">Top Clients by Conversations</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-700">
                    <thead class="bg-gray-700">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Client</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-300 uppercase tracking-wider">Conversations</th>
                        </tr>
                    </thead>
                    <tbody class="bg-gray-800 divide-y divide-gray-700">
                        <tr v-for="client in topClients" :key="client.id">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <Link :href="route('admin.clients.show', client.id)" class="text-sm font-medium text-white hover:text-indigo-400">
                                    {{ client.name }}
                                </Link>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-300">
                                {{ client.conversations_count }}
                            </td>
                        </tr>
                        <tr v-if="!topClients?.length">
                            <td colspan="2" class="px-6 py-8 text-center text-gray-400">
                                No data yet
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AdminLayout>
</template>
