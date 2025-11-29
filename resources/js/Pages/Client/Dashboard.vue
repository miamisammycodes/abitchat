<script setup>
import { Head, Link } from '@inertiajs/vue3'
import { useRoute } from '@/composables/useRoute'
import ClientLayout from '@/Layouts/ClientLayout.vue'
import { Card, CardContent } from '@/Components/ui/card'
import { Badge } from '@/Components/ui/badge'
import {
  MessageSquare,
  Users,
  FileText,
  BookOpen,
  Code,
  BarChart3,
  CreditCard,
} from 'lucide-vue-next'

const route = useRoute()

defineProps({
  tenant: Object,
  stats: Object,
})

const quickActions = [
  {
    name: 'Knowledge Base',
    description: 'Manage documents, FAQs, and training content',
    href: 'client.knowledge.index',
    icon: BookOpen,
    iconBg: 'bg-indigo-100',
    iconColor: 'text-indigo-600',
  },
  {
    name: 'Widget Settings',
    description: 'Customize and embed your chatbot',
    href: 'client.widget.index',
    icon: Code,
    iconBg: 'bg-purple-100',
    iconColor: 'text-purple-600',
  },
  {
    name: 'Leads',
    description: 'View and manage captured leads',
    href: 'client.leads.index',
    icon: Users,
    iconBg: 'bg-green-100',
    iconColor: 'text-green-600',
  },
  {
    name: 'Analytics',
    description: 'View performance metrics and insights',
    href: 'client.analytics.index',
    icon: BarChart3,
    iconBg: 'bg-amber-100',
    iconColor: 'text-amber-600',
  },
  {
    name: 'Billing',
    description: 'Manage subscription and payments',
    href: 'client.billing.index',
    icon: CreditCard,
    iconBg: 'bg-rose-100',
    iconColor: 'text-rose-600',
  },
]
</script>

<template>
  <Head title="Dashboard" />

  <ClientLayout>
    <div class="space-y-6">
      <!-- Header -->
      <div class="flex items-center gap-3">
        <h1 class="text-2xl font-bold text-foreground">Dashboard</h1>
        <Badge variant="secondary">{{ tenant?.plan }}</Badge>
      </div>

      <!-- Stats Grid -->
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <Card>
          <CardContent class="p-5">
            <div class="flex items-center gap-4">
              <div class="flex-shrink-0 rounded-lg bg-blue-100 p-3">
                <MessageSquare class="h-6 w-6 text-blue-600" />
              </div>
              <div>
                <p class="text-sm font-medium text-muted-foreground">Conversations</p>
                <p class="text-2xl font-semibold text-foreground">{{ stats?.conversations ?? 0 }}</p>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardContent class="p-5">
            <div class="flex items-center gap-4">
              <div class="flex-shrink-0 rounded-lg bg-green-100 p-3">
                <Users class="h-6 w-6 text-green-600" />
              </div>
              <div>
                <p class="text-sm font-medium text-muted-foreground">Leads</p>
                <p class="text-2xl font-semibold text-foreground">{{ stats?.leads ?? 0 }}</p>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardContent class="p-5">
            <div class="flex items-center gap-4">
              <div class="flex-shrink-0 rounded-lg bg-purple-100 p-3">
                <FileText class="h-6 w-6 text-purple-600" />
              </div>
              <div>
                <p class="text-sm font-medium text-muted-foreground">Knowledge Items</p>
                <p class="text-2xl font-semibold text-foreground">{{ stats?.knowledge_items ?? 0 }}</p>
              </div>
            </div>
          </CardContent>
        </Card>
      </div>

      <!-- Quick Actions -->
      <div>
        <h2 class="text-lg font-semibold text-foreground mb-4">Quick Actions</h2>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <Link
            v-for="action in quickActions"
            :key="action.name"
            :href="route(action.href)"
            class="group"
          >
            <Card class="h-full transition-shadow hover:shadow-md">
              <CardContent class="p-6">
                <div class="flex items-center gap-4">
                  <div :class="['flex-shrink-0 rounded-lg p-3', action.iconBg]">
                    <component :is="action.icon" :class="['h-6 w-6', action.iconColor]" />
                  </div>
                  <div>
                    <h3 class="text-base font-medium text-foreground group-hover:text-primary transition-colors">
                      {{ action.name }}
                    </h3>
                    <p class="text-sm text-muted-foreground">{{ action.description }}</p>
                  </div>
                </div>
              </CardContent>
            </Card>
          </Link>
        </div>
      </div>
    </div>
  </ClientLayout>
</template>
