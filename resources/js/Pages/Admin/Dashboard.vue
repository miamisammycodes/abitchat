<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link } from '@inertiajs/vue3'
import { useRoute } from '@/composables/useRoute'
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card'
import { Badge } from '@/Components/ui/badge'
import {
  Building2,
  MessageSquare,
  DollarSign,
  Clock,
  Users,
  Coins,
} from 'lucide-vue-next'

const route = useRoute()

defineProps({
  stats: Object,
  recentTenants: Array,
  recentTransactions: Array,
  topClients: Array,
})

const formatNumber = (num) => {
  if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M'
  if (num >= 1000) return (num / 1000).toFixed(1) + 'K'
  return num?.toLocaleString() || '0'
}

const formatCurrency = (amount) => {
  return 'Nu. ' + new Intl.NumberFormat('en-IN').format(amount || 0)
}

const formatDate = (date) => {
  return new Date(date).toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  })
}

const getStatusVariant = (status) => {
  const variants = {
    active: 'success',
    inactive: 'secondary',
    suspended: 'destructive',
    pending: 'warning',
    approved: 'success',
    rejected: 'destructive',
  }
  return variants[status] || 'secondary'
}
</script>

<template>
  <AdminLayout title="Dashboard">
    <!-- Stats Grid -->
    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
      <Card>
        <CardContent class="p-5">
          <div class="flex items-center">
            <div class="flex-shrink-0 rounded-lg bg-indigo-500/10 p-3">
              <Building2 class="h-6 w-6 text-indigo-500" />
            </div>
            <div class="ml-5">
              <p class="text-sm font-medium text-muted-foreground">Total Clients</p>
              <div class="flex items-baseline gap-2">
                <span class="text-2xl font-semibold text-foreground">{{ stats.tenants.total }}</span>
                <span class="text-sm text-emerald-500">{{ stats.tenants.active }} active</span>
              </div>
            </div>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardContent class="p-5">
          <div class="flex items-center">
            <div class="flex-shrink-0 rounded-lg bg-blue-500/10 p-3">
              <MessageSquare class="h-6 w-6 text-blue-500" />
            </div>
            <div class="ml-5">
              <p class="text-sm font-medium text-muted-foreground">Conversations</p>
              <div class="flex items-baseline gap-2">
                <span class="text-2xl font-semibold text-foreground">{{ formatNumber(stats.conversations.total) }}</span>
                <span class="text-sm text-blue-500">{{ stats.conversations.today }} today</span>
              </div>
            </div>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardContent class="p-5">
          <div class="flex items-center">
            <div class="flex-shrink-0 rounded-lg bg-emerald-500/10 p-3">
              <DollarSign class="h-6 w-6 text-emerald-500" />
            </div>
            <div class="ml-5">
              <p class="text-sm font-medium text-muted-foreground">Revenue</p>
              <div class="flex items-baseline gap-2">
                <span class="text-2xl font-semibold text-foreground">{{ formatCurrency(stats.revenue.thisMonth) }}</span>
                <span class="text-xs text-muted-foreground">this month</span>
              </div>
            </div>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardContent class="p-5">
          <div class="flex items-center">
            <div class="flex-shrink-0 rounded-lg bg-amber-500/10 p-3">
              <Clock class="h-6 w-6 text-amber-500" />
            </div>
            <div class="ml-5">
              <p class="text-sm font-medium text-muted-foreground">Pending Approvals</p>
              <div class="flex items-baseline gap-2">
                <span class="text-2xl font-semibold text-foreground">{{ stats.pendingTransactions }}</span>
                <Link
                  v-if="stats.pendingTransactions > 0"
                  :href="route('admin.transactions.index', { status: 'pending' })"
                  class="text-sm text-amber-500 hover:text-amber-400"
                >
                  Review
                </Link>
              </div>
            </div>
          </div>
        </CardContent>
      </Card>
    </div>

    <!-- Secondary Stats -->
    <div class="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-3">
      <Card>
        <CardContent class="p-5">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm font-medium text-muted-foreground">Total Leads</p>
              <p class="text-xl font-semibold text-foreground">{{ formatNumber(stats.leads.total) }}</p>
            </div>
            <div class="text-right">
              <p class="text-sm text-muted-foreground">This Week</p>
              <p class="text-lg font-medium text-emerald-500">+{{ stats.leads.thisWeek }}</p>
            </div>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardContent class="p-5">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm font-medium text-muted-foreground">Total Tokens Used</p>
              <p class="text-xl font-semibold text-foreground">{{ formatNumber(stats.tokens.total) }}</p>
            </div>
            <div class="text-right">
              <p class="text-sm text-muted-foreground">This Month</p>
              <p class="text-lg font-medium text-blue-500">{{ formatNumber(stats.tokens.thisMonth) }}</p>
            </div>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardContent class="p-5">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm font-medium text-muted-foreground">Total Revenue</p>
              <p class="text-xl font-semibold text-foreground">{{ formatCurrency(stats.revenue.total) }}</p>
            </div>
            <div class="text-right">
              <p class="text-sm text-muted-foreground">Users</p>
              <p class="text-lg font-medium text-indigo-500">{{ stats.users }}</p>
            </div>
          </div>
        </CardContent>
      </Card>
    </div>

    <!-- Tables -->
    <div class="mt-8 grid grid-cols-1 gap-6 lg:grid-cols-2">
      <!-- Recent Clients -->
      <Card>
        <CardHeader class="flex flex-row items-center justify-between border-b">
          <CardTitle>Recent Clients</CardTitle>
          <Link :href="route('admin.clients.index')" class="text-sm text-primary hover:text-primary/80">
            View all
          </Link>
        </CardHeader>
        <CardContent class="p-0">
          <ul class="divide-y">
            <li v-for="tenant in recentTenants" :key="tenant.id" class="px-4 py-4">
              <div class="flex items-center justify-between">
                <div>
                  <p class="text-sm font-medium text-foreground">{{ tenant.name }}</p>
                  <p class="text-xs text-muted-foreground">{{ formatDate(tenant.created_at) }}</p>
                </div>
                <Badge :variant="getStatusVariant(tenant.status)" class="capitalize">
                  {{ tenant.status }}
                </Badge>
              </div>
            </li>
            <li v-if="!recentTenants?.length" class="px-4 py-8 text-center text-muted-foreground">
              No clients yet
            </li>
          </ul>
        </CardContent>
      </Card>

      <!-- Recent Transactions -->
      <Card>
        <CardHeader class="flex flex-row items-center justify-between border-b">
          <CardTitle>Recent Transactions</CardTitle>
          <Link :href="route('admin.transactions.index')" class="text-sm text-primary hover:text-primary/80">
            View all
          </Link>
        </CardHeader>
        <CardContent class="p-0">
          <ul class="divide-y">
            <li v-for="txn in recentTransactions" :key="txn.id" class="px-4 py-4">
              <div class="flex items-center justify-between">
                <div>
                  <p class="text-sm font-medium text-foreground">{{ txn.tenant?.name }}</p>
                  <p class="text-xs text-muted-foreground">{{ txn.plan?.name }} - {{ formatCurrency(txn.amount) }}</p>
                </div>
                <Badge :variant="getStatusVariant(txn.status)" class="capitalize">
                  {{ txn.status }}
                </Badge>
              </div>
            </li>
            <li v-if="!recentTransactions?.length" class="px-4 py-8 text-center text-muted-foreground">
              No transactions yet
            </li>
          </ul>
        </CardContent>
      </Card>
    </div>

    <!-- Top Clients Table -->
    <Card class="mt-6">
      <CardHeader class="border-b">
        <CardTitle>Top Clients by Conversations</CardTitle>
      </CardHeader>
      <CardContent class="p-0">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y">
            <thead class="bg-muted">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Client</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-muted-foreground uppercase tracking-wider">Conversations</th>
              </tr>
            </thead>
            <tbody class="divide-y">
              <tr v-for="client in topClients" :key="client.id">
                <td class="px-6 py-4 whitespace-nowrap">
                  <Link :href="route('admin.clients.show', client.id)" class="text-sm font-medium text-foreground hover:text-primary">
                    {{ client.name }}
                  </Link>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-muted-foreground">
                  {{ client.conversations_count }}
                </td>
              </tr>
              <tr v-if="!topClients?.length">
                <td colspan="2" class="px-6 py-8 text-center text-muted-foreground">
                  No data yet
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </CardContent>
    </Card>
  </AdminLayout>
</template>
