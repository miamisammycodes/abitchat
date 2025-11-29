<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3'
import { useRoute } from '@/composables/useRoute'
import ClientLayout from '@/Layouts/ClientLayout.vue'
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card'
import { Button } from '@/Components/ui/button'
import { Badge } from '@/Components/ui/badge'
import { Alert, AlertDescription } from '@/Components/ui/alert'
import {
  CreditCard,
  AlertTriangle,
  Check,
} from 'lucide-vue-next'

const route = useRoute()
const page = usePage()

const props = defineProps({
  tenant: Object,
  currentPlan: Object,
  usage: Object,
  planExpired: Boolean,
  transactions: Array,
})

function getUsagePercent(used, limit) {
  if (limit === -1) return 0
  if (limit === 0) return 100
  return Math.min(Math.round((used / limit) * 100), 100)
}

function getUsageColor(used, limit) {
  if (limit === -1) return 'bg-green-500'
  const percent = (used / limit) * 100
  if (percent >= 90) return 'bg-red-500'
  if (percent >= 70) return 'bg-amber-500'
  return 'bg-green-500'
}

function formatLimit(limit) {
  if (limit === -1) return 'Unlimited'
  return limit.toLocaleString()
}

function getStatusVariant(status) {
  const variants = {
    pending: 'warning',
    approved: 'success',
    rejected: 'destructive',
  }
  return variants[status] || 'secondary'
}
</script>

