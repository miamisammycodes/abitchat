<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3'
import { useRoute } from '@/composables/useRoute'

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

    <div class="min-h-screen bg-gray-100">
        <nav class="bg-white shadow-sm">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between h-16">
                    <div class="flex items-center">
                        <Link :href="route('client.knowledge.show', item.id)" class="text-gray-500 hover:text-gray-700 mr-4">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                            </svg>
                        </Link>
                        <span class="text-xl font-semibold text-gray-800">Edit Knowledge Item</span>
                    </div>
                </div>
            </div>
        </nav>

        <div class="py-10">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="bg-white shadow sm:rounded-lg">
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
                            />
                            <p v-if="form.errors.title" class="mt-1 text-sm text-red-600">{{ form.errors.title }}</p>
                        </div>

                        <!-- Source URL (for webpages) -->
                        <div v-if="item.type === 'webpage'">
                            <label for="source_url" class="block text-sm font-medium text-gray-700">Source URL</label>
                            <input
                                id="source_url"
                                v-model="form.source_url"
                                type="url"
                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                            />
                            <p v-if="form.errors.source_url" class="mt-1 text-sm text-red-600">{{ form.errors.source_url }}</p>
                        </div>

                        <!-- Content (for FAQ and text) -->
                        <div v-if="item.type === 'faq' || item.type === 'text'">
                            <label for="content" class="block text-sm font-medium text-gray-700">Content</label>
                            <textarea
                                id="content"
                                v-model="form.content"
                                rows="10"
                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                            ></textarea>
                            <p v-if="form.errors.content" class="mt-1 text-sm text-red-600">{{ form.errors.content }}</p>
                        </div>

                        <!-- Document notice -->
                        <div v-if="item.type === 'document'" class="bg-yellow-50 border border-yellow-200 rounded-md p-4">
                            <div class="flex">
                                <svg class="h-5 w-5 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <div class="ml-3">
                                    <p class="text-sm text-yellow-700">
                                        To update the document content, please delete this item and upload a new file.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Submit -->
                        <div class="flex justify-end">
                            <Link
                                :href="route('client.knowledge.show', item.id)"
                                class="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                            >
                                Cancel
                            </Link>
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="ml-3 inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50"
                            >
                                <span v-if="form.processing">Saving...</span>
                                <span v-else>Save Changes</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</template>
