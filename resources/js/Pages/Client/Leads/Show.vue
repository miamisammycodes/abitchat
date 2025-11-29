<script setup>
import { ref } from 'vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { useRoute } from '@/composables/useRoute'
import ClientLayout from '@/Layouts/ClientLayout.vue'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card'
import { Button } from '@/Components/ui/button'
import { Input } from '@/Components/ui/input'
import { Label } from '@/Components/ui/label'
import { Badge } from '@/Components/ui/badge'
import {
  Download,
  Trash2,
  Mail,
  Phone,
  Building,
  Calendar,
  MessageSquare,
} from 'lucide-vue-next'

const route = useRoute()

const props = defineProps({
  lead: Object,
})

const showStatusModal = ref(false)
const showScoreModal = ref(false)

const statusForm = useForm({
  status: props.lead.status,
})

const scoreForm = useForm({
  score_adjustment: 0,
  score_reason: '',
})

const statuses = [
  { value: 'new', label: 'New' },
  { value: 'contacted', label: 'Contacted' },
  { value: 'qualified', label: 'Qualified' },
  { value: 'converted', label: 'Converted' },
  { value: 'lost', label: 'Lost' },
]

function getScoreVariant(score) {
  if (score >= 80) return 'success'
  if (score >= 60) return 'warning'
  if (score >= 40) return 'secondary'
  return 'outline'
}

function getScoreLabel(score) {
  if (score >= 80) return 'Hot'
  if (score >= 60) return 'Warm'
  if (score >= 40) return 'Moderate'
  return 'Cold'
}

function getStatusVariant(status) {
  const variants = {
    new: 'secondary',
    contacted: 'secondary',
    qualified: 'success',
    converted: 'success',
    lost: 'outline',
  }
  return variants[status] || 'secondary'
}

function updateStatus() {
  statusForm.put(route('client.leads.update', props.lead.id), {
    preserveScroll: true,
    onSuccess: () => {
      showStatusModal.value = false
    },
  })
}

function adjustScore() {
  scoreForm.put(route('client.leads.update', props.lead.id), {
    preserveScroll: true,
    onSuccess: () => {
      showScoreModal.value = false
      scoreForm.reset()
    },
  })
}

function deleteLead() {
  if (confirm('Are you sure you want to delete this lead? This action cannot be undone.')) {
    router.delete(route('client.leads.destroy', props.lead.id))
  }
}
</script>

