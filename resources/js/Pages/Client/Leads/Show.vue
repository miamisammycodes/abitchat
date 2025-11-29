<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useRoute } from '@/composables/useRoute';

const route = useRoute();

const props = defineProps({
    lead: Object,
});

const showStatusModal = ref(false);
const showScoreModal = ref(false);

const statusForm = useForm({
    status: props.lead.status,
});

const scoreForm = useForm({
    score_adjustment: 0,
    score_reason: '',
});

const statuses = [
    { value: 'new', label: 'New', color: 'blue' },
    { value: 'contacted', label: 'Contacted', color: 'purple' },
    { value: 'qualified', label: 'Qualified', color: 'emerald' },
    { value: 'converted', label: 'Converted', color: 'green' },
    { value: 'lost', label: 'Lost', color: 'neutral' },
];

function getScoreColor(score) {
    if (score >= 80) return 'text-emerald-600 bg-emerald-100';
    if (score >= 60) return 'text-amber-600 bg-amber-100';
    if (score >= 40) return 'text-blue-600 bg-blue-100';
    return 'text-gray-600 bg-gray-100';
}

function getScoreLabel(score) {
    if (score >= 80) return 'Hot';
    if (score >= 60) return 'Warm';
    if (score >= 40) return 'Moderate';
    return 'Cold';
}

function getStatusColor(status) {
    const colors = {
        new: 'text-blue-600 bg-blue-100',
        contacted: 'text-purple-600 bg-purple-100',
        qualified: 'text-emerald-600 bg-emerald-100',
        converted: 'text-green-600 bg-green-100',
        lost: 'text-gray-600 bg-gray-100',
    };
    return colors[status] || colors.new;
}

function updateStatus() {
    statusForm.put(route('client.leads.update', props.lead.id), {
        preserveScroll: true,
        onSuccess: () => {
            showStatusModal.value = false;
        },
    });
}

function adjustScore() {
    scoreForm.put(route('client.leads.update', props.lead.id), {
        preserveScroll: true,
        onSuccess: () => {
            showScoreModal.value = false;
            scoreForm.reset();
        },
    });
}

function deleteLead() {
    if (confirm('Are you sure you want to delete this lead? This action cannot be undone.')) {
        router.delete(route('client.leads.destroy', props.lead.id));
    }
}
</script>

