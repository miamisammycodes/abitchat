<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useRoute } from '@/composables/useRoute';

const route = useRoute();

const props = defineProps({
    stats: Object,
    conversationsOverTime: Array,
    leadsOverTime: Array,
    tokenUsageOverTime: Array,
    leadScoreDistribution: Object,
    leadStatusDistribution: Object,
    conversationsByHour: Array,
    topQuestions: Array,
    recentActivity: Array,
    selectedDays: Number,
});

const selectedPeriod = ref(props.selectedDays);

function changePeriod(days) {
    selectedPeriod.value = days;
    router.get(route('client.analytics.index'), { days }, {
        preserveState: true,
        preserveScroll: true,
    });
}

// Simple bar chart helper
function getBarHeight(value, max) {
    if (max === 0) return '0%';
    return `${(value / max) * 100}%`;
}

const maxConversations = computed(() => Math.max(...props.conversationsOverTime.map(d => d.count), 1));
const maxLeads = computed(() => Math.max(...props.leadsOverTime.map(d => d.count), 1));
const maxTokens = computed(() => Math.max(...props.tokenUsageOverTime.map(d => d.total), 1));
const maxHourly = computed(() => Math.max(...props.conversationsByHour.map(d => d.count), 1));

const totalLeads = computed(() => {
    return props.leadScoreDistribution.hot + props.leadScoreDistribution.warm + props.leadScoreDistribution.cold;
});
</script>