<template>
  <Head title="Billing" />

  <ClientLayout>
    <div class="space-y-6">
      <!-- Header -->
      <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-foreground">Billing</h1>
        <Button as-child>
          <Link :href="route('client.billing.plans')">
            <CreditCard class="h-4 w-4 mr-2" />
            View Plans
          </Link>
        </Button>
      </div>

      <!-- Success Message -->
      <Alert v-if="page.props.flash?.success" class="border-green-200 bg-green-50 text-green-800">
        <Check class="h-4 w-4" />
        <AlertDescription>{{ page.props.flash.success }}</AlertDescription>
      </Alert>

      <!-- Plan Expired Warning -->
      <Alert v-if="planExpired" variant="destructive">
        <AlertTriangle class="h-4 w-4" />
        <AlertDescription class="flex items-center justify-between w-full">
          <div>
            <p class="font-medium">Your plan has expired</p>
            <p class="text-sm opacity-90">Please renew your subscription to continue using all features.</p>
          </div>
          <Button variant="destructive" size="sm" as-child class="ml-4">
            <Link :href="route('client.billing.plans')">Renew Now</Link>
          </Button>
        </AlertDescription>
      </Alert>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Current Plan & Usage -->
        <div class="lg:col-span-2 space-y-6">
          <!-- Plan Card -->
          <Card>
            <CardHeader>
              <CardTitle>Current Plan</CardTitle>
            </CardHeader>
            <CardContent>
              <div v-if="currentPlan" class="flex items-start justify-between">
                <div>
                  <h3 class="text-2xl font-bold">{{ currentPlan.name }}</h3>
                  <p class="text-muted-foreground mt-1">{{ currentPlan.description }}</p>
                  <p v-if="tenant.plan_expires_at" class="text-sm mt-2" :class="planExpired ? 'text-destructive' : 'text-muted-foreground'">
                    {{ planExpired ? 'Expired' : 'Expires' }}: {{ new Date(tenant.plan_expires_at).toLocaleDateString() }}
                  </p>
                </div>
                <div class="text-right">
                  <p class="text-3xl font-bold">
                    {{ currentPlan.price == 0 ? 'Free' : `$${currentPlan.price}` }}
                  </p>
                  <p v-if="currentPlan.price > 0" class="text-sm text-muted-foreground">/{{ currentPlan.billing_period }}</p>
                </div>
              </div>
              <div v-else class="text-center py-8">
                <p class="text-muted-foreground mb-4">No active plan</p>
                <Button as-child>
                  <Link :href="route('client.billing.plans')">Choose a Plan</Link>
                </Button>
              </div>
            </CardContent>
          </Card>

          <!-- Usage Stats -->
          <Card>
            <CardHeader>
              <CardTitle>Usage This Month</CardTitle>
            </CardHeader>
            <CardContent class="space-y-4">
              <!-- Conversations -->
              <div>
                <div class="flex justify-between text-sm mb-1">
                  <span class="text-muted-foreground">Conversations</span>
                  <span class="font-medium">
                    {{ usage?.conversations?.used?.toLocaleString() ?? 0 }} / {{ formatLimit(usage?.conversations?.limit ?? 0) }}
                  </span>
                </div>
                <div class="h-2 bg-muted rounded-full overflow-hidden">
                  <div
                    :class="['h-full rounded-full transition-all', getUsageColor(usage?.conversations?.used ?? 0, usage?.conversations?.limit ?? 0)]"
                    :style="{ width: `${getUsagePercent(usage?.conversations?.used ?? 0, usage?.conversations?.limit ?? 0)}%` }"
                  ></div>
                </div>
              </div>

              <!-- Knowledge Items -->
              <div>
                <div class="flex justify-between text-sm mb-1">
                  <span class="text-muted-foreground">Knowledge Items</span>
                  <span class="font-medium">
                    {{ usage?.knowledge_items?.used?.toLocaleString() ?? 0 }} / {{ formatLimit(usage?.knowledge_items?.limit ?? 0) }}
                  </span>
                </div>
                <div class="h-2 bg-muted rounded-full overflow-hidden">
                  <div
                    :class="['h-full rounded-full transition-all', getUsageColor(usage?.knowledge_items?.used ?? 0, usage?.knowledge_items?.limit ?? 0)]"
                    :style="{ width: `${getUsagePercent(usage?.knowledge_items?.used ?? 0, usage?.knowledge_items?.limit ?? 0)}%` }"
                  ></div>
                </div>
              </div>

              <!-- Leads -->
              <div>
                <div class="flex justify-between text-sm mb-1">
                  <span class="text-muted-foreground">Leads Captured</span>
                  <span class="font-medium">
                    {{ usage?.leads?.used?.toLocaleString() ?? 0 }} / {{ formatLimit(usage?.leads?.limit ?? 0) }}
                  </span>
                </div>
                <div class="h-2 bg-muted rounded-full overflow-hidden">
                  <div
                    :class="['h-full rounded-full transition-all', getUsageColor(usage?.leads?.used ?? 0, usage?.leads?.limit ?? 0)]"
                    :style="{ width: `${getUsagePercent(usage?.leads?.used ?? 0, usage?.leads?.limit ?? 0)}%` }"
                  ></div>
                </div>
              </div>

              <!-- Tokens -->
              <div>
                <div class="flex justify-between text-sm mb-1">
                  <span class="text-muted-foreground">AI Tokens</span>
                  <span class="font-medium">
                    {{ usage?.tokens?.used?.toLocaleString() ?? 0 }} / {{ formatLimit(usage?.tokens?.limit ?? 0) }}
                  </span>
                </div>
                <div class="h-2 bg-muted rounded-full overflow-hidden">
                  <div
                    :class="['h-full rounded-full transition-all', getUsageColor(usage?.tokens?.used ?? 0, usage?.tokens?.limit ?? 0)]"
                    :style="{ width: `${getUsagePercent(usage?.tokens?.used ?? 0, usage?.tokens?.limit ?? 0)}%` }"
                  ></div>
                </div>
              </div>
            </CardContent>
          </Card>
        </div>

        <!-- Recent Transactions -->
        <Card>
          <CardHeader class="flex flex-row items-center justify-between">
            <CardTitle>Recent Transactions</CardTitle>
            <Button variant="ghost" size="sm" as-child>
              <Link :href="route('client.billing.transactions')">View All</Link>
            </Button>
          </CardHeader>
          <CardContent>
            <div v-if="!transactions?.length" class="text-center py-8 text-muted-foreground">
              No transactions yet
            </div>

            <div v-else class="space-y-3">
              <div
                v-for="transaction in transactions"
                :key="transaction.id"
                class="flex items-center justify-between p-3 bg-muted/50 rounded-lg"
              >
                <div>
                  <p class="font-medium text-sm">{{ transaction.plan?.name }}</p>
                  <p class="text-xs text-muted-foreground">{{ new Date(transaction.created_at).toLocaleDateString() }}</p>
                </div>
                <div class="text-right">
                  <p class="font-medium text-sm">${{ transaction.amount }}</p>
                  <Badge :variant="getStatusVariant(transaction.status)" class="text-xs capitalize">
                    {{ transaction.status }}
                  </Badge>
                </div>
              </div>
            </div>
          </CardContent>
        </Card>
      </div>
    </div>
  </ClientLayout>
</template>
