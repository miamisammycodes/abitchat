<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import { useRoute } from '@/composables/useRoute'
import { debounce } from 'lodash'

const route = useRoute()

const props = defineProps({
    transactions: Object,
    counts: Object,
    filters: Object,
})

const search = ref(props.filters.search)
const status = ref(props.filters.status)

const selectedTransaction = ref(null)
const showApproveModal = ref(false)
const showRejectModal = ref(false)

const approveForm = useForm({
    admin_notes: '',
})

const rejectForm = useForm({
    admin_notes: '',
})

const applyFilters = debounce(() => {
    router.get(route('admin.transactions.index'), {
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
    router.get(route('admin.transactions.index'), {
        search: search.value,
        status: newStatus,
    }, {
        preserveState: true,
    })
}

const openApproveModal = (txn) => {
    selectedTransaction.value = txn
    showApproveModal.value = true
}

const openRejectModal = (txn) => {
    selectedTransaction.value = txn
    showRejectModal.value = true
}

const approveTransaction = () => {
    approveForm.post(route('admin.transactions.approve', selectedTransaction.value.id), {
        onSuccess: () => {
            showApproveModal.value = false
            approveForm.reset()
        },
    })
}

const rejectTransaction = () => {
    rejectForm.post(route('admin.transactions.reject', selectedTransaction.value.id), {
        onSuccess: () => {
            showRejectModal.value = false
            rejectForm.reset()
        },
    })
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
        pending: 'bg-amber-900 text-amber-200',
        approved: 'bg-emerald-900 text-emerald-200',
        rejected: 'bg-red-900 text-red-200',
    }
    return colors[status] || 'bg-gray-700 text-gray-300'
}

const getPaymentMethodLabel = (method) => {
    const labels = {
        bank_transfer: 'Bank Transfer',
        upi: 'UPI',
        card: 'Card',
        cash: 'Cash',
        other: 'Other',
    }
    return labels[method] || method
}
</script>

<template>
    <AdminLayout title="Transactions">
        <!-- Tabs -->
        <div class="mb-6 border-b border-gray-700">
            <nav class="flex space-x-8">
                <button
                    @click="filterByStatus('all')"
                    :class="[
                        status === 'all' ? 'border-indigo-500 text-indigo-400' : 'border-transparent text-gray-400 hover:text-gray-300',
                        'py-4 px-1 border-b-2 font-medium text-sm'
                    ]"
                >
                    All ({{ counts.all }})
                </button>
                <button
                    @click="filterByStatus('pending')"
                    :class="[
                        status === 'pending' ? 'border-amber-500 text-amber-400' : 'border-transparent text-gray-400 hover:text-gray-300',
                        'py-4 px-1 border-b-2 font-medium text-sm'
                    ]"
                >
                    Pending ({{ counts.pending }})
                </button>
                <button
                    @click="filterByStatus('approved')"
                    :class="[
                        status === 'approved' ? 'border-emerald-500 text-emerald-400' : 'border-transparent text-gray-400 hover:text-gray-300',
                        'py-4 px-1 border-b-2 font-medium text-sm'
                    ]"
                >
                    Approved ({{ counts.approved }})
                </button>
                <button
                    @click="filterByStatus('rejected')"
                    :class="[
                        status === 'rejected' ? 'border-red-500 text-red-400' : 'border-transparent text-gray-400 hover:text-gray-300',
                        'py-4 px-1 border-b-2 font-medium text-sm'
                    ]"
                >
                    Rejected ({{ counts.rejected }})
                </button>
            </nav>
        </div>

        <!-- Search -->
        <div class="mb-6">
            <input
                v-model="search"
                type="text"
                placeholder="Search by transaction number or client name..."
                class="w-full max-w-md bg-gray-700 border-gray-600 text-white rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            />
        </div>

        <!-- Table -->
        <div class="bg-gray-800 shadow rounded-lg overflow-hidden">
            <table class="min-w-full divide-y divide-gray-700">
                <thead class="bg-gray-700">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Client</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Plan</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Transaction #</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Method</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-300 uppercase tracking-wider">Amount</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-300 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-gray-800 divide-y divide-gray-700">
                    <tr v-for="txn in transactions.data" :key="txn.id">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <Link :href="route('admin.clients.show', txn.tenant?.id)" class="text-sm font-medium text-white hover:text-indigo-400">
                                {{ txn.tenant?.name }}
                            </Link>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-300">
                            {{ txn.plan?.name }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="text-sm text-gray-300 font-mono">{{ txn.transaction_number }}</span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-300">
                            {{ getPaymentMethodLabel(txn.payment_method) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-white font-medium">
                            {{ formatCurrency(txn.amount) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-400">
                            {{ formatDate(txn.payment_date) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span :class="[getStatusColor(txn.status), 'px-2 py-1 text-xs rounded-full']">
                                {{ txn.status }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm space-x-2">
                            <template v-if="txn.status === 'pending'">
                                <button
                                    @click="openApproveModal(txn)"
                                    class="text-emerald-400 hover:text-emerald-300"
                                >
                                    Approve
                                </button>
                                <button
                                    @click="openRejectModal(txn)"
                                    class="text-red-400 hover:text-red-300"
                                >
                                    Reject
                                </button>
                            </template>
                            <span v-else class="text-gray-500">-</span>
                        </td>
                    </tr>
                    <tr v-if="!transactions.data?.length">
                        <td colspan="8" class="px-6 py-12 text-center text-gray-400">
                            No transactions found
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div v-if="transactions.links?.length > 3" class="mt-4 flex justify-center">
            <nav class="flex space-x-2">
                <template v-for="link in transactions.links" :key="link.label">
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

        <!-- Approve Modal -->
        <div v-if="showApproveModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <div class="bg-gray-800 rounded-lg p-6 w-full max-w-md">
                <h3 class="text-lg font-medium text-white mb-4">Approve Transaction</h3>
                <div class="mb-4 p-4 bg-gray-700 rounded-md">
                    <p class="text-sm text-gray-300"><strong>Client:</strong> {{ selectedTransaction?.tenant?.name }}</p>
                    <p class="text-sm text-gray-300"><strong>Plan:</strong> {{ selectedTransaction?.plan?.name }}</p>
                    <p class="text-sm text-gray-300"><strong>Amount:</strong> {{ formatCurrency(selectedTransaction?.amount) }}</p>
                    <p class="text-sm text-gray-300"><strong>Transaction #:</strong> {{ selectedTransaction?.transaction_number }}</p>
                </div>
                <form @submit.prevent="approveTransaction">
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-300 mb-1">Admin Notes (Optional)</label>
                        <textarea
                            v-model="approveForm.admin_notes"
                            rows="3"
                            class="w-full bg-gray-700 border-gray-600 text-white rounded-md"
                            placeholder="Add any notes..."
                        ></textarea>
                    </div>
                    <div class="flex justify-end space-x-3">
                        <button
                            type="button"
                            @click="showApproveModal = false"
                            class="px-4 py-2 text-sm text-gray-300 hover:text-white"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            :disabled="approveForm.processing"
                            class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700 disabled:opacity-50"
                        >
                            Approve & Activate Plan
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Reject Modal -->
        <div v-if="showRejectModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <div class="bg-gray-800 rounded-lg p-6 w-full max-w-md">
                <h3 class="text-lg font-medium text-white mb-4">Reject Transaction</h3>
                <div class="mb-4 p-4 bg-gray-700 rounded-md">
                    <p class="text-sm text-gray-300"><strong>Client:</strong> {{ selectedTransaction?.tenant?.name }}</p>
                    <p class="text-sm text-gray-300"><strong>Transaction #:</strong> {{ selectedTransaction?.transaction_number }}</p>
                </div>
                <form @submit.prevent="rejectTransaction">
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-300 mb-1">Reason for Rejection *</label>
                        <textarea
                            v-model="rejectForm.admin_notes"
                            rows="3"
                            class="w-full bg-gray-700 border-gray-600 text-white rounded-md"
                            placeholder="Please provide a reason..."
                            required
                        ></textarea>
                        <p v-if="rejectForm.errors.admin_notes" class="text-red-400 text-sm mt-1">{{ rejectForm.errors.admin_notes }}</p>
                    </div>
                    <div class="flex justify-end space-x-3">
                        <button
                            type="button"
                            @click="showRejectModal = false"
                            class="px-4 py-2 text-sm text-gray-300 hover:text-white"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            :disabled="rejectForm.processing"
                            class="px-4 py-2 bg-red-600 text-white text-sm rounded-md hover:bg-red-700 disabled:opacity-50"
                        >
                            Reject Transaction
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AdminLayout>
</template>