<template>
  <Head :title="`Lead: ${lead.name || 'Unknown'}`" />

  <ClientLayout>
    <div class="space-y-6">
      <!-- Header -->
      <div class="flex items-start justify-between gap-4">
        <div class="flex items-center gap-3">
          <div>
            <h1 class="text-2xl font-bold text-foreground">{{ lead.name || 'Unknown Lead' }}</h1>
            <p class="text-muted-foreground">Lead details and conversation history</p>
          </div>
          <Badge :variant="getStatusVariant(lead.status)" class="capitalize">
            {{ lead.status }}
          </Badge>
        </div>
        <div class="flex items-center gap-2 flex-shrink-0">
          <Button variant="outline" size="sm" as-child>
            <a :href="route('client.leads.export-single', lead.id)">
              <Download class="h-4 w-4 mr-2" />
              Export
            </a>
          </Button>
          <Button variant="outline" size="sm" @click="deleteLead" class="text-destructive hover:text-destructive">
            <Trash2 class="h-4 w-4 mr-2" />
            Delete
          </Button>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Left Column - Lead Info -->
        <div class="space-y-6">
          <!-- Contact Card -->
          <Card>
            <CardHeader>
              <CardTitle>Contact Information</CardTitle>
            </CardHeader>
            <CardContent>
              <dl class="space-y-4">
                <div class="flex items-start gap-3">
                  <Mail class="h-4 w-4 text-muted-foreground mt-0.5" />
                  <div>
                    <dt class="text-sm text-muted-foreground">Email</dt>
                    <dd>
                      <a v-if="lead.email" :href="`mailto:${lead.email}`" class="text-primary hover:underline">
                        {{ lead.email }}
                      </a>
                      <span v-else class="text-muted-foreground">Not provided</span>
                    </dd>
                  </div>
                </div>
                <div class="flex items-start gap-3">
                  <Phone class="h-4 w-4 text-muted-foreground mt-0.5" />
                  <div>
                    <dt class="text-sm text-muted-foreground">Phone</dt>
                    <dd>
                      <a v-if="lead.phone" :href="`tel:${lead.phone}`" class="text-primary hover:underline">
                        {{ lead.phone }}
                      </a>
                      <span v-else class="text-muted-foreground">Not provided</span>
                    </dd>
                  </div>
                </div>
                <div class="flex items-start gap-3">
                  <Building class="h-4 w-4 text-muted-foreground mt-0.5" />
                  <div>
                    <dt class="text-sm text-muted-foreground">Company</dt>
                    <dd>{{ lead.company || 'Not provided' }}</dd>
                  </div>
                </div>
                <div class="flex items-start gap-3">
                  <Calendar class="h-4 w-4 text-muted-foreground mt-0.5" />
                  <div>
                    <dt class="text-sm text-muted-foreground">Created</dt>
                    <dd>{{ new Date(lead.created_at).toLocaleString() }}</dd>
                  </div>
                </div>
              </dl>
            </CardContent>
          </Card>

          <!-- Score Card -->
          <Card>
            <CardHeader class="flex flex-row items-center justify-between">
              <CardTitle>Lead Score</CardTitle>
              <Button variant="ghost" size="sm" @click="showScoreModal = true">
                Adjust
              </Button>
            </CardHeader>
            <CardContent>
              <div class="text-center">
                <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-muted">
                  <span class="text-2xl font-bold">{{ lead.score }}</span>
                </div>
                <div class="mt-2">
                  <Badge :variant="getScoreVariant(lead.score)" class="text-base">
                    {{ getScoreLabel(lead.score) }}
                  </Badge>
                </div>
              </div>

              <!-- Score Bar -->
              <div class="mt-4">
                <div class="h-2 bg-muted rounded-full overflow-hidden">
                  <div
                    class="h-full bg-gradient-to-r from-blue-500 via-amber-500 to-green-500"
                    :style="{ width: `${lead.score}%` }"
                  ></div>
                </div>
              </div>
            </CardContent>
          </Card>

          <!-- Status Card -->
          <Card>
            <CardHeader class="flex flex-row items-center justify-between">
              <CardTitle>Status</CardTitle>
              <Button variant="ghost" size="sm" @click="showStatusModal = true">
                Change
              </Button>
            </CardHeader>
            <CardContent>
              <div class="flex flex-wrap gap-2">
                <Badge
                  v-for="status in statuses"
                  :key="status.value"
                  :variant="lead.status === status.value ? getStatusVariant(status.value) : 'outline'"
                  class="capitalize"
                >
                  {{ status.label }}
                </Badge>
              </div>
            </CardContent>
          </Card>
        </div>

        <!-- Right Column - Conversation -->
        <div class="lg:col-span-2">
          <Card class="h-full">
            <CardHeader>
              <CardTitle class="flex items-center gap-2">
                <MessageSquare class="h-5 w-5" />
                Conversation History
              </CardTitle>
              <CardDescription>
                {{ lead.conversations?.length || 0 }} conversation(s) with this lead
              </CardDescription>
            </CardHeader>
            <CardContent>
              <div v-if="lead.conversation?.messages?.length" class="space-y-4 max-h-[500px] overflow-y-auto">
                <div
                  v-for="message in lead.conversation.messages"
                  :key="message.id"
                  :class="[
                    'flex',
                    message.role === 'user' ? 'justify-end' : 'justify-start'
                  ]"
                >
                  <div
                    :class="[
                      'max-w-[80%] rounded-lg px-4 py-2',
                      message.role === 'user'
                        ? 'bg-primary text-primary-foreground'
                        : 'bg-muted'
                    ]"
                  >
                    <p class="text-sm whitespace-pre-wrap">{{ message.content }}</p>
                    <p :class="[
                      'text-xs mt-1 opacity-70'
                    ]">
                      {{ new Date(message.created_at).toLocaleTimeString() }}
                    </p>
                  </div>
                </div>
              </div>

              <div v-else class="text-center py-12">
                <MessageSquare class="mx-auto h-12 w-12 text-muted-foreground mb-4" />
                <p class="text-muted-foreground">No conversation messages available</p>
              </div>
            </CardContent>
          </Card>
        </div>
      </div>
    </div>

    <!-- Status Modal -->
    <div v-if="showStatusModal" class="fixed inset-0 z-50 overflow-y-auto">
      <div class="flex items-center justify-center min-h-screen px-4">
        <div class="fixed inset-0 bg-black/50" @click="showStatusModal = false"></div>
        <Card class="relative max-w-md w-full">
          <CardHeader>
            <CardTitle>Change Status</CardTitle>
          </CardHeader>
          <CardContent>
            <div class="space-y-2 mb-6">
              <label
                v-for="status in statuses"
                :key="status.value"
                class="flex items-center gap-3 p-3 rounded-lg border cursor-pointer transition hover:bg-muted"
                :class="statusForm.status === status.value ? 'border-primary bg-muted' : 'border-border'"
              >
                <input
                  type="radio"
                  v-model="statusForm.status"
                  :value="status.value"
                  class="text-primary"
                />
                <span class="font-medium">{{ status.label }}</span>
              </label>
            </div>

            <div class="flex gap-3">
              <Button variant="outline" class="flex-1" @click="showStatusModal = false">
                Cancel
              </Button>
              <Button class="flex-1" @click="updateStatus" :disabled="statusForm.processing">
                {{ statusForm.processing ? 'Saving...' : 'Save' }}
              </Button>
            </div>
          </CardContent>
        </Card>
      </div>
    </div>

    <!-- Score Modal -->
    <div v-if="showScoreModal" class="fixed inset-0 z-50 overflow-y-auto">
      <div class="flex items-center justify-center min-h-screen px-4">
        <div class="fixed inset-0 bg-black/50" @click="showScoreModal = false"></div>
        <Card class="relative max-w-md w-full">
          <CardHeader>
            <CardTitle>Adjust Score</CardTitle>
          </CardHeader>
          <CardContent>
            <div class="space-y-4 mb-6">
              <div class="space-y-2">
                <Label>
                  Adjustment ({{ scoreForm.score_adjustment >= 0 ? '+' : '' }}{{ scoreForm.score_adjustment }})
                </Label>
                <input
                  type="range"
                  v-model.number="scoreForm.score_adjustment"
                  min="-50"
                  max="50"
                  class="w-full"
                />
                <div class="flex justify-between text-xs text-muted-foreground">
                  <span>-50</span>
                  <span>0</span>
                  <span>+50</span>
                </div>
              </div>

              <div class="space-y-2">
                <Label>Reason (optional)</Label>
                <Input
                  v-model="scoreForm.score_reason"
                  placeholder="e.g., Responded to follow-up call"
                />
              </div>

              <div class="text-center p-4 bg-muted rounded-lg">
                <span class="text-muted-foreground">New score: </span>
                <span class="text-lg font-bold">
                  {{ Math.min(100, Math.max(0, lead.score + scoreForm.score_adjustment)) }}
                </span>
              </div>
            </div>

            <div class="flex gap-3">
              <Button variant="outline" class="flex-1" @click="showScoreModal = false">
                Cancel
              </Button>
              <Button
                class="flex-1"
                @click="adjustScore"
                :disabled="scoreForm.processing || scoreForm.score_adjustment === 0"
              >
                {{ scoreForm.processing ? 'Saving...' : 'Apply' }}
              </Button>
            </div>
          </CardContent>
        </Card>
      </div>
    </div>
  </ClientLayout>
</template>
