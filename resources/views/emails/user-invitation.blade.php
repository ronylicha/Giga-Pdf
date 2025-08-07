<x-mail::message>
# Bonjour !

Vous avez été invité(e) à rejoindre **{{ $tenant->name }}** sur Giga-PDF par {{ $invitedBy->name }}.

@if($invitation->message)
## Message de {{ $invitedBy->name }} :
{{ $invitation->message }}
@endif

## Détails de votre invitation :
- **Organisation :** {{ $tenant->name }}
- **Rôle :** {{ $role }}
- **Email :** {{ $invitation->email }}

<x-mail::button :url="$invitationUrl">
Accepter l'invitation
</x-mail::button>

Cette invitation expirera le {{ $invitation->expires_at->format('d/m/Y à H:i') }}.

Si vous ne pouvez pas cliquer sur le bouton ci-dessus, copiez et collez l'URL suivante dans votre navigateur :
{{ $invitationUrl }}

Si vous n'attendiez pas cette invitation, vous pouvez ignorer cet email en toute sécurité.

Cordialement,<br>
L'équipe {{ config('app.name') }}
</x-mail::message>