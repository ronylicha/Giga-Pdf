<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import Pagination from '@/Components/Pagination.vue';

defineProps({
    conversions: Object,
    filters: Object,
});
</script>

<template>
    <Head title="Conversions" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                Historique des conversions
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <!-- Filters -->
                        <div class="mb-6 flex justify-between items-center">
                            <div class="flex space-x-4">
                                <input 
                                    type="text" 
                                    placeholder="Rechercher..."
                                    class="rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600"
                                />
                                <select class="rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                    <option value="">Tous les statuts</option>
                                    <option value="pending">En attente</option>
                                    <option value="processing">En cours</option>
                                    <option value="completed">Terminé</option>
                                    <option value="failed">Échoué</option>
                                </select>
                            </div>
                        </div>

                        <!-- Conversions Table -->
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-800">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                            Document
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                            Conversion
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                            Statut
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                            Progression
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                            Date
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                                    <tr v-for="conversion in conversions.data" :key="conversion.id">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                                {{ conversion.document?.original_name || 'Document supprimé' }}
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900 dark:text-gray-100">
                                                {{ conversion.from_format.toUpperCase() }} → {{ conversion.to_format.toUpperCase() }}
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span :class="{
                                                'bg-yellow-100 text-yellow-800': conversion.status === 'pending',
                                                'bg-blue-100 text-blue-800': conversion.status === 'processing',
                                                'bg-green-100 text-green-800': conversion.status === 'completed',
                                                'bg-red-100 text-red-800': conversion.status === 'failed'
                                            }" class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full">
                                                {{ conversion.status === 'pending' ? 'En attente' : 
                                                   conversion.status === 'processing' ? 'En cours' :
                                                   conversion.status === 'completed' ? 'Terminé' : 'Échoué' }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="w-full bg-gray-200 rounded-full h-2.5 dark:bg-gray-700">
                                                <div :class="{
                                                    'bg-blue-600': conversion.status === 'processing',
                                                    'bg-green-600': conversion.status === 'completed',
                                                    'bg-red-600': conversion.status === 'failed'
                                                }" 
                                                class="h-2.5 rounded-full" 
                                                :style="{width: (conversion.progress || 0) + '%'}">
                                                </div>
                                            </div>
                                            <div class="text-xs text-gray-500 mt-1">{{ conversion.progress || 0 }}%</div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                            {{ new Date(conversion.created_at).toLocaleDateString('fr-FR') }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <div class="flex space-x-2">
                                                <a v-if="conversion.status === 'completed'" 
                                                   :href="route('conversions.show', conversion.id)"
                                                   class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">
                                                    Télécharger
                                                </a>
                                                <button v-if="conversion.status === 'failed'"
                                                        @click="retryConversion(conversion.id)"
                                                        class="text-yellow-600 hover:text-yellow-900 dark:text-yellow-400 dark:hover:text-yellow-300">
                                                    Réessayer
                                                </button>
                                                <button @click="deleteConversion(conversion.id)"
                                                        class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300">
                                                    Supprimer
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <div class="mt-6">
                            <Pagination :data="conversions" />
                        </div>

                        <!-- Empty State -->
                        <div v-if="conversions.data.length === 0" class="text-center py-8">
                            <div class="text-gray-400 dark:text-gray-600 text-lg">
                                Aucune conversion trouvée
                            </div>
                            <p class="text-gray-500 dark:text-gray-400 mt-2">
                                Commencez par convertir un document depuis votre liste de documents.
                            </p>
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
        retryConversion(conversionId) {
            if (confirm('Voulez-vous vraiment relancer cette conversion ?')) {
                router.post(route('conversions.retry', conversionId));
            }
        },
        
        deleteConversion(conversionId) {
            if (confirm('Voulez-vous vraiment supprimer cette conversion ?')) {
                router.delete(route('conversions.destroy', conversionId));
            }
        }
    }
}
</script>