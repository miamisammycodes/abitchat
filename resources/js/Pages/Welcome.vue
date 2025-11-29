<script setup>
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Welcome" />

    <div class="min-h-screen bg-gray-50 flex items-center justify-center">
        <div class="max-w-2xl mx-auto text-center px-4">
            <h1 class="text-4xl font-bold text-gray-900 mb-4">
                AI Chatbot SaaS
            </h1>
            <p class="text-lg text-gray-600 mb-8">
                Welcome to the AI-Powered WordPress Chatbot platform.
            </p>
            <div class="flex gap-4 justify-center">
                <a
                    href="/login"
                    class="px-6 py-3 bg-gray-900 text-white rounded-lg hover:bg-gray-800 transition"
                >
                    Login
                </a>
                <a
                    href="/register"
                    class="px-6 py-3 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-100 transition"
                >
                    Register
                </a>
            </div>
        </div>
    </div>
</template>
