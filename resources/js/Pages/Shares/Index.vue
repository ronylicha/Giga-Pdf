<template>
    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                My Shares
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Filters -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                    <div class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                                <input
                                    v-model="filters.search"
                                    type="text"
                                    placeholder="Search documents..."
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                                    @input="search"
                                />
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                                <select
                                    v-model="filters.type"
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                                    @change="search"
                                >
                                    <option value="">All Types</option>
                                    <option value="internal">Internal</option>
                                    <option value="public">Public</option>
                                    <option value="protected">Password Protected</option>
                                </select>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                                <select
                                    v-model="filters.active"
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                                    @change="search"
                                >
                                    <option value="">All</option>
                                    <option value="true">Active</option>
                                    <option value="false">Expired/Revoked</option>
                                </select>
                            </div>
                            
                            <div class="flex items-end">
                                <button
                                    @click="resetFilters"
                                    class="w-full px-4 py-2 bg-gray-100 text-gray-700 rounded-md hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-gray-500"
                                >
                                    Reset
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Shares Table -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div v-if="shares.data.length === 0" class="text-center py-12">
                            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m9.032 4.026A9.001 9.001 0 1112 3c4.243 0 7.771 2.936 8.716 6.884M19.316 13.342A3 3 0 0021 12c0-.482-.114-.938-.316-1.342"></path>
                            </svg>
                            <h3 class="mt-2 text-sm font-medium text-gray-900">No shares found</h3>
                            <p class="mt-1 text-sm text-gray-500">
                                Start by sharing a document from your documents page.
                            </p>
                        </div>
                        
                        <div v-else class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead>
                                    <tr>
                                        <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Document
                                        </th>
                                        <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Type
                                        </th>
                                        <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Shared With
                                        </th>
                                        <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Stats
                                        </th>
                                        <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Status
                                        </th>
                                        <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Expires
                                        </th>
                                        <th class="px-6 py-3 bg-gray-50 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr v-for="share in shares.data" :key="share.id">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900">
                                                {{ share.document.original_name }}
                                            </div>
                                            <div class="text-sm text-gray-500">
                                                {{ formatFileSize(share.document.size) }}
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span
                                                class="inline-flex px-2 py-1 text-xs font-semibold rounded-full"
                                                :class="getTypeClass(share.type)"
                                            >
                                                {{ formatType(share.type) }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <div v-if="share.recipient">
                                                {{ share.recipient.name }}
                                                <div class="text-xs text-gray-500">{{ share.recipient.email }}</div>
                                            </div>
                                            <div v-else class="text-gray-500">
                                                Anyone with the link
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <div class="flex items-center space-x-3">
                                                <div class="flex items-center">
                                                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                                    </svg>
                                                    {{ share.views_count }}
                                                </div>
                                                <div class="flex items-center">
                                                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10"></path>
                                                    </svg>
                                                    {{ share.downloads_count }}
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span
                                                class="inline-flex px-2 py-1 text-xs font-semibold rounded-full"
                                                :class="getStatusClass(share)"
                                            >
                                                {{ getStatus(share) }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            {{ share.expires_at ? formatDate(share.expires_at) : 'Never' }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <div class="flex justify-end space-x-2">
                                                <button
                                                    @click="copyLink(share)"
                                                    class="text-blue-600 hover:text-blue-900"
                                                    title="Copy link"
                                                >
                                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                                                    </svg>
                                                </button>
                                                
                                                <button
                                                    @click="viewStats(share)"
                                                    class="text-gray-600 hover:text-gray-900"
                                                    title="View statistics"
                                                >
                                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                                                    </svg>
                                                </button>
                                                
                                                <button
                                                    v-if="share.is_active"
                                                    @click="revokeShare(share)"
                                                    class="text-red-600 hover:text-red-900"
                                                    title="Revoke share"
                                                >
                                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"></path>
                                                    </svg>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Pagination -->
                        <div v-if="shares.links.length > 3" class="mt-6">
                            <Pagination :links="shares.links" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref, reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';

const props = defineProps({
    shares: Object,
    filters: Object,
});

const filters = reactive({
    search: props.filters?.search || '',
    type: props.filters?.type || '',
    active: props.filters?.active || '',
});

const search = () => {
    router.get(route('shares.index'), filters, {
        preserveState: true,
        preserveScroll: true,
    });
};

const resetFilters = () => {
    filters.search = '';
    filters.type = '';
    filters.active = '';
    search();
};

const formatType = (type) => {
    const types = {
        internal: 'Internal',
        public: 'Public Link',
        protected: 'Password',
    };
    return types[type] || type;
};

const getTypeClass = (type) => {
    const classes = {
        internal: 'bg-blue-100 text-blue-800',
        public: 'bg-green-100 text-green-800',
        protected: 'bg-yellow-100 text-yellow-800',
    };
    return classes[type] || 'bg-gray-100 text-gray-800';
};

const getStatus = (share) => {
    if (!share.is_active) return 'Revoked';
    if (share.expires_at && new Date(share.expires_at) < new Date()) return 'Expired';
    return 'Active';
};

const getStatusClass = (share) => {
    const status = getStatus(share);
    if (status === 'Active') return 'bg-green-100 text-green-800';
    return 'bg-red-100 text-red-800';
};

const formatFileSize = (bytes) => {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
};

const formatDate = (dateString) => {
    const date = new Date(dateString);
    return date.toLocaleDateString();
};

const copyLink = (share) => {
    const url = share.type === 'internal' 
        ? window.location.origin + '/documents/' + share.document.id
        : window.location.origin + '/share/' + share.token;
    
    navigator.clipboard.writeText(url).then(() => {
        alert('Link copied to clipboard!');
    });
};

const viewStats = (share) => {
    // Could open a modal or navigate to a stats page
    router.get(route('shares.stats', share.id));
};

const revokeShare = (share) => {
    if (confirm('Are you sure you want to revoke this share? This action cannot be undone.')) {
        router.delete(route('shares.destroy', share.id), {
            preserveScroll: true,
        });
    }
};
</script>