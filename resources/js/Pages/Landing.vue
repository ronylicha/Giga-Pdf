<template>
    <div class="min-h-screen bg-gradient-to-br from-indigo-50 via-white to-purple-50">
        <!-- Navigation -->
        <nav class="fixed w-full bg-white/90 backdrop-blur-md shadow-sm z-50">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between h-16">
                    <div class="flex items-center">
                        <div class="flex-shrink-0 flex items-center">
                            <svg class="h-8 w-8 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            <span class="ml-2 text-2xl font-bold bg-gradient-to-r from-indigo-600 to-purple-600 bg-clip-text text-transparent">
                                Giga-PDF
                            </span>
                        </div>
                    </div>
                    <div class="flex items-center space-x-4">
                        <a href="#features" class="text-gray-700 hover:text-indigo-600 px-3 py-2 text-sm font-medium transition">
                            Fonctionnalités
                        </a>
                        <a href="#pricing" class="text-gray-700 hover:text-indigo-600 px-3 py-2 text-sm font-medium transition">
                            Tarifs
                        </a>
                        <a href="#about" class="text-gray-700 hover:text-indigo-600 px-3 py-2 text-sm font-medium transition">
                            À propos
                        </a>
                        <Link
                            :href="route('login')"
                            class="text-gray-700 hover:text-indigo-600 px-3 py-2 text-sm font-medium transition"
                        >
                            Connexion
                        </Link>
                        <Link
                            :href="route('register')"
                            class="bg-indigo-600 text-white hover:bg-indigo-700 px-4 py-2 rounded-lg text-sm font-medium transition"
                        >
                            Commencer Gratuitement
                        </Link>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Hero Section -->
        <section class="pt-32 pb-20 px-4 sm:px-6 lg:px-8">
            <div class="max-w-7xl mx-auto">
                <div class="text-center">
                    <h1 class="text-5xl sm:text-6xl font-bold text-gray-900 mb-6">
                        Gérez vos PDF avec
                        <span class="bg-gradient-to-r from-indigo-600 to-purple-600 bg-clip-text text-transparent">
                            Intelligence
                        </span>
                    </h1>
                    <p class="text-xl text-gray-600 mb-8 max-w-3xl mx-auto">
                        Solution complète de gestion PDF multi-tenant avec conversion avancée, OCR, 
                        édition collaborative et sécurité renforcée pour votre entreprise.
                    </p>
                    <div class="flex flex-col sm:flex-row gap-4 justify-center">
                        <Link
                            :href="route('register')"
                            class="inline-flex items-center justify-center px-8 py-4 text-lg font-medium text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl shadow-lg hover:shadow-xl transform hover:-translate-y-1 transition-all duration-200"
                        >
                            <span>Essai Gratuit 30 Jours</span>
                            <svg class="ml-2 w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                            </svg>
                        </Link>
                        <button
                            @click="playDemo"
                            class="inline-flex items-center justify-center px-8 py-4 text-lg font-medium text-indigo-600 bg-white border-2 border-indigo-600 hover:bg-indigo-50 rounded-xl shadow-lg hover:shadow-xl transform hover:-translate-y-1 transition-all duration-200"
                        >
                            <svg class="mr-2 w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                <path d="M10 18a8 8 0 100-16 8 8 0 000 16zM9.555 7.168A1 1 0 008 8v4a1 1 0 001.555.832l3-2a1 1 0 000-1.664l-3-2z" />
                            </svg>
                            <span>Voir la Démo</span>
                        </button>
                    </div>
                </div>

                <!-- Stats -->
                <div class="mt-20 grid grid-cols-2 md:grid-cols-4 gap-8">
                    <div class="text-center">
                        <div class="text-4xl font-bold text-indigo-600">10k+</div>
                        <div class="text-gray-600 mt-2">Utilisateurs Actifs</div>
                    </div>
                    <div class="text-center">
                        <div class="text-4xl font-bold text-indigo-600">5M+</div>
                        <div class="text-gray-600 mt-2">PDF Traités</div>
                    </div>
                    <div class="text-center">
                        <div class="text-4xl font-bold text-indigo-600">99.9%</div>
                        <div class="text-gray-600 mt-2">Disponibilité</div>
                    </div>
                    <div class="text-center">
                        <div class="text-4xl font-bold text-indigo-600">50+</div>
                        <div class="text-gray-600 mt-2">Formats Supportés</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Features Section -->
        <section id="features" class="py-20 bg-white">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="text-center mb-16">
                    <h2 class="text-4xl font-bold text-gray-900 mb-4">
                        Fonctionnalités Puissantes
                    </h2>
                    <p class="text-xl text-gray-600 max-w-3xl mx-auto">
                        Tout ce dont vous avez besoin pour gérer, éditer et convertir vos documents PDF
                    </p>
                </div>

                <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-8">
                    <!-- Feature 1 -->
                    <div class="group hover:shadow-xl rounded-2xl p-8 transition-all duration-300 border border-gray-100">
                        <div class="w-14 h-14 bg-indigo-100 rounded-xl flex items-center justify-center mb-6 group-hover:bg-indigo-600 transition-colors">
                            <svg class="w-7 h-7 text-indigo-600 group-hover:text-white transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                            </svg>
                        </div>
                        <h3 class="text-xl font-semibold text-gray-900 mb-3">Conversion Universelle</h3>
                        <p class="text-gray-600">
                            Convertissez depuis et vers plus de 50 formats : Word, Excel, PowerPoint, Images, HTML, et plus encore.
                        </p>
                    </div>

                    <!-- Feature 2 -->
                    <div class="group hover:shadow-xl rounded-2xl p-8 transition-all duration-300 border border-gray-100">
                        <div class="w-14 h-14 bg-purple-100 rounded-xl flex items-center justify-center mb-6 group-hover:bg-purple-600 transition-colors">
                            <svg class="w-7 h-7 text-purple-600 group-hover:text-white transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                            </svg>
                        </div>
                        <h3 class="text-xl font-semibold text-gray-900 mb-3">OCR Intelligent</h3>
                        <p class="text-gray-600">
                            Extrayez automatiquement le texte de vos documents scannés et images avec une précision exceptionnelle.
                        </p>
                    </div>

                    <!-- Feature 3 -->
                    <div class="group hover:shadow-xl rounded-2xl p-8 transition-all duration-300 border border-gray-100">
                        <div class="w-14 h-14 bg-green-100 rounded-xl flex items-center justify-center mb-6 group-hover:bg-green-600 transition-colors">
                            <svg class="w-7 h-7 text-green-600 group-hover:text-white transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                            </svg>
                        </div>
                        <h3 class="text-xl font-semibold text-gray-900 mb-3">Sécurité Avancée</h3>
                        <p class="text-gray-600">
                            Chiffrement, signatures numériques, filigranes et authentification 2FA pour protéger vos documents.
                        </p>
                    </div>

                    <!-- Feature 4 -->
                    <div class="group hover:shadow-xl rounded-2xl p-8 transition-all duration-300 border border-gray-100">
                        <div class="w-14 h-14 bg-yellow-100 rounded-xl flex items-center justify-center mb-6 group-hover:bg-yellow-600 transition-colors">
                            <svg class="w-7 h-7 text-yellow-600 group-hover:text-white transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                            </svg>
                        </div>
                        <h3 class="text-xl font-semibold text-gray-900 mb-3">Édition Complète</h3>
                        <p class="text-gray-600">
                            Modifiez, annotez, fusionnez, divisez et réorganisez vos PDF directement dans votre navigateur.
                        </p>
                    </div>

                    <!-- Feature 5 -->
                    <div class="group hover:shadow-xl rounded-2xl p-8 transition-all duration-300 border border-gray-100">
                        <div class="w-14 h-14 bg-red-100 rounded-xl flex items-center justify-center mb-6 group-hover:bg-red-600 transition-colors">
                            <svg class="w-7 h-7 text-red-600 group-hover:text-white transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                            </svg>
                        </div>
                        <h3 class="text-xl font-semibold text-gray-900 mb-3">Multi-Tenant</h3>
                        <p class="text-gray-600">
                            Architecture isolée pour chaque organisation avec gestion des utilisateurs et permissions granulaires.
                        </p>
                    </div>

                    <!-- Feature 6 -->
                    <div class="group hover:shadow-xl rounded-2xl p-8 transition-all duration-300 border border-gray-100">
                        <div class="w-14 h-14 bg-blue-100 rounded-xl flex items-center justify-center mb-6 group-hover:bg-blue-600 transition-colors">
                            <svg class="w-7 h-7 text-blue-600 group-hover:text-white transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                            </svg>
                        </div>
                        <h3 class="text-xl font-semibold text-gray-900 mb-3">Temps Réel</h3>
                        <p class="text-gray-600">
                            Notifications instantanées et suivi en temps réel de vos conversions avec WebSockets.
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Pricing Section -->
        <section id="pricing" class="py-20 bg-gradient-to-br from-indigo-50 to-purple-50">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="text-center mb-16">
                    <h2 class="text-4xl font-bold text-gray-900 mb-4">
                        Tarifs Transparents
                    </h2>
                    <p class="text-xl text-gray-600">
                        Choisissez le plan qui correspond à vos besoins
                    </p>
                </div>

                <div class="grid md:grid-cols-3 gap-8">
                    <!-- Starter Plan -->
                    <div class="bg-white rounded-2xl shadow-lg p-8 hover:shadow-2xl transition-shadow">
                        <h3 class="text-2xl font-bold text-gray-900 mb-2">Starter</h3>
                        <p class="text-gray-600 mb-6">Pour les petites équipes</p>
                        <div class="mb-6">
                            <span class="text-5xl font-bold text-gray-900">19€</span>
                            <span class="text-gray-600">/mois</span>
                        </div>
                        <ul class="space-y-4 mb-8">
                            <li class="flex items-start">
                                <svg class="w-5 h-5 text-green-500 mt-1 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                                <span class="text-gray-700">5 utilisateurs</span>
                            </li>
                            <li class="flex items-start">
                                <svg class="w-5 h-5 text-green-500 mt-1 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                                <span class="text-gray-700">10 GB de stockage</span>
                            </li>
                            <li class="flex items-start">
                                <svg class="w-5 h-5 text-green-500 mt-1 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                                <span class="text-gray-700">1000 conversions/mois</span>
                            </li>
                            <li class="flex items-start">
                                <svg class="w-5 h-5 text-green-500 mt-1 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                                <span class="text-gray-700">Support email</span>
                            </li>
                        </ul>
                        <button class="w-full py-3 px-6 bg-gray-100 hover:bg-gray-200 text-gray-900 font-medium rounded-lg transition">
                            Commencer
                        </button>
                    </div>

                    <!-- Professional Plan -->
                    <div class="bg-indigo-600 rounded-2xl shadow-xl p-8 hover:shadow-2xl transition-shadow transform scale-105">
                        <div class="inline-block px-3 py-1 bg-yellow-400 text-yellow-900 text-sm font-semibold rounded-full mb-4">
                            POPULAIRE
                        </div>
                        <h3 class="text-2xl font-bold text-white mb-2">Professional</h3>
                        <p class="text-indigo-200 mb-6">Pour les entreprises en croissance</p>
                        <div class="mb-6">
                            <span class="text-5xl font-bold text-white">49€</span>
                            <span class="text-indigo-200">/mois</span>
                        </div>
                        <ul class="space-y-4 mb-8">
                            <li class="flex items-start">
                                <svg class="w-5 h-5 text-yellow-400 mt-1 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                                <span class="text-white">25 utilisateurs</span>
                            </li>
                            <li class="flex items-start">
                                <svg class="w-5 h-5 text-yellow-400 mt-1 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                                <span class="text-white">100 GB de stockage</span>
                            </li>
                            <li class="flex items-start">
                                <svg class="w-5 h-5 text-yellow-400 mt-1 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                                <span class="text-white">10000 conversions/mois</span>
                            </li>
                            <li class="flex items-start">
                                <svg class="w-5 h-5 text-yellow-400 mt-1 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                                <span class="text-white">OCR illimité</span>
                            </li>
                            <li class="flex items-start">
                                <svg class="w-5 h-5 text-yellow-400 mt-1 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                                <span class="text-white">Support prioritaire</span>
                            </li>
                        </ul>
                        <button class="w-full py-3 px-6 bg-white hover:bg-gray-100 text-indigo-600 font-medium rounded-lg transition">
                            Essai Gratuit
                        </button>
                    </div>

                    <!-- Enterprise Plan -->
                    <div class="bg-white rounded-2xl shadow-lg p-8 hover:shadow-2xl transition-shadow">
                        <h3 class="text-2xl font-bold text-gray-900 mb-2">Enterprise</h3>
                        <p class="text-gray-600 mb-6">Pour les grandes organisations</p>
                        <div class="mb-6">
                            <span class="text-5xl font-bold text-gray-900">Sur mesure</span>
                        </div>
                        <ul class="space-y-4 mb-8">
                            <li class="flex items-start">
                                <svg class="w-5 h-5 text-green-500 mt-1 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                                <span class="text-gray-700">Utilisateurs illimités</span>
                            </li>
                            <li class="flex items-start">
                                <svg class="w-5 h-5 text-green-500 mt-1 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                                <span class="text-gray-700">Stockage illimité</span>
                            </li>
                            <li class="flex items-start">
                                <svg class="w-5 h-5 text-green-500 mt-1 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                                <span class="text-gray-700">API dédiée</span>
                            </li>
                            <li class="flex items-start">
                                <svg class="w-5 h-5 text-green-500 mt-1 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                                <span class="text-gray-700">Déploiement sur site</span>
                            </li>
                            <li class="flex items-start">
                                <svg class="w-5 h-5 text-green-500 mt-1 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                                <span class="text-gray-700">Support 24/7 dédié</span>
                            </li>
                        </ul>
                        <button class="w-full py-3 px-6 bg-gray-900 hover:bg-gray-800 text-white font-medium rounded-lg transition">
                            Nous Contacter
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <!-- CTA Section -->
        <section class="py-20 bg-gradient-to-r from-indigo-600 to-purple-600">
            <div class="max-w-4xl mx-auto text-center px-4 sm:px-6 lg:px-8">
                <h2 class="text-4xl font-bold text-white mb-6">
                    Prêt à transformer votre gestion documentaire ?
                </h2>
                <p class="text-xl text-indigo-100 mb-8">
                    Rejoignez des milliers d'entreprises qui font confiance à Giga-PDF
                </p>
                <div class="flex flex-col sm:flex-row gap-4 justify-center">
                    <Link
                        :href="route('register')"
                        class="inline-flex items-center justify-center px-8 py-4 text-lg font-medium text-indigo-600 bg-white hover:bg-gray-100 rounded-xl shadow-lg hover:shadow-xl transform hover:-translate-y-1 transition-all duration-200"
                    >
                        Commencer Gratuitement
                    </Link>
                    <a
                        href="mailto:contact@giga-pdf.com"
                        class="inline-flex items-center justify-center px-8 py-4 text-lg font-medium text-white border-2 border-white hover:bg-white hover:text-indigo-600 rounded-xl shadow-lg hover:shadow-xl transform hover:-translate-y-1 transition-all duration-200"
                    >
                        Demander une Démo
                    </a>
                </div>
            </div>
        </section>

        <!-- Footer -->
        <footer class="bg-gray-900 text-gray-300">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
                <div class="grid md:grid-cols-4 gap-8">
                    <div>
                        <div class="flex items-center mb-4">
                            <svg class="h-8 w-8 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            <span class="ml-2 text-xl font-bold text-white">Giga-PDF</span>
                        </div>
                        <p class="text-sm">
                            La solution complète pour gérer, éditer et convertir vos documents PDF en toute sécurité.
                        </p>
                    </div>
                    
                    <div>
                        <h3 class="text-white font-semibold mb-4">Produit</h3>
                        <ul class="space-y-2">
                            <li><a href="#features" class="hover:text-white transition">Fonctionnalités</a></li>
                            <li><a href="#pricing" class="hover:text-white transition">Tarifs</a></li>
                            <li><a href="#" class="hover:text-white transition">API</a></li>
                            <li><a href="#" class="hover:text-white transition">Intégrations</a></li>
                        </ul>
                    </div>
                    
                    <div>
                        <h3 class="text-white font-semibold mb-4">Entreprise</h3>
                        <ul class="space-y-2">
                            <li><a href="#about" class="hover:text-white transition">À propos</a></li>
                            <li><a href="#" class="hover:text-white transition">Blog</a></li>
                            <li><a href="#" class="hover:text-white transition">Carrières</a></li>
                            <li><a href="#" class="hover:text-white transition">Contact</a></li>
                        </ul>
                    </div>
                    
                    <div>
                        <h3 class="text-white font-semibold mb-4">Légal</h3>
                        <ul class="space-y-2">
                            <li><a href="#" class="hover:text-white transition">Confidentialité</a></li>
                            <li><a href="#" class="hover:text-white transition">Conditions</a></li>
                            <li><a href="#" class="hover:text-white transition">Sécurité</a></li>
                            <li><a href="#" class="hover:text-white transition">RGPD</a></li>
                        </ul>
                    </div>
                </div>
                
                <div class="border-t border-gray-800 mt-8 pt-8 text-center">
                    <p class="text-sm">
                        © 2024 Giga-PDF. Tous droits réservés. Fait avec ❤️ en France.
                    </p>
                </div>
            </div>
        </footer>
    </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';

const playDemo = () => {
    // Ouvrir une modal ou rediriger vers une démo
    alert('Démo interactive bientôt disponible !');
};
</script>