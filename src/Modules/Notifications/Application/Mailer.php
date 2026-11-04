<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use App\Modules\Notifications\Domain\MailMessage;

interface Mailer
{
    public function send(MailMessage $message): void;
}
