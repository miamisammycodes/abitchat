<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { useRoute } from '@/composables/useRoute';

const route = useRoute();

const props = defineProps({
    plans: Array,
    currentPlanId: Number,
});

function formatLimit(limit) {
    if (limit === -1) return 'Unlimited';
    return limit.toLocaleString();
}
</script>

<template>
    <Head title="Choose Plan" />

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
                    <h1 class="text-xl font-semibold text-gray-900">Choose a Plan</h1>
                </div>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <div class="text-center mb-8">
                <h2 class="text-3xl font-bold text-gray-900">Simple, Transparent Pricing</h2>
                <p class="mt-2 text-gray-600">Choose the plan that best fits your needs</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div
                    v-for="plan in plans"
                    :key="plan.id"
                    :class="[
                        'bg-white rounded-xl border-2 p-6 relative',
                        plan.id === currentPlanId ? 'border-blue-500' : 'border-gray-200'
                    ]"
                >
                    <!-- Current Plan Badge -->
                    <div
                        v-if="plan.id === currentPlanId"
                        class="absolute -top-3 left-1/2 -translate-x-1/2 px-3 py-1 bg-blue-500 text-white text-xs font-medium rounded-full"
                    >
                        Current Plan
                    </div>

                    <!-- Popular Badge -->
                    <div
                        v-if="plan.slug === 'pro' && plan.id !== currentPlanId"
                        class="absolute -top-3 left-1/2 -translate-x-1/2 px-3 py-1 bg-emerald-500 text-white text-xs font-medium rounded-full"
                    >
                        Most Popular
                    </div>

                    <div class="text-center mb-6">
                        <h3 class="text-xl font-bold text-gray-900">{{ plan.name }}</h3>
                        <p class="text-sm text-gray-500 mt-1">{{ plan.description }}</p>
                        <div class="mt-4">
                            <span class="text-4xl font-bold text-gray-900">
                                {{ plan.price == 0 ? 'Free' : `$${plan.price}` }}
                            </span>
                            <span v-if="plan.price > 0" class="text-gray-500">/{{ plan.billing_period }}</span>
                        </div>
                    </div>

                    <!-- Limits -->
                    <div class="space-y-3 mb-6">
                        <div class="flex items-center gap-2 text-sm">
                            <svg class="w-4 h-4 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            <span class="text-gray-600">{{ formatLimit(plan.conversations_limit) }} conversations/mo</span>
                        </div>
                        <div class="flex items-center gap-2 text-sm">
                            <svg class="w-4 h-4 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            <span class="text-gray-600">{{ formatLimit(plan.knowledge_items_limit) }} knowledge items</span>
                        </div>
                        <div class="flex items-center gap-2 text-sm">
                            <svg class="w-4 h-4 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            <span class="text-gray-600">{{ formatLimit(plan.leads_limit) }} leads/mo</span>
                        </div>
                        <div class="flex items-center gap-2 text-sm">
                            <svg class="w-4 h-4 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            <span class="text-gray-600">{{ formatLimit(plan.tokens_limit) }} AI tokens/mo</span>
                        </div>
                    </div>

                    <!-- Features -->
                    <div v-if="plan.features?.length" class="border-t border-gray-100 pt-4 mb-6">
                        <div
                            v-for="(feature, idx) in plan.features"
                            :key="idx"
                            class="flex items-center gap-2 text-sm py-1"
                        >
                            <svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            <span class="text-gray-600">{{ feature }}</span>
                        </div>
                    </div>

                    <!-- Action Button -->
                    <Link
                        v-if="plan.id !== currentPlanId"
                        :href="route('client.billing.subscribe', plan.id)"
                        :class="[
                            'block w-full text-center px-4 py-2 rounded-lg font-medium transition',
                            plan.slug === 'pro'
                                ? 'bg-blue-600 hover:bg-blue-700 text-white'
                                : 'bg-gray-100 hover:bg-gray-200 text-gray-900'
                        ]"
                    >
                        {{ plan.price == 0 ? 'Get Started' : 'Subscribe' }}
                    </Link>
                    <button
                        v-else
                        disabled
                        class="block w-full text-center px-4 py-2 rounded-lg font-medium bg-gray-100 text-gray-400 cursor-not-allowed"
                    >
                        Current Plan
                    </button>
                </div>
            </div>

            <!-- Payment Info -->
            <div class="mt-12 bg-blue-50 rounded-xl p-6 text-center">
                <h3 class="text-lg font-semibold text-blue-900 mb-2">How Payment Works</h3>
                <p class="text-blue-700 max-w-2xl mx-auto">
                    After selecting a plan, you'll submit your payment transaction details (bank transfer, UPI, etc.).
                    Our team will verify your payment and activate your plan within 24 hours.
                </p>
            </div>
        </main>
    </div>
</template>
