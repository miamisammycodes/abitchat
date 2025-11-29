<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3'
import { useRoute } from '@/composables/useRoute'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card'
import { Button } from '@/Components/ui/button'
import { Input } from '@/Components/ui/input'
import { Label } from '@/Components/ui/label'

const route = useRoute()

const form = useForm({
  company_name: '',
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
})

const submit = () => {
  form.post(route('register.store'), {
    onFinish: () => form.reset('password', 'password_confirmation'),
  })
}
</script>

<template>
  <Head title="Register" />

  <div class="min-h-screen flex items-center justify-center bg-background py-12 px-4 sm:px-6 lg:px-8">
    <Card class="w-full max-w-md">
      <CardHeader class="text-center">
        <CardTitle class="text-2xl">Create your account</CardTitle>
        <CardDescription>
          Or
          <Link :href="route('login')" class="font-medium text-primary hover:text-primary/80">
            sign in to your existing account
          </Link>
        </CardDescription>
      </CardHeader>

      <CardContent>
        <form @submit.prevent="submit" class="space-y-4">
          <div class="space-y-2">
            <Label for="company_name">Company Name</Label>
            <Input
              id="company_name"
              v-model="form.company_name"
              type="text"
              required
              autofocus
              placeholder="Your Company"
            />
            <p v-if="form.errors.company_name" class="text-sm text-destructive">
              {{ form.errors.company_name }}
            </p>
          </div>

          <div class="space-y-2">
            <Label for="name">Your Name</Label>
            <Input
              id="name"
              v-model="form.name"
              type="text"
              required
              placeholder="John Doe"
            />
            <p v-if="form.errors.name" class="text-sm text-destructive">
              {{ form.errors.name }}
            </p>
          </div>

          <div class="space-y-2">
            <Label for="email">Email address</Label>
            <Input
              id="email"
              v-model="form.email"
              type="email"
              required
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
              placeholder="Password"
            />
            <p v-if="form.errors.password" class="text-sm text-destructive">
              {{ form.errors.password }}
            </p>
          </div>

          <div class="space-y-2">
            <Label for="password_confirmation">Confirm Password</Label>
            <Input
              id="password_confirmation"
              v-model="form.password_confirmation"
              type="password"
              required
              placeholder="Confirm password"
            />
          </div>

          <Button type="submit" class="w-full" :disabled="form.processing">
            {{ form.processing ? 'Creating account...' : 'Create account' }}
          </Button>
        </form>
      </CardContent>
    </Card>
  </div>
</template>
