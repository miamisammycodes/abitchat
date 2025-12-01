<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import { useRoute } from '@/composables/useRoute'
import { debounce } from 'lodash'
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card'
import { Button } from '@/Components/ui/button'
import { Input } from '@/Components/ui/input'
import { Label } from '@/Components/ui/label'
import { Badge } from '@/Components/ui/badge'
import { Textarea } from '@/Components/ui/textarea'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/Components/ui/table'

const route = useRoute()

const props = defineProps({
    transactions: Object,
    counts: Object,
    filters: Object,
})

const search = ref(props.filters.search)
const status = ref(props.filters.status)

const selectedTransaction = ref(null)
const showApproveModal = ref(false)
const showRejectModal = ref(false)

const approveForm = useForm({
    admin_notes: '',
})

const rejectForm = useForm({
    admin_notes: '',
})

const applyFilters = debounce(() => {
    router.get(route('admin.transactions.index'), {
        search: search.value,
        status: status.value,
    }, {
        preserveState: true,
        replace: true,
    })
}, 300)

watch(search, applyFilters)

const filterByStatus = (newStatus) => {
    status.value = newStatus
    router.get(route('admin.transactions.index'), {
        search: search.value,
        status: newStatus,
    }, {
        preserveState: true,
    })
}

const openApproveModal = (txn) => {
    selectedTransaction.value = txn
    showApproveModal.value = true
}

const openRejectModal = (txn) => {
    selectedTransaction.value = txn
    showRejectModal.value = true
}

const approveTransaction = () => {
    approveForm.post(route('admin.transactions.approve', selectedTransaction.value.id), {
        onSuccess: () => {
            showApproveModal.value = false
            approveForm.reset()
        },
    })
}

const rejectTransaction = () => {
    rejectForm.post(route('admin.transactions.reject', selectedTransaction.value.id), {
        onSuccess: () => {
            showRejectModal.value = false
            rejectForm.reset()
        },
    })
}

const formatCurrency = (amount) => {
    return 'Nu. ' + new Intl.NumberFormat('en-IN').format(amount || 0)
}

