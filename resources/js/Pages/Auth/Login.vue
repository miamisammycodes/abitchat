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
  password: '',
  remember: false,
})

const submit = () => {
  form.post(route('login.store'), {
    onFinish: () => form.reset('password'),
  })
}
</script>

<template>
  <Head title="Log in" />

  <div class="min-h-screen flex items-center justify-center bg-background py-12 px-4 sm:px-6 lg:px-8">
    <Card class="w-full max-w-md">
      <CardHeader class="text-center">
        <CardTitle class="text-2xl">Sign in to your account</CardTitle>
        <CardDescription>
          Or
          <Link :href="route('register')" class="font-medium text-primary hover:text-primary/80">
            create a new account
          </Link>
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
              autocomplete="username"
              placeholder="you@example.com"
            />
            <p v-if="form.errors.email" class="text-sm text-destructive">
              {{ form.errors.email }}
            </p>
          </div>

          <div class="space-y-2">
            <Label for="password">Password</Label>
            <Input
              id="password"
              v-model="form.password"
              type="password"
              required
              autocomplete="current-password"
              placeholder="Password"
            />
            <p v-if="form.errors.password" class="text-sm text-destructive">
              {{ form.errors.password }}
            </p>
          </div>

          <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
              <input
                id="remember"
                v-model="form.remember"
                type="checkbox"
                class="h-4 w-4 rounded border-input text-primary focus:ring-primary"
              />
              <Label for="remember" class="text-sm font-normal">Remember me</Label>
            </div>

            <Link :href="route('password.request')" class="text-sm font-medium text-primary hover:text-primary/80">
              Forgot your password?
            </Link>
          </div>

          <Button type="submit" class="w-full" :disabled="form.processing">
            {{ form.processing ? 'Signing in...' : 'Sign in' }}
          </Button>
        </form>
      </CardContent>
    </Card>
  </div>
</template>
