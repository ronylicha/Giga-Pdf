<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    align: {
        type: String,
        default: 'left',
    },
    width: {
        type: String,
        default: '48',
    },
    contentClasses: {
        type: String,
        default: 'py-1 bg-white dark:bg-gray-700',
    },
    active: {
        type: Boolean,
        default: false,
    },
    activeRoutes: {
        type: Array,
        default: () => [],
    },
});

const closeOnEscape = (e) => {
    if (open.value && e.key === 'Escape') {
        open.value = false;
    }
};

onMounted(() => document.addEventListener('keydown', closeOnEscape));
onUnmounted(() => document.removeEventListener('keydown', closeOnEscape));

const widthClass = computed(() => {
    return {
        48: 'w-48',
        64: 'w-64',
    }[props.width.toString()];
});

const alignmentClasses = computed(() => {
    if (props.align === 'left') {
        return 'ltr:origin-top-left rtl:origin-top-right start-0';
    } else if (props.align === 'right') {
        return 'ltr:origin-top-right rtl:origin-top-left end-0';
    } else {
        return 'origin-top';
    }
});

const open = ref(false);

// Check if any of the routes in activeRoutes are currently active
const isActive = computed(() => {
    if (props.active) return true;
    if (props.activeRoutes.length === 0) return false;
    
    const currentRoute = route().current();
    return props.activeRoutes.some(routeName => {
        if (routeName.includes('*')) {
            const pattern = routeName.replace('*', '');
            return currentRoute && currentRoute.startsWith(pattern);
        }
        return route().current(routeName);
    });
});

// Button classes that match NavLink styling exactly - without inline-flex as it's in the style
const buttonClasses = computed(() =>
    isActive.value
        ? 'px-1 pt-1 border-b-2 border-indigo-400 dark:border-indigo-600 text-sm font-medium leading-5 text-gray-900 dark:text-gray-100 focus:outline-none focus:border-indigo-700 transition duration-150 ease-in-out'
        : 'px-1 pt-1 border-b-2 border-transparent text-sm font-medium leading-5 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 hover:border-gray-300 dark:hover:border-gray-700 focus:outline-none focus:text-gray-700 dark:focus:text-gray-300 focus:border-gray-300 dark:focus:border-gray-700 transition duration-150 ease-in-out'
);
</script>

<template>
    <div class="relative h-full flex items-center">
        <!-- Trigger button styled exactly like NavLink with proper height -->
        <button
            @click="open = !open"
            :class="buttonClasses"
            type="button"
            style="height: 100%; display: inline-flex; align-items: center;"
        >
            <slot name="trigger" />
            <svg 
                class="ms-2 -me-0.5 h-4 w-4 transition-transform duration-200"
                :class="{ 'rotate-180': open }"
                xmlns="http://www.w3.org/2000/svg" 
                fill="none" 
                viewBox="0 0 24 24" 
                stroke-width="1.5" 
                stroke="currentColor"
            >
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
            </svg>
        </button>

        <!-- Full Screen Dropdown Overlay -->
        <div
            v-show="open"
            class="fixed inset-0 z-40"
            @click="open = false"
        ></div>

        <Transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="opacity-0 scale-95"
            enter-to-class="opacity-100 scale-100"
            leave-active-class="transition ease-in duration-75"
            leave-from-class="opacity-100 scale-100"
            leave-to-class="opacity-0 scale-95"
        >
            <div
                v-show="open"
                class="absolute z-50 rounded-md shadow-lg"
                :class="[widthClass, alignmentClasses]"
                style="top: calc(100% + 0.5rem);"
                @click="open = false"
            >
                <div
                    class="rounded-md ring-1 ring-black ring-opacity-5"
                    :class="contentClasses"
                >
                    <slot name="content" />
                </div>
            </div>
        </Transition>
    </div>
</template>