<template>
    <Head title="Analytics" />

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
                        <h1 class="text-xl font-semibold text-gray-900">Analytics</h1>
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            v-for="period in [7, 14, 30]"
                            :key="period"
                            @click="changePeriod(period)"
                            :class="[
                                'px-3 py-1.5 text-sm font-medium rounded-lg transition',
                                selectedPeriod === period
                                    ? 'bg-blue-600 text-white'
                                    : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                            ]"
                        >
                            {{ period }}d
                        </button>
                    </div>
                </div>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <!-- Stats Overview -->
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-8">
                <div class="bg-white rounded-xl p-4 border border-gray-200 shadow-sm">
                    <div class="text-2xl font-bold text-gray-900">{{ stats.total_conversations }}</div>
                    <div class="text-sm text-gray-500">Conversations</div>
                </div>
                <div class="bg-white rounded-xl p-4 border border-gray-200 shadow-sm">
                    <div class="text-2xl font-bold text-blue-600">{{ stats.total_leads }}</div>
                    <div class="text-sm text-gray-500">Leads</div>
                </div>
                <div class="bg-white rounded-xl p-4 border border-gray-200 shadow-sm">
                    <div class="text-2xl font-bold text-emerald-600">{{ stats.resolution_rate }}%</div>
                    <div class="text-sm text-gray-500">Resolution Rate</div>
                </div>
                <div class="bg-white rounded-xl p-4 border border-gray-200 shadow-sm">
                    <div class="text-2xl font-bold text-purple-600">{{ stats.lead_capture_rate }}%</div>
                    <div class="text-sm text-gray-500">Lead Capture</div>
                </div>
                <div class="bg-white rounded-xl p-4 border border-gray-200 shadow-sm">
                    <div class="text-2xl font-bold text-amber-600">{{ stats.token_usage.toLocaleString() }}</div>
                    <div class="text-sm text-gray-500">Tokens Used</div>
                </div>
                <div class="bg-white rounded-xl p-4 border border-gray-200 shadow-sm">
                    <div class="text-2xl font-bold text-gray-700">{{ stats.avg_messages_per_conversation }}</div>
                    <div class="text-sm text-gray-500">Avg Messages</div>
                </div>
            </div>

            <!-- Charts Row -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <!-- Conversations Over Time -->
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Conversations Over Time</h3>
                    <div class="h-48 flex items-end gap-1">
                        <div
                            v-for="(day, index) in conversationsOverTime"
                            :key="index"
                            class="flex-1 bg-blue-500 rounded-t hover:bg-blue-600 transition relative group"
                            :style="{ height: getBarHeight(day.count, maxConversations) }"
                        >
                            <div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-1 px-2 py-1 bg-gray-900 text-white text-xs rounded opacity-0 group-hover:opacity-100 whitespace-nowrap">
                                {{ day.label }}: {{ day.count }}
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-between mt-2 text-xs text-gray-500">
                        <span>{{ conversationsOverTime[0]?.label }}</span>
                        <span>{{ conversationsOverTime[conversationsOverTime.length - 1]?.label }}</span>
                    </div>
                </div>

                <!-- Leads Over Time -->
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Leads Over Time</h3>
                    <div class="h-48 flex items-end gap-1">
                        <div
                            v-for="(day, index) in leadsOverTime"
                            :key="index"
                            class="flex-1 bg-emerald-500 rounded-t hover:bg-emerald-600 transition relative group"
                            :style="{ height: getBarHeight(day.count, maxLeads) }"
                        >
                            <div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-1 px-2 py-1 bg-gray-900 text-white text-xs rounded opacity-0 group-hover:opacity-100 whitespace-nowrap">
                                {{ day.label }}: {{ day.count }}
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-between mt-2 text-xs text-gray-500">
                        <span>{{ leadsOverTime[0]?.label }}</span>
                        <span>{{ leadsOverTime[leadsOverTime.length - 1]?.label }}</span>
                    </div>
                </div>
            </div>

            <!-- Second Row -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                <!-- Token Usage -->
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Token Usage</h3>
                    <div class="h-32 flex items-end gap-1">
                        <div
                            v-for="(day, index) in tokenUsageOverTime"
                            :key="index"
                            class="flex-1 bg-amber-500 rounded-t hover:bg-amber-600 transition relative group"
                            :style="{ height: getBarHeight(day.total, maxTokens) }"
                        >
                            <div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-1 px-2 py-1 bg-gray-900 text-white text-xs rounded opacity-0 group-hover:opacity-100 whitespace-nowrap">
                                {{ day.label }}: {{ day.total.toLocaleString() }}
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Lead Score Distribution -->
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Lead Quality</h3>
                    <div class="space-y-3">
                        <div>
                            <div class="flex justify-between text-sm mb-1">
                                <span class="text-gray-600">Hot (70+)</span>
                                <span class="font-medium text-emerald-600">{{ leadScoreDistribution.hot }}</span>
                            </div>
                            <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
                                <div
                                    class="h-full bg-emerald-500 rounded-full"
                                    :style="{ width: totalLeads > 0 ? `${(leadScoreDistribution.hot / totalLeads) * 100}%` : '0%' }"
                                ></div>
                            </div>
                        </div>
                        <div>
                            <div class="flex justify-between text-sm mb-1">
                                <span class="text-gray-600">Warm (40-69)</span>
                                <span class="font-medium text-amber-600">{{ leadScoreDistribution.warm }}</span>
                            </div>
                            <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
                                <div
                                    class="h-full bg-amber-500 rounded-full"
                                    :style="{ width: totalLeads > 0 ? `${(leadScoreDistribution.warm / totalLeads) * 100}%` : '0%' }"
                                ></div>
                            </div>
                        </div>
                        <div>
                            <div class="flex justify-between text-sm mb-1">
                                <span class="text-gray-600">Cold (&lt;40)</span>
                                <span class="font-medium text-gray-600">{{ leadScoreDistribution.cold }}</span>
                            </div>
                            <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
                                <div
                                    class="h-full bg-gray-400 rounded-full"
                                    :style="{ width: totalLeads > 0 ? `${(leadScoreDistribution.cold / totalLeads) * 100}%` : '0%' }"
                                ></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Peak Hours -->
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Peak Hours</h3>
                    <div class="h-32 flex items-end gap-px">
                        <div
                            v-for="(hour, index) in conversationsByHour"
                            :key="index"
                            class="flex-1 bg-purple-500 hover:bg-purple-600 transition relative group"
                            :style="{ height: getBarHeight(hour.count, maxHourly) }"
                        >
                            <div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-1 px-2 py-1 bg-gray-900 text-white text-xs rounded opacity-0 group-hover:opacity-100 whitespace-nowrap z-10">
                                {{ hour.label }}: {{ hour.count }}
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-between mt-2 text-xs text-gray-500">
                        <span>12am</span>
                        <span>12pm</span>
                        <span>11pm</span>
                    </div>
                </div>
            </div>

            <!-- Bottom Row -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Top Questions -->
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Top Questions</h3>
                    <div v-if="topQuestions.length === 0" class="text-gray-500 text-center py-8">
                        No questions recorded yet
                    </div>
                    <div v-else class="space-y-3">
                        <div
                            v-for="(question, index) in topQuestions"
                            :key="index"
                            class="flex items-start gap-3"
                        >
                            <span class="flex-shrink-0 w-6 h-6 rounded-full bg-gray-100 text-gray-600 text-xs font-medium flex items-center justify-center">
                                {{ index + 1 }}
                            </span>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm text-gray-900 truncate">{{ question.question }}</p>
                                <p class="text-xs text-gray-500">{{ question.count }} times</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Recent Activity</h3>
                    <div v-if="recentActivity.length === 0" class="text-gray-500 text-center py-8">
                        No recent activity
                    </div>
                    <div v-else class="space-y-3">
                        <div
                            v-for="(activity, index) in recentActivity"
                            :key="index"
                            class="flex items-start gap-3"
                        >
                            <div :class="[
                                'flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center',
                                activity.type === 'lead' ? 'bg-emerald-100 text-emerald-600' : 'bg-blue-100 text-blue-600'
                            ]">
                                <svg v-if="activity.type === 'lead'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                </svg>
                                <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm text-gray-900">{{ activity.description }}</p>
                                <p class="text-xs text-gray-500">{{ activity.time_ago }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>
