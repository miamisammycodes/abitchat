<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { useRoute } from '@/composables/useRoute';
import { computed } from 'vue';

const route = useRoute();
const page = usePage();

const props = defineProps({
    tenant: Object,
    currentPlan: Object,
    usage: Object,
    planExpired: Boolean,
    transactions: Array,
});

function getUsagePercent(used, limit) {
    if (limit === -1) return 0; // Unlimited
    if (limit === 0) return 100;
    return Math.min(Math.round((used / limit) * 100), 100);
}

function getUsageColor(used, limit) {
    if (limit === -1) return 'bg-emerald-500';
    const percent = (used / limit) * 100;
    if (percent >= 90) return 'bg-red-500';
    if (percent >= 70) return 'bg-amber-500';
    return 'bg-emerald-500';
}

function formatLimit(limit) {
    if (limit === -1) return 'Unlimited';
    return limit.toLocaleString();
}

function getStatusColor(status) {
    const colors = {
        pending: 'text-amber-600 bg-amber-100',
        approved: 'text-emerald-600 bg-emerald-100',
        rejected: 'text-red-600 bg-red-100',
    };
    return colors[status] || 'text-gray-600 bg-gray-100';
}
</script>

<template>
    <Head title="Billing" />

    <div class="min-h-screen bg-gray-50">
        <!-- Header -->
        <header class="bg-white border-b border-gray-200">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <Link :href="route('dashboard')" class="text-gray-500 hover:text-gray-700">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                            </svg>
                        </Link>
                        <h1 class="text-xl font-semibold text-gray-900">Billing</h1>
                    </div>
                    <Link
                        :href="route('client.billing.plans')"
                        class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition text-sm font-medium"
                    >
                        View Plans
                    </Link>
                </div>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <!-- Success Message -->
            <div v-if="page.props.flash?.success" class="mb-6 bg-emerald-50 border border-emerald-200 rounded-lg p-4">
                <p class="text-emerald-800">{{ page.props.flash.success }}</p>
            </div>

            <!-- Plan Expired Warning -->
            <div v-if="planExpired" class="mb-6 bg-red-50 border border-red-200 rounded-lg p-4">
                <div class="flex items-center gap-3">
                    <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <div>
                        <p class="font-medium text-red-800">Your plan has expired</p>
                        <p class="text-sm text-red-600">Please renew your subscription to continue using all features.</p>
                    </div>
                    <Link
                        :href="route('client.billing.plans')"
                        class="ml-auto px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm font-medium"
                    >
                        Renew Now
                    </Link>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Current Plan -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Plan Card -->
                    <div class="bg-white rounded-xl border border-gray-200 p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-4">Current Plan</h2>

                        <div v-if="currentPlan" class="flex items-start justify-between">
                            <div>
                                <h3 class="text-2xl font-bold text-gray-900">{{ currentPlan.name }}</h3>
                                <p class="text-gray-500 mt-1">{{ currentPlan.description }}</p>
                                <p v-if="tenant.plan_expires_at" class="text-sm mt-2" :class="planExpired ? 'text-red-600' : 'text-gray-500'">
                                    {{ planExpired ? 'Expired' : 'Expires' }}: {{ new Date(tenant.plan_expires_at).toLocaleDateString() }}
                                </p>
                            </div>
                            <div class="text-right">
                                <p class="text-3xl font-bold text-gray-900">
                                    {{ currentPlan.price == 0 ? 'Free' : `$${currentPlan.price}` }}
                                </p>
                                <p v-if="currentPlan.price > 0" class="text-sm text-gray-500">/{{ currentPlan.billing_period }}</p>
                            </div>
                        </div>
                        <div v-else class="text-center py-8">
                            <p class="text-gray-500 mb-4">No active plan</p>
                            <Link
                                :href="route('client.billing.plans')"
                                class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium"
                            >
                                Choose a Plan
                            </Link>
                        </div>
                    </div>

                    <!-- Usage Stats -->
                    <div class="bg-white rounded-xl border border-gray-200 p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-4">Usage This Month</h2>

                        <div class="space-y-4">
                            <!-- Conversations -->
                            <div>
                                <div class="flex justify-between text-sm mb-1">
                                    <span class="text-gray-600">Conversations</span>
                                    <span class="font-medium text-gray-900">
                                        {{ usage.conversations.used.toLocaleString() }} / {{ formatLimit(usage.conversations.limit) }}
                                    </span>
                                </div>
                                <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
                                    <div
                                        :class="['h-full rounded-full', getUsageColor(usage.conversations.used, usage.conversations.limit)]"
                                        :style="{ width: `${getUsagePercent(usage.conversations.used, usage.conversations.limit)}%` }"
                                    ></div>
                                </div>
                            </div>

                            <!-- Knowledge Items -->
                            <div>
                                <div class="flex justify-between text-sm mb-1">
                                    <span class="text-gray-600">Knowledge Items</span>
                                    <span class="font-medium text-gray-900">
                                        {{ usage.knowledge_items.used.toLocaleString() }} / {{ formatLimit(usage.knowledge_items.limit) }}
                                    </span>
                                </div>
                                <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
                                    <div
                                        :class="['h-full rounded-full', getUsageColor(usage.knowledge_items.used, usage.knowledge_items.limit)]"
                                        :style="{ width: `${getUsagePercent(usage.knowledge_items.used, usage.knowledge_items.limit)}%` }"
                                    ></div>
                                </div>
                            </div>

                            <!-- Leads -->
                            <div>
                                <div class="flex justify-between text-sm mb-1">
                                    <span class="text-gray-600">Leads Captured</span>
                                    <span class="font-medium text-gray-900">
                                        {{ usage.leads.used.toLocaleString() }} / {{ formatLimit(usage.leads.limit) }}
                                    </span>
                                </div>
                                <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
                                    <div
                                        :class="['h-full rounded-full', getUsageColor(usage.leads.used, usage.leads.limit)]"
                                        :style="{ width: `${getUsagePercent(usage.leads.used, usage.leads.limit)}%` }"
                                    ></div>
                                </div>
                            </div>

                            <!-- Tokens -->
                            <div>
                                <div class="flex justify-between text-sm mb-1">
                                    <span class="text-gray-600">AI Tokens</span>
                                    <span class="font-medium text-gray-900">
                                        {{ usage.tokens.used.toLocaleString() }} / {{ formatLimit(usage.tokens.limit) }}
                                    </span>
                                </div>
                                <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
                                    <div
                                        :class="['h-full rounded-full', getUsageColor(usage.tokens.used, usage.tokens.limit)]"
                                        :style="{ width: `${getUsagePercent(usage.tokens.used, usage.tokens.limit)}%` }"
                                    ></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Transactions -->
                <div class="bg-white rounded-xl border border-gray-200 p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-lg font-semibold text-gray-900">Recent Transactions</h2>
                        <Link
                            :href="route('client.billing.transactions')"
                            class="text-sm text-blue-600 hover:text-blue-700"
                        >
                            View All
                        </Link>
                    </div>

                    <div v-if="transactions.length === 0" class="text-center py-8 text-gray-500">
                        No transactions yet
                    </div>

                    <div v-else class="space-y-3">
                        <div
                            v-for="transaction in transactions"
                            :key="transaction.id"
                            class="flex items-center justify-between p-3 bg-gray-50 rounded-lg"
                        >
                            <div>
                                <p class="font-medium text-gray-900 text-sm">{{ transaction.plan.name }}</p>
                                <p class="text-xs text-gray-500">{{ new Date(transaction.created_at).toLocaleDateString() }}</p>
                            </div>
                            <div class="text-right">
                                <p class="font-medium text-gray-900 text-sm">${{ transaction.amount }}</p>
                                <span :class="['text-xs px-2 py-0.5 rounded-full capitalize', getStatusColor(transaction.status)]">
                                    {{ transaction.status }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>
