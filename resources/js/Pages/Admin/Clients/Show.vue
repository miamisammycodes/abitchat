<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import { useRoute } from '@/composables/useRoute'
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card'
import { Button } from '@/Components/ui/button'
import { Input } from '@/Components/ui/input'
import { Label } from '@/Components/ui/label'
import { Badge } from '@/Components/ui/badge'
import { ArrowLeft } from 'lucide-vue-next'

const route = useRoute()

const props = defineProps({
    client: Object,
    stats: Object,
    transactions: Array,
    recentConversations: Array,
    plans: Array,
})

const showStatusModal = ref(false)
const showPlanModal = ref(false)

const statusForm = useForm({
    status: props.client.status,
})

const planForm = useForm({
    plan_id: props.client.plan_id || '',
    expires_at: props.client.plan_expires_at?.split('T')[0] || '',
})

const updateStatus = () => {
    statusForm.put(route('admin.clients.update-status', props.client.id), {
        onSuccess: () => {
            showStatusModal.value = false
        },
    })
}

const updatePlan = () => {
    planForm.put(route('admin.clients.update-plan', props.client.id), {
        onSuccess: () => {
            showPlanModal.value = false
        },
    })
}

const formatNumber = (num) => {
    return num?.toLocaleString() || '0'
}

const formatDate = (date) => {
    if (!date) return 'N/A'
    return new Date(date).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    })
}

const formatCurrency = (amount) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(amount || 0)
}

const getStatusVariant = (status) => {
    const variants = {
        active: 'success',
        inactive: 'secondary',
        suspended: 'destructive',
        pending: 'warning',
        approved: 'success',
        rejected: 'destructive',
    }
    return variants[status] || 'secondary'
}
</script>