const formatDate = (date) => {
    return new Date(date).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
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

const getPaymentMethodLabel = (method) => {
    const labels = {
        bank_transfer: 'Bank Transfer',
        upi: 'UPI',
        card: 'Card',
        cash: 'Cash',
        other: 'Other',
    }
    return labels[method] || method
}
</script>

<template>
    <AdminLayout title="Transactions">
        <!-- Tabs -->
        <div class="mb-6 border-b">
            <nav class="flex space-x-8">
                <button
                    @click="filterByStatus('all')"
                    :class="[
                        status === 'all' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground',
                        'py-4 px-1 border-b-2 font-medium text-sm'
                    ]"
                >
                    All ({{ counts.all }})
                </button>
                <button
                    @click="filterByStatus('pending')"
                    :class="[
                        status === 'pending' ? 'border-amber-500 text-amber-500' : 'border-transparent text-muted-foreground hover:text-foreground',
                        'py-4 px-1 border-b-2 font-medium text-sm'
                    ]"
                >
                    Pending ({{ counts.pending }})
                </button>
                <button
                    @click="filterByStatus('approved')"
                    :class="[
                        status === 'approved' ? 'border-emerald-500 text-emerald-500' : 'border-transparent text-muted-foreground hover:text-foreground',
                        'py-4 px-1 border-b-2 font-medium text-sm'
                    ]"
                >
                    Approved ({{ counts.approved }})
                </button>
                <button
                    @click="filterByStatus('rejected')"
                    :class="[
                        status === 'rejected' ? 'border-red-500 text-red-500' : 'border-transparent text-muted-foreground hover:text-foreground',
                        'py-4 px-1 border-b-2 font-medium text-sm'
                    ]"
                >
                    Rejected ({{ counts.rejected }})
                </button>
            </nav>
        </div>

        <!-- Search -->
        <div class="mb-6">
            <Input
                v-model="search"
                type="text"
                placeholder="Search by transaction number or client name..."
                class="w-full max-w-md"
            />
        </div>

        <!-- Table -->
        <Card>
            <CardContent class="p-0">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Client</TableHead>
                            <TableHead>Plan</TableHead>
                            <TableHead>Transaction #</TableHead>
                            <TableHead>Method</TableHead>
                            <TableHead class="text-right">Amount</TableHead>
                            <TableHead>Date</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead class="text-right">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="txn in transactions.data" :key="txn.id">
                            <TableCell>
                                <Link :href="route('admin.clients.show', txn.tenant?.id)" class="text-sm font-medium text-foreground hover:text-primary">
                                    {{ txn.tenant?.name }}
                                </Link>
                            </TableCell>
                            <TableCell class="text-muted-foreground">
                                {{ txn.plan?.name }}
                            </TableCell>
                            <TableCell>
                                <span class="text-muted-foreground font-mono">{{ txn.transaction_number }}</span>
                            </TableCell>
                            <TableCell class="text-muted-foreground">
                                {{ getPaymentMethodLabel(txn.payment_method) }}
                            </TableCell>
                            <TableCell class="text-right text-foreground font-medium">
                                {{ formatCurrency(txn.amount) }}
                            </TableCell>
                            <TableCell class="text-muted-foreground">
                                {{ formatDate(txn.payment_date) }}
                            </TableCell>
                            <TableCell>
                                <Badge :variant="getStatusVariant(txn.status)" class="capitalize">
                                    {{ txn.status }}
                                </Badge>
                            </TableCell>
                            <TableCell class="text-right space-x-2">
                                <template v-if="txn.status === 'pending'">
                                    <button
                                        @click="openApproveModal(txn)"
                                        class="text-sm text-emerald-500 hover:text-emerald-400"
                                    >
                                        Approve
                                    </button>
                                    <button
                                        @click="openRejectModal(txn)"
                                        class="text-sm text-red-500 hover:text-red-400"
                                    >
                                        Reject
                                    </button>
                                </template>
                                <span v-else class="text-muted-foreground">-</span>
                            </TableCell>
                        </TableRow>
                        <TableRow v-if="!transactions.data?.length">
                            <TableCell colspan="8" class="text-center py-12 text-muted-foreground">
                                No transactions found
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </CardContent>
        </Card>

        <!-- Pagination -->
        <div v-if="transactions.links?.length > 3" class="mt-4 flex justify-center">
            <nav class="flex space-x-2">
                <template v-for="link in transactions.links" :key="link.label">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        :class="[
                            'px-3 py-2 text-sm rounded-md',
                            link.active ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground hover:bg-accent'
                        ]"
                        v-html="link.label"
                    />
                </template>
            </nav>
        </div>

        <!-- Approve Modal -->
        <div v-if="showApproveModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <Card class="w-full max-w-md">
                <CardHeader>
                    <CardTitle>Approve Transaction</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="mb-4 p-4 bg-muted rounded-md">
                        <p class="text-sm text-muted-foreground"><strong class="text-foreground">Client:</strong> {{ selectedTransaction?.tenant?.name }}</p>
                        <p class="text-sm text-muted-foreground"><strong class="text-foreground">Plan:</strong> {{ selectedTransaction?.plan?.name }}</p>
                        <p class="text-sm text-muted-foreground"><strong class="text-foreground">Amount:</strong> {{ formatCurrency(selectedTransaction?.amount) }}</p>
                        <p class="text-sm text-muted-foreground"><strong class="text-foreground">Transaction #:</strong> {{ selectedTransaction?.transaction_number }}</p>
                    </div>
                    <form @submit.prevent="approveTransaction">
                        <div class="mb-4">
                            <Label class="mb-1">Admin Notes (Optional)</Label>
                            <Textarea
                                v-model="approveForm.admin_notes"
                                rows="3"
                                placeholder="Add any notes..."
                            />
                        </div>
                        <div class="flex justify-end space-x-3">
                            <Button
                                type="button"
                                variant="ghost"
                                @click="showApproveModal = false"
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                :disabled="approveForm.processing"
                                class="bg-emerald-600 hover:bg-emerald-700"
                            >
                                Approve & Activate Plan
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </div>

        <!-- Reject Modal -->
        <div v-if="showRejectModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <Card class="w-full max-w-md">
                <CardHeader>
                    <CardTitle>Reject Transaction</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="mb-4 p-4 bg-muted rounded-md">
                        <p class="text-sm text-muted-foreground"><strong class="text-foreground">Client:</strong> {{ selectedTransaction?.tenant?.name }}</p>
                        <p class="text-sm text-muted-foreground"><strong class="text-foreground">Transaction #:</strong> {{ selectedTransaction?.transaction_number }}</p>
                    </div>
                    <form @submit.prevent="rejectTransaction">
                        <div class="mb-4">
                            <Label class="mb-1">Reason for Rejection *</Label>
                            <Textarea
                                v-model="rejectForm.admin_notes"
                                rows="3"
                                placeholder="Please provide a reason..."
                                required
                            />
                            <p v-if="rejectForm.errors.admin_notes" class="text-red-500 text-sm mt-1">{{ rejectForm.errors.admin_notes }}</p>
                        </div>
                        <div class="flex justify-end space-x-3">
                            <Button
                                type="button"
                                variant="ghost"
                                @click="showRejectModal = false"
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                :disabled="rejectForm.processing"
                                variant="destructive"
                            >
                                Reject Transaction
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
