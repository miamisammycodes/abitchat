<script setup>
import { Head, useForm } from '@inertiajs/vue3'
import { useRoute } from '@/composables/useRoute'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card'
import { Button } from '@/Components/ui/button'
import { Input } from '@/Components/ui/input'
import { Label } from '@/Components/ui/label'

const route = useRoute()

const props = defineProps({
  email: String,
  token: String,
})

const form = useForm({
  token: props.token,
  email: props.email,
  password: '',
  password_confirmation: '',
})

const submit = () => {
  form.post(route('password.store'), {
    onFinish: () => form.reset('password', 'password_confirmation'),
  })
}
</script>

<template>
  <Head title="Reset Password" />

  <div class="min-h-screen flex items-center justify-center bg-background py-12 px-4 sm:px-6 lg:px-8">
    <Card class="w-full max-w-md">
      <CardHeader class="text-center">
        <CardTitle class="text-2xl">Set new password</CardTitle>
        <CardDescription>
          Enter your new password below.
        </CardDescription>
      </CardHeader>

      <CardContent>
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
            />
            <p v-if="form.errors.email" class="text-sm text-destructive">
              {{ form.errors.email }}
            </p>
          </div>

          <div class="space-y-2">
            <Label for="password">New Password</Label>
            <Input
              id="password"
              v-model="form.password"
              type="password"
              required
              autocomplete="new-password"
            />
            <p v-if="form.errors.password" class="text-sm text-destructive">
              {{ form.errors.password }}
            </p>
          </div>

          <div class="space-y-2">
            <Label for="password_confirmation">Confirm New Password</Label>
            <Input
              id="password_confirmation"
              v-model="form.password_confirmation"
              type="password"
              required
              autocomplete="new-password"
            />
          </div>

          <Button type="submit" class="w-full" :disabled="form.processing">
            {{ form.processing ? 'Resetting...' : 'Reset password' }}
          </Button>
        </form>
      </CardContent>
    </Card>
  </div>
</template>
