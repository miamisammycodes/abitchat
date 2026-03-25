<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import { useRoute } from '@/composables/useRoute'
import ClientLayout from '@/Layouts/ClientLayout.vue'
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card'
import { Button } from '@/Components/ui/button'
import { Badge } from '@/Components/ui/badge'
import { Input } from '@/Components/ui/input'
import { Label } from '@/Components/ui/label'
import { Textarea } from '@/Components/ui/textarea'
import { Check, Info, MessageSquare, X } from 'lucide-vue-next'

const route = useRoute()

const props = defineProps({
  plans: Array,
  currentPlanId: Number,
})

const showEnterpriseModal = ref(false)

const inquiryForm = useForm({
  name: '',
  email: '',
  company: '',
  message: '',
})

const trialForm = useForm({})

function activateFreeTrial(planId) {
  trialForm.post(route('client.billing.activate-trial', planId))
}

function formatLimit(limit) {
  if (limit === -1) return 'Unlimited'
  return limit.toLocaleString()
}

function openEnterpriseModal() {
  showEnterpriseModal.value = true
}

function closeEnterpriseModal() {
  showEnterpriseModal.value = false
  inquiryForm.reset()
}

function submitInquiry() {
  inquiryForm.post(route('client.billing.enterprise-inquiry'), {
    onSuccess: () => {
      closeEnterpriseModal()
    },
  })
}

function formatPrice(plan) {
  if (plan.is_contact_sales) {
    return 'Contact Us'
  }
  if (plan.price == 0) {
    return 'Free'
  }
  return `Nu. ${Number(plan.price).toLocaleString()}`
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
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <Card
          v-for="plan in plans"
          :key="plan.id"
          :class="[
            'relative',
            plan.id === currentPlanId ? 'border-primary border-2' : '',
            plan.is_contact_sales ? 'bg-gradient-to-br from-primary/5 to-primary/10' : ''
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
            v-if="plan.slug === 'starter' && plan.id !== currentPlanId"
            variant="success"
            class="absolute -top-3 left-1/2 -translate-x-1/2"
          >
            Most Popular
          </Badge>

          <!-- Enterprise Badge -->
          <Badge
            v-if="plan.is_contact_sales && plan.id !== currentPlanId"
            variant="outline"
            class="absolute -top-3 left-1/2 -translate-x-1/2 bg-background"
          >
            Enterprise
          </Badge>

          <CardContent class="p-6">
            <div class="text-center mb-6">
              <h3 class="text-xl font-bold">{{ plan.name }}</h3>
              <p class="text-sm text-muted-foreground mt-1">{{ plan.description }}</p>
              <div class="mt-4">
                <span :class="['text-4xl font-bold', plan.is_contact_sales ? 'text-primary' : '']">
                  {{ formatPrice(plan) }}
                </span>
                <span v-if="plan.price > 0 && !plan.is_contact_sales" class="text-muted-foreground">/{{ plan.billing_period }}</span>
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
            <template v-if="plan.id !== currentPlanId">
              <!-- Enterprise Contact Us Button -->
              <Button
                v-if="plan.is_contact_sales"
                variant="default"
                class="w-full"
                @click="openEnterpriseModal"
              >
                <MessageSquare class="h-4 w-4 mr-2" />
                Contact Us
              </Button>
              <!-- Free Trial Button -->
              <Button
                v-else-if="plan.price == 0"
                variant="default"
                class="w-full"
                :disabled="trialForm.processing"
                @click="activateFreeTrial(plan.id)"
              >
                Start Free Trial
              </Button>
              <!-- Regular Subscribe Button -->
              <Button
                v-else
                variant="default"
                class="w-full"
                as-child
              >
                <Link :href="route('client.billing.subscribe', plan.id)">
                  Subscribe
                </Link>
              </Button>
            </template>
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

    <!-- Enterprise Contact Modal -->
    <div v-if="showEnterpriseModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
      <Card class="w-full max-w-md mx-4">
        <CardHeader class="relative">
          <button
            @click="closeEnterpriseModal"
            class="absolute right-4 top-4 text-muted-foreground hover:text-foreground"
          >
            <X class="h-5 w-5" />
          </button>
          <CardTitle>Contact Us for Enterprise</CardTitle>
          <p class="text-sm text-muted-foreground">
            Get a custom plan tailored to your organization's needs
          </p>
        </CardHeader>
        <CardContent>
          <form @submit.prevent="submitInquiry" class="space-y-4">
            <div>
              <Label>Name *</Label>
              <Input
                v-model="inquiryForm.name"
                placeholder="Your name"
                required
              />
              <p v-if="inquiryForm.errors.name" class="text-red-500 text-sm mt-1">{{ inquiryForm.errors.name }}</p>
            </div>

            <div>
              <Label>Email *</Label>
              <Input
                v-model="inquiryForm.email"
                type="email"
                placeholder="your@email.com"
                required
              />
              <p v-if="inquiryForm.errors.email" class="text-red-500 text-sm mt-1">{{ inquiryForm.errors.email }}</p>
            </div>

            <div>
              <Label>Company</Label>
              <Input
                v-model="inquiryForm.company"
                placeholder="Company name (optional)"
              />
              <p v-if="inquiryForm.errors.company" class="text-red-500 text-sm mt-1">{{ inquiryForm.errors.company }}</p>
            </div>

            <div>
              <Label>Message *</Label>
              <Textarea
                v-model="inquiryForm.message"
                rows="4"
                placeholder="Tell us about your requirements..."
                required
              />
              <p v-if="inquiryForm.errors.message" class="text-red-500 text-sm mt-1">{{ inquiryForm.errors.message }}</p>
            </div>

            <div class="flex justify-end gap-3 pt-4">
              <Button type="button" variant="ghost" @click="closeEnterpriseModal">
                Cancel
              </Button>
              <Button type="submit" :disabled="inquiryForm.processing">
                Send Inquiry
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>
    </div>
  </ClientLayout>
</template>
