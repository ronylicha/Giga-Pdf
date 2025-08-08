<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Gestion des Tenants
                </h2>
                <Link
                    :href="route('tenants.create')"
                    class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
                >
                    <PlusIcon class="w-4 h-4 mr-2" />
                    Nouveau Tenant
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Filters -->
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg mb-6">
                    <div class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Rechercher</label>
                                <input
                                    v-model="filters.search"
                                    type="text"
                                    placeholder="Nom, domaine, slug..."
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    @input="debounceSearch"
                                />
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Plan</label>
                                <select
                                    v-model="filters.plan"
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    @change="applyFilters"
                                >
                                    <option value="">Tous les plans</option>
                                    <option value="basic">Basic</option>
                                    <option value="professional">Professional</option>
                                    <option value="enterprise">Enterprise</option>
                                </select>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Statut</label>
                                <select
                                    v-model="filters.status"
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    @change="applyFilters"
                                >
                                    <option value="">Tous</option>
                                    <option value="active">Actif</option>
                                    <option value="suspended">Suspendu</option>
                                </select>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Trier par</label>
                                <select
                                    v-model="filters.sort_by"
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    @change="applyFilters"
                                >
                                    <option value="created_at">Date de création</option>
                                    <option value="name">Nom</option>
                                    <option value="users_count">Nombre d'utilisateurs</option>
                                    <option value="documents_count">Nombre de documents</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tenants Table -->
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Tenant
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Plan
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Utilisateurs
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Documents
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Statut
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Créé le
                                    </th>
                                    <th class="relative px-6 py-3">
                                        <span class="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <tr v-for="tenant in tenants.data" :key="tenant.id">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div>
                                            <div class="text-sm font-medium text-gray-900">
                                                {{ tenant.name }}
                                            </div>
                                            <div class="text-sm text-gray-500">
                                                {{ tenant.domain || tenant.slug }}
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span :class="getPlanBadgeClass(tenant.subscription_plan)" class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full">
                                            {{ tenant.subscription_plan }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        {{ tenant.users_count }} / {{ tenant.max_users }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        {{ tenant.documents_count }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span v-if="tenant.is_active" class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                            Actif
                                        </span>
                                        <span v-else class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">
                                            Suspendu
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ formatDate(tenant.created_at) }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <div class="flex items-center justify-end space-x-2">
                                            <Link
                                                :href="route('tenants.show', tenant.id)"
                                                class="text-indigo-600 hover:text-indigo-900"
                                            >
                                                <EyeIcon class="w-5 h-5" />
                                            </Link>
                                            <Link
                                                :href="route('tenants.edit', tenant.id)"
                                                class="text-yellow-600 hover:text-yellow-900"
                                            >
                                                <PencilIcon class="w-5 h-5" />
                                            </Link>
                                            <button
                                                @click="toggleStatus(tenant)"
                                                :class="tenant.is_active ? 'text-orange-600 hover:text-orange-900' : 'text-green-600 hover:text-green-900'"
                                            >
                                                <component :is="tenant.is_active ? PauseIcon : PlayIcon" class="w-5 h-5" />
                                            </button>
                                            <button
                                                @click="confirmDelete(tenant)"
                                                class="text-red-600 hover:text-red-900"
                                            >
                                                <TrashIcon class="w-5 h-5" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div class="bg-white px-4 py-3 border-t border-gray-200 sm:px-6">
                        <div class="flex items-center justify-between">
                            <div class="flex-1 flex justify-between sm:hidden">
                                <Link
                                    v-if="tenants.prev_page_url"
                                    :href="tenants.prev_page_url"
                                    class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50"
                                >
                                    Précédent
                                </Link>
                                <Link
                                    v-if="tenants.next_page_url"
                                    :href="tenants.next_page_url"
                                    class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50"
                                >
                                    Suivant
                                </Link>
                            </div>
                            <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                                <div>
                                    <p class="text-sm text-gray-700">
                                        Affichage de
                                        <span class="font-medium">{{ tenants.from }}</span>
                                        à
                                        <span class="font-medium">{{ tenants.to }}</span>
                                        sur
                                        <span class="font-medium">{{ tenants.total }}</span>
                                        résultats
                                    </p>
                                </div>
                                <div>
                                    <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px">
                                        <Link
                                            v-for="link in tenants.links"
                                            :key="link.label"
                                            :href="link.url"
                                            :class="[
                                                link.active ? 'bg-indigo-50 border-indigo-500 text-indigo-600' : 'bg-white border-gray-300 text-gray-500 hover:bg-gray-50',
                                                'relative inline-flex items-center px-4 py-2 border text-sm font-medium'
                                            ]"
                                            v-html="link.label"
                                        />
                                    </nav>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Delete Confirmation Modal -->
        <ConfirmationModal
            :show="deleteModalOpen"
            @close="deleteModalOpen = false"
            @confirm="deleteTenant"
        >
            <template #title>
                Supprimer le tenant
            </template>
            <template #content>
                Êtes-vous sûr de vouloir supprimer le tenant "{{ tenantToDelete?.name }}" ?
                Cette action est irréversible.
            </template>
        </ConfirmationModal>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref, reactive } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ConfirmationModal from '@/Components/ConfirmationModal.vue';
import { 
    PlusIcon, 
    EyeIcon, 
    PencilIcon, 
    TrashIcon, 
    PauseIcon, 
    PlayIcon 
} from '@heroicons/vue/24/outline';

const props = defineProps({
    tenants: Object,
    filters: Object,
    plans: Array,
});

const filters = reactive({
    search: props.filters?.search || '',
    plan: props.filters?.plan || '',
    status: props.filters?.status || '',
    sort_by: props.filters?.sort_by || 'created_at',
    sort_order: props.filters?.sort_order || 'desc',
});

const deleteModalOpen = ref(false);
const tenantToDelete = ref(null);

let searchTimeout = null;

const debounceSearch = () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        applyFilters();
    }, 300);
};

const applyFilters = () => {
    router.get(route('tenants.index'), filters, {
        preserveState: true,
        preserveScroll: true,
    });
};

const getPlanBadgeClass = (plan) => {
    switch (plan) {
        case 'enterprise':
            return 'bg-purple-100 text-purple-800';
        case 'professional':
            return 'bg-blue-100 text-blue-800';
        case 'basic':
            return 'bg-gray-100 text-gray-800';
        default:
            return 'bg-gray-100 text-gray-800';
    }
};

const formatDate = (date) => {
    return new Date(date).toLocaleDateString('fr-FR', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
};

const toggleStatus = (tenant) => {
    router.post(route('tenants.toggle-status', tenant.id), {}, {
        preserveScroll: true,
    });
};

const confirmDelete = (tenant) => {
    tenantToDelete.value = tenant;
    deleteModalOpen.value = true;
};

const deleteTenant = () => {
    router.delete(route('tenants.destroy', tenantToDelete.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            deleteModalOpen.value = false;
            tenantToDelete.value = null;
        },
    });
};
</script>