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
import { AlertCircle } from 'lucide-vue-next'

const route = useRoute()

const props = defineProps({
  item: Object,
})

const form = useForm({
  title: props.item.title,
  content: props.item.content || '',
  source_url: props.item.source_url || '',
})

const submit = () => {
  form.put(route('client.knowledge.update', props.item.id))
}
</script>

<template>
  <Head :title="`Edit: ${item.title}`" />

  <ClientLayout>
    <div class="max-w-3xl mx-auto space-y-6">
      <!-- Header -->
      <div>
        <h1 class="text-2xl font-bold text-foreground">Edit Knowledge Item</h1>
        <p class="text-muted-foreground mt-1">Update your knowledge item</p>
      </div>

      <Card>
        <CardContent class="p-6">
          <form @submit.prevent="submit" class="space-y-6">
            <!-- Title -->
            <div class="space-y-2">
              <Label for="title">Title</Label>
              <Input
                id="title"
                v-model="form.title"
                type="text"
                required
              />
              <p v-if="form.errors.title" class="text-sm text-destructive">{{ form.errors.title }}</p>
            </div>

            <!-- Source URL (for webpages) -->
            <div v-if="item.type === 'webpage'" class="space-y-2">
              <Label for="source_url">Source URL</Label>
              <Input
                id="source_url"
                v-model="form.source_url"
                type="url"
              />
              <p v-if="form.errors.source_url" class="text-sm text-destructive">{{ form.errors.source_url }}</p>
            </div>

            <!-- Content (for FAQ and text) -->
            <div v-if="item.type === 'faq' || item.type === 'text'" class="space-y-2">
              <Label for="content">Content</Label>
              <Textarea
                id="content"
                v-model="form.content"
                :rows="10"
              />
              <p v-if="form.errors.content" class="text-sm text-destructive">{{ form.errors.content }}</p>
            </div>

            <!-- Document notice -->
            <Alert v-if="item.type === 'document'" variant="warning">
              <AlertCircle class="h-4 w-4" />
              <AlertDescription>
                To update the document content, please delete this item and upload a new file.
              </AlertDescription>
            </Alert>

            <!-- Submit -->
            <div class="flex justify-end gap-3">
              <Button variant="outline" as-child>
                <Link :href="route('client.knowledge.show', item.id)">Cancel</Link>
              </Button>
              <Button type="submit" :disabled="form.processing">
                {{ form.processing ? 'Saving...' : 'Save Changes' }}
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>
    </div>
  </ClientLayout>
</template>
