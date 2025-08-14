<template>
    <div class="test-navigation-wrapper">
        <!-- Test pour vérifier l'alignement de la navigation -->
        <div class="bg-gray-100 p-4">
            <h2 class="text-lg font-semibold mb-4">Test d'alignement de la navigation</h2>
            
            <!-- Simulation de la navigation -->
            <div class="bg-white border-b">
                <div class="max-w-7xl mx-auto px-4">
                    <div class="flex h-16 justify-between">
                        <div class="flex">
                            <!-- Logo placeholder -->
                            <div class="flex shrink-0 items-center">
                                <div class="h-10 w-10 bg-indigo-500 rounded"></div>
                            </div>

                            <!-- Navigation Links alignés -->
                            <div class="hidden sm:-my-px sm:ms-10 sm:flex items-stretch space-x-8">
                                <!-- NavLink simple -->
                                <a href="#" class="inline-flex items-center px-1 pt-1 border-b-2 border-indigo-400 text-sm font-medium leading-5 text-gray-900">
                                    Dashboard
                                </a>
                                
                                <!-- NavDropdown simulé -->
                                <button class="inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium leading-5 text-gray-500 hover:text-gray-700 hover:border-gray-300">
                                    Documents
                                    <svg class="ms-2 -me-0.5 h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                    </svg>
                                </button>
                                
                                <!-- NavDropdown simulé -->
                                <button class="inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium leading-5 text-gray-500 hover:text-gray-700 hover:border-gray-300">
                                    Outils PDF
                                    <svg class="ms-2 -me-0.5 h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                    </svg>
                                </button>
                                
                                <!-- NavDropdown simulé -->
                                <button class="inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium leading-5 text-gray-500 hover:text-gray-700 hover:border-gray-300">
                                    Administration
                                    <svg class="ms-2 -me-0.5 h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Vérification avec grille d'alignement -->
            <div class="mt-4 p-4 bg-white border rounded">
                <h3 class="font-semibold mb-2">Checklist d'alignement :</h3>
                <ul class="space-y-2 text-sm">
                    <li class="flex items-center">
                        <span class="text-green-500 mr-2">✓</span>
                        Tous les éléments utilisent <code class="bg-gray-100 px-1">inline-flex items-center</code>
                    </li>
                    <li class="flex items-center">
                        <span class="text-green-500 mr-2">✓</span>
                        Padding uniforme : <code class="bg-gray-100 px-1">px-1 pt-1</code>
                    </li>
                    <li class="flex items-center">
                        <span class="text-green-500 mr-2">✓</span>
                        Bordure cohérente : <code class="bg-gray-100 px-1">border-b-2</code>
                    </li>
                    <li class="flex items-center">
                        <span class="text-green-500 mr-2">✓</span>
                        Container parent : <code class="bg-gray-100 px-1">flex items-stretch</code>
                    </li>
                    <li class="flex items-center">
                        <span class="text-green-500 mr-2">✓</span>
                        Espacement horizontal : <code class="bg-gray-100 px-1">space-x-8</code>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>

<script setup>
// Component de test pour vérifier l'alignement de la navigation
</script>