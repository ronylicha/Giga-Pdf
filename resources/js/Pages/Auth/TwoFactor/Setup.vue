<script setup>
import { Head, router } from '@inertiajs/vue3'
import { ref } from 'vue'

const loading = ref(false)
const secret = ref('')
const qr = ref('')
const recoveryCodes = ref([])
const code = ref('')
const error = ref('')

async function start() {
  loading.value = true
  error.value = ''
  try {
    const res = await fetch('/two-factor/enable', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content } })
    const data = await res.json()
    if (!res.ok) throw new Error(data.error || 'Erreur')
    secret.value = data.secret
    qr.value = data.qr_code
    recoveryCodes.value = data.recovery_codes
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

async function confirm() {
  loading.value = true
  error.value = ''
  try {
    const res = await fetch('/two-factor/confirm', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, body: JSON.stringify({ code: code.value }) })
    if (res.redirected) {
      window.location = res.url
      return
    }
    const data = await res.json()
    if (!res.ok) throw new Error(data.message || 'Code invalide')
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <Head title="Activer la 2FA" />
  <div class="max-w-2xl mx-auto p-6">
    <h1 class="text-xl font-semibold mb-4">Activer l’authentification à deux facteurs</h1>

    <div v-if="error" class="mb-4 text-red-600">{{ error }}</div>

    <button class="px-4 py-2 bg-indigo-600 text-white rounded" :disabled="loading" @click="start">Générer le QR code</button>

    <div v-if="qr" class="mt-6 space-y-4">
      <div class="bg-white p-4 rounded shadow">
        <div class="mb-2">Scannez ce QR code avec Google Authenticator:</div>
        <img :src="qr" alt="QR code" class="w-48 h-48" />
      </div>

      <div class="bg-white p-4 rounded shadow">
        <div class="mb-2">Entrez le code à 6 chiffres:</div>
        <input v-model="code" class="border rounded px-3 py-2 w-full" maxlength="6" placeholder="123456" />
        <button class="mt-3 px-4 py-2 bg-green-600 text-white rounded" :disabled="loading || code.length!==6" @click="confirm">Confirmer</button>
      </div>

      <div class="bg-white p-4 rounded shadow">
        <div class="font-medium mb-2">Codes de récupération (à conserver):</div>
        <ul class="list-disc pl-6">
          <li v-for="c in recoveryCodes" :key="c" class="font-mono">{{ c }}</li>
        </ul>
      </div>
    </div>
  </div>
</template>
