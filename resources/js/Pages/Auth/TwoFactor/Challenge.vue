<script setup>
import { Head } from '@inertiajs/vue3'
import { ref } from 'vue'

const code = ref('')
const error = ref('')
const loading = ref(false)

async function submit() {
  loading.value = true
  error.value = ''
  try {
    const res = await fetch('/two-factor/verify', { 
      method: 'POST', 
      headers: { 
        'Content-Type': 'application/json', 
        'Accept': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content 
      }, 
      body: JSON.stringify({ code: code.value }) 
    })
    
    if (res.ok) {
      const data = await res.json()
      if (data.redirect) {
        window.location.href = data.redirect
      }
      return
    }
    
    const data = await res.json()
    throw new Error(data.message || 'Code invalide')
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <Head title="Vérification 2FA" />
  <div class="max-w-md mx-auto p-6">
    <h1 class="text-xl font-semibold mb-4">Saisissez votre code 2FA</h1>
    <div v-if="error" class="mb-4 text-red-600">{{ error }}</div>
    <input v-model="code" maxlength="12" class="border rounded w-full px-3 py-2" placeholder="Code ou code de récupération" />
    <button class="mt-3 px-4 py-2 bg-indigo-600 text-white rounded" :disabled="loading || !code" @click="submit">Vérifier</button>
  </div>
</template>
