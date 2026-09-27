<?php

namespace App\Exceptions;

use RuntimeException;

class PdfExtractionException extends RuntimeException
{
    public static function noSelectableText(): self
    {
        return new self('This PDF contains no selectable text (it may be scanned).');
    }

    public static function unreadable(string $reason): self
    {
        return new self('The PDF could not be read. '.$reason);
    }
}
