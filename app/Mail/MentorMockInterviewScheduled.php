<?php

namespace App\Mail;

use App\Models\WeekFourMentorInterviewSchedule;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MentorMockInterviewScheduled extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly WeekFourMentorInterviewSchedule $schedule, public readonly string $recipientName, public readonly bool $recipientIsMentor = false) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->recipientIsMentor
                ? "QA L3 Mock Interview Scheduled - {$this->schedule->participant->name}"
                : 'QA L3 Mock Interview Scheduled',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.mentor-mock-interview-scheduled',
            with: ['schedule' => $this->schedule->loadMissing(['participant', 'mentor']), 'recipientName' => $this->recipientName, 'recipientIsMentor' => $this->recipientIsMentor],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
