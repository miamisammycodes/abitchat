<script setup>
import { ref } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { useRoute } from '@/composables/useRoute'
import ClientLayout from '@/Layouts/ClientLayout.vue'
import { Card, CardContent } from '@/Components/ui/card'
import { Button } from '@/Components/ui/button'
import { Input } from '@/Components/ui/input'
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
  Users,
  UserPlus,
  UserCheck,
  Star,
  Download,
  Search,
  ChevronLeft,
  ChevronRight,
} from 'lucide-vue-next'

const route = useRoute()

const props = defineProps({
  leads: Object,
  stats: Object,
  filters: Object,
})

const search = ref(props.filters?.search || '')
const statusFilter = ref(props.filters?.status || 'all')

const statuses = [
  { value: 'all', label: 'All Leads' },
  { value: 'new', label: 'New' },
  { value: 'contacted', label: 'Contacted' },
  { value: 'qualified', label: 'Qualified' },
  { value: 'converted', label: 'Converted' },
  { value: 'lost', label: 'Lost' },
]

function applyFilters() {
  router.get(route('client.leads.index'), {
    search: search.value || undefined,
    status: statusFilter.value !== 'all' ? statusFilter.value : undefined,
  }, {
    preserveState: true,
    preserveScroll: true,
  })
}

function getScoreVariant(score) {
  if (score >= 80) return 'success'
  if (score >= 60) return 'warning'
  if (score >= 40) return 'secondary'
  return 'outline'
}

function getScoreLabel(score) {
  if (score >= 80) return 'Hot'
  if (score >= 60) return 'Warm'
  if (score >= 40) return 'Moderate'
  return 'Cold'
}

function getStatusVariant(status) {
  const variants = {
    new: 'secondary',
    contacted: 'secondary',
    qualified: 'success',
    converted: 'success',
    lost: 'outline',
  }
  return variants[status] || 'secondary'
}

function exportCsv() {
  window.location.href = route('client.leads.export', {
    status: statusFilter.value !== 'all' ? statusFilter.value : undefined,
  })
}
</script>

<template>
  <Head title="Leads" />

  <ClientLayout>
    <div class="space-y-6">
      <!-- Header -->
      <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-foreground">Leads</h1>
        <Button @click="exportCsv">
          <Download class="h-4 w-4 mr-2" />
          Export CSV
        </Button>
      </div>

      <!-- Stats Grid -->
      <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
        <Card>
          <CardContent class="p-4">
            <div class="flex items-center gap-3">
              <div class="rounded-lg bg-gray-100 p-2">
                <Users class="h-5 w-5 text-gray-600" />
              </div>
              <div>
                <p class="text-2xl font-bold text-foreground">{{ stats?.total ?? 0 }}</p>
                <p class="text-sm text-muted-foreground">Total Leads</p>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardContent class="p-4">
            <div class="flex items-center gap-3">
              <div class="rounded-lg bg-blue-100 p-2">
                <UserPlus class="h-5 w-5 text-blue-600" />
              </div>
              <div>
                <p class="text-2xl font-bold text-blue-600">{{ stats?.new ?? 0 }}</p>
                <p class="text-sm text-muted-foreground">New</p>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardContent class="p-4">
            <div class="flex items-center gap-3">
              <div class="rounded-lg bg-green-100 p-2">
                <UserCheck class="h-5 w-5 text-green-600" />
              </div>
              <div>
                <p class="text-2xl font-bold text-green-600">{{ stats?.qualified ?? 0 }}</p>
                <p class="text-sm text-muted-foreground">Qualified</p>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardContent class="p-4">
            <div class="flex items-center gap-3">
              <div class="rounded-lg bg-amber-100 p-2">
                <Star class="h-5 w-5 text-amber-600" />
              </div>
              <div>
                <p class="text-2xl font-bold text-amber-600">{{ stats?.high_quality ?? 0 }}</p>
                <p class="text-sm text-muted-foreground">High Quality (70+)</p>
              </div>
            </div>
          </CardContent>
        </Card>
      </div>

      <!-- Filters -->
      <Card>
        <CardContent class="p-4">
          <div class="flex flex-col sm:flex-row gap-4">
            <div class="flex-1 relative">
              <Search class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
              <Input
                v-model="search"
                @keyup.enter="applyFilters"
                type="text"
                placeholder="Search by name, email, phone, or company..."
                class="pl-9"
              />
            </div>
            <select
              v-model="statusFilter"
              @change="applyFilters"
              class="px-4 py-2 border border-input rounded-md bg-background text-foreground"
            >
              <option v-for="status in statuses" :key="status.value" :value="status.value">
                {{ status.label }}
              </option>
            </select>
            <Button @click="applyFilters">Search</Button>
          </div>
        </CardContent>
      </Card>

      <!-- Leads Table -->
      <Card>
        <CardContent class="p-0">
          <div v-if="!leads?.data?.length" class="p-12 text-center">
            <Users class="mx-auto h-12 w-12 text-muted-foreground mb-4" />
            <h3 class="text-lg font-medium text-foreground mb-1">No leads yet</h3>
            <p class="text-muted-foreground">Leads will appear here when visitors share their contact information via the chatbot.</p>
          </div>

          <Table v-else>
            <TableHeader>
              <TableRow>
                <TableHead>Lead</TableHead>
                <TableHead>Contact</TableHead>
                <TableHead>Score</TableHead>
                <TableHead>Status</TableHead>
                <TableHead>Created</TableHead>
                <TableHead class="text-right">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow v-for="lead in leads.data" :key="lead.id">
                <TableCell>
                  <div class="font-medium">{{ lead.name || 'Unknown' }}</div>
                  <div v-if="lead.company" class="text-sm text-muted-foreground">{{ lead.company }}</div>
                </TableCell>
                <TableCell>
                  <div v-if="lead.email" class="text-sm">{{ lead.email }}</div>
                  <div v-if="lead.phone" class="text-sm text-muted-foreground">{{ lead.phone }}</div>
                </TableCell>
                <TableCell>
                  <Badge :variant="getScoreVariant(lead.score)">
                    {{ lead.score }} - {{ getScoreLabel(lead.score) }}
                  </Badge>
                </TableCell>
                <TableCell>
                  <Badge :variant="getStatusVariant(lead.status)" class="capitalize">
                    {{ lead.status }}
                  </Badge>
                </TableCell>
                <TableCell class="text-muted-foreground">
                  {{ new Date(lead.created_at).toLocaleDateString() }}
                </TableCell>
                <TableCell class="text-right">
                  <Button variant="ghost" size="sm" as-child>
                    <Link :href="route('client.leads.show', lead.id)">
                      View
                    </Link>
                  </Button>
                </TableCell>
              </TableRow>
            </TableBody>
          </Table>

          <!-- Pagination -->
          <div v-if="leads?.data?.length" class="px-6 py-4 border-t border-border flex items-center justify-between">
            <div class="text-sm text-muted-foreground">
              Showing {{ leads.from }} to {{ leads.to }} of {{ leads.total }} leads
            </div>
            <div class="flex gap-2">
              <Button
                v-if="leads.prev_page_url"
                variant="outline"
                size="sm"
                as-child
              >
                <Link :href="leads.prev_page_url">
                  <ChevronLeft class="h-4 w-4 mr-1" />
                  Previous
                </Link>
              </Button>
              <Button
                v-if="leads.next_page_url"
                variant="outline"
                size="sm"
                as-child
              >
                <Link :href="leads.next_page_url">
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
