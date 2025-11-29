<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import { useRoute } from '@/composables/useRoute'
import ClientLayout from '@/Layouts/ClientLayout.vue'
import { Card, CardContent } from '@/Components/ui/card'
import { Button } from '@/Components/ui/button'
import { Badge } from '@/Components/ui/badge'
import {
  FileText,
  HelpCircle,
  Globe,
  AlignLeft,
  Plus,
  Pencil,
  Trash2,
} from 'lucide-vue-next'

const route = useRoute()

defineProps({
  items: Array,
  stats: Object,
})

const deleteItem = (id) => {
  if (confirm('Are you sure you want to delete this item?')) {
    router.delete(route('client.knowledge.destroy', id))
  }
}

const getStatusVariant = (status) => {
  return {
    pending: 'warning',
    processing: 'secondary',
    ready: 'success',
    failed: 'destructive',
  }[status] || 'secondary'
}

const getTypeIcon = (type) => {
  return {
    document: FileText,
    faq: HelpCircle,
    webpage: Globe,
    text: AlignLeft,
  }[type] || FileText
}
</script>

<template>
  <Head title="Knowledge Base" />

  <ClientLayout>
    <div class="space-y-6">
      <!-- Header -->
      <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-foreground">Knowledge Base</h1>
        <Button as-child>
          <Link :href="route('client.knowledge.create')">
            <Plus class="h-4 w-4 mr-2" />
            Add Knowledge
          </Link>
        </Button>
      </div>

      <!-- Stats Grid -->
      <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
        <Card>
          <CardContent class="p-4">
            <div class="flex items-center gap-3">
              <div class="rounded-lg bg-blue-100 p-2">
                <FileText class="h-5 w-5 text-blue-600" />
              </div>
              <div>
                <p class="text-sm text-muted-foreground">Documents</p>
                <p class="text-xl font-semibold">{{ stats?.documents ?? 0 }}</p>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardContent class="p-4">
            <div class="flex items-center gap-3">
              <div class="rounded-lg bg-purple-100 p-2">
                <HelpCircle class="h-5 w-5 text-purple-600" />
              </div>
              <div>
                <p class="text-sm text-muted-foreground">FAQs</p>
                <p class="text-xl font-semibold">{{ stats?.faqs ?? 0 }}</p>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardContent class="p-4">
            <div class="flex items-center gap-3">
              <div class="rounded-lg bg-green-100 p-2">
                <Globe class="h-5 w-5 text-green-600" />
              </div>
              <div>
                <p class="text-sm text-muted-foreground">Webpages</p>
                <p class="text-xl font-semibold">{{ stats?.webpages ?? 0 }}</p>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardContent class="p-4">
            <div class="flex items-center gap-3">
              <div class="rounded-lg bg-amber-100 p-2">
                <AlignLeft class="h-5 w-5 text-amber-600" />
              </div>
              <div>
                <p class="text-sm text-muted-foreground">Text Snippets</p>
                <p class="text-xl font-semibold">{{ stats?.text ?? 0 }}</p>
              </div>
            </div>
          </CardContent>
        </Card>
      </div>

      <!-- Items List -->
      <Card>
        <CardContent class="p-0">
          <div v-if="!items || items.length === 0" class="text-center py-12">
            <FileText class="mx-auto h-12 w-12 text-muted-foreground" />
            <h3 class="mt-2 text-sm font-medium text-foreground">No knowledge items</h3>
            <p class="mt-1 text-sm text-muted-foreground">Get started by adding your first knowledge item.</p>
            <div class="mt-6">
              <Button as-child>
                <Link :href="route('client.knowledge.create')">
                  <Plus class="h-4 w-4 mr-2" />
                  Add Knowledge
                </Link>
              </Button>
            </div>
          </div>

          <ul v-else class="divide-y divide-border">
            <li v-for="item in items" :key="item.id" class="px-4 py-4 sm:px-6 hover:bg-accent/50 transition-colors">
              <div class="flex items-center justify-between gap-4">
                <div class="flex items-center min-w-0 gap-4">
                  <div class="flex-shrink-0 rounded-lg bg-muted p-2">
                    <component :is="getTypeIcon(item.type)" class="h-5 w-5 text-muted-foreground" />
                  </div>
                  <div class="min-w-0">
                    <Link
                      :href="route('client.knowledge.show', item.id)"
                      class="text-sm font-medium text-primary hover:underline truncate block"
                    >
                      {{ item.title }}
                    </Link>
                    <p class="text-sm text-muted-foreground">
                      <span class="capitalize">{{ item.type }}</span>
                      <span class="mx-1">&middot;</span>
                      {{ item.chunks_count }} chunks
                      <span class="mx-1">&middot;</span>
                      {{ item.created_at }}
                    </p>
                  </div>
                </div>
                <div class="flex items-center gap-3 flex-shrink-0">
                  <Badge :variant="getStatusVariant(item.status)">
                    {{ item.status }}
                  </Badge>
                  <div class="flex items-center gap-1">
                    <Button variant="ghost" size="icon" as-child>
                      <Link :href="route('client.knowledge.edit', item.id)">
                        <Pencil class="h-4 w-4" />
                      </Link>
                    </Button>
                    <Button variant="ghost" size="icon" @click="deleteItem(item.id)">
                      <Trash2 class="h-4 w-4 text-destructive" />
                    </Button>
                  </div>
                </div>
              </div>
            </li>
          </ul>
        </CardContent>
      </Card>
    </div>
  </ClientLayout>
</template>
