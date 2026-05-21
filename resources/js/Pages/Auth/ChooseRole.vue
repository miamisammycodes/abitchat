<script setup>
import { Head, useForm } from '@inertiajs/vue3'
import { Button } from '@/Components/ui/button'
import { LayoutDashboard, Shield } from 'lucide-vue-next'

/**
 * ChooseRole — shown to dual-role users (super_admin + tenant role) after login.
 * Lets the user pick which context to enter first.
 *
 * Props from ChooseRoleController::create():
 *   availableContexts: [{ context, label, description }]
 */
defineProps({
  availableContexts: {
    type: Array,
    default: () => [],
  },
})

const form = useForm({
  context: '',
})

const contextIcon = (context) => (context === 'admin' ? Shield : LayoutDashboard)

const choose = (context) => {
  form.context = context
  form.post('/login/choose')
}
</script>

<template>
  <Head title="Choose Your Context" />

  <div class="flex min-h-screen items-center justify-center bg-background px-4">
    <div class="w-full max-w-md space-y-6">
      <!-- Header -->
      <div class="text-center">
        <h1 class="text-2xl font-bold tracking-tight text-foreground">
          Where would you like to go?
        </h1>
        <p class="mt-2 text-sm text-muted-foreground">
          You have access to multiple contexts. Choose where to continue.
        </p>
      </div>

      <!-- Error -->
      <div
        v-if="form.errors.context"
        class="rounded-md bg-destructive/10 border border-destructive/30 px-4 py-3 text-sm text-destructive"
      >
        {{ form.errors.context }}
      </div>

      <!-- Context cards -->
      <div class="grid gap-3">
        <button
          v-for="ctx in availableContexts"
          :key="ctx.context"
          :disabled="form.processing"
          class="flex items-start gap-4 rounded-lg border bg-card p-5 text-left transition-colors hover:bg-accent hover:border-primary disabled:opacity-60 focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2"
          @click="choose(ctx.context)"
        >
          <div class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
            <component :is="contextIcon(ctx.context)" class="h-5 w-5" />
          </div>
          <div>
            <div class="text-sm font-semibold text-foreground">{{ ctx.label }}</div>
            <div class="mt-0.5 text-xs text-muted-foreground">{{ ctx.description }}</div>
          </div>
        </button>
      </div>

      <!-- Fallback if no contexts (should not normally happen) -->
      <p v-if="availableContexts.length === 0" class="text-center text-sm text-muted-foreground">
        No available contexts. Please contact support.
      </p>
    </div>
  </div>
</template>
