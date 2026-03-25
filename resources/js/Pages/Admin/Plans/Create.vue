<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import { useRoute } from '@/composables/useRoute'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card'
import { Button } from '@/Components/ui/button'
import { Input } from '@/Components/ui/input'
import { Label } from '@/Components/ui/label'
import { Textarea } from '@/Components/ui/textarea'
import { Switch } from '@/Components/ui/switch'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select'
import { ArrowLeft, Plus, X } from 'lucide-vue-next'

const route = useRoute()

const form = useForm({
    name: '',
    slug: '',
    description: '',
    price: 0,
    billing_period: 'monthly',
    conversations_limit: 100,
    messages_per_conversation: 20,
    knowledge_items_limit: 10,
    tokens_limit: 50000,
    leads_limit: 50,
    features: [],
    is_active: true,
    is_contact_sales: false,
    sort_order: 1,
})

const newFeature = ref('')

// Auto-generate slug from name
watch(() => form.name, (name) => {
    form.slug = name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '')
})

const addFeature = () => {
    if (newFeature.value.trim()) {
        form.features.push(newFeature.value.trim())
        newFeature.value = ''
    }
}

const removeFeature = (index) => {
    form.features.splice(index, 1)
}

const submit = () => {
    form.post(route('admin.plans.store'))
}

// Limit input handlers for unlimited checkbox
const limitFields = [
    { key: 'conversations_limit', label: 'Conversations' },
    { key: 'messages_per_conversation', label: 'Messages/Conversation' },
    { key: 'knowledge_items_limit', label: 'Knowledge Items' },
    { key: 'tokens_limit', label: 'Tokens' },
    { key: 'leads_limit', label: 'Leads' },
]

const isUnlimited = (field) => form[field] === -1

const toggleUnlimited = (field) => {
    if (form[field] === -1) {
        form[field] = 100
    } else {
        form[field] = -1
    }
}
</script>

<template>
    <AdminLayout title="Create Plan">
        <div class="max-w-3xl mx-auto">
            <!-- Header -->
            <div class="mb-6">
                <Link :href="route('admin.plans.index')" class="inline-flex items-center text-muted-foreground hover:text-foreground mb-4">
                    <ArrowLeft class="w-4 h-4 mr-2" />
                    Back to Plans
                </Link>
                <h1 class="text-2xl font-bold text-foreground">Create New Plan</h1>
                <p class="text-muted-foreground">Add a new subscription plan</p>
            </div>

            <form @submit.prevent="submit">
                <!-- Basic Info -->
                <Card class="mb-6">
                    <CardHeader>
                        <CardTitle>Basic Information</CardTitle>
                        <CardDescription>Plan name, description, and pricing</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <Label>Name *</Label>
                                <Input v-model="form.name" placeholder="e.g., Pro" />
                                <p v-if="form.errors.name" class="text-red-500 text-sm mt-1">{{ form.errors.name }}</p>
                            </div>
                            <div>
                                <Label>Slug *</Label>
                                <Input v-model="form.slug" placeholder="e.g., pro" />
                                <p v-if="form.errors.slug" class="text-red-500 text-sm mt-1">{{ form.errors.slug }}</p>
                            </div>
                        </div>

                        <div>
                            <Label>Description</Label>
                            <Textarea v-model="form.description" placeholder="Brief description of the plan" rows="2" />
                            <p v-if="form.errors.description" class="text-red-500 text-sm mt-1">{{ form.errors.description }}</p>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <Label>Price (Nu.) *</Label>
                                <Input v-model.number="form.price" type="number" min="0" step="0.01" :disabled="form.is_contact_sales" />
                                <p v-if="form.errors.price" class="text-red-500 text-sm mt-1">{{ form.errors.price }}</p>
                            </div>
                            <div>
                                <Label>Billing Period *</Label>
                                <Select v-model="form.billing_period">
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="monthly">Monthly</SelectItem>
                                        <SelectItem value="yearly">Yearly</SelectItem>
                                    </SelectContent>
                                </Select>
                                <p v-if="form.errors.billing_period" class="text-red-500 text-sm mt-1">{{ form.errors.billing_period }}</p>
                            </div>
                        </div>

                        <div class="flex items-center justify-between p-4 bg-muted rounded-lg">
                            <div>
                                <Label>Contact Sales Plan</Label>
                                <p class="text-sm text-muted-foreground">Show "Contact Us" instead of price</p>
                            </div>
                            <Switch v-model:checked="form.is_contact_sales" />
                        </div>
                    </CardContent>
                </Card>

                <!-- Limits -->
                <Card class="mb-6">
                    <CardHeader>
                        <CardTitle>Plan Limits</CardTitle>
                        <CardDescription>Set usage limits (-1 for unlimited)</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div v-for="field in limitFields" :key="field.key" class="flex items-center gap-4">
                            <div class="flex-1">
                                <Label>{{ field.label }}</Label>
                                <Input
                                    v-model.number="form[field.key]"
                                    type="number"
                                    :min="-1"
                                    :disabled="isUnlimited(field.key)"
                                    :class="{ 'bg-muted': isUnlimited(field.key) }"
                                />
                            </div>
                            <div class="flex items-center gap-2 pt-6">
                                <Switch
                                    :checked="isUnlimited(field.key)"
                                    @update:checked="toggleUnlimited(field.key)"
                                />
                                <span class="text-sm text-muted-foreground">Unlimited</span>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <!-- Features -->
                <Card class="mb-6">
                    <CardHeader>
                        <CardTitle>Features</CardTitle>
                        <CardDescription>List of features included in this plan</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div class="flex gap-2 mb-4">
                            <Input
                                v-model="newFeature"
                                placeholder="Add a feature..."
                                @keyup.enter.prevent="addFeature"
                            />
                            <Button type="button" variant="outline" @click="addFeature">
                                <Plus class="w-4 h-4" />
                            </Button>
                        </div>

                        <div v-if="form.features.length" class="space-y-2">
                            <div
                                v-for="(feature, index) in form.features"
                                :key="index"
                                class="flex items-center justify-between p-3 bg-muted rounded-lg"
                            >
                                <span class="text-sm">{{ feature }}</span>
                                <button type="button" @click="removeFeature(index)" class="text-muted-foreground hover:text-destructive">
                                    <X class="w-4 h-4" />
                                </button>
                            </div>
                        </div>
                        <p v-else class="text-muted-foreground text-sm">No features added yet</p>
                    </CardContent>
                </Card>

                <!-- Settings -->
                <Card class="mb-6">
                    <CardHeader>
                        <CardTitle>Settings</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="flex items-center justify-between">
                            <div>
                                <Label>Active</Label>
                                <p class="text-sm text-muted-foreground">Make this plan available for subscription</p>
                            </div>
                            <Switch v-model:checked="form.is_active" />
                        </div>

                        <div>
                            <Label>Sort Order</Label>
                            <Input v-model.number="form.sort_order" type="number" min="0" class="w-24" />
                            <p class="text-sm text-muted-foreground mt-1">Lower numbers appear first</p>
                        </div>
                    </CardContent>
                </Card>

                <!-- Actions -->
                <div class="flex justify-end gap-4">
                    <Link :href="route('admin.plans.index')">
                        <Button type="button" variant="outline">Cancel</Button>
                    </Link>
                    <Button type="submit" :disabled="form.processing">
                        Create Plan
                    </Button>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
