<?php

namespace App\Mail;

use App\Spot;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SpotApproved extends Mailable
{
    use Queueable;
    use SerializesModels;
    public $spot;

    public $theme = 'rit-atlas';

    /**
     * Create a new message instance.
     *
     * @param Spot $spot
     */
    public function __construct(Spot $spot)
    {
        $this->spot = $spot;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->markdown('emails.spots.approved');
    }
}
