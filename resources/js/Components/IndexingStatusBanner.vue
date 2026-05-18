<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { Card } from '@/Components/ui/card'

const page = usePage()
const session = computed(() => page.props.latest_crawl_session)

const banner = computed(() => {
  if (!session.value) return null
  const s = session.value
  switch (s.status) {
    case 'queued':
    case 'running':
      return {
        tone: 'info',
        text: `Indexing your site… ${s.pages_indexed}${s.pages_discovered ? ` of ${s.pages_discovered}` : ''} pages indexed so far.`,
      }
    case 'completed':
      return {
        tone: 'success',
        text: `Indexed ${s.pages_indexed} pages from your site.`,
        link: { href: `/knowledge-base?crawl_session_id=${s.id}`, label: 'View' },
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
        link: { href: `/knowledge-base?crawl_session_id=${s.id}`, label: 'View' },
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
      <span>{{ banner.text }}</span>
      <Link v-if="banner.link" :href="banner.link.href" class="text-sm font-medium underline">{{ banner.link.label }}</Link>
    </div>
  </Card>
</template>
