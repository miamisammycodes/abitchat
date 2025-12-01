<script setup>
import { ref, computed } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { useRoute } from '@/composables/useRoute'
import { Button } from '@/Components/ui/button'
import { Badge } from '@/Components/ui/badge'
import {
  LayoutDashboard,
  Building2,
  ClipboardList,
  FileText,
  Menu,
  X,
  LogOut,
  Sun,
  Moon,
} from 'lucide-vue-next'
import { useTheme } from '@/composables/useTheme'

const route = useRoute()
const { theme, toggleTheme } = useTheme()
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
  { name: 'Dashboard', href: 'admin.dashboard', icon: LayoutDashboard },
  { name: 'Clients', href: 'admin.clients.index', icon: Building2 },
  { name: 'Transactions', href: 'admin.transactions.index', icon: ClipboardList },
  { name: 'Activity Logs', href: 'admin.logs.index', icon: FileText },
]

const isCurrentRoute = (routeName) => {
  const currentUrl = page.url
  const targetUrl = route(routeName)
  return currentUrl === targetUrl || currentUrl.startsWith(targetUrl + '/')
}

const logout = () => {
  router.post(route('admin.logout'))
}
</script>

<template>
  <Head :title="title + ' - Admin'" />

  <div class="min-h-screen bg-background">
    <!-- Mobile sidebar backdrop -->
    <div
      v-if="sidebarOpen"
      class="fixed inset-0 z-40 bg-black/80 lg:hidden"
      @click="sidebarOpen = false"
    />

    <!-- Mobile sidebar -->
    <div
      v-if="sidebarOpen"
      class="fixed inset-y-0 left-0 z-50 w-64 bg-card lg:hidden"
    >
      <div class="flex h-16 items-center justify-between px-4 border-b">
        <span class="text-xl font-semibold text-foreground">Admin Portal</span>
        <Button variant="ghost" size="icon" @click="sidebarOpen = false" class="text-muted-foreground hover:text-foreground">
          <X class="h-5 w-5" />
        </Button>
      </div>
      <nav class="mt-4 px-2 space-y-1">
        <Link
          v-for="item in navigation"
          :key="item.name"
          :href="route(item.href)"
          :class="[
            isCurrentRoute(item.href)
              ? 'bg-primary text-primary-foreground'
              : 'text-muted-foreground hover:bg-accent hover:text-foreground',
            'group flex items-center px-3 py-2 text-sm font-medium rounded-md'
          ]"
          @click="sidebarOpen = false"
        >
          <component :is="item.icon" class="mr-3 h-5 w-5 flex-shrink-0" />
          {{ item.name }}
        </Link>
      </nav>
    </div>

    <!-- Desktop sidebar -->
    <div class="hidden lg:fixed lg:inset-y-0 lg:flex lg:w-64 lg:flex-col">
      <div class="flex min-h-0 flex-1 flex-col bg-card">
        <div class="flex h-16 flex-shrink-0 items-center px-4 border-b">
          <span class="text-xl font-semibold text-foreground">Admin Portal</span>
        </div>
        <div class="flex flex-1 flex-col overflow-y-auto">
          <nav class="flex-1 space-y-1 px-2 py-4">
            <Link
              v-for="item in navigation"
              :key="item.name"
              :href="route(item.href)"
              :class="[
                isCurrentRoute(item.href)
                  ? 'bg-primary text-primary-foreground'
                  : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                'group flex items-center px-3 py-2 text-sm font-medium rounded-md'
              ]"
            >
              <component :is="item.icon" class="mr-3 h-5 w-5 flex-shrink-0" />
              {{ item.name }}
            </Link>
          </nav>
        </div>
      </div>
    </div>

    <!-- Main content -->
    <div class="lg:pl-64">
      <!-- Top bar -->
      <div class="sticky top-0 z-10 flex h-16 flex-shrink-0 bg-card shadow border-b">
        <Button
          variant="ghost"
          size="icon"
          class="px-4 text-muted-foreground lg:hidden"
          @click="sidebarOpen = true"
        >
          <Menu class="h-6 w-6" />
        </Button>
        <div class="flex flex-1 justify-between px-4">
          <div class="flex flex-1 items-center">
            <h1 class="text-lg font-semibold text-foreground">{{ title }}</h1>
          </div>
          <div class="flex items-center gap-4">
            <span class="text-sm text-muted-foreground">{{ admin.name }}</span>
            <Badge variant="secondary">
              {{ admin.role }}
            </Badge>
            <Button variant="ghost" size="icon" @click="toggleTheme" title="Toggle theme" class="text-muted-foreground hover:text-foreground">
              <Sun v-if="theme === 'dark'" class="h-5 w-5" />
              <Moon v-else class="h-5 w-5" />
            </Button>
            <Button variant="ghost" size="sm" @click="logout" class="text-muted-foreground hover:text-foreground">
              <LogOut class="h-4 w-4 mr-2" />
              Sign out
            </Button>
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
