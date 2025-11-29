<script setup>
import { ref, computed } from 'vue';
import { Head, Link, useForm, usePage, router } from '@inertiajs/vue3';
import { useRoute } from '@/composables/useRoute';

const route = useRoute();

const props = defineProps({
    tenant: Object,
    embedUrl: String,
});

const page = usePage();

const form = useForm({
    welcome_message: props.tenant.settings?.welcome_message || 'Hello! How can I help you today?',
    primary_color: props.tenant.settings?.primary_color || '#4F46E5',
    position: props.tenant.settings?.position || 'bottom-right',
    bot_name: props.tenant.settings?.bot_name || props.tenant.name,
    offline_message: props.tenant.settings?.offline_message || 'We are currently offline. Please leave a message.',
});

const showApiKey = ref(false);
const copied = ref(false);

const embedCode = computed(() => {
    return `<script
    src="${props.embedUrl}"
    data-chatbot-key="${props.tenant.api_key}"
    data-chatbot-url="${page.props.ziggy?.url || window.location.origin}"
    data-chatbot-position="${form.position}"
    data-chatbot-color="${form.primary_color}">
<\/script>`;
});

function copyEmbedCode() {
    navigator.clipboard.writeText(embedCode.value);
    copied.value = true;
    setTimeout(() => copied.value = false, 2000);
}

function copyApiKey() {
    navigator.clipboard.writeText(props.tenant.api_key);
    copied.value = true;
    setTimeout(() => copied.value = false, 2000);
}

function saveSettings() {
    form.put(route('client.widget.update'));
}

function regenerateApiKey() {
    if (confirm('Are you sure? This will invalidate your current embed code and require updating all installations.')) {
        router.post(route('client.widget.regenerate-key'));
    }
}
</script>