<template>
    <AdminLayout :title="client.name">
        <!-- Header -->
        <div class="mb-6 flex items-center justify-between">
            <div>
                <Link :href="route('admin.clients.index')" class="text-sm text-zinc-400 hover:text-white mb-2 inline-flex items-center gap-1">
                    <ArrowLeft class="h-4 w-4" />
                    Back to Clients
                </Link>
                <h2 class="text-2xl font-bold text-white">{{ client.name }}</h2>
                <p class="text-sm text-zinc-400">{{ client.slug }}</p>
            </div>
            <div class="flex space-x-3">
                <Button
                    @click="showStatusModal = true"
                    variant="secondary"
                    class="bg-zinc-700 text-white hover:bg-zinc-600"
                >
                    Change Status
                </Button>
                <Button @click="showPlanModal = true">
                    Change Plan
                </Button>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4 mb-8">
            <Card class="bg-zinc-800 border-zinc-700">
                <CardContent class="p-5">
                    <p class="text-sm font-medium text-zinc-400">Status</p>
                    <Badge :variant="getStatusVariant(client.status)" class="mt-1 capitalize">
                        {{ client.status }}
                    </Badge>
                </CardContent>
            </Card>
            <Card class="bg-zinc-800 border-zinc-700">
                <CardContent class="p-5">
                    <p class="text-sm font-medium text-zinc-400">Current Plan</p>
                    <p class="text-xl font-semibold text-white mt-1">{{ client.current_plan?.name || 'Free' }}</p>
                    <p v-if="client.plan_expires_at" class="text-xs text-zinc-400">Expires {{ formatDate(client.plan_expires_at) }}</p>
                </CardContent>
            </Card>
            <Card class="bg-zinc-800 border-zinc-700">
                <CardContent class="p-5">
                    <p class="text-sm font-medium text-zinc-400">Users</p>
                    <p class="text-xl font-semibold text-white mt-1">{{ client.users?.length || 0 }}</p>
                </CardContent>
            </Card>
            <Card class="bg-zinc-800 border-zinc-700">
                <CardContent class="p-5">
                    <p class="text-sm font-medium text-zinc-400">Created</p>
                    <p class="text-xl font-semibold text-white mt-1">{{ formatDate(client.created_at) }}</p>
                </CardContent>
            </Card>
        </div>

        <!-- Usage Stats -->
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-3 mb-8">
            <Card class="bg-zinc-800 border-zinc-700">
                <CardContent class="p-5">
                    <div class="flex justify-between items-center">
                        <div>
                            <p class="text-sm font-medium text-zinc-400">Conversations</p>
                            <p class="text-2xl font-semibold text-white">{{ formatNumber(stats.conversations.total) }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-xs text-zinc-400">This Month</p>
                            <p class="text-lg font-medium text-blue-400">{{ stats.conversations.thisMonth }}</p>
                        </div>
                    </div>
                </CardContent>
            </Card>
            <Card class="bg-zinc-800 border-zinc-700">
                <CardContent class="p-5">
                    <div class="flex justify-between items-center">
                        <div>
                            <p class="text-sm font-medium text-zinc-400">Leads</p>
                            <p class="text-2xl font-semibold text-white">{{ formatNumber(stats.leads.total) }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-xs text-zinc-400">This Month</p>
                            <p class="text-lg font-medium text-emerald-400">{{ stats.leads.thisMonth }}</p>
                        </div>
                    </div>
                </CardContent>
            </Card>
            <Card class="bg-zinc-800 border-zinc-700">
                <CardContent class="p-5">
                    <div class="flex justify-between items-center">
                        <div>
                            <p class="text-sm font-medium text-zinc-400">Tokens Used</p>
                            <p class="text-2xl font-semibold text-white">{{ formatNumber(stats.tokens.total) }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-xs text-zinc-400">This Month</p>
                            <p class="text-lg font-medium text-indigo-400">{{ formatNumber(stats.tokens.thisMonth) }}</p>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!-- Users & Transactions -->
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2 mb-8">
            <!-- Users -->
            <Card class="bg-zinc-800 border-zinc-700">
                <CardHeader class="border-b border-zinc-700">
                    <CardTitle class="text-white">Team Members</CardTitle>
                </CardHeader>
                <CardContent class="p-0">
                    <ul class="divide-y divide-zinc-700">
                        <li v-for="user in client.users" :key="user.id" class="px-4 py-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-medium text-white">{{ user.name }}</p>
                                    <p class="text-xs text-zinc-400">{{ user.email }}</p>
                                </div>
                                <Badge variant="secondary" class="bg-zinc-700 text-zinc-300">
                                    {{ user.role || 'user' }}
                                </Badge>
                            </div>
                        </li>
                        <li v-if="!client.users?.length" class="px-4 py-8 text-center text-zinc-400">
                            No users
                        </li>
                    </ul>
                </CardContent>
            </Card>

            <!-- Transactions -->
            <Card class="bg-zinc-800 border-zinc-700">
                <CardHeader class="border-b border-zinc-700">
                    <CardTitle class="text-white">Recent Transactions</CardTitle>
                </CardHeader>
                <CardContent class="p-0">
                    <ul class="divide-y divide-zinc-700">
                        <li v-for="txn in transactions" :key="txn.id" class="px-4 py-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-medium text-white">{{ txn.plan?.name }}</p>
                                    <p class="text-xs text-zinc-400">{{ formatCurrency(txn.amount) }} - {{ formatDate(txn.created_at) }}</p>
                                </div>
                                <Badge :variant="getStatusVariant(txn.status)" class="capitalize">
                                    {{ txn.status }}
                                </Badge>
                            </div>
                        </li>
                        <li v-if="!transactions?.length" class="px-4 py-8 text-center text-zinc-400">
                            No transactions
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </div>

        <!-- Status Modal -->
        <div v-if="showStatusModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <Card class="w-full max-w-md bg-zinc-800 border-zinc-700">
                <CardHeader>
                    <CardTitle class="text-white">Change Client Status</CardTitle>
                </CardHeader>
                <CardContent>
                    <form @submit.prevent="updateStatus">
                        <select
                            v-model="statusForm.status"
                            class="w-full h-9 rounded-md bg-zinc-700 border-zinc-600 text-white px-3 text-sm mb-4"
                        >
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="suspended">Suspended</option>
                        </select>
                        <div class="flex justify-end space-x-3">
                            <Button
                                type="button"
                                variant="ghost"
                                @click="showStatusModal = false"
                                class="text-zinc-300 hover:text-white"
                            >
                                Cancel
                            </Button>
                            <Button type="submit" :disabled="statusForm.processing">
                                Update Status
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </div>

        <!-- Plan Modal -->
        <div v-if="showPlanModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <Card class="w-full max-w-md bg-zinc-800 border-zinc-700">
                <CardHeader>
                    <CardTitle class="text-white">Change Client Plan</CardTitle>
                </CardHeader>
                <CardContent>
                    <form @submit.prevent="updatePlan">
                        <div class="mb-4">
                            <Label class="text-zinc-300 mb-1">Plan</Label>
                            <select
                                v-model="planForm.plan_id"
                                class="w-full h-9 rounded-md bg-zinc-700 border-zinc-600 text-white px-3 text-sm"
                            >
                                <option value="">Select a plan</option>
                                <option v-for="p in plans" :key="p.id" :value="p.id">
                                    {{ p.name }} - {{ formatCurrency(p.price) }}/{{ p.billing_period }}
                                </option>
                            </select>
                        </div>
                        <div class="mb-4">
                            <Label class="text-zinc-300 mb-1">Expires At</Label>
                            <Input
                                v-model="planForm.expires_at"
                                type="date"
                                class="bg-zinc-700 border-zinc-600 text-white"
                            />
                        </div>
                        <div class="flex justify-end space-x-3">
                            <Button
                                type="button"
                                variant="ghost"
                                @click="showPlanModal = false"
                                class="text-zinc-300 hover:text-white"
                            >
                                Cancel
                            </Button>
                            <Button type="submit" :disabled="planForm.processing">
                                Update Plan
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
