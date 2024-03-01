<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CivicAlertNotification extends Mailable
{
    use SerializesModels;

    /**
     * The order instance.
     *
     * @var \App\Models\CivicAlert
     */
    protected $civicAlert;

    /**
     * Create a new message instance.
     *
     * @param  \App\Models\CivicAlert  $civicAlert
     * @return void
     */
    public function __construct($civicAlert)
    {
        $this->civicAlert = $civicAlert;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->view('emails.civic_alert_notification')
                ->with([
                    'userName' => $this->civicAlert->user->name,
                    'categoryName' => ($this->civicAlert->civicAlertCategory) ? $this->civicAlert->civicAlertCategory->name : '',
                    'description' => $this->civicAlert->description,
                ]);
    }
}
