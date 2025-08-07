<template>
    <GuestLayout>
        <Head title="Accepter l'invitation" />

        <div class="mb-4 text-center">
            <h2 class="text-2xl font-bold text-gray-900">Bienvenue sur Giga-PDF</h2>
            <p class="mt-2 text-gray-600">
                Vous avez été invité(e) à rejoindre <strong>{{ tenant.name }}</strong>
            </p>
        </div>

        <div class="mb-6 p-4 bg-blue-50 rounded-lg">
            <div class="text-sm text-gray-700">
                <div class="mb-2">
                    <span class="font-medium">Email:</span> {{ invitation.email }}
                </div>
                <div class="mb-2">
                    <span class="font-medium">Rôle:</span> {{ getRoleName(invitation.role) }}
                </div>
                <div v-if="invitation.message" class="mt-3 p-3 bg-white rounded">
                    <span class="font-medium">Message de l'invitation:</span>
                    <p class="mt-1">{{ invitation.message }}</p>
                </div>
            </div>
        </div>

        <form @submit.prevent="submit">
            <div>
                <InputLabel for="name" value="Votre nom complet" />
                <TextInput
                    id="name"
                    type="text"
                    class="mt-1 block w-full"
                    v-model="form.name"
                    :value="invitation.first_name && invitation.last_name ? 
                        `${invitation.first_name} ${invitation.last_name}` : ''"
                    required
                    autofocus
                />
                <InputError class="mt-2" :message="form.errors.name" />
            </div>

            <div class="mt-4">
                <InputLabel for="password" value="Mot de passe" />
                <TextInput
                    id="password"
                    type="password"
                    class="mt-1 block w-full"
                    v-model="form.password"
                    required
                />
                <InputError class="mt-2" :message="form.errors.password" />
                <p class="mt-1 text-xs text-gray-500">
                    Minimum 8 caractères avec majuscules, minuscules et chiffres
                </p>
            </div>

            <div class="mt-4">
                <InputLabel for="password_confirmation" value="Confirmer le mot de passe" />
                <TextInput
                    id="password_confirmation"
                    type="password"
                    class="mt-1 block w-full"
                    v-model="form.password_confirmation"
                    required
                />
                <InputError class="mt-2" :message="form.errors.password_confirmation" />
            </div>

            <div class="mt-6">
                <label class="flex items-center">
                    <Checkbox v-model="form.terms" />
                    <span class="ml-2 text-sm text-gray-600">
                        J'accepte les 
                        <a href="#" class="text-blue-600 hover:text-blue-800">conditions d'utilisation</a>
                        et la 
                        <a href="#" class="text-blue-600 hover:text-blue-800">politique de confidentialité</a>
                    </span>
                </label>
                <InputError class="mt-2" :message="form.errors.terms" />
            </div>

            <div class="flex items-center justify-end mt-6">
                <Link
                    :href="route('login')"
                    class="underline text-sm text-gray-600 hover:text-gray-900"
                >
                    J'ai déjà un compte
                </Link>

                <PrimaryButton class="ml-4" :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                    Créer mon compte
                </PrimaryButton>
            </div>
        </form>

        <div v-if="hasExpired" class="mt-6 p-4 bg-red-50 rounded-lg">
            <p class="text-red-600 text-sm">
                Cette invitation a expiré. Veuillez contacter l'administrateur pour recevoir une nouvelle invitation.
            </p>
        </div>
    </GuestLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import Checkbox from '@/Components/Checkbox.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({
    invitation: Object,
    tenant: Object,
});

const form = useForm({
    name: props.invitation.first_name && props.invitation.last_name ? 
        `${props.invitation.first_name} ${props.invitation.last_name}` : '',
    password: '',
    password_confirmation: '',
    terms: false,
});

const hasExpired = computed(() => {
    return new Date(props.invitation.expires_at) < new Date();
});

const getRoleName = (role) => {
    const roles = {
        'user': 'Utilisateur',
        'editor': 'Éditeur',
        'manager': 'Manager',
        'tenant_admin': 'Administrateur',
    };
    return roles[role] || role;
};

const submit = () => {
    if (!form.terms) {
        form.errors.terms = 'Vous devez accepter les conditions d\'utilisation';
        return;
    }
    
    form.post(route('invitations.accept.post', props.invitation.token), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>