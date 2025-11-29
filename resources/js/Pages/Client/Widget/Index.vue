<script setup>
import { ref, computed } from 'vue'
import { Head, Link, useForm, usePage, router } from '@inertiajs/vue3'
import { useRoute } from '@/composables/useRoute'
import ClientLayout from '@/Layouts/ClientLayout.vue'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card'
import { Button } from '@/Components/ui/button'
import { Input } from '@/Components/ui/input'
import { Label } from '@/Components/ui/label'
import { Textarea } from '@/Components/ui/textarea'
import { Alert, AlertDescription } from '@/Components/ui/alert'
import {
  Copy,
  Check,
  Eye,
  EyeOff,
  RefreshCw,
  MessageSquare,
  Send,
} from 'lucide-vue-next'

const route = useRoute()

const props = defineProps({
  tenant: Object,
  embedUrl: String,
})

const page = usePage()

const form = useForm({
  welcome_message: props.tenant.settings?.welcome_message || 'Hello! How can I help you today?',
  primary_color: props.tenant.settings?.primary_color || '#4F46E5',
  position: props.tenant.settings?.position || 'bottom-right',
  bot_name: props.tenant.settings?.bot_name || props.tenant.name,
  offline_message: props.tenant.settings?.offline_message || 'We are currently offline. Please leave a message.',
})

const showApiKey = ref(false)
const copied = ref(false)

const embedCode = computed(() => {
  return `<script
    src="${props.embedUrl}"
    data-chatbot-key="${props.tenant.api_key}"
    data-chatbot-url="${page.props.ziggy?.url || window.location.origin}"
    data-chatbot-position="${form.position}"
    data-chatbot-color="${form.primary_color}">
<\/script>`
})

function copyEmbedCode() {
  navigator.clipboard.writeText(embedCode.value)
  copied.value = true
  setTimeout(() => copied.value = false, 2000)
}

function copyApiKey() {
  navigator.clipboard.writeText(props.tenant.api_key)
  copied.value = true
  setTimeout(() => copied.value = false, 2000)
}

function saveSettings() {
  form.put(route('client.widget.update'))
}

function regenerateApiKey() {
  if (confirm('Are you sure? This will invalidate your current embed code and require updating all installations.')) {
    router.post(route('client.widget.regenerate-key'))
  }
}
</script>

