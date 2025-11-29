<script setup>
import { ref, computed } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { useRoute } from '@/composables/useRoute'
import ClientLayout from '@/Layouts/ClientLayout.vue'
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card'
import { Button } from '@/Components/ui/button'
import {
  MessageSquare,
  Users,
  CheckCircle,
  Target,
  Coins,
  MessagesSquare,
  User,
} from 'lucide-vue-next'

const route = useRoute()

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
})

const selectedPeriod = ref(props.selectedDays)

function changePeriod(days) {
  selectedPeriod.value = days
  router.get(route('client.analytics.index'), { days }, {
    preserveState: true,
    preserveScroll: true,
  })
}

function getBarHeight(value, max) {
  if (max === 0) return '0%'
  return `${(value / max) * 100}%`
}

const maxConversations = computed(() => Math.max(...props.conversationsOverTime.map(d => d.count), 1))
const maxLeads = computed(() => Math.max(...props.leadsOverTime.map(d => d.count), 1))
const maxTokens = computed(() => Math.max(...props.tokenUsageOverTime.map(d => d.total), 1))
const maxHourly = computed(() => Math.max(...props.conversationsByHour.map(d => d.count), 1))

const totalLeads = computed(() => {
  return props.leadScoreDistribution.hot + props.leadScoreDistribution.warm + props.leadScoreDistribution.cold
})
</script>

