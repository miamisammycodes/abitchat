<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { useRoute } from '@/composables/useRoute';

const route = useRoute();

const props = defineProps({
    transactions: Object,
});

function getStatusColor(status) {
    const colors = {
        pending: 'text-amber-600 bg-amber-100',
        approved: 'text-emerald-600 bg-emerald-100',
        rejected: 'text-red-600 bg-red-100',
    };
    return colors[status] || 'text-gray-600 bg-gray-100';
}

function getPaymentMethodLabel(method) {
    const labels = {
        bank_transfer: 'Bank Transfer',
        upi: 'UPI',
        card: 'Card',
        cash: 'Cash',
        other: 'Other',
    };
    return labels[method] || method;
}
</script>

<template>
    <Head title="Transaction History" />

    <div class="min-h-screen bg-gray-50">
        <!-- Header -->
        <header class="bg-white border-b border-gray-200">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex items-center gap-4">
                    <Link :href="route('client.billing.index')" class="text-gray-500 hover:text-gray-700">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                        </svg>
                    </Link>
                    <h1 class="text-xl font-semibold text-gray-900">Transaction History</h1>
                </div>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div v-if="transactions.data.length === 0" class="p-12 text-center">
                    <svg class="w-12 h-12 mx-auto text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                    </svg>
                    <h3 class="text-lg font-medium text-gray-900 mb-1">No transactions yet</h3>
                    <p class="text-gray-500 mb-4">Your payment history will appear here.</p>
                    <Link
                        :href="route('client.billing.plans')"
                        class="inline-flex px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium"
                    >
                        View Plans
                    </Link>
                </div>

                <table v-else class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Transaction</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Plan</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Method</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <tr v-for="transaction in transactions.data" :key="transaction.id" class="hover:bg-gray-50">
                            <td class="px-6 py-4">
                                <div class="font-medium text-gray-900">{{ transaction.transaction_number }}</div>
                                <div v-if="transaction.notes" class="text-sm text-gray-500 truncate max-w-xs">
                                    {{ transaction.notes }}
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="font-medium text-gray-900">{{ transaction.plan.name }}</div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="font-medium text-gray-900">${{ transaction.amount }}</div>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-500">
                                {{ getPaymentMethodLabel(transaction.payment_method) }}
                            </td>
                            <td class="px-6 py-4">
                                <span :class="['px-2 py-1 text-xs font-medium rounded-full capitalize', getStatusColor(transaction.status)]">
                                    {{ transaction.status }}
                                </span>
                                <div v-if="transaction.admin_notes" class="text-xs text-gray-500 mt-1">
                                    {{ transaction.admin_notes }}
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-500">
                                <div>{{ new Date(transaction.payment_date).toLocaleDateString() }}</div>
                                <div class="text-xs">Submitted: {{ new Date(transaction.created_at).toLocaleDateString() }}</div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Pagination -->
                <div v-if="transactions.data.length > 0" class="px-6 py-4 border-t border-gray-200 flex items-center justify-between">
                    <div class="text-sm text-gray-500">
                        Showing {{ transactions.from }} to {{ transactions.to }} of {{ transactions.total }} transactions
                    </div>
                    <div class="flex gap-2">
                        <Link
                            v-if="transactions.prev_page_url"
                            :href="transactions.prev_page_url"
                            class="px-3 py-1 bg-gray-100 text-gray-700 rounded hover:bg-gray-200 text-sm"
                        >
                            Previous
                        </Link>
                        <Link
                            v-if="transactions.next_page_url"
                            :href="transactions.next_page_url"
                            class="px-3 py-1 bg-gray-100 text-gray-700 rounded hover:bg-gray-200 text-sm"
                        >
                            Next
                        </Link>
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>
