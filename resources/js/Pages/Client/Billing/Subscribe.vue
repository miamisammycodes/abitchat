<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3'
import { useRoute } from '@/composables/useRoute'
import ClientLayout from '@/Layouts/ClientLayout.vue'
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card'
import { Button } from '@/Components/ui/button'
import { Input } from '@/Components/ui/input'
import { Label } from '@/Components/ui/label'
import { Textarea } from '@/Components/ui/textarea'
import { Alert, AlertDescription } from '@/Components/ui/alert'
import { Check, Clock, Info } from 'lucide-vue-next'

const route = useRoute()

const props = defineProps({
  plan: Object,
  pendingTransaction: Object,
})

const form = useForm({
  transaction_number: '',
  reference_number: '',
  amount: props.plan.price,
  payment_method: 'bob',
  payment_date: new Date().toISOString().split('T')[0],
  notes: '',
})

function submit() {
  form.post(route('client.billing.submit-payment', props.plan.id))
}

const banks = [
  { value: 'bob', label: 'Bank of Bhutan' },
  { value: 'bnb', label: 'Bhutan National Bank' },
  { value: 'dpnb', label: 'Druk PNB Ltd' },
  { value: 'bdbl', label: 'Bhutan Development Bank Ltd.' },
  { value: 'tbank', label: 'T Bank Ltd' },
  { value: 'dk', label: 'Dk.' },
]
</script>

