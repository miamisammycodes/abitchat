<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useRoute } from '@/composables/useRoute';

const route = useRoute();

const props = defineProps({
    leads: Object,
    stats: Object,
    filters: Object,
});

const search = ref(props.filters.search);
const statusFilter = ref(props.filters.status);

const statuses = [
    { value: 'all', label: 'All Leads' },
    { value: 'new', label: 'New' },
    { value: 'contacted', label: 'Contacted' },
    { value: 'qualified', label: 'Qualified' },
    { value: 'converted', label: 'Converted' },
    { value: 'lost', label: 'Lost' },
];

function applyFilters() {
    router.get(route('client.leads.index'), {
        search: search.value || undefined,
        status: statusFilter.value !== 'all' ? statusFilter.value : undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
}

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

function exportCsv() {
    window.location.href = route('client.leads.export', {
        status: statusFilter.value !== 'all' ? statusFilter.value : undefined,
    });
}
</script>

<template>
    <Head title="Leads" />

    <div class="min-h-screen bg-gray-50 ">
        <!-- Header -->
        <header class="bg-white  border-b border-gray-200 ">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <Link :href="route('dashboard')" class="text-gray-500 hover:text-gray-700 ">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                            </svg>
                        </Link>
                        <h1 class="text-xl font-semibold text-gray-900 ">Leads</h1>
                    </div>
                    <button
                        @click="exportCsv"
                        class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition text-sm font-medium flex items-center gap-2"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        Export CSV
                    </button>
                </div>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <!-- Stats Cards -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
                <div class="bg-white  rounded-xl p-4 border border-gray-200 ">
                    <div class="text-2xl font-bold text-gray-900 ">{{ stats.total }}</div>
                    <div class="text-sm text-gray-500">Total Leads</div>
                </div>
                <div class="bg-white  rounded-xl p-4 border border-gray-200 ">
                    <div class="text-2xl font-bold text-blue-600">{{ stats.new }}</div>
                    <div class="text-sm text-gray-500">New</div>
                </div>
                <div class="bg-white  rounded-xl p-4 border border-gray-200 ">
                    <div class="text-2xl font-bold text-emerald-600">{{ stats.qualified }}</div>
                    <div class="text-sm text-gray-500">Qualified</div>
                </div>
                <div class="bg-white  rounded-xl p-4 border border-gray-200 ">
                    <div class="text-2xl font-bold text-amber-600">{{ stats.high_quality }}</div>
                    <div class="text-sm text-gray-500">High Quality (70+)</div>
                </div>
            </div>

            <!-- Filters -->
            <div class="bg-white  rounded-xl border border-gray-200  p-4 mb-6">
                <div class="flex flex-col sm:flex-row gap-4">
                    <div class="flex-1">
                        <input
                            v-model="search"
                            @keyup.enter="applyFilters"
                            type="text"
                            placeholder="Search by name, email, phone, or company..."
                            class="w-full px-4 py-2 border border-gray-300  rounded-lg bg-white  text-gray-900 "
                        />
                    </div>
                    <select
                        v-model="statusFilter"
                        @change="applyFilters"
                        class="px-4 py-2 border border-gray-300  rounded-lg bg-white  text-gray-900 "
                    >
                        <option v-for="status in statuses" :key="status.value" :value="status.value">
                            {{ status.label }}
                        </option>
                    </select>
                    <button
                        @click="applyFilters"
                        class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition font-medium"
                    >
                        Search
                    </button>
                </div>
            </div>

            <!-- Leads Table -->
            <div class="bg-white  rounded-xl border border-gray-200  overflow-hidden">
                <div v-if="leads.data.length === 0" class="p-12 text-center">
                    <svg class="w-12 h-12 mx-auto text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                    </svg>
                    <h3 class="text-lg font-medium text-gray-900  mb-1">No leads yet</h3>
                    <p class="text-gray-500">Leads will appear here when visitors share their contact information via the chatbot.</p>
                </div>

                <table v-else class="min-w-full divide-y divide-neutral-200 dark:divide-neutral-700">
                    <thead class="bg-gray-50 ">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Lead</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contact</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Score</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                        <tr v-for="lead in leads.data" :key="lead.id" class="hover:bg-gray-50 /50">
                            <td class="px-6 py-4">
                                <div class="font-medium text-gray-900 ">
                                    {{ lead.name || 'Unknown' }}
                                </div>
                                <div v-if="lead.company" class="text-sm text-gray-500">
                                    {{ lead.company }}
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div v-if="lead.email" class="text-sm text-gray-900 ">
                                    {{ lead.email }}
                                </div>
                                <div v-if="lead.phone" class="text-sm text-gray-500">
                                    {{ lead.phone }}
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <span :class="['px-2 py-1 text-xs font-medium rounded-full', getScoreColor(lead.score)]">
                                    {{ lead.score }} - {{ getScoreLabel(lead.score) }}
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <span :class="['px-2 py-1 text-xs font-medium rounded-full capitalize', getStatusColor(lead.status)]">
                                    {{ lead.status }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-500">
                                {{ new Date(lead.created_at).toLocaleDateString() }}
                            </td>
                            <td class="px-6 py-4 text-right">
                                <Link
                                    :href="route('client.leads.show', lead.id)"
                                    class="text-blue-600 hover:text-blue-700 text-sm font-medium"
                                >
                                    View
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Pagination -->
                <div v-if="leads.data.length > 0" class="px-6 py-4 border-t border-gray-200  flex items-center justify-between">
                    <div class="text-sm text-gray-500">
                        Showing {{ leads.from }} to {{ leads.to }} of {{ leads.total }} leads
                    </div>
                    <div class="flex gap-2">
                        <Link
                            v-if="leads.prev_page_url"
                            :href="leads.prev_page_url"
                            class="px-3 py-1 bg-gray-100  text-gray-700  rounded hover:bg-gray-200  text-sm"
                        >
                            Previous
                        </Link>
                        <Link
                            v-if="leads.next_page_url"
                            :href="leads.next_page_url"
                            class="px-3 py-1 bg-gray-100  text-gray-700  rounded hover:bg-gray-200  text-sm"
                        >
                            Next
                        </Link>
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>
