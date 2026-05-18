<script setup>
import { ref, computed } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import { useRoute } from '@/composables/useRoute'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card'
import { Button } from '@/Components/ui/button'
import { Input } from '@/Components/ui/input'
import { Label } from '@/Components/ui/label'

const props = defineProps({
  trialKnowledgeItemsLimit: { type: Number, default: 10 },
})

const route = useRoute()
const currentStep = ref(1)

const form = useForm({
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
  company_name: '',
  website_url: '',
})

const STEP_FIELDS = {
  1: ['name', 'email', 'password', 'password_confirmation'],
  2: ['company_name'],
  3: ['website_url'],
}

const step1Valid = computed(() =>
  form.name && form.email && form.password && form.password === form.password_confirmation
)
const step2Valid = computed(() => !!form.company_name)

const next = () => {
  if (currentStep.value === 1 && !step1Valid.value) return
  if (currentStep.value === 2 && !step2Valid.value) return
  currentStep.value++
}

const back = () => {
  if (currentStep.value > 1) currentStep.value--
}

const submit = () => {
  form.post(route('register.store'), {
    onError: errors => {
      const fieldErrorStep = Object.keys(errors)
        .map(field => Number(Object.entries(STEP_FIELDS).find(([, fs]) => fs.includes(field))?.[0]))
        .filter(Boolean)
        .sort()[0]
      if (fieldErrorStep) currentStep.value = fieldErrorStep
    },
    onFinish: () => form.reset('password', 'password_confirmation'),
  })
}

const skipWebsite = () => {
  form.website_url = ''
  submit()
}
</script>

<template>
  <Head title="Register" />

  <div class="min-h-screen flex items-center justify-center bg-background py-12 px-4 sm:px-6 lg:px-8">
    <Card class="w-full max-w-md">
      <CardHeader class="text-center">
        <CardTitle class="text-2xl">Create your account</CardTitle>
        <CardDescription>
          Step {{ currentStep }} of 3
          <span class="block text-xs mt-1">
            Already have an account?
            <Link :href="route('login')" class="font-medium text-primary hover:text-primary/80">Sign in</Link>
          </span>
        </CardDescription>
      </CardHeader>

      <CardContent>
        <form @submit.prevent="submit" class="space-y-4">
          <!-- Step 1: Account -->
          <template v-if="currentStep === 1">
            <div class="space-y-2">
              <Label for="name">Your Name</Label>
              <Input id="name" v-model="form.name" type="text" required autofocus placeholder="John Doe" />
              <p v-if="form.errors.name" class="text-sm text-destructive">{{ form.errors.name }}</p>
            </div>
            <div class="space-y-2">
              <Label for="email">Email address</Label>
              <Input id="email" v-model="form.email" type="email" required placeholder="you@example.com" />
              <p v-if="form.errors.email" class="text-sm text-destructive">{{ form.errors.email }}</p>
            </div>
            <div class="space-y-2">
              <Label for="password">Password</Label>
              <Input id="password" v-model="form.password" type="password" required placeholder="Password" />
              <p v-if="form.errors.password" class="text-sm text-destructive">{{ form.errors.password }}</p>
            </div>
            <div class="space-y-2">
              <Label for="password_confirmation">Confirm Password</Label>
              <Input id="password_confirmation" v-model="form.password_confirmation" type="password" required placeholder="Confirm password" />
            </div>
            <Button type="button" class="w-full" :disabled="!step1Valid" @click="next">Next</Button>
          </template>

          <!-- Step 2: Company -->
          <template v-if="currentStep === 2">
            <div class="space-y-2">
              <Label for="company_name">Company Name</Label>
              <Input id="company_name" v-model="form.company_name" type="text" required autofocus placeholder="Your Company" />
              <p v-if="form.errors.company_name" class="text-sm text-destructive">{{ form.errors.company_name }}</p>
            </div>
            <div class="flex gap-2">
              <Button type="button" variant="outline" @click="back" class="flex-1">Back</Button>
              <Button type="button" class="flex-1" :disabled="!step2Valid" @click="next">Next</Button>
            </div>
          </template>

          <!-- Step 3: Website (optional) -->
          <template v-if="currentStep === 3">
            <div class="space-y-2">
              <Label for="website_url">Website URL (optional)</Label>
              <Input id="website_url" v-model="form.website_url" type="url" placeholder="https://yourcompany.com" />
              <p class="text-xs text-muted-foreground">
                We'll automatically index your site so the bot can answer questions about your products and content.
                Free trial indexes up to {{ trialKnowledgeItemsLimit }} pages.
              </p>
              <p v-if="form.errors.website_url" class="text-sm text-destructive">{{ form.errors.website_url }}</p>
            </div>
            <div class="flex flex-col gap-2">
              <div class="flex gap-2">
                <Button type="button" variant="outline" @click="back" class="flex-1">Back</Button>
                <Button type="submit" class="flex-1" :disabled="form.processing">
                  {{ form.processing ? 'Creating account...' : 'Create account' }}
                </Button>
              </div>
              <Button type="button" variant="ghost" @click="skipWebsite" :disabled="form.processing">
                Skip — I'll add this later
              </Button>
            </div>
          </template>
        </form>
      </CardContent>
    </Card>
  </div>
</template>
