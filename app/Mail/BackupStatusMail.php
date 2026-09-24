<?php

namespace App\Mail;

use App\Models\Backup;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class BackupStatusMail extends Mailable
{
    use Queueable, SerializesModels;

    public $backup;
    public $createdBackup;

    /**
     * Create a new message instance.
     */
    public function __construct(Backup $backup, $createdBackup = null)
    {
        $this->backup = $backup;
        $this->createdBackup = $createdBackup;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        $projectName = $this->backup->project->name ?? 'Project';
        $subject = "Backup Update for Project: {$projectName}";

        return $this->subject($subject)
                    ->view('emails.backup_status');
    }
}