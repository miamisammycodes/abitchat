<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { router, useForm } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import { useRoute } from '@/composables/useRoute'
import { debounce } from 'lodash'
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card'
import { Button } from '@/Components/ui/button'
import { Input } from '@/Components/ui/input'
import { Label } from '@/Components/ui/label'
import { Badge } from '@/Components/ui/badge'
import { Textarea } from '@/Components/ui/textarea'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/Components/ui/table'
import { MessageSquare, X } from 'lucide-vue-next'

const route = useRoute()

const props = defineProps({
    inquiries: Object,
    counts: Object,
    filters: Object,
})

const search = ref(props.filters.search)
const status = ref(props.filters.status)

const selectedInquiry = ref(null)
const showDetailModal = ref(false)

const updateForm = useForm({
    status: '',
    admin_notes: '',
})

const applyFilters = debounce(() => {
    router.get(route('admin.inquiries.index'), {
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
    router.get(route('admin.inquiries.index'), {
        search: search.value,
        status: newStatus,
    }, {
        preserveState: true,
    })
}

const openDetailModal = (inquiry) => {
    selectedInquiry.value = inquiry
    updateForm.status = inquiry.status
    updateForm.admin_notes = inquiry.admin_notes || ''
    showDetailModal.value = true
}

const closeDetailModal = () => {
    showDetailModal.value = false
    selectedInquiry.value = null
    updateForm.reset()
}

const updateInquiry = () => {
    updateForm.put(route('admin.inquiries.update', selectedInquiry.value.id), {
        onSuccess: () => {
            closeDetailModal()
        },
    })
}

const formatDate = (date) => {
    return new Date(date).toLocaleDateString('en-US', {
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
        contacted: 'default',
        closed: 'secondary',
    }
    return variants[status] || 'secondary'
}

const truncate = (text, length = 50) => {
    if (!text) return ''
    return text.length > length ? text.substring(0, length) + '...' : text
}
</script>

<template>
    <AdminLayout title="Enterprise Inquiries">
        <!-- Header -->
        <div class="mb-6">
            <div class="flex items-center gap-2 mb-2">
                <MessageSquare class="h-6 w-6 text-primary" />
                <h1 class="text-2xl font-bold text-foreground">Enterprise Inquiries</h1>
            </div>
            <p class="text-muted-foreground">Manage enterprise plan inquiries from potential customers</p>
        </div>

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
                    @click="filterByStatus('contacted')"
                    :class="[
                        status === 'contacted' ? 'border-blue-500 text-blue-500' : 'border-transparent text-muted-foreground hover:text-foreground',
                        'py-4 px-1 border-b-2 font-medium text-sm'
                    ]"
                >
                    Contacted ({{ counts.contacted }})
                </button>
                <button
                    @click="filterByStatus('closed')"
                    :class="[
                        status === 'closed' ? 'border-gray-500 text-gray-500' : 'border-transparent text-muted-foreground hover:text-foreground',
                        'py-4 px-1 border-b-2 font-medium text-sm'
                    ]"
                >
                    Closed ({{ counts.closed }})
                </button>
            </nav>
        </div>

        <!-- Search -->
        <div class="mb-6">
            <Input
                v-model="search"
                type="text"
                placeholder="Search by name, email, or company..."
                class="w-full max-w-md"
            />
        </div>

        <!-- Table -->
        <Card>
            <CardContent class="p-0">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Contact</TableHead>
                            <TableHead>Company</TableHead>
                            <TableHead>Message</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>Date</TableHead>
                            <TableHead class="text-right">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="inquiry in inquiries.data" :key="inquiry.id">
                            <TableCell>
                                <div class="font-medium text-foreground">{{ inquiry.name }}</div>
                                <div class="text-sm text-muted-foreground">{{ inquiry.email }}</div>
                            </TableCell>
                            <TableCell class="text-muted-foreground">
                                {{ inquiry.company || '-' }}
                            </TableCell>
                            <TableCell>
                                <span class="text-muted-foreground text-sm">{{ truncate(inquiry.message, 60) }}</span>
                            </TableCell>
                            <TableCell>
                                <Badge :variant="getStatusVariant(inquiry.status)" class="capitalize">
                                    {{ inquiry.status }}
                                </Badge>
                            </TableCell>
                            <TableCell class="text-muted-foreground text-sm">
                                {{ formatDate(inquiry.created_at) }}
                            </TableCell>
                            <TableCell class="text-right">
                                <Button variant="ghost" size="sm" @click="openDetailModal(inquiry)">
                                    View
                                </Button>
                            </TableCell>
                        </TableRow>
                        <TableRow v-if="!inquiries.data?.length">
                            <TableCell colspan="6" class="text-center py-12 text-muted-foreground">
                                No inquiries found
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </CardContent>
        </Card>

        <!-- Pagination -->
        <div v-if="inquiries.links?.length > 3" class="mt-4 flex justify-center">
            <nav class="flex space-x-2">
                <template v-for="link in inquiries.links" :key="link.label">
                    <a
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

        <!-- Detail Modal -->
        <div v-if="showDetailModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <Card class="w-full max-w-lg mx-4">
                <CardHeader class="relative">
                    <button
                        @click="closeDetailModal"
                        class="absolute right-4 top-4 text-muted-foreground hover:text-foreground"
                    >
                        <X class="h-5 w-5" />
                    </button>
                    <CardTitle>Inquiry Details</CardTitle>
                </CardHeader>
                <CardContent>
                    <!-- Inquiry Info -->
                    <div class="space-y-4 mb-6">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <Label class="text-muted-foreground">Name</Label>
                                <p class="font-medium">{{ selectedInquiry?.name }}</p>
                            </div>
                            <div>
                                <Label class="text-muted-foreground">Email</Label>
                                <p class="font-medium">
                                    <a :href="`mailto:${selectedInquiry?.email}`" class="text-primary hover:underline">
                                        {{ selectedInquiry?.email }}
                                    </a>
                                </p>
                            </div>
                        </div>
                        <div v-if="selectedInquiry?.company">
                            <Label class="text-muted-foreground">Company</Label>
                            <p class="font-medium">{{ selectedInquiry?.company }}</p>
                        </div>
                        <div>
                            <Label class="text-muted-foreground">Message</Label>
                            <p class="text-sm bg-muted p-3 rounded-md mt-1">{{ selectedInquiry?.message }}</p>
                        </div>
                        <div>
                            <Label class="text-muted-foreground">Submitted</Label>
                            <p class="text-sm">{{ formatDate(selectedInquiry?.created_at) }}</p>
                        </div>
                    </div>

                    <!-- Update Form -->
                    <form @submit.prevent="updateInquiry" class="space-y-4 border-t pt-4">
                        <div>
                            <Label>Status</Label>
                            <Select v-model="updateForm.status">
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="pending">Pending</SelectItem>
                                    <SelectItem value="contacted">Contacted</SelectItem>
                                    <SelectItem value="closed">Closed</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label>Admin Notes</Label>
                            <Textarea
                                v-model="updateForm.admin_notes"
                                rows="3"
                                placeholder="Add notes about this inquiry..."
                            />
                        </div>
                        <div class="flex justify-end gap-3">
                            <Button type="button" variant="ghost" @click="closeDetailModal">
                                Cancel
                            </Button>
                            <Button type="submit" :disabled="updateForm.processing">
                                Update Inquiry
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
