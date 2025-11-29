<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import { useRoute } from '@/composables/useRoute'

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
    { id: 'document', label: 'Document', icon: 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z' },
    { id: 'faq', label: 'FAQ', icon: 'M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z' },
    { id: 'webpage', label: 'Webpage', icon: 'M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9' },
    { id: 'text', label: 'Text', icon: 'M4 6h16M4 12h16M4 18h7' },
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

    <div class="min-h-screen bg-gray-100">
        <nav class="bg-white shadow-sm">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between h-16">
                    <div class="flex items-center">
                        <Link :href="route('client.knowledge.index')" class="text-gray-500 hover:text-gray-700 mr-4">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                            </svg>
                        </Link>
                        <span class="text-xl font-semibold text-gray-800">Add Knowledge</span>
                    </div>
                </div>
            </div>
        </nav>

        <div class="py-10">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
                <!-- Tabs -->
                <div class="bg-white shadow sm:rounded-lg">
                    <div class="border-b border-gray-200">
                        <nav class="flex -mb-px">
                            <button
                                v-for="tab in tabs"
                                :key="tab.id"
                                @click="selectTab(tab.id)"
                                :class="[
                                    'flex-1 py-4 px-1 text-center border-b-2 font-medium text-sm',
                                    activeTab === tab.id
                                        ? 'border-indigo-500 text-indigo-600'
                                        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                                ]"
                            >
                                <svg class="w-5 h-5 mx-auto mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="tab.icon" />
                                </svg>
                                {{ tab.label }}
                            </button>
                        </nav>
                    </div>

                    <form @submit.prevent="submit" class="p-6 space-y-6">
                        <!-- Title -->
                        <div>
                            <label for="title" class="block text-sm font-medium text-gray-700">Title</label>
                            <input
                                id="title"
                                v-model="form.title"
                                type="text"
                                required
                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                :placeholder="activeTab === 'faq' ? 'e.g., What are your business hours?' : 'Enter a descriptive title'"
                            />
                            <p v-if="form.errors.title" class="mt-1 text-sm text-red-600">{{ form.errors.title }}</p>
                        </div>

                        <!-- Document Upload -->
                        <div v-if="activeTab === 'document'">
                            <label class="block text-sm font-medium text-gray-700">Upload Document</label>
                            <div class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-md">
                                <div class="space-y-1 text-center">
                                    <svg class="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                                        <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                    <div class="flex text-sm text-gray-600">
                                        <label for="file" class="relative cursor-pointer bg-white rounded-md font-medium text-indigo-600 hover:text-indigo-500 focus-within:outline-none focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-indigo-500">
                                            <span>Upload a file</span>
                                            <input id="file" type="file" class="sr-only" @change="handleFileChange" accept=".pdf,.doc,.docx,.txt,.md" />
                                        </label>
                                        <p class="pl-1">or drag and drop</p>
                                    </div>
                                    <p class="text-xs text-gray-500">PDF, DOC, DOCX, TXT, MD up to 10MB</p>
                                </div>
                            </div>
                            <p v-if="form.file" class="mt-2 text-sm text-gray-600">Selected: {{ form.file.name }}</p>
                            <p v-if="form.errors.file" class="mt-1 text-sm text-red-600">{{ form.errors.file }}</p>
                        </div>

                        <!-- FAQ Content -->
                        <div v-if="activeTab === 'faq'">
                            <label for="content" class="block text-sm font-medium text-gray-700">Answer</label>
                            <textarea
                                id="content"
                                v-model="form.content"
                                rows="6"
                                required
                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                placeholder="Enter the answer to your FAQ question..."
                            ></textarea>
                            <p v-if="form.errors.content" class="mt-1 text-sm text-red-600">{{ form.errors.content }}</p>
                        </div>

                        <!-- Webpage URL -->
                        <div v-if="activeTab === 'webpage'">
                            <label for="source_url" class="block text-sm font-medium text-gray-700">Website URL</label>
                            <input
                                id="source_url"
                                v-model="form.source_url"
                                type="url"
                                required
                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                placeholder="https://example.com/page"
                            />
                            <p class="mt-1 text-sm text-gray-500">We'll crawl this page and extract its content.</p>
                            <p v-if="form.errors.source_url" class="mt-1 text-sm text-red-600">{{ form.errors.source_url }}</p>
                        </div>

                        <!-- Raw Text -->
                        <div v-if="activeTab === 'text'">
                            <label for="text_content" class="block text-sm font-medium text-gray-700">Text Content</label>
                            <textarea
                                id="text_content"
                                v-model="form.content"
                                rows="10"
                                required
                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                placeholder="Paste your text content here..."
                            ></textarea>
                            <p v-if="form.errors.content" class="mt-1 text-sm text-red-600">{{ form.errors.content }}</p>
                        </div>

                        <!-- Submit -->
                        <div class="flex justify-end">
                            <Link
                                :href="route('client.knowledge.index')"
                                class="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                            >
                                Cancel
                            </Link>
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="ml-3 inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50"
                            >
                                <span v-if="form.processing">Processing...</span>
                                <span v-else>Add Knowledge</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</template>