<template>
  <Head :title="`Subscribe to ${plan.name}`" />

  <ClientLayout>
    <div class="max-w-4xl mx-auto space-y-6">
      <!-- Header -->
      <div>
        <h1 class="text-2xl font-bold text-foreground">Subscribe to {{ plan.name }}</h1>
        <p class="text-muted-foreground mt-1">Complete your payment to activate your subscription</p>
      </div>

      <!-- Pending Transaction Warning -->
      <Alert v-if="pendingTransaction" variant="warning">
        <Clock class="h-4 w-4" />
        <AlertDescription>
          <p class="font-medium">You have a pending payment</p>
          <p class="text-sm opacity-90">
            Transaction #{{ pendingTransaction.transaction_number }} is awaiting verification.
            Submitted on {{ new Date(pendingTransaction.created_at).toLocaleDateString() }}.
          </p>
        </AlertDescription>
      </Alert>

      <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <!-- Plan Summary -->
        <Card>
          <CardHeader>
            <CardTitle>Plan Summary</CardTitle>
          </CardHeader>
          <CardContent>
            <div class="text-center pb-4 border-b border-border">
              <h3 class="text-xl font-bold">{{ plan.name }}</h3>
              <p class="text-sm text-muted-foreground mt-1">{{ plan.description }}</p>
            </div>

            <div class="py-4 border-b border-border">
              <div class="flex justify-between items-center">
                <span class="text-muted-foreground">Price</span>
                <span class="text-2xl font-bold">Nu. {{ plan.price }}</span>
              </div>
              <p class="text-sm text-muted-foreground text-right">per {{ plan.billing_period }}</p>
            </div>

            <div class="pt-4 space-y-2 text-sm">
              <div class="flex items-center gap-2">
                <Check class="h-4 w-4 text-green-500" />
                <span class="text-muted-foreground">{{ plan.conversations_limit === -1 ? 'Unlimited' : plan.conversations_limit }} conversations</span>
              </div>
              <div class="flex items-center gap-2">
                <Check class="h-4 w-4 text-green-500" />
                <span class="text-muted-foreground">{{ plan.knowledge_items_limit === -1 ? 'Unlimited' : plan.knowledge_items_limit }} knowledge items</span>
              </div>
              <div class="flex items-center gap-2">
                <Check class="h-4 w-4 text-green-500" />
                <span class="text-muted-foreground">{{ plan.leads_limit === -1 ? 'Unlimited' : plan.leads_limit }} leads</span>
              </div>
            </div>
          </CardContent>
        </Card>

        <!-- Payment Form -->
        <div class="md:col-span-2">
          <Card>
            <CardHeader>
              <CardTitle>Payment Details</CardTitle>
            </CardHeader>
            <CardContent class="space-y-6">
              <div class="bg-primary/5 rounded-lg p-4">
                <div class="flex items-start gap-2">
                  <Info class="h-5 w-5 text-primary mt-0.5" />
                  <div>
                    <h3 class="font-medium">Payment Instructions</h3>
                    <ol class="text-sm text-muted-foreground space-y-1 list-decimal list-inside mt-2">
                      <li>Make payment via bank transfer to our account</li>
                      <li>Note the transaction/reference number</li>
                      <li>Select your bank and fill in the form below</li>
                      <li>We'll verify and activate your plan within 24 hours</li>
                    </ol>
                  </div>
                </div>
              </div>

              <form @submit.prevent="submit" class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                  <div class="space-y-2">
                    <Label for="transaction_number">Transaction Number *</Label>
                    <Input
                      id="transaction_number"
                      v-model="form.transaction_number"
                      required
                      placeholder="e.g., TXN123456789"
                    />
                    <p v-if="form.errors.transaction_number" class="text-sm text-destructive">
                      {{ form.errors.transaction_number }}
                    </p>
                  </div>

                  <div class="space-y-2">
                    <Label for="reference_number">Reference Number *</Label>
                    <Input
                      id="reference_number"
                      v-model="form.reference_number"
                      required
                      maxlength="6"
                      placeholder="e.g., AB1234"
                      class="uppercase"
                    />
                    <p class="text-xs text-muted-foreground">6 characters (letters/numbers)</p>
                    <p v-if="form.errors.reference_number" class="text-sm text-destructive">
                      {{ form.errors.reference_number }}
                    </p>
                  </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                  <div class="space-y-2">
                    <Label for="amount">Amount Paid *</Label>
                    <div class="relative">
                      <span class="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground">Nu.</span>
                      <Input
                        id="amount"
                        v-model="form.amount"
                        type="number"
                        step="0.01"
                        required
                        class="pl-10"
                      />
                    </div>
                    <p v-if="form.errors.amount" class="text-sm text-destructive">
                      {{ form.errors.amount }}
                    </p>
                  </div>

                  <div class="space-y-2">
                    <Label for="payment_date">Payment Date *</Label>
                    <Input
                      id="payment_date"
                      v-model="form.payment_date"
                      type="date"
                      required
                    />
                    <p v-if="form.errors.payment_date" class="text-sm text-destructive">
                      {{ form.errors.payment_date }}
                    </p>
                  </div>
                </div>

                <div class="space-y-2">
                  <Label for="payment_method">Bank *</Label>
                  <select
                    id="payment_method"
                    v-model="form.payment_method"
                    required
                    class="w-full px-3 py-2 border border-input rounded-md bg-background text-foreground"
                  >
                    <option v-for="bank in banks" :key="bank.value" :value="bank.value">
                      {{ bank.label }}
                    </option>
                  </select>
                  <p v-if="form.errors.payment_method" class="text-sm text-destructive">
                    {{ form.errors.payment_method }}
                  </p>
                </div>

                <div class="space-y-2">
                  <Label for="notes">Notes (Optional)</Label>
                  <Textarea
                    id="notes"
                    v-model="form.notes"
                    :rows="3"
                    placeholder="Any additional information about your payment..."
                  />
                </div>

                <div class="flex items-center gap-4 pt-4">
                  <Button type="submit" class="flex-1" :disabled="form.processing">
                    {{ form.processing ? 'Submitting...' : 'Submit Payment' }}
                  </Button>
                  <Button variant="outline" as-child>
                    <Link :href="route('client.billing.plans')">Cancel</Link>
                  </Button>
                </div>
              </form>
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  </ClientLayout>
</template>
