<script setup>
import { computed } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { ArrowLeft, Download, Archive, ArchiveRestore, ExternalLink } from 'lucide-vue-next'
import ClientLayout from '@/Layouts/ClientLayout.vue'

const props = defineProps({
  conversation: Object,
})

function statusBadgeClass(s) {
  return {
    active: 'bg-green-500/10 text-green-700 dark:text-green-400',
    closed: 'bg-gray-500/10 text-gray-700 dark:text-gray-400',
    archived: 'bg-orange-500/10 text-orange-700 dark:text-orange-400',
  }[s] || 'bg-gray-500/10 text-gray-700'
}

function leadScoreLabel(score) {
  if (score >= 70) return { label: 'Hot', cls: 'text-red-600 dark:text-red-400' }
  if (score >= 40) return { label: 'Warm', cls: 'text-orange-600 dark:text-orange-400' }
  return { label: 'Cold', cls: 'text-blue-600 dark:text-blue-400' }
}

const leadBadge = computed(() =>
  props.conversation.lead ? leadScoreLabel(props.conversation.lead.score ?? 0) : null,
)

function formatTime(iso) {
  return new Date(iso).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
}

function formatDateTime(iso) {
  return new Date(iso).toLocaleString('en-GB', {
    year: 'numeric', month: 'short', day: '2-digit',
    hour: '2-digit', minute: '2-digit',
  })
}

function truncateMid(s, n = 16) {
  if (!s || s.length <= n) return s
  const half = Math.floor(n / 2)
  return s.slice(0, half) + '…' + s.slice(-half)
}

function archive() {
  if (!confirm('Archive this conversation?')) return
  router.put(`/conversations/${props.conversation.id}/archive`)
}

function unarchive() {
  if (!confirm('Restore this conversation?')) return
  router.put(`/conversations/${props.conversation.id}/unarchive`)
}
</script>

<template>
  <Head :title="`Conversation #${conversation.id}`" />
  <ClientLayout>
    <div class="px-4 py-6 sm:px-6 lg:px-8">
      <Link href="/conversations" class="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground">
        <ArrowLeft class="h-4 w-4" />
        Back to conversations
      </Link>

      <div class="mt-4 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">Conversation #{{ conversation.id }}</h1>
        <span class="rounded-full px-3 py-1 text-xs font-medium" :class="statusBadgeClass(conversation.status)">{{ conversation.status }}</span>
      </div>

      <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-[1fr_22rem]">
        <!-- Transcript column -->
        <div class="rounded-lg border bg-card p-6">
          <div v-if="conversation.messages.length === 0" class="py-12 text-center text-sm text-muted-foreground">
            No messages in this conversation.
          </div>
          <div v-else class="space-y-4">
            <div
              v-for="m in conversation.messages"
              :key="m.id"
              class="flex"
              :class="m.role === 'assistant' ? 'justify-end' : 'justify-start'"
            >
              <div class="max-w-[75%]">
                <div
                  class="rounded-2xl px-4 py-2 text-sm"
                  :class="m.role === 'assistant' ? 'bg-primary/10 text-foreground' : 'bg-muted text-foreground'"
                >
                  <p class="whitespace-pre-wrap">{{ m.content }}</p>
                </div>
                <p class="mt-1 px-1 text-xs text-muted-foreground" :class="m.role === 'assistant' ? 'text-right' : 'text-left'" :title="m.created_at">
                  {{ formatTime(m.created_at) }}
                </p>
              </div>
            </div>
          </div>
        </div>

        <!-- Right sidebar -->
        <div class="space-y-4">
          <!-- Metadata card -->
          <div class="rounded-lg border bg-card p-4">
            <h3 class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Metadata</h3>
            <dl class="mt-3 space-y-2 text-sm">
              <div>
                <dt class="text-muted-foreground">Started</dt>
                <dd>{{ formatDateTime(conversation.created_at) }}</dd>
              </div>
              <div>
                <dt class="text-muted-foreground">Session</dt>
                <dd class="font-mono text-xs" :title="conversation.session_id">{{ truncateMid(conversation.session_id, 24) }}</dd>
              </div>
              <div v-if="conversation.metadata?.ip">
                <dt class="text-muted-foreground">IP</dt>
                <dd>{{ conversation.metadata.ip }}</dd>
              </div>
              <div v-if="conversation.metadata?.user_agent">
                <dt class="text-muted-foreground">User agent</dt>
                <dd class="break-words text-xs">{{ conversation.metadata.user_agent }}</dd>
              </div>
            </dl>
          </div>

          <!-- Lead card -->
          <div v-if="conversation.lead" class="rounded-lg border bg-card p-4">
            <h3 class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Lead</h3>
            <div class="mt-3 space-y-1 text-sm">
              <p class="font-medium">{{ conversation.lead.name || '(unnamed)' }}</p>
              <p class="text-muted-foreground">{{ conversation.lead.email }}</p>
              <p>
                Score: {{ conversation.lead.score ?? 0 }}
                <span v-if="leadBadge" class="ml-1 font-medium" :class="leadBadge.cls">{{ leadBadge.label }}</span>
              </p>
              <Link :href="`/leads/${conversation.lead.id}`" class="mt-2 inline-flex items-center gap-1 text-sm text-primary hover:underline">
                View lead <ExternalLink class="h-3 w-3" />
              </Link>
            </div>
          </div>

          <!-- Actions card -->
          <div class="rounded-lg border bg-card p-4">
            <h3 class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Actions</h3>
            <div class="mt-3 space-y-2">
              <a
                :href="`/conversations/${conversation.id}/export`"
                class="flex w-full items-center justify-center gap-2 rounded-md border px-3 py-2 text-sm hover:bg-muted"
              >
                <Download class="h-4 w-4" />
                Export transcript
              </a>
              <button
                v-if="conversation.status !== 'archived'"
                @click="archive"
                class="flex w-full items-center justify-center gap-2 rounded-md border px-3 py-2 text-sm hover:bg-muted"
              >
                <Archive class="h-4 w-4" />
                Archive
              </button>
              <button
                v-else
                @click="unarchive"
                class="flex w-full items-center justify-center gap-2 rounded-md border px-3 py-2 text-sm hover:bg-muted"
              >
                <ArchiveRestore class="h-4 w-4" />
                Unarchive
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </ClientLayout>
</template>
