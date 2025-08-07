<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    conversion: Object,
});
</script>

<template>
    <Head title="Détails de la conversion" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                    Détails de la conversion
                </h2>
                <Link :href="route('conversions.index')" 
                      class="inline-flex items-center px-4 py-2 bg-gray-800 dark:bg-gray-200 border border-transparent rounded-md font-semibold text-xs text-white dark:text-gray-800 uppercase tracking-widest hover:bg-gray-700 dark:hover:bg-white focus:bg-gray-700 dark:focus:bg-white active:bg-gray-900 dark:active:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150">
                    Retour à la liste
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
                <!-- Conversion Status -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-6">
                            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                                Statut de la conversion
                            </h3>
                            <span :class="{
                                'bg-yellow-100 text-yellow-800': conversion.status === 'pending',
                                'bg-blue-100 text-blue-800': conversion.status === 'processing',
                                'bg-green-100 text-green-800': conversion.status === 'completed',
                                'bg-red-100 text-red-800': conversion.status === 'failed'
                            }" class="px-3 py-1 text-sm font-semibold rounded-full">
                                {{ conversion.status === 'pending' ? 'En attente' : 
                                   conversion.status === 'processing' ? 'En cours' :
                                   conversion.status === 'completed' ? 'Terminé' : 'Échoué' }}
                            </span>
                        </div>

                        <!-- Progress Bar -->
                        <div class="mb-4">
                            <div class="flex justify-between text-sm text-gray-600 dark:text-gray-400 mb-2">
                                <span>Progression</span>
                                <span>{{ conversion.progress || 0 }}%</span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-3 dark:bg-gray-700">
                                <div :class="{
                                    'bg-blue-600': conversion.status === 'processing',
                                    'bg-green-600': conversion.status === 'completed',
                                    'bg-red-600': conversion.status === 'failed',
                                    'bg-gray-400': conversion.status === 'pending'
                                }" 
                                class="h-3 rounded-full transition-all duration-300" 
                                :style="{width: (conversion.progress || 0) + '%'}">
                                </div>
                            </div>
                        </div>

                        <!-- Error Message -->
                        <div v-if="conversion.status === 'failed' && conversion.error_message" 
                             class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-md p-4">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                                    </svg>
                                </div>
                                <div class="ml-3">
                                    <h3 class="text-sm font-medium text-red-800 dark:text-red-200">
                                        Erreur de conversion
                                    </h3>
                                    <div class="mt-2 text-sm text-red-700 dark:text-red-300">
                                        {{ conversion.error_message }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Conversion Details -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-6">
                            Détails de la conversion
                        </h3>

                        <dl class="grid grid-cols-1 gap-x-4 gap-y-6 sm:grid-cols-2">
                            <div>
                                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">
                                    Document source
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                                    {{ conversion.document?.original_name || 'Document supprimé' }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">
                                    Type de conversion
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                                    {{ conversion.from_format.toUpperCase() }} → {{ conversion.to_format.toUpperCase() }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">
                                    Date de début
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                                    {{ conversion.started_at ? new Date(conversion.started_at).toLocaleString('fr-FR') : 'Non commencé' }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">
                                    Date de fin
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                                    {{ conversion.completed_at ? new Date(conversion.completed_at).toLocaleString('fr-FR') : 'En cours' }}
                                </dd>
                            </div>

                            <div v-if="conversion.completed_at && conversion.started_at">
                                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">
                                    Durée
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                                    {{ Math.round((new Date(conversion.completed_at) - new Date(conversion.started_at)) / 1000) }}s
                                </dd>
                            </div>

                            <div>
                                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">
                                    Utilisateur
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                                    {{ conversion.user?.name || 'Utilisateur supprimé' }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <!-- Actions -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                            Actions
                        </h3>

                        <div class="flex space-x-3">
                            <a v-if="conversion.status === 'completed' && conversion.result_document" 
                               :href="route('documents.download', conversion.result_document.id)"
                               class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                                Télécharger le résultat
                            </a>

                            <button v-if="conversion.status === 'failed'"
                                    @click="retryConversion"
                                    class="inline-flex items-center px-4 py-2 bg-yellow-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-yellow-700 focus:bg-yellow-700 active:bg-yellow-900 focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:ring-offset-2 transition ease-in-out duration-150">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                </svg>
                                Réessayer
                            </button>

                            <button @click="deleteConversion"
                                    class="inline-flex items-center px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-700 focus:bg-red-700 active:bg-red-900 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition ease-in-out duration-150">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                </svg>
                                Supprimer
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script>
import { router } from '@inertiajs/vue3';

export default {
    methods: {
        retryConversion() {
            if (confirm('Voulez-vous vraiment relancer cette conversion ?')) {
                router.post(route('conversions.retry', this.conversion.id));
            }
        },
        
        deleteConversion() {
            if (confirm('Voulez-vous vraiment supprimer cette conversion ? Cette action est irréversible.')) {
                router.delete(route('conversions.destroy', this.conversion.id), {
                    onSuccess: () => {
                        router.visit(route('conversions.index'));
                    }
                });
            }
        }
    }
}
</script>