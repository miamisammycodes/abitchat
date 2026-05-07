<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link, useForm, usePage } from '@inertiajs/vue3'
import { ref } from 'vue'
import { useRoute } from '@/composables/useRoute'
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card'
import { Button } from '@/Components/ui/button'
import { Label } from '@/Components/ui/label'
import { Badge } from '@/Components/ui/badge'
import { Textarea } from '@/Components/ui/textarea'

const route = useRoute()
const page = usePage()

const props = defineProps({
    transaction: Object,
})

const showApproveModal = ref(false)
const showRejectModal = ref(false)

const approveForm = useForm({ admin_notes: '' })
const rejectForm = useForm({ admin_notes: '' })

const approveTransaction = () => {
    approveForm.post(route('admin.transactions.approve', props.transaction.id), {
        onSuccess: () => {
            showApproveModal.value = false
            approveForm.reset()
        },
    })
}

const rejectTransaction = () => {
    rejectForm.post(route('admin.transactions.reject', props.transaction.id), {
        onSuccess: () => {
            showRejectModal.value = false
            rejectForm.reset()
        },
    })
}

const formatCurrency = (amount) => 'Nu. ' + Number(amount || 0).toLocaleString('en-US')

const formatDate = (date) => {
    if (!date) return '—'
    return new Date(date).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    })
}

const formatDateTime = (date) => {
    if (!date) return '—'
    return new Date(date).toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    })
}

const getStatusVariant = (status) => {
    const variants = {
        pending: 'warning',
        approved: 'success',
        rejected: 'destructive',
    }
    return variants[status] || 'secondary'
}

const getBankLabel = (bank) => {
    const labels = {
        bob: 'Bank of Bhutan',
        bnb: 'Bhutan National Bank',
        dpnb: 'Druk PNB',
        bdbl: 'BDBL',
        tbank: 'T-Bank',
        dk: 'DK Bank',
    }
    return labels[bank] || bank
}
</script>

<template>
    <AdminLayout title="Transaction">
        <div class="space-y-6">
            <div class="flex items-center justify-between">
                <div>
                    <Link :href="route('admin.transactions.index')" class="text-sm text-muted-foreground hover:text-foreground">
                        ← Back to Transactions
                    </Link>
                    <h2 class="mt-1 text-2xl font-bold text-foreground">
                        {{ transaction.transaction_number }}
                    </h2>
                </div>
                <Badge :variant="getStatusVariant(transaction.status)" class="capitalize">
                    {{ transaction.status }}
                </Badge>
            </div>

            <Card>
                <CardHeader class="border-b">
                    <CardTitle>Payment Details</CardTitle>
                </CardHeader>
                <CardContent class="p-6">
                    <dl class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                        <div>
                            <dt class="text-xs uppercase text-muted-foreground">Client</dt>
                            <dd class="mt-1 text-sm font-medium text-foreground">
                                <Link
                                    v-if="transaction.tenant"
                                    :href="route('admin.clients.show', transaction.tenant.id)"
                                    class="hover:underline"
                                >
                                    {{ transaction.tenant.name }}
                                </Link>
                                <span v-else>—</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase text-muted-foreground">Plan</dt>
                            <dd class="mt-1 text-sm font-medium text-foreground">
                                {{ transaction.plan?.name || '—' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase text-muted-foreground">Amount</dt>
                            <dd class="mt-1 text-sm font-medium text-foreground">
                                {{ formatCurrency(transaction.amount) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase text-muted-foreground">Payment Method</dt>
                            <dd class="mt-1 text-sm font-medium text-foreground">
                                {{ getBankLabel(transaction.payment_method) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase text-muted-foreground">Reference Number</dt>
                            <dd class="mt-1 font-mono text-sm text-foreground">
                                {{ transaction.reference_number || '—' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase text-muted-foreground">Payment Date</dt>
                            <dd class="mt-1 text-sm text-foreground">
                                {{ formatDate(transaction.payment_date) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase text-muted-foreground">Submitted</dt>
                            <dd class="mt-1 text-sm text-foreground">
                                {{ formatDateTime(transaction.created_at) }}
                            </dd>
                        </div>
                        <div v-if="transaction.approved_at">
                            <dt class="text-xs uppercase text-muted-foreground">
                                {{ transaction.status === 'rejected' ? 'Rejected' : 'Approved' }}
                            </dt>
                            <dd class="mt-1 text-sm text-foreground">
                                {{ formatDateTime(transaction.approved_at) }}
                            </dd>
                        </div>
                    </dl>

                    <div v-if="transaction.notes" class="mt-6">
                        <dt class="text-xs uppercase text-muted-foreground">Customer Notes</dt>
                        <dd class="mt-1 whitespace-pre-wrap text-sm text-foreground">
                            {{ transaction.notes }}
                        </dd>
                    </div>

                    <div v-if="transaction.admin_notes" class="mt-6">
                        <dt class="text-xs uppercase text-muted-foreground">Admin Notes</dt>
                        <dd class="mt-1 whitespace-pre-wrap text-sm text-foreground">
                            {{ transaction.admin_notes }}
                        </dd>
                    </div>
                </CardContent>
            </Card>

            <div v-if="transaction.status === 'pending'" class="flex justify-end gap-3">
                <Button variant="destructive" @click="showRejectModal = true">
                    Reject
                </Button>
                <Button class="bg-emerald-600 hover:bg-emerald-700" @click="showApproveModal = true">
                    Approve &amp; Activate Plan
                </Button>
            </div>

            <div v-if="page.props.flash?.error" class="rounded border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">
                {{ page.props.flash.error }}
            </div>
        </div>

        <div v-if="showApproveModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showApproveModal = false">
            <Card class="w-full max-w-md">
                <CardHeader>
                    <CardTitle>Approve Transaction</CardTitle>
                </CardHeader>
                <CardContent>
                    <form @submit.prevent="approveTransaction">
                        <div class="mb-4">
                            <Label class="mb-1">Admin Notes (Optional)</Label>
                            <Textarea v-model="approveForm.admin_notes" rows="3" placeholder="Add any notes..." />
                            <p v-if="approveForm.errors.admin_notes" class="mt-1 text-sm text-destructive">
                                {{ approveForm.errors.admin_notes }}
                            </p>
                        </div>
                        <div class="flex justify-end space-x-3">
                            <Button type="button" variant="ghost" @click="showApproveModal = false">
                                Cancel
                            </Button>
                            <Button type="submit" :disabled="approveForm.processing" class="bg-emerald-600 hover:bg-emerald-700">
                                Approve &amp; Activate Plan
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </div>

        <div v-if="showRejectModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showRejectModal = false">
            <Card class="w-full max-w-md">
                <CardHeader>
                    <CardTitle>Reject Transaction</CardTitle>
                </CardHeader>
                <CardContent>
                    <form @submit.prevent="rejectTransaction">
                        <div class="mb-4">
                            <Label class="mb-1">Reason for Rejection *</Label>
                            <Textarea v-model="rejectForm.admin_notes" rows="3" placeholder="Please provide a reason..." required />
                            <p v-if="rejectForm.errors.admin_notes" class="mt-1 text-sm text-destructive">
                                {{ rejectForm.errors.admin_notes }}
                            </p>
                        </div>
                        <div class="flex justify-end space-x-3">
                            <Button type="button" variant="ghost" @click="showRejectModal = false">
                                Cancel
                            </Button>
                            <Button type="submit" :disabled="rejectForm.processing" variant="destructive">
                                Reject Transaction
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
