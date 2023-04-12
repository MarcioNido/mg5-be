<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TransactionsUpdatedEvent
{
    use Dispatchable, SerializesModels;
}
