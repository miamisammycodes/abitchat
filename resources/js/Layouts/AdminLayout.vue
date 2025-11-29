<script setup>
import { ref, computed } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { useRoute } from '@/composables/useRoute'

const route = useRoute()
const page = usePage()

defineProps({
    title: {
        type: String,
        default: 'Admin'
    }
})

const admin = computed(() => page.props.auth?.admin || {})
const sidebarOpen = ref(false)

const navigation = [
    { name: 'Dashboard', href: 'admin.dashboard', icon: 'dashboard' },
    { name: 'Clients', href: 'admin.clients.index', icon: 'clients' },
    { name: 'Transactions', href: 'admin.transactions.index', icon: 'transactions' },
    { name: 'Activity Logs', href: 'admin.logs.index', icon: 'logs' },
]

const isCurrentRoute = (routeName) => {
    const currentUrl = page.url
    const targetUrl = route(routeName)
    // Check if current URL starts with target URL (for nested routes)
    return currentUrl === targetUrl || currentUrl.startsWith(targetUrl + '/')
}

const logout = () => {
    router.post(route('admin.logout'))
}
</script>

<template>
    <Head :title="title + ' - Admin'" />

    <div class="min-h-screen bg-gray-900">
        <!-- Mobile sidebar backdrop -->
        <div
            v-if="sidebarOpen"
            class="fixed inset-0 z-40 bg-gray-900/80 lg:hidden"
            @click="sidebarOpen = false"
        />

        <!-- Mobile sidebar -->
        <div
            v-if="sidebarOpen"
            class="fixed inset-y-0 left-0 z-50 w-64 bg-gray-800 lg:hidden"
        >
            <div class="flex h-16 items-center justify-between px-4">
                <span class="text-xl font-semibold text-white">Admin Portal</span>
                <button @click="sidebarOpen = false" class="text-gray-400 hover:text-white">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <nav class="mt-4 px-2 space-y-1">
                <Link
                    v-for="item in navigation"
                    :key="item.name"
                    :href="route(item.href)"
                    :class="[
                        isCurrentRoute(item.href)
                            ? 'bg-gray-900 text-white'
                            : 'text-gray-300 hover:bg-gray-700 hover:text-white',
                        'group flex items-center px-3 py-2 text-sm font-medium rounded-md'
                    ]"
                >
                    <!-- Dashboard Icon -->
                    <svg v-if="item.icon === 'dashboard'" class="mr-3 h-5 w-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                    </svg>
                    <!-- Clients Icon -->
                    <svg v-if="item.icon === 'clients'" class="mr-3 h-5 w-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                    </svg>
                    <!-- Transactions Icon -->
                    <svg v-if="item.icon === 'transactions'" class="mr-3 h-5 w-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                    </svg>
                    <!-- Logs Icon -->
                    <svg v-if="item.icon === 'logs'" class="mr-3 h-5 w-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    {{ item.name }}
                </Link>
            </nav>
        </div>

        <!-- Desktop sidebar -->
        <div class="hidden lg:fixed lg:inset-y-0 lg:flex lg:w-64 lg:flex-col">
            <div class="flex min-h-0 flex-1 flex-col bg-gray-800">
                <div class="flex h-16 flex-shrink-0 items-center px-4 border-b border-gray-700">
                    <span class="text-xl font-semibold text-white">Admin Portal</span>
                </div>
                <div class="flex flex-1 flex-col overflow-y-auto">
                    <nav class="flex-1 space-y-1 px-2 py-4">
                        <Link
                            v-for="item in navigation"
                            :key="item.name"
                            :href="route(item.href)"
                            :class="[
                                isCurrentRoute(item.href)
                                    ? 'bg-gray-900 text-white'
                                    : 'text-gray-300 hover:bg-gray-700 hover:text-white',
                                'group flex items-center px-3 py-2 text-sm font-medium rounded-md'
                            ]"
                        >
                            <!-- Dashboard Icon -->
                            <svg v-if="item.icon === 'dashboard'" class="mr-3 h-5 w-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                            </svg>
                            <!-- Clients Icon -->
                            <svg v-if="item.icon === 'clients'" class="mr-3 h-5 w-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                            </svg>
                            <!-- Transactions Icon -->
                            <svg v-if="item.icon === 'transactions'" class="mr-3 h-5 w-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                            </svg>
                            <!-- Logs Icon -->
                            <svg v-if="item.icon === 'logs'" class="mr-3 h-5 w-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            {{ item.name }}
                        </Link>
                    </nav>
                </div>
            </div>
        </div>

        <!-- Main content -->
        <div class="lg:pl-64">
            <!-- Top bar -->
            <div class="sticky top-0 z-10 flex h-16 flex-shrink-0 bg-gray-800 shadow">
                <button
                    type="button"
                    class="px-4 text-gray-400 focus:outline-none lg:hidden"
                    @click="sidebarOpen = true"
                >
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                </button>
                <div class="flex flex-1 justify-between px-4">
                    <div class="flex flex-1 items-center">
                        <h1 class="text-lg font-semibold text-white">{{ title }}</h1>
                    </div>
                    <div class="flex items-center space-x-4">
                        <span class="text-sm text-gray-300">{{ admin.name }}</span>
                        <span class="px-2 py-1 text-xs font-medium rounded-full bg-indigo-900 text-indigo-200">
                            {{ admin.role }}
                        </span>
                        <button
                            @click="logout"
                            class="text-sm text-gray-400 hover:text-white"
                        >
                            Sign out
                        </button>
                    </div>
                </div>
            </div>

            <!-- Page content -->
            <main class="py-6">
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <slot />
                </div>
            </main>
        </div>
    </div>
</template>