<template>
    <Head :title="`Lead: ${lead.name || 'Unknown'}`" />

    <div class="min-h-screen bg-gray-50 ">
        <!-- Header -->
        <header class="bg-white  border-b border-gray-200 ">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <Link :href="route('client.leads.index')" class="text-gray-500 hover:text-gray-700 ">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                            </svg>
                        </Link>
                        <h1 class="text-xl font-semibold text-gray-900 ">
                            {{ lead.name || 'Unknown Lead' }}
                        </h1>
                        <span :class="['px-2 py-1 text-xs font-medium rounded-full capitalize', getStatusColor(lead.status)]">
                            {{ lead.status }}
                        </span>
                    </div>
                    <div class="flex items-center gap-3">
                        <a
                            :href="route('client.leads.export-single', lead.id)"
                            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition text-sm font-medium flex items-center gap-2"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            Export
                        </a>
                        <button
                            @click="deleteLead"
                            class="px-4 py-2 text-rose-600 hover:text-rose-700 text-sm font-medium"
                        >
                            Delete Lead
                        </button>
                    </div>
                </div>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Lead Info -->
                <div class="lg:col-span-1 space-y-6">
                    <!-- Contact Card -->
                    <div class="bg-white  rounded-xl border border-gray-200  p-6">
                        <h2 class="text-lg font-semibold text-gray-900  mb-4">Contact Information</h2>

                        <dl class="space-y-4">
                            <div>
                                <dt class="text-sm text-gray-500">Name</dt>
                                <dd class="text-gray-900  font-medium">{{ lead.name || 'Not provided' }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm text-gray-500">Email</dt>
                                <dd class="text-gray-900 ">
                                    <a v-if="lead.email" :href="`mailto:${lead.email}`" class="text-blue-600 hover:underline">
                                        {{ lead.email }}
                                    </a>
                                    <span v-else class="text-gray-400">Not provided</span>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm text-gray-500">Phone</dt>
                                <dd class="text-gray-900 ">
                                    <a v-if="lead.phone" :href="`tel:${lead.phone}`" class="text-blue-600 hover:underline">
                                        {{ lead.phone }}
                                    </a>
                                    <span v-else class="text-gray-400">Not provided</span>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm text-gray-500">Company</dt>
                                <dd class="text-gray-900 ">{{ lead.company || 'Not provided' }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm text-gray-500">Source</dt>
                                <dd class="text-gray-900  capitalize">{{ lead.source }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm text-gray-500">Created</dt>
                                <dd class="text-gray-900 ">{{ new Date(lead.created_at).toLocaleString() }}</dd>
                            </div>
                        </dl>
                    </div>

                    <!-- Score Card -->
                    <div class="bg-white  rounded-xl border border-gray-200  p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h2 class="text-lg font-semibold text-gray-900 ">Lead Score</h2>
                            <button
                                @click="showScoreModal = true"
                                class="text-sm text-blue-600 hover:text-blue-700"
                            >
                                Adjust
                            </button>
                        </div>

                        <div class="text-center">
                            <div :class="['inline-flex items-center justify-center w-20 h-20 rounded-full text-2xl font-bold', getScoreColor(lead.score)]">
                                {{ lead.score }}
                            </div>
                            <div class="mt-2 text-lg font-medium text-gray-900 ">
                                {{ getScoreLabel(lead.score) }}
                            </div>
                        </div>

                        <!-- Score Bar -->
                        <div class="mt-4">
                            <div class="h-2 bg-gray-200  rounded-full overflow-hidden">
                                <div
                                    class="h-full bg-gradient-to-r from-blue-500 via-amber-500 to-emerald-500"
                                    :style="{ width: `${lead.score}%` }"
                                ></div>
                            </div>
                        </div>
                    </div>

                    <!-- Status Card -->
                    <div class="bg-white  rounded-xl border border-gray-200  p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h2 class="text-lg font-semibold text-gray-900 ">Status</h2>
                            <button
                                @click="showStatusModal = true"
                                class="text-sm text-blue-600 hover:text-blue-700"
                            >
                                Change
                            </button>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <span
                                v-for="status in statuses"
                                :key="status.value"
                                :class="[
                                    'px-3 py-1.5 text-sm font-medium rounded-full capitalize transition',
                                    lead.status === status.value
                                        ? getStatusColor(status.value)
                                        : 'bg-gray-100  text-gray-500'
                                ]"
                            >
                                {{ status.label }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Conversation History -->
                <div class="lg:col-span-2">
                    <div class="bg-white  rounded-xl border border-gray-200  overflow-hidden">
                        <div class="p-6 border-b border-gray-200 ">
                            <h2 class="text-lg font-semibold text-gray-900 ">Conversation History</h2>
                            <p class="text-sm text-gray-500 mt-1">
                                {{ lead.conversations?.length || 0 }} conversation(s) with this lead
                            </p>
                        </div>

                        <div v-if="lead.conversation?.messages?.length" class="p-6 space-y-4 max-h-[600px] overflow-y-auto">
                            <div
                                v-for="message in lead.conversation.messages"
                                :key="message.id"
                                :class="[
                                    'flex',
                                    message.role === 'user' ? 'justify-end' : 'justify-start'
                                ]"
                            >
                                <div
                                    :class="[
                                        'max-w-[80%] rounded-lg px-4 py-2',
                                        message.role === 'user'
                                            ? 'bg-blue-600 text-white'
                                            : 'bg-gray-100  text-gray-900 '
                                    ]"
                                >
                                    <p class="text-sm whitespace-pre-wrap">{{ message.content }}</p>
                                    <p :class="[
                                        'text-xs mt-1',
                                        message.role === 'user' ? 'text-blue-200' : 'text-gray-500'
                                    ]">
                                        {{ new Date(message.created_at).toLocaleTimeString() }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div v-else class="p-12 text-center">
                            <svg class="w-12 h-12 mx-auto text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                            </svg>
                            <p class="text-gray-500">No conversation messages available</p>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <!-- Status Modal -->
        <div v-if="showStatusModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-black/50" @click="showStatusModal = false"></div>
                <div class="relative bg-white  rounded-xl max-w-md w-full p-6">
                    <h3 class="text-lg font-semibold text-gray-900  mb-4">Change Status</h3>

                    <div class="space-y-2 mb-6">
                        <label
                            v-for="status in statuses"
                            :key="status.value"
                            class="flex items-center gap-3 p-3 rounded-lg border cursor-pointer transition"
                            :class="statusForm.status === status.value
                                ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20'
                                : 'border-gray-200  hover:bg-gray-50 '"
                        >
                            <input
                                type="radio"
                                v-model="statusForm.status"
                                :value="status.value"
                                class="text-blue-600"
                            />
                            <span class="font-medium text-gray-900 ">{{ status.label }}</span>
                        </label>
                    </div>

                    <div class="flex gap-3">
                        <button
                            @click="showStatusModal = false"
                            class="flex-1 px-4 py-2 bg-gray-100  text-gray-700  rounded-lg hover:bg-gray-200  transition"
                        >
                            Cancel
                        </button>
                        <button
                            @click="updateStatus"
                            :disabled="statusForm.processing"
                            class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:bg-blue-400 transition"
                        >
                            {{ statusForm.processing ? 'Saving...' : 'Save' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Score Modal -->
        <div v-if="showScoreModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-black/50" @click="showScoreModal = false"></div>
                <div class="relative bg-white  rounded-xl max-w-md w-full p-6">
                    <h3 class="text-lg font-semibold text-gray-900  mb-4">Adjust Score</h3>

                    <div class="space-y-4 mb-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700  mb-1">
                                Adjustment ({{ scoreForm.score_adjustment >= 0 ? '+' : '' }}{{ scoreForm.score_adjustment }})
                            </label>
                            <input
                                type="range"
                                v-model.number="scoreForm.score_adjustment"
                                min="-50"
                                max="50"
                                class="w-full"
                            />
                            <div class="flex justify-between text-xs text-gray-500">
                                <span>-50</span>
                                <span>0</span>
                                <span>+50</span>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700  mb-1">
                                Reason (optional)
                            </label>
                            <input
                                type="text"
                                v-model="scoreForm.score_reason"
                                placeholder="e.g., Responded to follow-up call"
                                class="w-full px-4 py-2 border border-gray-300  rounded-lg bg-white  text-gray-900 "
                            />
                        </div>

                        <div class="text-center p-4 bg-gray-50  rounded-lg">
                            <span class="text-gray-500">New score: </span>
                            <span class="text-lg font-bold text-gray-900 ">
                                {{ Math.min(100, Math.max(0, lead.score + scoreForm.score_adjustment)) }}
                            </span>
                        </div>
                    </div>

                    <div class="flex gap-3">
                        <button
                            @click="showScoreModal = false"
                            class="flex-1 px-4 py-2 bg-gray-100  text-gray-700  rounded-lg hover:bg-gray-200  transition"
                        >
                            Cancel
                        </button>
                        <button
                            @click="adjustScore"
                            :disabled="scoreForm.processing || scoreForm.score_adjustment === 0"
                            class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:bg-blue-400 transition"
                        >
                            {{ scoreForm.processing ? 'Saving...' : 'Apply' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
