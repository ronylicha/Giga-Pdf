<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Mes Documents
                </h2>
                <div class="flex space-x-4">
                    <Link
                        :href="route('documents.create')"
                        class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
                    >
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                        </svg>
                        Nouveau Document
                    </Link>
                    <button
                        @click="showConvertModal = true"
                        class="inline-flex items-center px-4 py-2 bg-green-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-green-700"
                    >
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                        </svg>
                        Convertir
                    </button>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Filters and Search -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                    <div class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <!-- Search -->
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Rechercher</label>
                                <div class="relative">
                                    <input
                                        v-model="filters.search"
                                        type="text"
                                        placeholder="Nom du document..."
                                        class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500"
                                        @input="applyFilters"
                                    >
                                    <svg class="absolute left-3 top-2.5 w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                    </svg>
                                </div>
                            </div>

                            <!-- File Type Filter -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Type de fichier</label>
                                <select
                                    v-model="filters.type"
                                    @change="applyFilters"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500"
                                >
                                    <option value="">Tous les types</option>
                                    <option value="pdf">PDF</option>
                                    <option value="doc">Word</option>
                                    <option value="xls">Excel</option>
                                    <option value="ppt">PowerPoint</option>
                                    <option value="image">Images</option>
                                    <option value="other">Autres</option>
                                </select>
                            </div>

                            <!-- Sort -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Trier par</label>
                                <select
                                    v-model="filters.sort"
                                    @change="applyFilters"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500"
                                >
                                    <option value="created_desc">Plus récents</option>
                                    <option value="created_asc">Plus anciens</option>
                                    <option value="name_asc">Nom (A-Z)</option>
                                    <option value="name_desc">Nom (Z-A)</option>
                                    <option value="size_asc">Taille (croissant)</option>
                                    <option value="size_desc">Taille (décroissant)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- View Toggle -->
                <div class="flex justify-end mb-4 space-x-2">
                    <button
                        @click="viewMode = 'grid'"
                        :class="[
                            'p-2 rounded-lg transition',
                            viewMode === 'grid' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-100'
                        ]"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" />
                        </svg>
                    </button>
                    <button
                        @click="viewMode = 'list'"
                        :class="[
                            'p-2 rounded-lg transition',
                            viewMode === 'list' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-100'
                        ]"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>
                </div>

                <!-- Documents Grid View -->
                <div v-if="viewMode === 'grid'" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                    <div
                        v-for="document in filteredDocuments"
                        :key="document.id"
                        class="bg-white rounded-lg shadow-sm hover:shadow-lg transition-shadow duration-200 overflow-hidden group"
                    >
                        <!-- Thumbnail -->
                        <div class="relative h-48 bg-gray-100">
                            <img
                                v-if="document.thumbnail_url"
                                :src="document.thumbnail_url"
                                :alt="document.original_name"
                                class="w-full h-full object-cover"
                            >
                            <div v-else class="flex items-center justify-center h-full">
                                <svg class="w-16 h-16 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                            </div>
                            
                            <!-- Quick Actions Overlay -->
                            <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-50 transition-opacity duration-200 flex items-center justify-center opacity-0 group-hover:opacity-100">
                                <div class="flex space-x-2">
                                    <button
                                        @click="previewDocument(document)"
                                        class="p-2 bg-white rounded-full hover:bg-gray-100 transition"
                                        title="Aperçu"
                                    >
                                        <svg class="w-5 h-5 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                        </svg>
                                    </button>
                                    <button
                                        @click="downloadDocument(document)"
                                        class="p-2 bg-white rounded-full hover:bg-gray-100 transition"
                                        title="Télécharger"
                                    >
                                        <svg class="w-5 h-5 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                        </svg>
                                    </button>
                                    <button
                                        @click="shareDocument(document)"
                                        class="p-2 bg-white rounded-full hover:bg-gray-100 transition"
                                        title="Partager"
                                    >
                                        <svg class="w-5 h-5 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m9.032 4.026a3 3 0 10-5.414-2.642m5.414 2.642a3 3 0 01-5.414-2.642m0 0a3 3 0 00-5.414 2.642m5.414-2.642l-5.414-2.642" />
                                        </svg>
                                    </button>
                                    <button
                                        @click="deleteDocument(document)"
                                        class="p-2 bg-white rounded-full hover:bg-red-100 transition"
                                        title="Supprimer"
                                    >
                                        <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <!-- File Type Badge -->
                            <div class="absolute top-2 right-2">
                                <span :class="getFileTypeBadgeClass(document.extension)" class="px-2 py-1 text-xs font-semibold rounded">
                                    {{ document.extension.toUpperCase() }}
                                </span>
                            </div>
                        </div>

                        <!-- Document Info -->
                        <div class="p-4">
                            <h3 class="font-semibold text-gray-900 truncate mb-2" :title="document.original_name">
                                {{ document.original_name }}
                            </h3>
                            <div class="flex justify-between text-sm text-gray-500">
                                <span>{{ formatFileSize(document.size) }}</span>
                                <span>{{ formatDate(document.created_at) }}</span>
                            </div>
                            
                            <!-- Actions -->
                            <div class="mt-4 flex justify-between">
                                <Link
                                    :href="route('documents.html-editor', document.id)"
                                    class="text-indigo-600 hover:text-indigo-900 font-medium text-sm"
                                >
                                    Éditer
                                </Link>
                                <div class="relative">
                                    <button
                                        @click="toggleDropdown(document.id)"
                                        class="text-gray-500 hover:text-gray-700"
                                    >
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z" />
                                        </svg>
                                    </button>
                                    <div
                                        v-if="activeDropdown === document.id"
                                        class="absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg z-10"
                                    >
                                        <button
                                            @click="shareDocument(document)"
                                            class="block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded-t-md"
                                        >
                                            <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m9.032 4.026a3 3 0 10-5.414-2.642m5.414 2.642a3 3 0 01-5.414-2.642m0 0a3 3 0 00-5.414 2.642m5.414-2.642l-5.414-2.642" />
                                            </svg>
                                            Partager
                                        </button>
                                        <button
                                            @click="deleteDocument(document)"
                                            class="block w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-gray-100 rounded-b-md"
                                        >
                                            <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                            </svg>
                                            Supprimer
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Documents List View -->
                <div v-else class="bg-white shadow-sm rounded-lg overflow-hidden">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <input type="checkbox" @change="toggleSelectAll" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Nom
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Type
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Taille
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Modifié
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <tr v-for="document in filteredDocuments" :key="document.id" class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <input
                                        type="checkbox"
                                        :value="document.id"
                                        v-model="selectedDocuments"
                                        class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                    >
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 h-10 w-10">
                                            <img
                                                v-if="document.thumbnail_url"
                                                :src="document.thumbnail_url"
                                                :alt="document.original_name"
                                                class="h-10 w-10 rounded object-cover"
                                            >
                                            <div v-else class="h-10 w-10 rounded bg-gray-200 flex items-center justify-center">
                                                <svg class="w-6 h-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                                </svg>
                                            </div>
                                        </div>
                                        <div class="ml-4">
                                            <div class="text-sm font-medium text-gray-900">
                                                {{ document.original_name }}
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span :class="getFileTypeBadgeClass(document.extension)" class="px-2 py-1 text-xs font-semibold rounded">
                                        {{ document.extension.toUpperCase() }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    {{ formatFileSize(document.size) }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    {{ formatDate(document.created_at) }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <div class="flex space-x-2">
                                        <button
                                            @click="previewDocument(document)"
                                            class="text-indigo-600 hover:text-indigo-900"
                                            title="Aperçu"
                                        >
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                            </svg>
                                        </button>
                                        <Link
                                            :href="route('documents.html-editor', document.id)"
                                            class="text-green-600 hover:text-green-900"
                                            title="Éditer"
                                        >
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                            </svg>
                                        </Link>
                                        <button
                                            @click="downloadDocument(document)"
                                            class="text-blue-600 hover:text-blue-900"
                                            title="Télécharger"
                                        >
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                            </svg>
                                        </button>
                                        <button
                                            @click="deleteDocument(document)"
                                            class="text-red-600 hover:text-red-900"
                                            title="Supprimer"
                                        >
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Bulk Actions -->
                <div v-if="selectedDocuments.length > 0" class="fixed bottom-6 right-6 bg-white rounded-lg shadow-lg p-4">
                    <div class="flex items-center space-x-4">
                        <span class="text-sm text-gray-600">
                            {{ selectedDocuments.length }} document(s) sélectionné(s)
                        </span>
                        <button
                            @click="bulkDownload"
                            class="px-3 py-1 bg-blue-600 text-white rounded hover:bg-blue-700"
                        >
                            Télécharger
                        </button>
                        <button
                            @click="bulkDelete"
                            class="px-3 py-1 bg-red-600 text-white rounded hover:bg-red-700"
                        >
                            Supprimer
                        </button>
                        <button
                            @click="selectedDocuments = []"
                            class="text-gray-500 hover:text-gray-700"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Empty State -->
                <div v-if="filteredDocuments.length === 0" class="text-center py-12">
                    <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    <h3 class="mt-2 text-sm font-medium text-gray-900">Aucun document</h3>
                    <p class="mt-1 text-sm text-gray-500">Commencez par télécharger un nouveau document.</p>
                    <div class="mt-6">
                        <Link
                            :href="route('documents.create')"
                            class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                        >
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                            </svg>
                            Nouveau Document
                        </Link>
                    </div>
                </div>

                <!-- Share Modal -->
                <Modal :show="showShareModal" @close="showShareModal = false">
                    <div class="p-6">
                        <h3 class="text-lg font-semibold mb-4">Partager le document</h3>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Type de partage</label>
                                <select v-model="shareType" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                                    <option value="link">Lien public</option>
                                    <option value="email">Par email</option>
                                </select>
                            </div>
                            
                            <div v-if="shareType === 'email'">
                                <label class="block text-sm font-medium text-gray-700">Email du destinataire</label>
                                <input
                                    v-model="shareEmail"
                                    type="email"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                                    placeholder="email@example.com"
                                >
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Date d'expiration (optionnel)</label>
                                <input
                                    v-model="shareExpiration"
                                    type="datetime-local"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                                >
                            </div>
                            
                            <div>
                                <label class="flex items-center">
                                    <input
                                        v-model="shareWithPassword"
                                        type="checkbox"
                                        class="rounded border-gray-300 text-indigo-600 shadow-sm"
                                    >
                                    <span class="ml-2 text-sm text-gray-700">Protéger avec un mot de passe</span>
                                </label>
                            </div>
                            
                            <div v-if="shareWithPassword">
                                <label class="block text-sm font-medium text-gray-700">Mot de passe</label>
                                <input
                                    v-model="sharePassword"
                                    type="password"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                                    placeholder="Entrez un mot de passe"
                                >
                            </div>
                        </div>
                        <div class="mt-6 flex justify-end space-x-2">
                            <button
                                @click="showShareModal = false"
                                class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300"
                            >
                                Annuler
                            </button>
                            <button
                                @click="createShare"
                                class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700"
                            >
                                Créer le lien
                            </button>
                        </div>
                    </div>
                </Modal>

                <!-- Pagination -->
                <div v-if="documents.links && documents.links.length > 3" class="mt-6">
                    <nav class="flex items-center justify-between">
                        <div class="flex-1 flex justify-between sm:hidden">
                            <Link
                                v-if="documents.prev_page_url"
                                :href="documents.prev_page_url"
                                class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50"
                            >
                                Précédent
                            </Link>
                            <Link
                                v-if="documents.next_page_url"
                                :href="documents.next_page_url"
                                class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50"
                            >
                                Suivant
                            </Link>
                        </div>
                        <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                            <div>
                                <p class="text-sm text-gray-700">
                                    Affichage de
                                    <span class="font-medium">{{ documents.from }}</span>
                                    à
                                    <span class="font-medium">{{ documents.to }}</span>
                                    sur
                                    <span class="font-medium">{{ documents.total }}</span>
                                    résultats
                                </p>
                            </div>
                            <div>
                                <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px">
                                    <template v-for="(link, index) in documents.links" :key="index">
                                        <Link
                                            v-if="link.url"
                                            :href="link.url"
                                            :class="[
                                                'relative inline-flex items-center px-4 py-2 border text-sm font-medium',
                                                link.active
                                                    ? 'z-10 bg-indigo-50 border-indigo-500 text-indigo-600'
                                                    : 'bg-white border-gray-300 text-gray-500 hover:bg-gray-50',
                                                index === 0 ? 'rounded-l-md' : '',
                                                index === documents.links.length - 1 ? 'rounded-r-md' : ''
                                            ]"
                                            v-html="link.label"
                                        />
                                        <span
                                            v-else
                                            :class="[
                                                'relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700',
                                                index === 0 ? 'rounded-l-md' : '',
                                                index === documents.links.length - 1 ? 'rounded-r-md' : ''
                                            ]"
                                            v-html="link.label"
                                        />
                                    </template>
                                </nav>
                            </div>
                        </div>
                    </nav>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import { format } from 'date-fns';
import { fr } from 'date-fns/locale';

const props = defineProps({
    documents: Object,
    filters: Object,
});

const viewMode = ref('grid');
const selectedDocuments = ref([]);
const showConvertModal = ref(false);
const activeDropdown = ref(null);

// Share modal variables
const showShareModal = ref(false);
const selectedShareDocument = ref(null);
const shareType = ref('link');
const shareEmail = ref('');
const shareExpiration = ref('');
const shareWithPassword = ref(false);
const sharePassword = ref('');

const filters = ref({
    search: props.filters?.search || '',
    type: props.filters?.type || '',
    sort: props.filters?.sort || 'created_desc',
});

const filteredDocuments = computed(() => {
    let docs = props.documents?.data || [];
    
    // Apply search filter
    if (filters.value.search) {
        docs = docs.filter(doc => 
            doc.original_name.toLowerCase().includes(filters.value.search.toLowerCase())
        );
    }
    
    // Apply type filter
    if (filters.value.type) {
        docs = docs.filter(doc => {
            switch(filters.value.type) {
                case 'pdf': return doc.extension === 'pdf';
                case 'doc': return ['doc', 'docx'].includes(doc.extension);
                case 'xls': return ['xls', 'xlsx'].includes(doc.extension);
                case 'ppt': return ['ppt', 'pptx'].includes(doc.extension);
                case 'image': return ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg'].includes(doc.extension);
                default: return true;
            }
        });
    }
    
    // Apply sorting
    docs.sort((a, b) => {
        switch(filters.value.sort) {
            case 'name_asc': return a.original_name.localeCompare(b.original_name);
            case 'name_desc': return b.original_name.localeCompare(a.original_name);
            case 'size_asc': return a.size - b.size;
            case 'size_desc': return b.size - a.size;
            case 'created_asc': return new Date(a.created_at) - new Date(b.created_at);
            case 'created_desc': return new Date(b.created_at) - new Date(a.created_at);
            default: return 0;
        }
    });
    
    return docs;
});

const applyFilters = () => {
    router.get(route('documents.index'), filters.value, {
        preserveState: true,
        preserveScroll: true,
    });
};

const formatFileSize = (bytes) => {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
};

const formatDate = (date) => {
    return format(new Date(date), 'dd MMM yyyy', { locale: fr });
};

const getFileTypeBadgeClass = (extension) => {
    const ext = extension.toLowerCase();
    if (ext === 'pdf') return 'bg-red-100 text-red-800';
    if (['doc', 'docx'].includes(ext)) return 'bg-blue-100 text-blue-800';
    if (['xls', 'xlsx'].includes(ext)) return 'bg-green-100 text-green-800';
    if (['ppt', 'pptx'].includes(ext)) return 'bg-orange-100 text-orange-800';
    if (['jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg'].includes(ext)) return 'bg-purple-100 text-purple-800';
    return 'bg-gray-100 text-gray-800';
};

const previewDocument = (document) => {
    window.open(route('documents.show', document.id), '_blank');
};

const downloadDocument = (document) => {
    window.location.href = route('documents.download', document.id);
};

const toggleDropdown = (documentId) => {
    if (activeDropdown.value === documentId) {
        activeDropdown.value = null;
    } else {
        activeDropdown.value = documentId;
    }
};

// Close dropdown when clicking outside
document.addEventListener('click', (event) => {
    if (!event.target.closest('.relative')) {
        activeDropdown.value = null;
    }
});

const shareDocument = (document) => {
    activeDropdown.value = null;
    selectedShareDocument.value = document;
    // Reset share form
    shareType.value = 'link';
    shareEmail.value = '';
    shareExpiration.value = '';
    shareWithPassword.value = false;
    sharePassword.value = '';
    // Open modal
    showShareModal.value = true;
};

const createShare = () => {
    if (!selectedShareDocument.value) return;
    
    router.post(route('documents.share', selectedShareDocument.value.id), {
        type: shareType.value,
        email: shareEmail.value,
        expires_at: shareExpiration.value,
        password: shareWithPassword.value ? sharePassword.value : null,
    }, {
        onSuccess: () => {
            showShareModal.value = false;
            alert('Lien de partage créé avec succès!');
        },
        onError: () => {
            alert('Erreur lors de la création du lien de partage');
        }
    });
};

const deleteDocument = (document) => {
    activeDropdown.value = null;
    if (confirm(`Êtes-vous sûr de vouloir supprimer "${document.original_name}" ?`)) {
        router.delete(route('documents.destroy', document.id), {
            onSuccess: () => {
                // Document will be removed from list automatically
            },
            onError: () => {
                alert('Erreur lors de la suppression du document');
            }
        });
    }
};

const toggleSelectAll = (event) => {
    if (event.target.checked) {
        selectedDocuments.value = filteredDocuments.value.map(doc => doc.id);
    } else {
        selectedDocuments.value = [];
    }
};

const bulkDownload = () => {
    if (selectedDocuments.value.length === 0) {
        alert('Veuillez sélectionner au moins un document');
        return;
    }
    
    // Create a form to submit for download
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = route('documents.bulk-download');
    
    // Add CSRF token
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    if (csrfToken) {
        const tokenInput = document.createElement('input');
        tokenInput.type = 'hidden';
        tokenInput.name = '_token';
        tokenInput.value = csrfToken;
        form.appendChild(tokenInput);
    }
    
    // Add document IDs
    selectedDocuments.value.forEach(id => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'document_ids[]';
        input.value = id;
        form.appendChild(input);
    });
    
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
};

const bulkDelete = () => {
    const count = selectedDocuments.value.length;
    if (confirm(`Êtes-vous sûr de vouloir supprimer ${count} document(s) ?`)) {
        // Send bulk delete request
        router.post(route('documents.bulk-delete'), {
            document_ids: selectedDocuments.value
        }, {
            preserveScroll: true,
            onSuccess: () => {
                // Show success message
                alert(`${count} document(s) supprimé(s) avec succès`);
                selectedDocuments.value = [];
            },
            onError: (errors) => {
                console.error('Erreur lors de la suppression:', errors);
                alert('Erreur lors de la suppression des documents');
            }
        });
    }
};

</script>