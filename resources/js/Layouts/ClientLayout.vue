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
  Sun,
  Moon,
  AlertTriangle,
} from 'lucide-vue-next'
import { useTheme } from '@/composables/useTheme'

const page = usePage()
const user = computed(() => page.props.auth?.user)
const tenant = computed(() => page.props.tenant)
const usageWarnings = computed(() => page.props.usageWarnings ?? [])
const usageStats = computed(() => page.props.usageStats ?? [])

const formatNum = (n) => {
  if (n >= 1_000_000) return (n / 1_000_000).toFixed(1).replace(/\.0$/, '') + 'M'
  if (n >= 1_000) return (n / 1_000).toFixed(1).replace(/\.0$/, '') + 'k'
  return n.toLocaleString()
}

const barColor = (severity) => ({
  ok: 'bg-emerald-500',
  caution: 'bg-amber-500',
  warning: 'bg-orange-500',
  critical: 'bg-red-500',
}[severity] ?? 'bg-emerald-500')

const sidebarOpen = ref(false)
const userMenuOpen = ref(false)

const { theme, toggleTheme } = useTheme()

const navigation = [
  { name: 'Dashboard', href: '/dashboard', icon: LayoutDashboard },
  { name: 'Widget', href: '/widget-settings', icon: MessageSquare },
  { name: 'Knowledge Base', href: '/knowledge', icon: BookOpen },
  { name: 'Leads', href: '/leads', icon: Users },
  { name: 'Analytics', href: '/analytics', icon: BarChart3 },
  { name: 'Billing', href: '/billing', icon: CreditCard },
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
          <!-- Theme toggle -->
          <Button variant="ghost" size="icon" @click="toggleTheme" title="Toggle theme">
            <Sun v-if="theme === 'dark'" class="h-5 w-5" />
            <Moon v-else class="h-5 w-5" />
          </Button>

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

      <!-- Usage limit banners (only when at/over limit or near it) -->
      <div v-if="usageWarnings.length" class="border-b">
        <div
          v-for="w in usageWarnings"
          :key="w.type"
          :class="[
            'flex items-center gap-3 px-4 sm:px-6 py-3 text-sm border-b last:border-b-0',
            w.severity === 'critical'
              ? 'bg-destructive/10 text-destructive border-destructive/20'
              : 'bg-amber-500/10 text-amber-700 dark:text-amber-400 border-amber-500/20',
          ]"
        >
          <AlertTriangle class="h-4 w-4 shrink-0" />
          <div class="flex-1">
            <span v-if="w.severity === 'critical'" class="font-medium">
              You've reached your monthly {{ w.label }} limit ({{ w.used.toLocaleString() }} / {{ w.limit.toLocaleString() }}).
              Visitors will see an unavailable message until you upgrade.
            </span>
            <span v-else class="font-medium">
              You're at {{ w.percent }}% of your monthly {{ w.label }}
              ({{ w.used.toLocaleString() }} / {{ w.limit.toLocaleString() }}).
            </span>
          </div>
          <Link
            href="/billing/plans"
            class="font-medium underline underline-offset-2 hover:no-underline shrink-0"
          >
            Upgrade plan
          </Link>
        </div>
      </div>

      <!-- Compact always-visible usage strip -->
      <Link
        v-if="usageStats.length"
        href="/billing"
        class="block border-b bg-muted/30 hover:bg-muted/50 transition-colors"
      >
        <div class="flex flex-wrap items-center gap-x-6 gap-y-2 px-4 sm:px-6 py-2 text-xs">
          <div
            v-for="s in usageStats"
            :key="s.type"
            class="flex items-center gap-2 min-w-0"
          >
            <span class="capitalize text-muted-foreground shrink-0">{{ s.label }}</span>
            <div class="h-1.5 w-20 sm:w-28 rounded-full bg-muted overflow-hidden shrink-0">
              <div
                :class="['h-full rounded-full transition-all', barColor(s.severity)]"
                :style="{ width: `${s.percent}%` }"
              />
            </div>
            <span
              :class="[
                'tabular-nums shrink-0',
                s.severity === 'critical' ? 'text-destructive font-medium' :
                s.severity === 'warning' ? 'text-orange-600 dark:text-orange-400 font-medium' :
                'text-muted-foreground'
              ]"
            >
              {{ formatNum(s.used) }} / {{ formatNum(s.limit) }}
            </span>
          </div>
        </div>
      </Link>

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
