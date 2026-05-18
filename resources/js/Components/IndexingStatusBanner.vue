<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { useRoute } from '@/composables/useRoute'
import { Card } from '@/Components/ui/card'

const POLL_INTERVAL_MS = 3000
// Grace window for "no session yet" — covers the race between registration
// redirect and the worker actually creating the CrawlSession row.
const NULL_GRACE_MS = 20000
const ACTIVE_STATUSES = ['queued', 'running']

const page = usePage()
const route = useRoute()
const session = ref(page.props.latest_crawl_session)
const mountedAt = ref(0)
let pollTimer = null

const fetchLatest = async () => {
  try {
    const res = await fetch(route('widget.indexing.status'), {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    })
    if (!res.ok) return
    const data = await res.json()
    session.value = data.session
  } catch {
    // network blip — next tick will retry
  }
}

const tick = () => {
  const inActive = session.value && ACTIVE_STATUSES.includes(session.value.status)
  const inGrace = !session.value && Date.now() - mountedAt.value < NULL_GRACE_MS
  if (!inActive && !inGrace) {
    stopPolling()
    return
  }
  fetchLatest()
}

const startPolling = () => {
  if (pollTimer) return
  pollTimer = setInterval(tick, POLL_INTERVAL_MS)
}

const stopPolling = () => {
  if (pollTimer) {
    clearInterval(pollTimer)
    pollTimer = null
  }
}

onMounted(() => {
  mountedAt.value = Date.now()
  if (session.value === null || ACTIVE_STATUSES.includes(session.value?.status)) {
    startPolling()
  }
})

// Re-sync local state when Inertia shared props refresh between page visits
watch(
  () => page.props.latest_crawl_session,
  next => { session.value = next },
)

// Restart polling if a new active session appears (e.g. user clicks Recrawl)
watch(
  () => session.value?.status,
  status => {
    if (status && ACTIVE_STATUSES.includes(status)) startPolling()
  },
)

onBeforeUnmount(stopPolling)

const banner = computed(() => {
  if (!session.value) return null
  const s = session.value
  switch (s.status) {
    case 'queued':
    case 'running':
      return {
        tone: 'info',
        spinner: true,
        text: `Indexing your site… ${s.pages_indexed}${s.pages_discovered ? ` of ${s.pages_discovered}` : ''} pages indexed so far.`,
      }
    case 'completed':
      return {
        tone: 'success',
        text: `Indexed ${s.pages_indexed} pages from your site.`,
        link: { href: `/knowledge?crawl_session_id=${s.id}`, label: 'View' },
      }
    case 'partial':
      if (s.pages_skipped_budget > 0) {
        return {
          tone: 'warning',
          text: `Indexed ${s.pages_indexed} pages — plan limit reached. Upgrade to crawl more.`,
          link: { href: '/billing', label: 'Upgrade' },
        }
      }
      return {
        tone: 'warning',
        text: `Indexed ${s.pages_indexed} pages — some pages could not be processed.`,
        link: { href: `/knowledge?crawl_session_id=${s.id}`, label: 'View' },
      }
    case 'failed':
      return {
        tone: 'error',
        text: s.error_message ? `Indexing failed: ${s.error_message}` : 'Indexing failed.',
        link: { href: '/widget-settings', label: 'Retry' },
      }
    default:
      return null
  }
})

const toneClasses = {
  info: 'bg-blue-50 text-blue-900 border-blue-200',
  success: 'bg-emerald-50 text-emerald-900 border-emerald-200',
  warning: 'bg-amber-50 text-amber-900 border-amber-200',
  error: 'bg-rose-50 text-rose-900 border-rose-200',
}
</script>

<template>
  <Card v-if="banner" :class="['p-4 border', toneClasses[banner.tone]]">
    <div class="flex items-center justify-between gap-4">
      <div class="flex items-center gap-3">
        <svg
          v-if="banner.spinner"
          class="h-4 w-4 animate-spin text-current"
          xmlns="http://www.w3.org/2000/svg"
          fill="none"
          viewBox="0 0 24 24"
          aria-hidden="true"
        >
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
        </svg>
        <span>{{ banner.text }}</span>
      </div>
      <Link v-if="banner.link" :href="banner.link.href" class="text-sm font-medium underline">{{ banner.link.label }}</Link>
    </div>
  </Card>
</template>
