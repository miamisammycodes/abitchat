<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useRoute } from '@/composables/useRoute';

const route = useRoute();

const props = defineProps({
    plan: Object,
    pendingTransaction: Object,
});

const form = useForm({
    transaction_number: '',
    amount: props.plan.price,
    payment_method: 'bank_transfer',
    payment_date: new Date().toISOString().split('T')[0],
    notes: '',
});

function submit() {
    form.post(route('client.billing.submit-payment', props.plan.id));
}

const paymentMethods = [
    { value: 'bank_transfer', label: 'Bank Transfer' },
    { value: 'upi', label: 'UPI' },
    { value: 'card', label: 'Card' },
    { value: 'cash', label: 'Cash' },
    { value: 'other', label: 'Other' },
];
</script>

<template>
    <Head :title="`Subscribe to ${plan.name}`" />

    <div class="min-h-screen bg-gray-50">
        <!-- Header -->
        <header class="bg-white border-b border-gray-200">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex items-center gap-4">
                    <Link :href="route('client.billing.plans')" class="text-gray-500 hover:text-gray-700">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                        </svg>
                    </Link>
                    <h1 class="text-xl font-semibold text-gray-900">Subscribe to {{ plan.name }}</h1>
                </div>
            </div>
        </header>

        <main class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <!-- Pending Transaction Warning -->
            <div v-if="pendingTransaction" class="mb-6 bg-amber-50 border border-amber-200 rounded-lg p-4">
                <div class="flex items-start gap-3">
                    <svg class="w-5 h-5 text-amber-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <div>
                        <p class="font-medium text-amber-800">You have a pending payment</p>
                        <p class="text-sm text-amber-700 mt-1">
                            Transaction #{{ pendingTransaction.transaction_number }} is awaiting verification.
                            Submitted on {{ new Date(pendingTransaction.created_at).toLocaleDateString() }}.
                        </p>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Plan Summary -->
                <div class="bg-white rounded-xl border border-gray-200 p-6 h-fit">
                    <h2 class="text-lg font-semibold text-gray-900 mb-4">Plan Summary</h2>

                    <div class="text-center pb-4 border-b border-gray-100">
                        <h3 class="text-xl font-bold text-gray-900">{{ plan.name }}</h3>
                        <p class="text-sm text-gray-500 mt-1">{{ plan.description }}</p>
                    </div>

                    <div class="py-4 border-b border-gray-100">
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">Price</span>
                            <span class="text-2xl font-bold text-gray-900">${{ plan.price }}</span>
                        </div>
                        <p class="text-sm text-gray-500 text-right">per {{ plan.billing_period }}</p>
                    </div>

                    <div class="pt-4 space-y-2 text-sm">
                        <div class="flex items-center gap-2">
                            <svg class="w-4 h-4 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            <span class="text-gray-600">{{ plan.conversations_limit === -1 ? 'Unlimited' : plan.conversations_limit }} conversations</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <svg class="w-4 h-4 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            <span class="text-gray-600">{{ plan.knowledge_items_limit === -1 ? 'Unlimited' : plan.knowledge_items_limit }} knowledge items</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <svg class="w-4 h-4 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            <span class="text-gray-600">{{ plan.leads_limit === -1 ? 'Unlimited' : plan.leads_limit }} leads</span>
                        </div>
                    </div>
                </div>

                <!-- Payment Form -->
                <div class="md:col-span-2 bg-white rounded-xl border border-gray-200 p-6">
                    <h2 class="text-lg font-semibold text-gray-900 mb-4">Payment Details</h2>

                    <div class="bg-blue-50 rounded-lg p-4 mb-6">
                        <h3 class="font-medium text-blue-900 mb-2">Payment Instructions</h3>
                        <ol class="text-sm text-blue-700 space-y-1 list-decimal list-inside">
                            <li>Make payment via bank transfer, UPI, or card</li>
                            <li>Note the transaction/reference number</li>
                            <li>Fill in the form below with your payment details</li>
                            <li>We'll verify and activate your plan within 24 hours</li>
                        </ol>
                    </div>

                    <form @submit.prevent="submit" class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Transaction/Reference Number *
                            </label>
                            <input
                                v-model="form.transaction_number"
                                type="text"
                                required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg bg-white text-gray-900"
                                placeholder="e.g., TXN123456789"
                            />
                            <p v-if="form.errors.transaction_number" class="mt-1 text-sm text-red-600">
                                {{ form.errors.transaction_number }}
                            </p>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    Amount Paid *
                                </label>
                                <div class="relative">
                                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500">$</span>
                                    <input
                                        v-model="form.amount"
                                        type="number"
                                        step="0.01"
                                        required
                                        class="w-full pl-8 pr-4 py-2 border border-gray-300 rounded-lg bg-white text-gray-900"
                                    />
                                </div>
                                <p v-if="form.errors.amount" class="mt-1 text-sm text-red-600">
                                    {{ form.errors.amount }}
                                </p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    Payment Date *
                                </label>
                                <input
                                    v-model="form.payment_date"
                                    type="date"
                                    required
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg bg-white text-gray-900"
                                />
                                <p v-if="form.errors.payment_date" class="mt-1 text-sm text-red-600">
                                    {{ form.errors.payment_date }}
                                </p>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Payment Method *
                            </label>
                            <select
                                v-model="form.payment_method"
                                required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg bg-white text-gray-900"
                            >
                                <option v-for="method in paymentMethods" :key="method.value" :value="method.value">
                                    {{ method.label }}
                                </option>
                            </select>
                            <p v-if="form.errors.payment_method" class="mt-1 text-sm text-red-600">
                                {{ form.errors.payment_method }}
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Notes (Optional)
                            </label>
                            <textarea
                                v-model="form.notes"
                                rows="3"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg bg-white text-gray-900"
                                placeholder="Any additional information about your payment..."
                            ></textarea>
                        </div>

                        <div class="flex items-center gap-4 pt-4">
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="flex-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:bg-blue-400 text-white font-medium rounded-lg transition"
                            >
                                {{ form.processing ? 'Submitting...' : 'Submit Payment' }}
                            </button>
                            <Link
                                :href="route('client.billing.plans')"
                                class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 font-medium rounded-lg transition"
                            >
                                Cancel
                            </Link>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>
</template>
