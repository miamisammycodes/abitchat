<script setup>
import { Link } from '@inertiajs/vue3'
import { ArrowLeftRight } from 'lucide-vue-next'

/**
 * ContextSwitchChip — shows a switch link when the user can navigate
 * between admin and client contexts.
 *
 * Only visible when the user holds BOTH has_super_admin_role AND has_tenant_role.
 * Reads from auth.user as emitted by HandleInertiaRequests::share().
 *
 * Props:
 *   user    — auth.user object (can be null for anonymous)
 *   context — 'admin' | 'client'  (which context the current layout is in)
 */
const props = defineProps({
  user: {
    type: Object,
    default: null,
  },
  context: {
    type: String,
    default: 'client',
    validator: (v) => ['admin', 'client'].includes(v),
  },
})

const canSwitch = () =>
  props.user?.has_super_admin_role && props.user?.has_tenant_role
</script>

<template>
  <Link
    v-if="canSwitch()"
    :href="context === 'admin' ? '/dashboard' : '/admin/dashboard'"
    class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium text-muted-foreground hover:bg-accent hover:text-accent-foreground transition-colors"
    :title="context === 'admin' ? 'Switch to client view' : 'Switch to admin view'"
  >
    <ArrowLeftRight class="h-3 w-3" />
    <span>{{ context === 'admin' ? 'Client' : 'Admin' }}</span>
  </Link>
</template>
