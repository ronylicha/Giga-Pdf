<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, usePage } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

const page = usePage()
const user = computed(() => page.props.auth?.user || {})
const has2FA = computed(() => !!user.value.two_factor_confirmed_at || !!user.value.two_factor_secret)
const loading = ref(false)
const message = ref('')
const recovery = ref([])

async function disable2FA() {
  loading.value = true
  message.value = ''
  try {
    const pwd = prompt('Confirmez avec votre mot de passe:')
    if (!pwd) { loading.value = false; return }
    const res = await fetch('/two-factor/disable', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, body: JSON.stringify({ password: pwd }) })
    if (res.redirected) { window.location = res.url; return }
    const data = await res.json().catch(() => ({}))
    if (!res.ok) throw new Error(data.message || 'Erreur')
    message.value = '2FA désactivée'
    window.location.reload()
  } catch (e) {
    message.value = e.message
  } finally {
    loading.value = false
  }
}

async function regenCodes() {
  loading.value = true
  message.value = ''
  try {
    const pwd = prompt('Confirmez avec votre mot de passe:')
    if (!pwd) { loading.value = false; return }
    const res = await fetch('/two-factor/recovery-codes', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, body: JSON.stringify({ password: pwd }) })
    const data = await res.json()
    if (!res.ok) throw new Error(data.message || 'Erreur')
    recovery.value = data.recovery_codes
    message.value = 'Nouveaux codes générés'
  } catch (e) {
    message.value = e.message
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <Head title="Sécurité du profil" />
  <AuthenticatedLayout>
    <template #header>
      <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Sécurité</h2>
    </template>

    <div class="py-12">
      <div class="mx-auto max-w-3xl space-y-6 sm:px-6 lg:px-8">
        <div class="bg-white p-6 shadow sm:rounded-lg dark:bg-gray-800">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-lg font-medium text-gray-900 dark:text-gray-100">Authentification à deux facteurs</div>
              <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                <span v-if="has2FA">Activée pour ce compte.</span>
                <span v-else>Non activée. Nous recommandons de l’activer.</span>
              </div>
            </div>
            <div class="space-x-2">
              <a v-if="!has2FA" href="/two-factor" class="px-4 py-2 bg-indigo-600 text-white rounded">Activer</a>
              <button v-else class="px-4 py-2 bg-red-600 text-white rounded" :disabled="loading" @click="disable2FA">Désactiver</button>
              <button v-if="has2FA" class="px-4 py-2 bg-gray-200 rounded" :disabled="loading" @click="regenCodes">Régénérer codes</button>
            </div>
          </div>
          <div v-if="message" class="mt-4 text-sm" :class="{'text-green-600': message.toLowerCase().includes('succès') || message.toLowerCase().includes('activ'), 'text-red-600': message.toLowerCase().includes('erreur')}">{{ message }}</div>
          <div v-if="recovery.length" class="mt-4">
            <div class="font-medium mb-2">Codes de récupération:</div>
            <ul class="list-disc pl-6">
              <li v-for="c in recovery" :key="c" class="font-mono">{{ c }}</li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>
