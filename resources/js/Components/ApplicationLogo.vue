<template>
    <div class="flex items-center">
        <!-- Logo Icon -->
        <svg class="h-full" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
            <!-- Circular arrow background -->
            <circle cx="50" cy="50" r="45" fill="#2E3A8C" />
            
            <!-- Orange arrow accent -->
            <path d="M 30 35 L 55 35 L 55 25 L 75 45 L 55 65 L 55 55 L 30 55 Z" fill="#E97837" />
            
            <!-- Document icon -->
            <rect x="35" y="40" width="25" height="35" fill="white" rx="2" />
            <rect x="40" y="45" width="15" height="2" fill="#2E3A8C" />
            <rect x="40" y="50" width="15" height="2" fill="#2E3A8C" />
            <rect x="40" y="55" width="10" height="2" fill="#2E3A8C" />
            
            <!-- PDF badge -->
            <rect x="48" y="62" width="18" height="10" fill="#E74C3C" rx="1" />
            <text x="57" y="69" font-family="Arial, sans-serif" font-size="6" fill="white" text-anchor="middle">PDF</text>
        </svg>
        
        <!-- Text Logo -->
        <div class="ml-3 flex items-baseline">
            <span class="text-2xl font-bold text-[#2E3A8C]">Giga-Pdf</span>
            <span class="text-2xl font-bold text-[#E97837]">.com</span>
        </div>
    </div>
</template>

<style scoped>
/* Ensure the component scales properly */
@media (max-width: 640px) {
    .text-2xl {
        font-size: 1.25rem;
    }
}
</style>