<template>
    <Head title="Widget Settings" />

    <div class="min-h-screen bg-gray-50">
        <!-- Header -->
        <header class="bg-white border-b border-gray-200">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <Link :href="route('dashboard')" class="text-gray-500 hover:text-gray-700">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                            </svg>
                        </Link>
                        <h1 class="text-xl font-semibold text-gray-900">Widget Settings</h1>
                    </div>
                </div>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <!-- Success Message -->
            <div v-if="page.props.flash?.success" class="mb-6 bg-emerald-50 border border-emerald-200 rounded-lg p-4">
                <p class="text-emerald-800">{{ page.props.flash.success }}</p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Settings Form -->
                <div class="space-y-6">
                    <!-- Embed Code Card -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-4">Embed Code</h2>
                        <p class="text-sm text-gray-600 mb-4">
                            Add this code to your website, just before the closing <code class="bg-gray-100 px-1 rounded">&lt;/body&gt;</code> tag.
                        </p>
                        <div class="relative">
                            <pre class="bg-gray-900 text-gray-100 p-4 rounded-lg text-sm overflow-x-auto"><code>{{ embedCode }}</code></pre>
                            <button
                                @click="copyEmbedCode"
                                class="absolute top-2 right-2 px-3 py-1 bg-gray-700 hover:bg-gray-600 text-white text-xs rounded transition"
                            >
                                {{ copied ? 'Copied!' : 'Copy' }}
                            </button>
                        </div>
                    </div>

                    <!-- API Key Card -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-4">API Key</h2>
                        <div class="flex items-center gap-2 mb-4">
                            <div class="flex-1 relative">
                                <input
                                    :type="showApiKey ? 'text' : 'password'"
                                    :value="tenant.api_key"
                                    readonly
                                    class="w-full px-4 py-2 bg-gray-100 border border-gray-200 rounded-lg text-sm font-mono text-gray-900"
                                />
                                <button
                                    @click="showApiKey = !showApiKey"
                                    class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-700"
                                >
                                    <svg v-if="!showApiKey" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                    </svg>
                                    <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                                    </svg>
                                </button>
                            </div>
                            <button
                                @click="copyApiKey"
                                class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg transition text-sm"
                            >
                                Copy
                            </button>
                        </div>
                        <button
                            @click="regenerateApiKey"
                            class="text-sm text-rose-600 hover:text-rose-700"
                        >
                            Regenerate API Key
                        </button>
                    </div>

                    <!-- Customization Card -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-4">Customization</h2>

                        <form @submit.prevent="saveSettings" class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    Bot Name
                                </label>
                                <input
                                    v-model="form.bot_name"
                                    type="text"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg bg-white text-gray-900"
                                    placeholder="Assistant"
                                />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    Welcome Message
                                </label>
                                <textarea
                                    v-model="form.welcome_message"
                                    rows="3"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg bg-white text-gray-900"
                                    placeholder="Hello! How can I help you today?"
                                ></textarea>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    Primary Color
                                </label>
                                <div class="flex items-center gap-3">
                                    <input
                                        v-model="form.primary_color"
                                        type="color"
                                        class="w-12 h-10 rounded border border-gray-300 cursor-pointer"
                                    />
                                    <input
                                        v-model="form.primary_color"
                                        type="text"
                                        class="flex-1 px-4 py-2 border border-gray-300 rounded-lg bg-white text-gray-900 font-mono"
                                        placeholder="#4F46E5"
                                    />
                                </div>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    Widget Position
                                </label>
                                <select
                                    v-model="form.position"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg bg-white text-gray-900"
                                >
                                    <option value="bottom-right">Bottom Right</option>
                                    <option value="bottom-left">Bottom Left</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    Offline Message
                                </label>
                                <textarea
                                    v-model="form.offline_message"
                                    rows="2"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg bg-white text-gray-900"
                                    placeholder="We are currently offline..."
                                ></textarea>
                            </div>

                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:bg-blue-400 text-white font-medium rounded-lg transition"
                            >
                                {{ form.processing ? 'Saving...' : 'Save Settings' }}
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Preview -->
                <div class="lg:sticky lg:top-8">
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-4">Preview</h2>

                        <!-- Widget Preview -->
                        <div class="relative bg-gray-100 rounded-lg h-[500px] overflow-hidden">
                            <!-- Fake website content -->
                            <div class="p-4">
                                <div class="h-4 w-32 bg-gray-300 rounded mb-4"></div>
                                <div class="space-y-2">
                                    <div class="h-3 bg-gray-200 rounded w-full"></div>
                                    <div class="h-3 bg-gray-200 rounded w-4/5"></div>
                                    <div class="h-3 bg-gray-200 rounded w-3/4"></div>
                                </div>
                            </div>

                            <!-- Widget Preview -->
                            <div
                                class="absolute bottom-4"
                                :class="form.position === 'bottom-left' ? 'left-4' : 'right-4'"
                            >
                                <!-- Chat Window Preview -->
                                <div class="w-72 bg-white rounded-xl shadow-lg overflow-hidden mb-3">
                                    <div
                                        class="p-3 text-white"
                                        :style="{ backgroundColor: form.primary_color }"
                                    >
                                        <div class="flex items-center gap-2">
                                            <div class="w-8 h-8 rounded-full bg-white/20 flex items-center justify-center text-sm">
                                                🤖
                                            </div>
                                            <div>
                                                <div class="font-medium text-sm">{{ form.bot_name || 'Assistant' }}</div>
                                                <div class="text-xs opacity-80">Online</div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="p-3 bg-gray-50 h-32">
                                        <div class="bg-white p-2 rounded-lg text-sm shadow-sm max-w-[80%] text-gray-900">
                                            {{ form.welcome_message || 'Hello! How can I help you?' }}
                                        </div>
                                    </div>
                                    <div class="p-3 border-t">
                                        <div class="flex gap-2">
                                            <div class="flex-1 px-3 py-2 bg-gray-100 rounded-full text-sm text-gray-400">
                                                Type a message...
                                            </div>
                                            <div
                                                class="w-9 h-9 rounded-full flex items-center justify-center text-white"
                                                :style="{ backgroundColor: form.primary_color }"
                                            >
                                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                                                    <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
                                                </svg>
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
                                    <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                                        <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/>
                                    </svg>
                                </div>
                            </div>
                        </div>

                        <p class="mt-4 text-sm text-gray-500 text-center">
                            Live preview of how your widget will appear
                        </p>
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>
