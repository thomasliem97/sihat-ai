<?php

namespace App\Notifications;

use App\Models\MedicalRecord;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CriticalEscalationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public MedicalRecord $record) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('SihatAI critical escalation: '.$this->record->title)
            ->line('A critical finding was flagged and requires clinician review.')
            ->line('Record #'.$this->record->id.': '.$this->record->title)
            ->action('Open record', url('/records/'.$this->record->id));
    }
}
