<?php

namespace App\Mail;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserInvitation extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public Invitation $invitation
    ) {
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Invitation à rejoindre ' . $this->invitation->tenant->name . ' sur Giga-PDF',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.user-invitation',
            with: [
                'invitation' => $this->invitation,
                'tenant' => $this->invitation->tenant,
                'invitedBy' => $this->invitation->invitedBy,
                'invitationUrl' => $this->invitation->getInvitationUrl(),
                'role' => $this->getRoleName($this->invitation->role),
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }

    /**
     * Get human-readable role name
     */
    protected function getRoleName(string $role): string
    {
        return match($role) {
            'user' => 'Utilisateur',
            'editor' => 'Éditeur',
            'manager' => 'Manager',
            'tenant_admin' => 'Administrateur',
            'super_admin' => 'Super Administrateur',
            default => 'Utilisateur',
        };
    }
}
