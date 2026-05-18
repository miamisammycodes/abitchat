<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import axios from 'axios'
import { useRoute } from '@/composables/useRoute'
import ClientLayout from '@/Layouts/ClientLayout.vue'
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card'
import { Button } from '@/Components/ui/button'
import { Input } from '@/Components/ui/input'
import { Label } from '@/Components/ui/label'
import { Alert, AlertDescription } from '@/Components/ui/alert'
import { Loader2, CheckCircle2, AlertCircle } from 'lucide-vue-next'

const route = useRoute()

const props = defineProps({
  plan: Object,
  transaction: Object,
  qrImageBase64: String,
})

const state = ref('polling')  // polling | verifying | paid | timeout
const rrn = ref('')
const rrnError = ref('')
let pollTimer = null
let timeoutTimer = null
const POLL_INTERVAL_MS = 3000
const POLL_TIMEOUT_MS = 120000

const qrSrc = computed(() => `data:image/png;base64,${props.qrImageBase64}`)

async function pollStatus() {
  try {
    const { data } = await axios.get(route('client.billing.dk-qr.status', props.transaction.id))
    if (data.state === 'paid') {
      stopPolling()
      state.value = 'paid'
      setTimeout(() => router.visit(route('client.billing.index')), 1500)
    }
  } catch (e) {
    // transient — keep polling
  }
}

function startPolling() {
  pollTimer = setInterval(pollStatus, POLL_INTERVAL_MS)
  timeoutTimer = setTimeout(() => {
    stopPolling()
    if (state.value === 'polling') {
      state.value = 'timeout'
    }
  }, POLL_TIMEOUT_MS)
}

function stopPolling() {
  if (pollTimer) clearInterval(pollTimer)
  if (timeoutTimer) clearTimeout(timeoutTimer)
  pollTimer = null
  timeoutTimer = null
}

async function submitRrn() {
  rrnError.value = ''
  if (!rrn.value || rrn.value.length < 4) {
    rrnError.value = 'Please enter the reference number from your bank receipt.'
    return
  }
  state.value = 'verifying'
  try {
    const { data } = await axios.post(
      route('client.billing.dk-qr.verify-rrn', props.transaction.id),
      { rrn: rrn.value.trim() }
    )
    if (data.state === 'paid') {
      stopPolling()
      state.value = 'paid'
      setTimeout(() => router.visit(route('client.billing.index')), 1500)
      return
    }
    rrnError.value = data.message || 'Could not verify that reference number.'
    state.value = 'polling'
  } catch (e) {
    if (e.response?.status === 429) {
      rrnError.value = 'Too many attempts. Contact support with reference ' + props.transaction.dk_reference_no
    } else if (e.response?.data?.errors?.rrn) {
      rrnError.value = e.response.data.errors.rrn[0]
    } else if (e.response?.data?.message) {
      rrnError.value = e.response.data.message
    } else {
      rrnError.value = 'Network error — please try again.'
    }
    state.value = 'polling'
  }
}

onMounted(() => { startPolling() })
onUnmounted(() => { stopPolling() })
</script>

<template>
  <Head :title="`Pay ${plan.name} via DK QR`" />
  <ClientLayout>
    <div class="max-w-2xl mx-auto space-y-6">
      <div>
        <h1 class="text-2xl font-bold">Pay with DK Bank QR</h1>
        <p class="text-muted-foreground mt-1">Scan with any Bhutanese bank app to pay {{ plan.name }} (Nu. {{ plan.price }})</p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>{{ plan.name }} — Nu. {{ plan.price }}</CardTitle>
        </CardHeader>
        <CardContent class="text-center space-y-4">
          <img :src="qrSrc" alt="DK Bank payment QR" class="mx-auto rounded-lg shadow-md" style="max-width: 280px" />
          <p class="text-sm text-muted-foreground">
            Reference: <code>{{ transaction.dk_reference_no }}</code>
          </p>

          <Alert v-if="state === 'polling'" class="text-left">
            <Loader2 class="h-4 w-4 animate-spin" />
            <AlertDescription>
              <p class="font-medium">Waiting for payment...</p>
              <p class="text-sm opacity-90">DK Bank payers are auto-verified. This usually takes a few seconds after you pay.</p>
            </AlertDescription>
          </Alert>

          <Alert v-if="state === 'paid'" class="text-left">
            <CheckCircle2 class="h-4 w-4" />
            <AlertDescription>
              <p class="font-medium">Payment confirmed!</p>
              <p class="text-sm opacity-90">Redirecting to your billing dashboard...</p>
            </AlertDescription>
          </Alert>

          <Alert v-if="state === 'timeout'" variant="warning" class="text-left">
            <AlertCircle class="h-4 w-4" />
            <AlertDescription>
              <p class="font-medium">We're not seeing your payment yet</p>
              <p class="text-sm opacity-90">If you've already paid, paste your bank's reference number below to verify manually.</p>
            </AlertDescription>
          </Alert>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Paid from a non-DK bank?</CardTitle>
        </CardHeader>
        <CardContent class="space-y-3">
          <p class="text-sm text-muted-foreground">
            After paying, paste your bank's reference number below. Look for "Journal No", "RRN",
            "Transaction ID", or "Reference No" on your bank's success screen or SMS receipt. Length varies by bank.
          </p>
          <Label for="rrn">Bank reference number</Label>
          <Input
            id="rrn"
            v-model="rrn"
            placeholder="Paste here"
            :disabled="state === 'verifying' || state === 'paid'"
            @keyup.enter="submitRrn"
          />
          <p v-if="rrnError" class="text-sm text-destructive">{{ rrnError }}</p>
          <Button
            @click="submitRrn"
            :disabled="state === 'verifying' || state === 'paid' || !rrn"
            class="w-full"
          >
            <Loader2 v-if="state === 'verifying'" class="h-4 w-4 mr-2 animate-spin" />
            {{ state === 'verifying' ? 'Verifying...' : 'Verify Payment' }}
          </Button>
        </CardContent>
      </Card>
    </div>
  </ClientLayout>
</template>
