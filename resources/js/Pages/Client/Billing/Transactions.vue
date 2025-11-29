<script setup>
import { Head, Link } from '@inertiajs/vue3'
import { useRoute } from '@/composables/useRoute'
import ClientLayout from '@/Layouts/ClientLayout.vue'
import { Card, CardContent } from '@/Components/ui/card'
import { Button } from '@/Components/ui/button'
import { Badge } from '@/Components/ui/badge'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/Components/ui/table'
import {
  ClipboardList,
  ChevronLeft,
  ChevronRight,
} from 'lucide-vue-next'

const route = useRoute()

const props = defineProps({
  transactions: Object,
})

function getStatusVariant(status) {
  const variants = {
    pending: 'warning',
    approved: 'success',
    rejected: 'destructive',
  }
  return variants[status] || 'secondary'
}

function getPaymentMethodLabel(method) {
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
  <Head title="Transaction History" />

  <ClientLayout>
    <div class="space-y-6">
      <!-- Header -->
      <div>
        <h1 class="text-2xl font-bold text-foreground">Transaction History</h1>
        <p class="text-muted-foreground mt-1">View your payment history and transaction details</p>
      </div>

      <Card>
        <CardContent class="p-0">
          <div v-if="!transactions?.data?.length" class="p-12 text-center">
            <ClipboardList class="mx-auto h-12 w-12 text-muted-foreground mb-4" />
            <h3 class="text-lg font-medium mb-1">No transactions yet</h3>
            <p class="text-muted-foreground mb-4">Your payment history will appear here.</p>
            <Button as-child>
              <Link :href="route('client.billing.plans')">View Plans</Link>
            </Button>
          </div>

          <Table v-else>
            <TableHeader>
              <TableRow>
                <TableHead>Transaction</TableHead>
                <TableHead>Plan</TableHead>
                <TableHead>Amount</TableHead>
                <TableHead>Method</TableHead>
                <TableHead>Status</TableHead>
                <TableHead>Date</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow v-for="transaction in transactions.data" :key="transaction.id">
                <TableCell>
                  <div class="font-medium">{{ transaction.transaction_number }}</div>
                  <div v-if="transaction.notes" class="text-sm text-muted-foreground truncate max-w-xs">
                    {{ transaction.notes }}
                  </div>
                </TableCell>
                <TableCell class="font-medium">{{ transaction.plan?.name }}</TableCell>
                <TableCell class="font-medium">${{ transaction.amount }}</TableCell>
                <TableCell class="text-muted-foreground">
                  {{ getPaymentMethodLabel(transaction.payment_method) }}
                </TableCell>
                <TableCell>
                  <Badge :variant="getStatusVariant(transaction.status)" class="capitalize">
                    {{ transaction.status }}
                  </Badge>
                  <div v-if="transaction.admin_notes" class="text-xs text-muted-foreground mt-1">
                    {{ transaction.admin_notes }}
                  </div>
                </TableCell>
                <TableCell class="text-muted-foreground">
                  <div>{{ new Date(transaction.payment_date).toLocaleDateString() }}</div>
                  <div class="text-xs">Submitted: {{ new Date(transaction.created_at).toLocaleDateString() }}</div>
                </TableCell>
              </TableRow>
            </TableBody>
          </Table>

          <!-- Pagination -->
          <div v-if="transactions?.data?.length" class="px-6 py-4 border-t border-border flex items-center justify-between">
            <div class="text-sm text-muted-foreground">
              Showing {{ transactions.from }} to {{ transactions.to }} of {{ transactions.total }} transactions
            </div>
            <div class="flex gap-2">
              <Button
                v-if="transactions.prev_page_url"
                variant="outline"
                size="sm"
                as-child
              >
                <Link :href="transactions.prev_page_url">
                  <ChevronLeft class="h-4 w-4 mr-1" />
                  Previous
                </Link>
              </Button>
              <Button
                v-if="transactions.next_page_url"
                variant="outline"
                size="sm"
                as-child
              >
                <Link :href="transactions.next_page_url">
                  Next
                  <ChevronRight class="h-4 w-4 ml-1" />
                </Link>
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>
    </div>
  </ClientLayout>
</template>
