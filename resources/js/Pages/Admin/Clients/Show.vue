<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link, router, useForm, usePage } from '@inertiajs/vue3'
import { ref } from 'vue'
import { useRoute } from '@/composables/useRoute'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card'
import { Button } from '@/Components/ui/button'
import { Input } from '@/Components/ui/input'
import { Label } from '@/Components/ui/label'
import { Badge } from '@/Components/ui/badge'
import { Textarea } from '@/Components/ui/textarea'
import { Alert, AlertDescription } from '@/Components/ui/alert'
import { ArrowLeft, Check } from 'lucide-vue-next'

const route = useRoute()
const page = usePage()

const props = defineProps({
    client: Object,
    stats: Object,
    transactions: Array,
    recentConversations: Array,
    plans: Array,
    botTypes: Array,
    botTones: Array,
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

const botPersonalityForm = useForm({
    bot_type: props.client.bot_type || 'hybrid',
    bot_tone: props.client.bot_tone || 'friendly',
    bot_custom_instructions: props.client.bot_custom_instructions || '',
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

const updateBotPersonality = () => {
    botPersonalityForm.put(route('admin.clients.update-bot-personality', props.client.id))
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
    return 'Nu. ' + new Intl.NumberFormat('en-IN').format(amount || 0)
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
                <Link :href="route('admin.clients.index')" class="text-sm text-muted-foreground hover:text-foreground mb-2 inline-flex items-center gap-1">
                    <ArrowLeft class="h-4 w-4" />
                    Back to Clients
                </Link>
                <h2 class="text-2xl font-bold text-foreground">{{ client.name }}</h2>
                <p class="text-sm text-muted-foreground">{{ client.slug }}</p>
            </div>
            <div class="flex space-x-3">
                <Button
                    @click="showStatusModal = true"
                    variant="secondary"
                >
                    Change Status
                </Button>
                <Button @click="showPlanModal = true">
                    Change Plan
                </Button>
            </div>
        </div>

        <!-- Success Message -->
        <Alert v-if="page.props.flash?.success" class="mb-6 border-green-500/50 bg-green-500/10 text-green-400">
            <Check class="h-4 w-4" />
            <AlertDescription>{{ page.props.flash.success }}</AlertDescription>
        </Alert>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4 mb-8">
            <Card>
                <CardContent class="p-5">
                    <p class="text-sm font-medium text-muted-foreground">Status</p>
                    <Badge :variant="getStatusVariant(client.status)" class="mt-1 capitalize">
                        {{ client.status }}
                    </Badge>
                </CardContent>
            </Card>
            <Card>
                <CardContent class="p-5">
                    <p class="text-sm font-medium text-muted-foreground">Current Plan</p>
                    <p class="text-xl font-semibold text-foreground mt-1">{{ client.current_plan?.name || 'Free' }}</p>
                    <p v-if="client.plan_expires_at" class="text-xs text-muted-foreground">Expires {{ formatDate(client.plan_expires_at) }}</p>
                </CardContent>
            </Card>
            <Card>
                <CardContent class="p-5">
                    <p class="text-sm font-medium text-muted-foreground">Users</p>
                    <p class="text-xl font-semibold text-foreground mt-1">{{ client.users?.length || 0 }}</p>
                </CardContent>
            </Card>
            <Card>
                <CardContent class="p-5">
                    <p class="text-sm font-medium text-muted-foreground">Created</p>
                    <p class="text-xl font-semibold text-foreground mt-1">{{ formatDate(client.created_at) }}</p>
                </CardContent>
            </Card>
        </div>

        <!-- Usage Stats -->
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-3 mb-8">
            <Card>
                <CardContent class="p-5">
                    <div class="flex justify-between items-center">
                        <div>
                            <p class="text-sm font-medium text-muted-foreground">Conversations</p>
                            <p class="text-2xl font-semibold text-foreground">{{ formatNumber(stats.conversations.total) }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-xs text-muted-foreground">This Month</p>
                            <p class="text-lg font-medium text-blue-500">{{ stats.conversations.thisMonth }}</p>
                        </div>
                    </div>
                </CardContent>
            </Card>
            <Card>
                <CardContent class="p-5">
                    <div class="flex justify-between items-center">
                        <div>
                            <p class="text-sm font-medium text-muted-foreground">Leads</p>
                            <p class="text-2xl font-semibold text-foreground">{{ formatNumber(stats.leads.total) }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-xs text-muted-foreground">This Month</p>
                            <p class="text-lg font-medium text-emerald-500">{{ stats.leads.thisMonth }}</p>
                        </div>
                    </div>
                </CardContent>
            </Card>
            <Card>
                <CardContent class="p-5">
                    <div class="flex justify-between items-center">
                        <div>
                            <p class="text-sm font-medium text-muted-foreground">Tokens Used</p>
                            <p class="text-2xl font-semibold text-foreground">{{ formatNumber(stats.tokens.total) }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-xs text-muted-foreground">This Month</p>
                            <p class="text-lg font-medium text-indigo-500">{{ formatNumber(stats.tokens.thisMonth) }}</p>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!-- Bot Personality -->
        <Card class="mb-8">
            <CardHeader class="border-b">
                <CardTitle>Bot Personality</CardTitle>
                <CardDescription>Configure how this client's chatbot behaves and communicates</CardDescription>
            </CardHeader>
            <CardContent class="p-6">
                <form @submit.prevent="updateBotPersonality" class="space-y-6">
                    <!-- Bot Type -->
                    <div class="space-y-3">
                        <Label>Bot Type</Label>
                        <div class="grid gap-3">
                            <label
                                v-for="type in botTypes"
                                :key="type.value"
                                class="flex items-start gap-3 p-3 border rounded-lg cursor-pointer transition-colors"
                                :class="botPersonalityForm.bot_type === type.value ? 'border-primary bg-primary/10' : 'hover:border-muted-foreground'"
                            >
                                <input
                                    v-model="botPersonalityForm.bot_type"
                                    type="radio"
                                    :value="type.value"
                                    class="mt-1"
                                />
                                <div>
                                    <div class="font-medium text-sm text-foreground">{{ type.label }}</div>
                                    <div class="text-xs text-muted-foreground">{{ type.description }}</div>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Bot Tone -->
                    <div class="space-y-2">
                        <Label>Communication Tone</Label>
                        <select
                            v-model="botPersonalityForm.bot_tone"
                            class="w-full h-9 rounded-md bg-background border px-3 text-sm text-foreground"
                        >
                            <option v-for="tone in botTones" :key="tone.value" :value="tone.value">
                                {{ tone.label }} - {{ tone.description }}
                            </option>
                        </select>
                    </div>

                    <!-- Custom Instructions -->
                    <div class="space-y-2">
                        <Label>Custom Instructions (Optional)</Label>
                        <Textarea
                            v-model="botPersonalityForm.bot_custom_instructions"
                            :rows="4"
                            placeholder="Add any additional instructions for how the bot should behave..."
                        />
                        <p class="text-xs text-muted-foreground">
                            These instructions will be added to guide the bot's responses. Max 2000 characters.
                        </p>
                    </div>

                    <Button type="submit" :disabled="botPersonalityForm.processing">
                        {{ botPersonalityForm.processing ? 'Saving...' : 'Save Bot Personality' }}
                    </Button>
                </form>
            </CardContent>
        </Card>

        <!-- Users & Transactions -->
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2 mb-8">
            <!-- Users -->
            <Card>
                <CardHeader class="border-b">
                    <CardTitle>Team Members</CardTitle>
                </CardHeader>
                <CardContent class="p-0">
                    <ul class="divide-y">
                        <li v-for="user in client.users" :key="user.id" class="px-4 py-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-medium text-foreground">{{ user.name }}</p>
                                    <p class="text-xs text-muted-foreground">{{ user.email }}</p>
                                </div>
                                <Badge variant="secondary">
                                    {{ user.role || 'user' }}
                                </Badge>
                            </div>
                        </li>
                        <li v-if="!client.users?.length" class="px-4 py-8 text-center text-muted-foreground">
                            No users
                        </li>
                    </ul>
                </CardContent>
            </Card>

            <!-- Transactions -->
            <Card>
                <CardHeader class="border-b">
                    <CardTitle>Recent Transactions</CardTitle>
                </CardHeader>
                <CardContent class="p-0">
                    <ul class="divide-y">
                        <li v-for="txn in transactions" :key="txn.id" class="px-4 py-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-medium text-foreground">{{ txn.plan?.name }}</p>
                                    <p class="text-xs text-muted-foreground">{{ formatCurrency(txn.amount) }} - {{ formatDate(txn.created_at) }}</p>
                                </div>
                                <Badge :variant="getStatusVariant(txn.status)" class="capitalize">
                                    {{ txn.status }}
                                </Badge>
                            </div>
                        </li>
                        <li v-if="!transactions?.length" class="px-4 py-8 text-center text-muted-foreground">
                            No transactions
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </div>

        <!-- Status Modal -->
        <div v-if="showStatusModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <Card class="w-full max-w-md">
                <CardHeader>
                    <CardTitle>Change Client Status</CardTitle>
                </CardHeader>
                <CardContent>
                    <form @submit.prevent="updateStatus">
                        <select
                            v-model="statusForm.status"
                            class="w-full h-9 rounded-md bg-background border px-3 text-sm text-foreground mb-4"
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
            <Card class="w-full max-w-md">
                <CardHeader>
                    <CardTitle>Change Client Plan</CardTitle>
                </CardHeader>
                <CardContent>
                    <form @submit.prevent="updatePlan">
                        <div class="mb-4">
                            <Label class="mb-1">Plan</Label>
                            <select
                                v-model="planForm.plan_id"
                                class="w-full h-9 rounded-md bg-background border px-3 text-sm text-foreground"
                            >
                                <option value="">Select a plan</option>
                                <option v-for="p in plans" :key="p.id" :value="p.id">
                                    {{ p.name }} - {{ formatCurrency(p.price) }}/{{ p.billing_period }}
                                </option>
                            </select>
                        </div>
                        <div class="mb-4">
                            <Label class="mb-1">Expires At</Label>
                            <Input
                                v-model="planForm.expires_at"
                                type="date"
                            />
                        </div>
                        <div class="flex justify-end space-x-3">
                            <Button
                                type="button"
                                variant="ghost"
                                @click="showPlanModal = false"
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
