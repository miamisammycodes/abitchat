<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import { useRoute } from '@/composables/useRoute'

const route = useRoute()

const props = defineProps({
    client: Object,
    stats: Object,
    transactions: Array,
    recentConversations: Array,
    plans: Array,
})

const showStatusModal = ref(false)
const showPlanModal = ref(false)

const statusForm = useForm({
    status: props.client.status,
})

const planForm = useForm({
    plan_id: props.client.plan_id || '',
    expires_at: props.client.plan_expires_at?.split('T')[0] || '',
})

const updateStatus = () => {
    statusForm.put(route('admin.clients.update-status', props.client.id), {
        onSuccess: () => {
            showStatusModal.value = false
        },
    })
}

const updatePlan = () => {
    planForm.put(route('admin.clients.update-plan', props.client.id), {
        onSuccess: () => {
            showPlanModal.value = false
        },
    })
}

const formatNumber = (num) => {
    return num?.toLocaleString() || '0'
}

const formatDate = (date) => {
    if (!date) return 'N/A'
    return new Date(date).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    })
}

const formatCurrency = (amount) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(amount || 0)
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
    <AdminLayout :title="client.name">
        <!-- Header -->
        <div class="mb-6 flex items-center justify-between">
            <div>
                <Link :href="route('admin.clients.index')" class="text-sm text-gray-400 hover:text-white mb-2 inline-block">
                    &larr; Back to Clients
                </Link>
                <h2 class="text-2xl font-bold text-white">{{ client.name }}</h2>
                <p class="text-sm text-gray-400">{{ client.slug }}</p>
            </div>
            <div class="flex space-x-3">
                <button
                    @click="showStatusModal = true"
                    class="px-4 py-2 bg-gray-700 text-white text-sm rounded-md hover:bg-gray-600"
                >
                    Change Status
                </button>
                <button
                    @click="showPlanModal = true"
                    class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700"
                >
                    Change Plan
                </button>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4 mb-8">
            <div class="bg-gray-800 overflow-hidden shadow rounded-lg p-5">
                <p class="text-sm font-medium text-gray-400">Status</p>
                <span :class="[getStatusColor(client.status), 'px-3 py-1 text-sm rounded-full inline-block mt-1']">
                    {{ client.status }}
                </span>
            </div>
            <div class="bg-gray-800 overflow-hidden shadow rounded-lg p-5">
                <p class="text-sm font-medium text-gray-400">Current Plan</p>
                <p class="text-xl font-semibold text-white mt-1">{{ client.current_plan?.name || 'Free' }}</p>
                <p v-if="client.plan_expires_at" class="text-xs text-gray-400">Expires {{ formatDate(client.plan_expires_at) }}</p>
            </div>
            <div class="bg-gray-800 overflow-hidden shadow rounded-lg p-5">
                <p class="text-sm font-medium text-gray-400">Users</p>
                <p class="text-xl font-semibold text-white mt-1">{{ client.users?.length || 0 }}</p>
            </div>
            <div class="bg-gray-800 overflow-hidden shadow rounded-lg p-5">
                <p class="text-sm font-medium text-gray-400">Created</p>
                <p class="text-xl font-semibold text-white mt-1">{{ formatDate(client.created_at) }}</p>
            </div>
        </div>

        <!-- Usage Stats -->
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-3 mb-8">
            <div class="bg-gray-800 overflow-hidden shadow rounded-lg p-5">
                <div class="flex justify-between items-center">
                    <div>
                        <p class="text-sm font-medium text-gray-400">Conversations</p>
                        <p class="text-2xl font-semibold text-white">{{ formatNumber(stats.conversations.total) }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs text-gray-400">This Month</p>
                        <p class="text-lg font-medium text-blue-400">{{ stats.conversations.thisMonth }}</p>
                    </div>
                </div>
            </div>
            <div class="bg-gray-800 overflow-hidden shadow rounded-lg p-5">
                <div class="flex justify-between items-center">
                    <div>
                        <p class="text-sm font-medium text-gray-400">Leads</p>
                        <p class="text-2xl font-semibold text-white">{{ formatNumber(stats.leads.total) }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs text-gray-400">This Month</p>
                        <p class="text-lg font-medium text-emerald-400">{{ stats.leads.thisMonth }}</p>
                    </div>
                </div>
            </div>
            <div class="bg-gray-800 overflow-hidden shadow rounded-lg p-5">
                <div class="flex justify-between items-center">
                    <div>
                        <p class="text-sm font-medium text-gray-400">Tokens Used</p>
                        <p class="text-2xl font-semibold text-white">{{ formatNumber(stats.tokens.total) }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs text-gray-400">This Month</p>
                        <p class="text-lg font-medium text-indigo-400">{{ formatNumber(stats.tokens.thisMonth) }}</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Users & Transactions -->
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2 mb-8">
            <!-- Users -->
            <div class="bg-gray-800 shadow rounded-lg">
                <div class="px-4 py-5 sm:px-6 border-b border-gray-700">
                    <h3 class="text-lg font-medium text-white">Team Members</h3>
                </div>
                <ul class="divide-y divide-gray-700">
                    <li v-for="user in client.users" :key="user.id" class="px-4 py-4">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-white">{{ user.name }}</p>
                                <p class="text-xs text-gray-400">{{ user.email }}</p>
                            </div>
                            <span class="px-2 py-1 text-xs rounded-full bg-gray-700 text-gray-300">
                                {{ user.role || 'user' }}
                            </span>
                        </div>
                    </li>
                    <li v-if="!client.users?.length" class="px-4 py-8 text-center text-gray-400">
                        No users
                    </li>
                </ul>
            </div>

            <!-- Transactions -->
            <div class="bg-gray-800 shadow rounded-lg">
                <div class="px-4 py-5 sm:px-6 border-b border-gray-700">
                    <h3 class="text-lg font-medium text-white">Recent Transactions</h3>
                </div>
                <ul class="divide-y divide-gray-700">
                    <li v-for="txn in transactions" :key="txn.id" class="px-4 py-4">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-white">{{ txn.plan?.name }}</p>
                                <p class="text-xs text-gray-400">{{ formatCurrency(txn.amount) }} - {{ formatDate(txn.created_at) }}</p>
                            </div>
                            <span :class="[getStatusColor(txn.status), 'px-2 py-1 text-xs rounded-full']">
                                {{ txn.status }}
                            </span>
                        </div>
                    </li>
                    <li v-if="!transactions?.length" class="px-4 py-8 text-center text-gray-400">
                        No transactions
                    </li>
                </ul>
            </div>
        </div>

        <!-- Status Modal -->
        <div v-if="showStatusModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <div class="bg-gray-800 rounded-lg p-6 w-full max-w-md">
                <h3 class="text-lg font-medium text-white mb-4">Change Client Status</h3>
                <form @submit.prevent="updateStatus">
                    <select
                        v-model="statusForm.status"
                        class="w-full bg-gray-700 border-gray-600 text-white rounded-md mb-4"
                    >
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="suspended">Suspended</option>
                    </select>
                    <div class="flex justify-end space-x-3">
                        <button
                            type="button"
                            @click="showStatusModal = false"
                            class="px-4 py-2 text-sm text-gray-300 hover:text-white"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            :disabled="statusForm.processing"
                            class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700 disabled:opacity-50"
                        >
                            Update Status
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Plan Modal -->
        <div v-if="showPlanModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <div class="bg-gray-800 rounded-lg p-6 w-full max-w-md">
                <h3 class="text-lg font-medium text-white mb-4">Change Client Plan</h3>
                <form @submit.prevent="updatePlan">
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-300 mb-1">Plan</label>
                        <select
                            v-model="planForm.plan_id"
                            class="w-full bg-gray-700 border-gray-600 text-white rounded-md"
                        >
                            <option value="">Select a plan</option>
                            <option v-for="plan in plans" :key="plan.id" :value="plan.id">
                                {{ plan.name }} - {{ formatCurrency(plan.price) }}/{{ plan.billing_period }}
                            </option>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-300 mb-1">Expires At</label>
                        <input
                            v-model="planForm.expires_at"
                            type="date"
                            class="w-full bg-gray-700 border-gray-600 text-white rounded-md"
                        />
                    </div>
                    <div class="flex justify-end space-x-3">
                        <button
                            type="button"
                            @click="showPlanModal = false"
                            class="px-4 py-2 text-sm text-gray-300 hover:text-white"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            :disabled="planForm.processing"
                            class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700 disabled:opacity-50"
                        >
                            Update Plan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AdminLayout>
</template>
