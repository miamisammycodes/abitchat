<script setup>
import { ref, computed } from 'vue'
import { Link, usePage, router } from '@inertiajs/vue3'
import { Button } from '@/Components/ui/button'
import { Avatar, AvatarFallback } from '@/Components/ui/avatar'
import { Separator } from '@/Components/ui/separator'
import {
  LayoutDashboard,
  MessageSquare,
  BookOpen,
  Users,
  BarChart3,
  CreditCard,
  Settings,
  LogOut,
  Menu,
  X,
  ChevronDown,
} from 'lucide-vue-next'

const page = usePage()
const user = computed(() => page.props.auth?.user)
const tenant = computed(() => page.props.tenant)

const sidebarOpen = ref(false)
const userMenuOpen = ref(false)

const navigation = [
  { name: 'Dashboard', href: '/dashboard', icon: LayoutDashboard },
  { name: 'Widget', href: '/dashboard/widget', icon: MessageSquare },
  { name: 'Knowledge Base', href: '/dashboard/knowledge', icon: BookOpen },
  { name: 'Leads', href: '/dashboard/leads', icon: Users },
  { name: 'Analytics', href: '/dashboard/analytics', icon: BarChart3 },
  { name: 'Billing', href: '/dashboard/billing', icon: CreditCard },
]

const isActive = (href) => {
  const currentPath = page.url
  if (href === '/dashboard') {
    return currentPath === '/dashboard'
  }
  return currentPath.startsWith(href)
}

const logout = () => {
  router.post('/logout')
}

const getInitials = (name) => {
  if (!name) return 'U'
  return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2)
}
</script>

<template>
  <div class="min-h-screen bg-background">
    <!-- Mobile sidebar backdrop -->
    <div
      v-if="sidebarOpen"
      class="fixed inset-0 z-40 bg-black/80 lg:hidden"
      @click="sidebarOpen = false"
    />

    <!-- Mobile sidebar -->
    <div
      :class="[
        'fixed inset-y-0 left-0 z-50 w-72 bg-card border-r transform transition-transform duration-300 ease-in-out lg:hidden',
        sidebarOpen ? 'translate-x-0' : '-translate-x-full'
      ]"
    >
      <div class="flex h-16 items-center justify-between px-6 border-b">
        <span class="text-xl font-bold text-foreground">{{ tenant?.name || 'AbitChat' }}</span>
        <Button variant="ghost" size="icon" @click="sidebarOpen = false">
          <X class="h-5 w-5" />
        </Button>
      </div>
      <nav class="flex flex-col gap-1 p-4">
        <Link
          v-for="item in navigation"
          :key="item.name"
          :href="item.href"
          :class="[
            'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
            isActive(item.href)
              ? 'bg-primary text-primary-foreground'
              : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground'
          ]"
          @click="sidebarOpen = false"
        >
          <component :is="item.icon" class="h-5 w-5" />
          {{ item.name }}
        </Link>
      </nav>
    </div>

    <!-- Desktop sidebar -->
    <div class="hidden lg:fixed lg:inset-y-0 lg:flex lg:w-72 lg:flex-col">
      <div class="flex grow flex-col gap-y-5 overflow-y-auto border-r bg-card px-6 pb-4">
        <div class="flex h-16 shrink-0 items-center">
          <span class="text-xl font-bold text-foreground">{{ tenant?.name || 'AbitChat' }}</span>
        </div>
        <nav class="flex flex-1 flex-col">
          <ul role="list" class="flex flex-1 flex-col gap-y-1">
            <li v-for="item in navigation" :key="item.name">
              <Link
                :href="item.href"
                :class="[
                  'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                  isActive(item.href)
                    ? 'bg-primary text-primary-foreground'
                    : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground'
                ]"
              >
                <component :is="item.icon" class="h-5 w-5" />
                {{ item.name }}
              </Link>
            </li>
          </ul>
        </nav>
      </div>
    </div>

    <!-- Main content -->
    <div class="lg:pl-72">
      <!-- Top header -->
      <header class="sticky top-0 z-30 flex h-16 items-center gap-4 border-b bg-card px-4 sm:px-6">
        <Button variant="ghost" size="icon" class="lg:hidden" @click="sidebarOpen = true">
          <Menu class="h-5 w-5" />
        </Button>

        <div class="flex flex-1 items-center justify-end gap-4">
          <!-- User menu -->
          <div class="relative">
            <Button
              variant="ghost"
              class="flex items-center gap-2"
              @click="userMenuOpen = !userMenuOpen"
            >
              <Avatar class="h-8 w-8">
                <AvatarFallback class="text-xs">{{ getInitials(user?.name) }}</AvatarFallback>
              </Avatar>
              <span class="hidden sm:block text-sm font-medium">{{ user?.name }}</span>
              <ChevronDown class="h-4 w-4 text-muted-foreground" />
            </Button>

            <!-- Dropdown menu -->
            <div
              v-if="userMenuOpen"
              class="absolute right-0 mt-2 w-48 rounded-md border bg-card py-1 shadow-lg"
              @click="userMenuOpen = false"
            >
              <div class="px-4 py-2 border-b">
                <p class="text-sm font-medium">{{ user?.name }}</p>
                <p class="text-xs text-muted-foreground">{{ user?.email }}</p>
              </div>
              <Link
                href="/dashboard/settings"
                class="flex items-center gap-2 px-4 py-2 text-sm text-muted-foreground hover:bg-accent hover:text-accent-foreground"
              >
                <Settings class="h-4 w-4" />
                Settings
              </Link>
              <Separator />
              <button
                @click="logout"
                class="flex w-full items-center gap-2 px-4 py-2 text-sm text-destructive hover:bg-accent"
              >
                <LogOut class="h-4 w-4" />
                Logout
              </button>
            </div>
          </div>
        </div>
      </header>

      <!-- Page content -->
      <main class="p-4 sm:p-6 lg:p-8">
        <slot />
      </main>
    </div>

    <!-- Click outside to close user menu -->
    <div
      v-if="userMenuOpen"
      class="fixed inset-0 z-20"
      @click="userMenuOpen = false"
    />
  </div>
</template>
