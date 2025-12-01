<script setup>
import { Head, Link } from '@inertiajs/vue3'
import { useRoute } from '@/composables/useRoute'
import ClientLayout from '@/Layouts/ClientLayout.vue'
import { Card, CardContent } from '@/Components/ui/card'
import { Button } from '@/Components/ui/button'
import { Badge } from '@/Components/ui/badge'
import { Check, Info } from 'lucide-vue-next'

const route = useRoute()

const props = defineProps({
  plans: Array,
  currentPlanId: Number,
})

function formatLimit(limit) {
  if (limit === -1) return 'Unlimited'
  return limit.toLocaleString()
}
</script>

<template>
  <Head title="Choose Plan" />

  <ClientLayout>
    <div class="space-y-6">
      <!-- Header -->
      <div class="text-center">
        <h1 class="text-3xl font-bold text-foreground">Simple, Transparent Pricing</h1>
        <p class="mt-2 text-muted-foreground">Choose the plan that best fits your needs</p>
      </div>

      <!-- Plans Grid -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <Card
          v-for="plan in plans"
          :key="plan.id"
          :class="[
            'relative',
            plan.id === currentPlanId ? 'border-primary border-2' : ''
          ]"
        >
          <!-- Current Plan Badge -->
          <Badge
            v-if="plan.id === currentPlanId"
            class="absolute -top-3 left-1/2 -translate-x-1/2"
          >
            Current Plan
          </Badge>

          <!-- Popular Badge -->
          <Badge
            v-if="plan.slug === 'pro' && plan.id !== currentPlanId"
            variant="success"
            class="absolute -top-3 left-1/2 -translate-x-1/2"
          >
            Most Popular
          </Badge>

          <CardContent class="p-6">
            <div class="text-center mb-6">
              <h3 class="text-xl font-bold">{{ plan.name }}</h3>
              <p class="text-sm text-muted-foreground mt-1">{{ plan.description }}</p>
              <div class="mt-4">
                <span class="text-4xl font-bold">
                  {{ plan.price == 0 ? 'Free' : `Nu. ${plan.price}` }}
                </span>
                <span v-if="plan.price > 0" class="text-muted-foreground">/{{ plan.billing_period }}</span>
              </div>
            </div>

            <!-- Limits -->
            <div class="space-y-3 mb-6">
              <div class="flex items-center gap-2 text-sm">
                <Check class="h-4 w-4 text-green-500" />
                <span class="text-muted-foreground">{{ formatLimit(plan.conversations_limit) }} conversations/mo</span>
              </div>
              <div class="flex items-center gap-2 text-sm">
                <Check class="h-4 w-4 text-green-500" />
                <span class="text-muted-foreground">{{ formatLimit(plan.knowledge_items_limit) }} knowledge items</span>
              </div>
              <div class="flex items-center gap-2 text-sm">
                <Check class="h-4 w-4 text-green-500" />
                <span class="text-muted-foreground">{{ formatLimit(plan.leads_limit) }} leads/mo</span>
              </div>
              <div class="flex items-center gap-2 text-sm">
                <Check class="h-4 w-4 text-green-500" />
                <span class="text-muted-foreground">{{ formatLimit(plan.tokens_limit) }} AI tokens/mo</span>
              </div>
            </div>

            <!-- Features -->
            <div v-if="plan.features?.length" class="border-t border-border pt-4 mb-6">
              <div
                v-for="(feature, idx) in plan.features"
                :key="idx"
                class="flex items-center gap-2 text-sm py-1"
              >
                <Check class="h-4 w-4 text-primary" />
                <span class="text-muted-foreground">{{ feature }}</span>
              </div>
            </div>

            <!-- Action Button -->
            <Button
              v-if="plan.id !== currentPlanId"
              :variant="plan.slug === 'pro' ? 'default' : 'outline'"
              class="w-full"
              as-child
            >
              <Link :href="route('client.billing.subscribe', plan.id)">
                {{ plan.price == 0 ? 'Get Started' : 'Subscribe' }}
              </Link>
            </Button>
            <Button v-else variant="outline" class="w-full" disabled>
              Current Plan
            </Button>
          </CardContent>
        </Card>
      </div>

      <!-- Payment Info -->
      <Card class="bg-primary/5 border-primary/20">
        <CardContent class="p-6 text-center">
          <Info class="h-6 w-6 text-primary mx-auto mb-2" />
          <h3 class="text-lg font-semibold mb-2">How Payment Works</h3>
          <p class="text-muted-foreground max-w-2xl mx-auto">
            After selecting a plan, you'll submit your payment transaction details (bank transfer, UPI, etc.).
            Our team will verify your payment and activate your plan within 24 hours.
          </p>
        </CardContent>
      </Card>
    </div>
  </ClientLayout>
</template>