<template>
  <Head title="Widget Settings" />

  <ClientLayout>
    <div class="space-y-6">
      <!-- Header -->
      <div>
        <h1 class="text-2xl font-bold text-foreground">Widget Settings</h1>
        <p class="text-muted-foreground mt-1">Customize and embed your chatbot</p>
      </div>

      <!-- Success Message -->
      <Alert v-if="page.props.flash?.success" variant="success" class="border-green-200 bg-green-50 text-green-800">
        <Check class="h-4 w-4" />
        <AlertDescription>{{ page.props.flash.success }}</AlertDescription>
      </Alert>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Settings Form -->
        <div class="space-y-6">
          <!-- Embed Code Card -->
          <Card>
            <CardHeader>
              <CardTitle>Embed Code</CardTitle>
              <CardDescription>
                Add this code to your website, just before the closing <code class="bg-muted px-1 rounded text-sm">&lt;/body&gt;</code> tag.
              </CardDescription>
            </CardHeader>
            <CardContent>
              <div class="relative">
                <pre class="bg-zinc-900 text-zinc-100 p-4 rounded-lg text-sm overflow-x-auto"><code>{{ embedCode }}</code></pre>
                <Button
                  variant="secondary"
                  size="sm"
                  class="absolute top-2 right-2"
                  @click="copyEmbedCode"
                >
                  <Check v-if="copied" class="h-4 w-4 mr-1" />
                  <Copy v-else class="h-4 w-4 mr-1" />
                  {{ copied ? 'Copied!' : 'Copy' }}
                </Button>
              </div>
            </CardContent>
          </Card>

          <!-- API Key Card -->
          <Card>
            <CardHeader>
              <CardTitle>API Key</CardTitle>
            </CardHeader>
            <CardContent class="space-y-4">
              <div class="flex items-center gap-2">
                <div class="flex-1 relative">
                  <Input
                    :type="showApiKey ? 'text' : 'password'"
                    :model-value="tenant.api_key"
                    readonly
                    class="pr-10 font-mono"
                  />
                  <Button
                    variant="ghost"
                    size="icon"
                    class="absolute right-0 top-0 h-full"
                    @click="showApiKey = !showApiKey"
                  >
                    <EyeOff v-if="showApiKey" class="h-4 w-4" />
                    <Eye v-else class="h-4 w-4" />
                  </Button>
                </div>
                <Button variant="outline" @click="copyApiKey">
                  <Copy class="h-4 w-4 mr-2" />
                  Copy
                </Button>
              </div>
              <Button variant="ghost" size="sm" class="text-destructive" @click="regenerateApiKey">
                <RefreshCw class="h-4 w-4 mr-2" />
                Regenerate API Key
              </Button>
            </CardContent>
          </Card>

          <!-- Customization Card -->
          <Card>
            <CardHeader>
              <CardTitle>Customization</CardTitle>
            </CardHeader>
            <CardContent>
              <form @submit.prevent="saveSettings" class="space-y-4">
                <div class="space-y-2">
                  <Label for="bot_name">Bot Name</Label>
                  <Input
                    id="bot_name"
                    v-model="form.bot_name"
                    placeholder="Assistant"
                  />
                </div>

                <div class="space-y-2">
                  <Label for="welcome_message">Welcome Message</Label>
                  <Textarea
                    id="welcome_message"
                    v-model="form.welcome_message"
                    :rows="3"
                    placeholder="Hello! How can I help you today?"
                  />
                </div>

                <div class="space-y-2">
                  <Label>Primary Color</Label>
                  <div class="flex items-center gap-3">
                    <input
                      v-model="form.primary_color"
                      type="color"
                      class="w-12 h-10 rounded border border-input cursor-pointer"
                    />
                    <Input
                      v-model="form.primary_color"
                      class="flex-1 font-mono"
                      placeholder="#4F46E5"
                    />
                  </div>
                </div>

                <div class="space-y-2">
                  <Label for="position">Widget Position</Label>
                  <select
                    id="position"
                    v-model="form.position"
                    class="w-full px-3 py-2 border border-input rounded-md bg-background text-foreground"
                  >
                    <option value="bottom-right">Bottom Right</option>
                    <option value="bottom-left">Bottom Left</option>
                  </select>
                </div>

                <div class="space-y-2">
                  <Label for="offline_message">Offline Message</Label>
                  <Textarea
                    id="offline_message"
                    v-model="form.offline_message"
                    :rows="2"
                    placeholder="We are currently offline..."
                  />
                </div>

                <Button type="submit" class="w-full" :disabled="form.processing">
                  {{ form.processing ? 'Saving...' : 'Save Settings' }}
                </Button>
              </form>
            </CardContent>
          </Card>
        </div>

        <!-- Preview -->
        <div class="lg:sticky lg:top-8 h-fit">
          <Card>
            <CardHeader>
              <CardTitle>Preview</CardTitle>
              <CardDescription>Live preview of how your widget will appear</CardDescription>
            </CardHeader>
            <CardContent>
              <!-- Widget Preview -->
              <div class="relative bg-muted rounded-lg h-[500px] overflow-hidden">
                <!-- Fake website content -->
                <div class="p-4">
                  <div class="h-4 w-32 bg-muted-foreground/20 rounded mb-4"></div>
                  <div class="space-y-2">
                    <div class="h-3 bg-muted-foreground/10 rounded w-full"></div>
                    <div class="h-3 bg-muted-foreground/10 rounded w-4/5"></div>
                    <div class="h-3 bg-muted-foreground/10 rounded w-3/4"></div>
                  </div>
                </div>

                <!-- Widget Preview -->
                <div
                  class="absolute bottom-4"
                  :class="form.position === 'bottom-left' ? 'left-4' : 'right-4'"
                >
                  <!-- Chat Window Preview -->
                  <div class="w-72 bg-card rounded-xl shadow-lg overflow-hidden mb-3 border">
                    <div
                      class="p-3 text-white"
                      :style="{ backgroundColor: form.primary_color }"
                    >
                      <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-full bg-white/20 flex items-center justify-center">
                          <MessageSquare class="h-4 w-4" />
                        </div>
                        <div>
                          <div class="font-medium text-sm">{{ form.bot_name || 'Assistant' }}</div>
                          <div class="text-xs opacity-80">Online</div>
                        </div>
                      </div>
                    </div>
                    <div class="p-3 bg-muted/50 h-32">
                      <div class="bg-card p-2 rounded-lg text-sm shadow-sm max-w-[80%]">
                        {{ form.welcome_message || 'Hello! How can I help you?' }}
                      </div>
                    </div>
                    <div class="p-3 border-t">
                      <div class="flex gap-2">
                        <div class="flex-1 px-3 py-2 bg-muted rounded-full text-sm text-muted-foreground">
                          Type a message...
                        </div>
                        <div
                          class="w-9 h-9 rounded-full flex items-center justify-center text-white"
                          :style="{ backgroundColor: form.primary_color }"
                        >
                          <Send class="h-4 w-4" />
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- Launcher Button Preview -->
                  <div
                    class="w-12 h-12 rounded-full flex items-center justify-center text-white shadow-lg"
                    :style="{ backgroundColor: form.primary_color }"
                    :class="form.position === 'bottom-left' ? '' : 'ml-auto'"
                  >
                    <MessageSquare class="h-6 w-6" />
                  </div>
                </div>
              </div>
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  </ClientLayout>
</template>
