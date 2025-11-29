<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3'
import { useRoute } from '@/composables/useRoute'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card'
import { Button } from '@/Components/ui/button'
import { Input } from '@/Components/ui/input'
import { Label } from '@/Components/ui/label'
import { Alert, AlertDescription } from '@/Components/ui/alert'
import { CheckCircle } from 'lucide-vue-next'

const route = useRoute()

defineProps({
  status: String,
})

const form = useForm({
  email: '',
})

const submit = () => {
  form.post(route('password.email'))
}
</script>

<template>
  <Head title="Forgot Password" />

  <div class="min-h-screen flex items-center justify-center bg-background py-12 px-4 sm:px-6 lg:px-8">
    <Card class="w-full max-w-md">
      <CardHeader class="text-center">
        <CardTitle class="text-2xl">Reset your password</CardTitle>
        <CardDescription>
          Enter your email address and we'll send you a password reset link.
        </CardDescription>
      </CardHeader>

      <CardContent>
        <Alert v-if="status" class="mb-6 border-green-200 bg-green-50 text-green-800">
          <CheckCircle class="h-4 w-4" />
          <AlertDescription>{{ status }}</AlertDescription>
        </Alert>

        <form @submit.prevent="submit" class="space-y-4">
          <div class="space-y-2">
            <Label for="email">Email address</Label>
            <Input
              id="email"
              v-model="form.email"
              type="email"
              required
              autofocus
              placeholder="you@example.com"
            />
            <p v-if="form.errors.email" class="text-sm text-destructive">
              {{ form.errors.email }}
            </p>
          </div>

          <div class="flex items-center justify-between">
            <Link :href="route('login')" class="text-sm font-medium text-primary hover:text-primary/80">
              Back to login
            </Link>

            <Button type="submit" :disabled="form.processing">
              {{ form.processing ? 'Sending...' : 'Send reset link' }}
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  </div>
</template>
