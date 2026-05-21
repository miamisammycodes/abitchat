<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import { useRoute } from '@/composables/useRoute'
import ClientLayout from '@/Layouts/ClientLayout.vue'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card'
import { Button } from '@/Components/ui/button'
import { Badge } from '@/Components/ui/badge'
import {
  RefreshCw,
  Pencil,
  Trash2,
  ExternalLink,
} from 'lucide-vue-next'

const route = useRoute()

const props = defineProps({
  item: Object,
  chunks: { type: Array, default: () => [] },
  chunks_with_embedding: { type: Number, default: 0 },
})

const embeddingSummary = () => {
  const total = props.chunks.length
  if (total === 0) return null
  if (props.chunks_with_embedding === total) return `${total} embedded`
  return `${props.chunks_with_embedding} / ${total} embedded`
}

const deleteItem = () => {
  if (confirm('Are you sure you want to delete this item?')) {
    router.delete(route('client.knowledge.destroy', props.item.id))
  }
}

const reprocess = () => {
  router.post(route('client.knowledge.reprocess', props.item.id))
}

const getStatusVariant = (status) => {
  return {
    pending: 'warning',
    processing: 'secondary',
    ready: 'success',
    failed: 'destructive',
  }[status] || 'secondary'
}
</script>

<template>
  <Head :title="item.title" />

  <ClientLayout>
    <div class="max-w-4xl mx-auto space-y-6">
      <!-- Header -->
      <div class="flex items-start justify-between gap-4">
        <div>
          <h1 class="text-2xl font-bold text-foreground">{{ item.title }}</h1>
          <p class="text-muted-foreground mt-1">Knowledge item details</p>
        </div>
        <div class="flex items-center gap-2 flex-shrink-0">
          <Button v-if="$page.props.auth.user.can.manage_knowledge_base" variant="outline" size="sm" @click="reprocess">
            <RefreshCw class="h-4 w-4 mr-2" />
            Reprocess
          </Button>
          <Button v-if="$page.props.auth.user.can.manage_knowledge_base" variant="outline" size="sm" as-child>
            <Link :href="route('client.knowledge.edit', item.id)">
              <Pencil class="h-4 w-4 mr-2" />
              Edit
            </Link>
          </Button>
          <Button v-if="$page.props.auth.user.can.manage_knowledge_base" variant="outline" size="sm" @click="deleteItem" class="text-destructive hover:text-destructive">
            <Trash2 class="h-4 w-4 mr-2" />
            Delete
          </Button>
        </div>
      </div>

      <!-- Details Card -->
      <Card>
        <CardHeader class="flex flex-row items-center justify-between">
          <div>
            <CardTitle>Knowledge Item Details</CardTitle>
            <CardDescription>Information about this knowledge item</CardDescription>
          </div>
          <Badge :variant="getStatusVariant(item.status)">
            {{ item.status }}
          </Badge>
        </CardHeader>
        <CardContent class="p-0">
          <dl class="divide-y divide-border">
            <div class="px-6 py-4 grid grid-cols-3 gap-4">
              <dt class="text-sm font-medium text-muted-foreground">Type</dt>
              <dd class="text-sm text-foreground col-span-2 capitalize">{{ item.type }}</dd>
            </div>
            <div class="px-6 py-4 grid grid-cols-3 gap-4 bg-muted/30">
              <dt class="text-sm font-medium text-muted-foreground">Chunks</dt>
              <dd class="text-sm text-foreground col-span-2">{{ item.chunks_count }}</dd>
            </div>
            <div v-if="item.source_url" class="px-6 py-4 grid grid-cols-3 gap-4">
              <dt class="text-sm font-medium text-muted-foreground">Source URL</dt>
              <dd class="text-sm col-span-2">
                <a
                  :href="item.source_url"
                  target="_blank"
                  class="text-primary hover:underline inline-flex items-center gap-1"
                >
                  {{ item.source_url }}
                  <ExternalLink class="h-3 w-3" />
                </a>
              </dd>
            </div>
            <div v-if="item.metadata?.original_name" class="px-6 py-4 grid grid-cols-3 gap-4">
              <dt class="text-sm font-medium text-muted-foreground">Original File</dt>
              <dd class="text-sm text-foreground col-span-2">{{ item.metadata.original_name }}</dd>
            </div>
            <div class="px-6 py-4 grid grid-cols-3 gap-4 bg-muted/30">
              <dt class="text-sm font-medium text-muted-foreground">Created</dt>
              <dd class="text-sm text-foreground col-span-2">{{ item.created_at }}</dd>
            </div>
            <div class="px-6 py-4 grid grid-cols-3 gap-4">
              <dt class="text-sm font-medium text-muted-foreground">Last Updated</dt>
              <dd class="text-sm text-foreground col-span-2">{{ item.updated_at }}</dd>
            </div>
            <div v-if="item.content" class="px-6 py-4">
              <dt class="text-sm font-medium text-muted-foreground mb-3">Content Preview</dt>
              <dd class="text-sm text-foreground bg-muted/50 p-4 rounded-lg max-h-96 overflow-y-auto whitespace-pre-wrap font-mono">
                {{ item.content }}
              </dd>
            </div>
          </dl>
        </CardContent>
      </Card>

      <!-- Chunks Card -->
      <Card>
        <CardHeader class="flex flex-row items-center justify-between">
          <div>
            <CardTitle>Chunks</CardTitle>
            <CardDescription>Text segments indexed for retrieval</CardDescription>
          </div>
          <Badge v-if="embeddingSummary()" variant="secondary">
            {{ embeddingSummary() }}
          </Badge>
        </CardHeader>
        <CardContent>
          <p v-if="chunks.length === 0" class="text-sm text-muted-foreground">
            No chunks yet — item has not been processed, or processing failed.
          </p>
          <div v-else class="space-y-3">
            <div
              v-for="chunk in chunks"
              :key="chunk.index"
              class="border border-border rounded-lg overflow-hidden"
            >
              <div class="flex items-center justify-between px-4 py-2 bg-muted/40 text-sm">
                <span class="font-mono font-medium text-foreground">Chunk #{{ chunk.index }}</span>
                <span class="text-muted-foreground">{{ chunk.chars }} chars</span>
              </div>
              <pre class="px-4 py-3 text-sm text-foreground whitespace-pre-wrap font-mono max-h-48 overflow-y-auto">{{ chunk.content }}</pre>
            </div>
          </div>
        </CardContent>
      </Card>
    </div>
  </ClientLayout>
</template>