<template>
  <Head title="Analytics" />

  <ClientLayout>
    <div class="space-y-6">
      <!-- Header -->
      <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-foreground">Analytics</h1>
        <div class="flex items-center gap-2">
          <Button
            v-for="period in [7, 14, 30]"
            :key="period"
            :variant="selectedPeriod === period ? 'default' : 'outline'"
            size="sm"
            @click="changePeriod(period)"
          >
            {{ period }}d
          </Button>
        </div>
      </div>

      <!-- Stats Overview -->
      <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
        <Card>
          <CardContent class="p-4">
            <div class="flex items-center gap-3">
              <div class="rounded-lg bg-blue-100 p-2">
                <MessageSquare class="h-4 w-4 text-blue-600" />
              </div>
              <div>
                <p class="text-2xl font-bold">{{ stats?.total_conversations ?? 0 }}</p>
                <p class="text-xs text-muted-foreground">Conversations</p>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardContent class="p-4">
            <div class="flex items-center gap-3">
              <div class="rounded-lg bg-green-100 p-2">
                <Users class="h-4 w-4 text-green-600" />
              </div>
              <div>
                <p class="text-2xl font-bold text-green-600">{{ stats?.total_leads ?? 0 }}</p>
                <p class="text-xs text-muted-foreground">Leads</p>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardContent class="p-4">
            <div class="flex items-center gap-3">
              <div class="rounded-lg bg-emerald-100 p-2">
                <CheckCircle class="h-4 w-4 text-emerald-600" />
              </div>
              <div>
                <p class="text-2xl font-bold text-emerald-600">{{ stats?.resolution_rate ?? 0 }}%</p>
                <p class="text-xs text-muted-foreground">Resolution Rate</p>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardContent class="p-4">
            <div class="flex items-center gap-3">
              <div class="rounded-lg bg-purple-100 p-2">
                <Target class="h-4 w-4 text-purple-600" />
              </div>
              <div>
                <p class="text-2xl font-bold text-purple-600">{{ stats?.lead_capture_rate ?? 0 }}%</p>
                <p class="text-xs text-muted-foreground">Lead Capture</p>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardContent class="p-4">
            <div class="flex items-center gap-3">
              <div class="rounded-lg bg-amber-100 p-2">
                <Coins class="h-4 w-4 text-amber-600" />
              </div>
              <div>
                <p class="text-2xl font-bold text-amber-600">{{ (stats?.token_usage ?? 0).toLocaleString() }}</p>
                <p class="text-xs text-muted-foreground">Tokens Used</p>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardContent class="p-4">
            <div class="flex items-center gap-3">
              <div class="rounded-lg bg-gray-100 p-2">
                <MessagesSquare class="h-4 w-4 text-gray-600" />
              </div>
              <div>
                <p class="text-2xl font-bold">{{ stats?.avg_messages_per_conversation ?? 0 }}</p>
                <p class="text-xs text-muted-foreground">Avg Messages</p>
              </div>
            </div>
          </CardContent>
        </Card>
      </div>

      <!-- Charts Row -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Conversations Over Time -->
        <Card>
          <CardHeader>
            <CardTitle>Conversations Over Time</CardTitle>
          </CardHeader>
          <CardContent>
            <div class="h-48 flex items-end gap-1">
              <div
                v-for="(day, index) in conversationsOverTime"
                :key="index"
                class="flex-1 bg-blue-500 rounded-t hover:bg-blue-600 transition relative group cursor-pointer"
                :style="{ height: getBarHeight(day.count, maxConversations) }"
              >
                <div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-1 px-2 py-1 bg-foreground text-background text-xs rounded opacity-0 group-hover:opacity-100 whitespace-nowrap z-10">
                  {{ day.label }}: {{ day.count }}
                </div>
              </div>
            </div>
            <div class="flex justify-between mt-2 text-xs text-muted-foreground">
              <span>{{ conversationsOverTime[0]?.label }}</span>
              <span>{{ conversationsOverTime[conversationsOverTime.length - 1]?.label }}</span>
            </div>
          </CardContent>
        </Card>

        <!-- Leads Over Time -->
        <Card>
          <CardHeader>
            <CardTitle>Leads Over Time</CardTitle>
          </CardHeader>
          <CardContent>
            <div class="h-48 flex items-end gap-1">
              <div
                v-for="(day, index) in leadsOverTime"
                :key="index"
                class="flex-1 bg-green-500 rounded-t hover:bg-green-600 transition relative group cursor-pointer"
                :style="{ height: getBarHeight(day.count, maxLeads) }"
              >
                <div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-1 px-2 py-1 bg-foreground text-background text-xs rounded opacity-0 group-hover:opacity-100 whitespace-nowrap z-10">
                  {{ day.label }}: {{ day.count }}
                </div>
              </div>
            </div>
            <div class="flex justify-between mt-2 text-xs text-muted-foreground">
              <span>{{ leadsOverTime[0]?.label }}</span>
              <span>{{ leadsOverTime[leadsOverTime.length - 1]?.label }}</span>
            </div>
          </CardContent>
        </Card>
      </div>

      <!-- Second Row -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Token Usage -->
        <Card>
          <CardHeader>
            <CardTitle>Token Usage</CardTitle>
          </CardHeader>
          <CardContent>
            <div class="h-32 flex items-end gap-1">
              <div
                v-for="(day, index) in tokenUsageOverTime"
                :key="index"
                class="flex-1 bg-amber-500 rounded-t hover:bg-amber-600 transition relative group cursor-pointer"
                :style="{ height: getBarHeight(day.total, maxTokens) }"
              >
                <div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-1 px-2 py-1 bg-foreground text-background text-xs rounded opacity-0 group-hover:opacity-100 whitespace-nowrap z-10">
                  {{ day.label }}: {{ day.total.toLocaleString() }}
                </div>
              </div>
            </div>
          </CardContent>
        </Card>

        <!-- Lead Score Distribution -->
        <Card>
          <CardHeader>
            <CardTitle>Lead Quality</CardTitle>
          </CardHeader>
          <CardContent>
            <div class="space-y-3">
              <div>
                <div class="flex justify-between text-sm mb-1">
                  <span class="text-muted-foreground">Hot (70+)</span>
                  <span class="font-medium text-green-600">{{ leadScoreDistribution?.hot ?? 0 }}</span>
                </div>
                <div class="h-2 bg-muted rounded-full overflow-hidden">
                  <div
                    class="h-full bg-green-500 rounded-full transition-all"
                    :style="{ width: totalLeads > 0 ? `${(leadScoreDistribution.hot / totalLeads) * 100}%` : '0%' }"
                  ></div>
                </div>
              </div>
              <div>
                <div class="flex justify-between text-sm mb-1">
                  <span class="text-muted-foreground">Warm (40-69)</span>
                  <span class="font-medium text-amber-600">{{ leadScoreDistribution?.warm ?? 0 }}</span>
                </div>
                <div class="h-2 bg-muted rounded-full overflow-hidden">
                  <div
                    class="h-full bg-amber-500 rounded-full transition-all"
                    :style="{ width: totalLeads > 0 ? `${(leadScoreDistribution.warm / totalLeads) * 100}%` : '0%' }"
                  ></div>
                </div>
              </div>
              <div>
                <div class="flex justify-between text-sm mb-1">
                  <span class="text-muted-foreground">Cold (&lt;40)</span>
                  <span class="font-medium text-gray-600">{{ leadScoreDistribution?.cold ?? 0 }}</span>
                </div>
                <div class="h-2 bg-muted rounded-full overflow-hidden">
                  <div
                    class="h-full bg-gray-400 rounded-full transition-all"
                    :style="{ width: totalLeads > 0 ? `${(leadScoreDistribution.cold / totalLeads) * 100}%` : '0%' }"
                  ></div>
                </div>
              </div>
            </div>
          </CardContent>
        </Card>

        <!-- Peak Hours -->
        <Card>
          <CardHeader>
            <CardTitle>Peak Hours</CardTitle>
          </CardHeader>
          <CardContent>
            <div class="h-32 flex items-end gap-px">
              <div
                v-for="(hour, index) in conversationsByHour"
                :key="index"
                class="flex-1 bg-purple-500 hover:bg-purple-600 transition relative group cursor-pointer"
                :style="{ height: getBarHeight(hour.count, maxHourly) }"
              >
                <div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-1 px-2 py-1 bg-foreground text-background text-xs rounded opacity-0 group-hover:opacity-100 whitespace-nowrap z-10">
                  {{ hour.label }}: {{ hour.count }}
                </div>
              </div>
            </div>
            <div class="flex justify-between mt-2 text-xs text-muted-foreground">
              <span>12am</span>
              <span>12pm</span>
              <span>11pm</span>
            </div>
          </CardContent>
        </Card>
      </div>

      <!-- Bottom Row -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Top Questions -->
        <Card>
          <CardHeader>
            <CardTitle>Top Questions</CardTitle>
          </CardHeader>
          <CardContent>
            <div v-if="!topQuestions?.length" class="text-muted-foreground text-center py-8">
              No questions recorded yet
            </div>
            <div v-else class="space-y-3">
              <div
                v-for="(question, index) in topQuestions"
                :key="index"
                class="flex items-start gap-3"
              >
                <span class="flex-shrink-0 w-6 h-6 rounded-full bg-muted text-muted-foreground text-xs font-medium flex items-center justify-center">
                  {{ index + 1 }}
                </span>
                <div class="flex-1 min-w-0">
                  <p class="text-sm truncate">{{ question.question }}</p>
                  <p class="text-xs text-muted-foreground">{{ question.count }} times</p>
                </div>
              </div>
            </div>
          </CardContent>
        </Card>

        <!-- Recent Activity -->
        <Card>
          <CardHeader>
            <CardTitle>Recent Activity</CardTitle>
          </CardHeader>
          <CardContent>
            <div v-if="!recentActivity?.length" class="text-muted-foreground text-center py-8">
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
                  activity.type === 'lead' ? 'bg-green-100 text-green-600' : 'bg-blue-100 text-blue-600'
                ]">
                  <User v-if="activity.type === 'lead'" class="h-4 w-4" />
                  <MessageSquare v-else class="h-4 w-4" />
                </div>
                <div class="flex-1 min-w-0">
                  <p class="text-sm">{{ activity.description }}</p>
                  <p class="text-xs text-muted-foreground">{{ activity.time_ago }}</p>
                </div>
              </div>
            </div>
          </CardContent>
        </Card>
      </div>
    </div>
  </ClientLayout>
</template>
