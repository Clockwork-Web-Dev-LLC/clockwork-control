<?php

namespace Modules\ClientReports\Mail;

use Illuminate\Mail\Mailables\Envelope;

/**
 * The client's report email exactly as they would get it, sent to an
 * operator to check in a real inbox. The only difference is a "[TEST]"
 * marker at the start of the subject, so the copy can't be mistaken for a
 * real send.
 */
class ClientReportTestMail extends ClientReportMail
{
    public const SUBJECT_PREFIX = '[TEST] ';

    public function envelope(): Envelope
    {
        $envelope = parent::envelope();

        return $envelope->subject(self::SUBJECT_PREFIX.$envelope->subject);
    }
}
