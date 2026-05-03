<script setup>
import { ref } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { MessageCircle } from 'lucide-vue-next'
import ClientLayout from '@/Layouts/ClientLayout.vue'

const props = defineProps({
  conversations: Object,
  filters: Object,
})

const status = ref(props.filters?.status ?? '')
const from = ref(props.filters?.from ?? '')
const to = ref(props.filters?.to ?? '')
const hasLead = ref(Boolean(props.filters?.has_lead))

function applyFilters() {
  router.get('/conversations', {
    status: status.value || undefined,
    from: from.value || undefined,
    to: to.value || undefined,
    has_lead: hasLead.value ? 1 : undefined,
  }, { preserveState: true, preserveScroll: true })
}

function statusBadgeClass(s) {
  return {
    active: 'bg-green-500/10 text-green-700 dark:text-green-400',
    closed: 'bg-gray-500/10 text-gray-700 dark:text-gray-400',
    archived: 'bg-orange-500/10 text-orange-700 dark:text-orange-400',
  }[s] || 'bg-gray-500/10 text-gray-700'
}

function relativeTime(iso) {
  const ms = Date.now() - new Date(iso).getTime()
  const min = Math.round(ms / 60000)
  if (min < 1) return 'just now'
  if (min < 60) return `${min}m ago`
  const hr = Math.round(min / 60)
  if (hr < 24) return `${hr}h ago`
  const d = Math.round(hr / 24)
  return `${d}d ago`
}

function truncate(text, n = 80) {
  if (!text) return ''
  return text.length > n ? text.slice(0, n) + '…' : text
}
</script>

<template>
  <Head title="Conversations" />
  <ClientLayout>
    <div class="px-4 py-6 sm:px-6 lg:px-8">
      <div class="flex items-center justify-between">
        <h1 class="text-2xl font-semibold">Conversations</h1>
      </div>

      <!-- Filter strip -->
      <div class="mt-6 flex flex-wrap items-end gap-4 rounded-lg border bg-card p-4">
        <div>
          <label class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Status</label>
          <select v-model="status" @change="applyFilters" class="mt-1 block rounded-md border bg-background px-3 py-2 text-sm">
            <option value="">Active + Closed</option>
            <option value="active">Active</option>
            <option value="closed">Closed</option>
            <option value="archived">Archived</option>
            <option value="all">All</option>
          </select>
        </div>
        <div>
          <label class="text-xs font-medium uppercase tracking-wide text-muted-foreground">From</label>
          <input v-model="from" @change="applyFilters" type="date" class="mt-1 block rounded-md border bg-background px-3 py-2 text-sm" />
        </div>
        <div>
          <label class="text-xs font-medium uppercase tracking-wide text-muted-foreground">To</label>
          <input v-model="to" @change="applyFilters" type="date" class="mt-1 block rounded-md border bg-background px-3 py-2 text-sm" />
        </div>
        <label class="ml-2 flex cursor-pointer items-center gap-2 text-sm">
          <input v-model="hasLead" @change="applyFilters" type="checkbox" class="rounded border" />
          Has lead
        </label>
      </div>

      <!-- Empty state -->
      <div v-if="conversations.data.length === 0" class="mt-12 flex flex-col items-center text-center">
        <MessageCircle class="h-12 w-12 text-muted-foreground" />
        <p class="mt-4 text-sm text-muted-foreground">No conversations yet — add the widget to your site to start collecting them.</p>
        <Link href="/widget-settings" class="mt-2 text-sm text-primary underline">Go to widget settings</Link>
      </div>

      <!-- Table -->
      <div v-else class="mt-6 overflow-hidden rounded-lg border bg-card">
        <table class="min-w-full divide-y">
          <thead class="bg-muted/40">
            <tr class="text-left text-xs font-medium uppercase tracking-wide text-muted-foreground">
              <th class="px-4 py-3">When</th>
              <th class="px-4 py-3">Last message</th>
              <th class="px-4 py-3">Status</th>
              <th class="px-4 py-3">Lead</th>
              <th class="px-4 py-3 text-right">Messages</th>
            </tr>
          </thead>
          <tbody class="divide-y">
            <tr
              v-for="conv in conversations.data"
              :key="conv.id"
              @click="router.visit(`/conversations/${conv.id}`)"
              class="cursor-pointer hover:bg-muted/40"
            >
              <td class="whitespace-nowrap px-4 py-3 text-sm" :title="conv.created_at">{{ relativeTime(conv.created_at) }}</td>
              <td class="px-4 py-3 text-sm text-muted-foreground">{{ truncate(conv.latest_message?.content) || '—' }}</td>
              <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusBadgeClass(conv.status)">{{ conv.status }}</span></td>
              <td class="px-4 py-3 text-sm">
                <span v-if="conv.lead" class="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary">✓ captured</span>
                <span v-else class="text-muted-foreground">—</span>
              </td>
              <td class="px-4 py-3 text-right text-sm">{{ conv.messages_count }}</td>
            </tr>
          </tbody>
        </table>

        <!-- Pagination footer -->
        <div class="flex items-center justify-between border-t bg-muted/20 px-4 py-3 text-sm">
          <span class="text-muted-foreground">
            Showing {{ conversations.from }}–{{ conversations.to }} of {{ conversations.total }}
          </span>
          <div class="flex gap-1">
            <Link
              v-for="link in conversations.links"
              :key="link.label"
              :href="link.url || ''"
              v-html="link.label"
              :class="[
                'rounded px-3 py-1 text-sm',
                link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted',
                !link.url && 'pointer-events-none text-muted-foreground/50',
              ]"
              preserve-state
              preserve-scroll
            />
          </div>
        </div>
      </div>
    </div>
  </ClientLayout>
</template>
