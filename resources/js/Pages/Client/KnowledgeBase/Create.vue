<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import { useRoute } from '@/composables/useRoute'
import ClientLayout from '@/Layouts/ClientLayout.vue'
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card'
import { Button } from '@/Components/ui/button'
import { Input } from '@/Components/ui/input'
import { Label } from '@/Components/ui/label'
import { Textarea } from '@/Components/ui/textarea'
import {
  FileText,
  HelpCircle,
  Globe,
  AlignLeft,
  Upload,
} from 'lucide-vue-next'

const route = useRoute()

const activeTab = ref('document')

const form = useForm({
  type: 'document',
  title: '',
  content: '',
  source_url: '',
  file: null,
})

const tabs = [
  { id: 'document', label: 'Document', icon: FileText },
  { id: 'faq', label: 'FAQ', icon: HelpCircle },
  { id: 'webpage', label: 'Webpage', icon: Globe },
  { id: 'text', label: 'Text', icon: AlignLeft },
]

const selectTab = (tabId) => {
  activeTab.value = tabId
  form.type = tabId
  form.clearErrors()
}

const handleFileChange = (event) => {
  form.file = event.target.files[0]
}

const submit = () => {
  form.post(route('client.knowledge.store'), {
    forceFormData: true,
  })
}
</script>

<template>
  <Head title="Add Knowledge" />

  <ClientLayout>
    <div class="max-w-3xl mx-auto space-y-6">
      <!-- Header -->
      <div>
        <h1 class="text-2xl font-bold text-foreground">Add Knowledge</h1>
        <p class="text-muted-foreground mt-1">Add content to train your chatbot</p>
      </div>

      <Card>
        <!-- Tabs -->
        <div class="border-b border-border">
          <nav class="flex">
            <button
              v-for="tab in tabs"
              :key="tab.id"
              @click="selectTab(tab.id)"
              :class="[
                'flex-1 py-4 px-1 text-center border-b-2 font-medium text-sm transition-colors',
                activeTab === tab.id
                  ? 'border-primary text-primary'
                  : 'border-transparent text-muted-foreground hover:text-foreground hover:border-border'
              ]"
            >
              <component :is="tab.icon" class="h-5 w-5 mx-auto mb-1" />
              {{ tab.label }}
            </button>
          </nav>
        </div>

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
                :placeholder="activeTab === 'faq' ? 'e.g., What are your business hours?' : 'Enter a descriptive title'"
              />
              <p v-if="form.errors.title" class="text-sm text-destructive">{{ form.errors.title }}</p>
            </div>

            <!-- Document Upload -->
            <div v-if="activeTab === 'document'" class="space-y-2">
              <Label>Upload Document</Label>
              <div class="flex justify-center px-6 pt-5 pb-6 border-2 border-dashed border-border rounded-lg hover:border-primary/50 transition-colors">
                <div class="space-y-2 text-center">
                  <Upload class="mx-auto h-12 w-12 text-muted-foreground" />
                  <div class="flex text-sm text-muted-foreground">
                    <label for="file" class="relative cursor-pointer rounded-md font-medium text-primary hover:text-primary/80 focus-within:outline-none">
                      <span>Upload a file</span>
                      <input id="file" type="file" class="sr-only" @change="handleFileChange" accept=".pdf,.doc,.docx,.txt,.md" />
                    </label>
                    <p class="pl-1">or drag and drop</p>
                  </div>
                  <p class="text-xs text-muted-foreground">PDF, DOC, DOCX, TXT, MD up to 10MB</p>
                </div>
              </div>
              <p v-if="form.file" class="text-sm text-muted-foreground">Selected: {{ form.file.name }}</p>
              <p v-if="form.errors.file" class="text-sm text-destructive">{{ form.errors.file }}</p>
            </div>

            <!-- FAQ Content -->
            <div v-if="activeTab === 'faq'" class="space-y-2">
              <Label for="content">Answer</Label>
              <Textarea
                id="content"
                v-model="form.content"
                :rows="6"
                required
                placeholder="Enter the answer to your FAQ question..."
              />
              <p v-if="form.errors.content" class="text-sm text-destructive">{{ form.errors.content }}</p>
            </div>

            <!-- Webpage URL -->
            <div v-if="activeTab === 'webpage'" class="space-y-2">
              <Label for="source_url">Website URL</Label>
              <Input
                id="source_url"
                v-model="form.source_url"
                type="url"
                required
                placeholder="https://example.com/page"
              />
              <p class="text-sm text-muted-foreground">We'll crawl this page and extract its content.</p>
              <p v-if="form.errors.source_url" class="text-sm text-destructive">{{ form.errors.source_url }}</p>
            </div>

            <!-- Raw Text -->
            <div v-if="activeTab === 'text'" class="space-y-2">
              <Label for="text_content">Text Content</Label>
              <Textarea
                id="text_content"
                v-model="form.content"
                :rows="10"
                required
                placeholder="Paste your text content here..."
              />
              <p v-if="form.errors.content" class="text-sm text-destructive">{{ form.errors.content }}</p>
            </div>

            <!-- Submit -->
            <div class="flex justify-end gap-3">
              <Button variant="outline" as-child>
                <Link :href="route('client.knowledge.index')">Cancel</Link>
              </Button>
              <Button type="submit" :disabled="form.processing">
                {{ form.processing ? 'Processing...' : 'Add Knowledge' }}
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>
    </div>
  </ClientLayout>
</template>
