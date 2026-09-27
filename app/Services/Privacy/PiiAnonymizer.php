<?php

namespace App\Services\Privacy;

use App\Models\User;

/**
 * Strips anything identifying from conversation text before it is used for
 * anything beyond answering the owner: training exports, retention copies or
 * support tickets.
 *
 * Two layers run in order:
 *  1. the account holder's own identity (we know exactly who they are, so their
 *     name and email are removed precisely rather than guessed at), then
 *  2. structural patterns that match secrets and personal data in any text.
 *
 * Everything becomes a stable, readable token so a dataset keeps its shape
 * while losing the personal part.
 */
final class PiiAnonymizer
{
    /**
     * @return array<string, string> token => human label, for documentation
     */
    public static function tokens(): array
    {
        return [
            '[EMAIL]' => 'email address',
            '[NAME]' => 'account holder name',
            '[PHONE]' => 'phone number',
            '[CARD]' => 'payment card number',
            '[SSN]' => 'national insurance / social security number',
            '[IP]' => 'IP address',
            '[SECRET]' => 'API key, token or password',
        ];
    }

    public function anonymize(string $text, ?User $user = null): string
    {
        if (trim($text) === '') {
            return $text;
        }

        $text = $this->removeIdentity($text, $user);
        $text = $this->removeSecrets($text);
        $text = $this->removePersonalData($text);

        return $text;
    }

    /**
     * The account holder's own name and email, matched on word boundaries so a
     * surname inside another word is left alone.
     */
    private function removeIdentity(string $text, ?User $user): string
    {
        if ($user === null) {
            return $text;
        }

        $email = trim((string) $user->email);

        if ($email !== '') {
            $text = (string) preg_replace(
                '/'.preg_quote($email, '/').'/iu',
                '[EMAIL]',
                $text,
            );
        }

        $name = trim((string) $user->name);

        if ($name !== '' && mb_strlen($name) >= 3) {
            $text = (string) preg_replace(
                '/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/iu',
                '[NAME]',
                $text,
            );
        }

        return $text;
    }

    private function removeSecrets(string $text): string
    {
        // password=…, api_key: …, token "…"
        $text = (string) preg_replace(
            '/\b(pass(?:word|wd)?|pwd|secret|token|api[_-]?key|access[_-]?key|auth[_-]?key|credential|bearer)\b(\s*[:=]\s*)(["\']?)[^\s"\',;]+/iu',
            '$1$2[SECRET]',
            $text,
        );

        // Well-known key shapes.
        $text = (string) preg_replace(
            '/\b(sk-[A-Za-z0-9_-]{16,}|ghp_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}|AKIA[0-9A-Z]{16}|xox[baprs]-[A-Za-z0-9-]{10,})\b/',
            '[SECRET]',
            $text,
        );

        // JSON web tokens.
        $text = (string) preg_replace(
            '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/',
            '[SECRET]',
            $text,
        );

        // Credentials embedded in a URL: scheme://user:pass@host
        $text = (string) preg_replace(
            '#(https?://)[^/\s:@]+:[^/\s@]+@#i',
            '$1[SECRET]@',
            $text,
        );

        return $text;
    }

    private function removePersonalData(string $text): string
    {
        $text = (string) preg_replace(
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu',
            '[EMAIL]',
            $text,
        );

        $text = (string) preg_replace(
            '/\b\d{3}-\d{2}-\d{4}\b/',
            '[SSN]',
            $text,
        );

        // 13–19 digits, optionally split by spaces or dashes: card numbers.
        $text = (string) preg_replace(
            '/(?<!\d)(?:\d[ -]?){12,18}\d(?!\d)/',
            '[CARD]',
            $text,
        );

        $text = (string) preg_replace(
            '/\b(?:\d{1,3}\.){3}\d{1,3}\b/',
            '[IP]',
            $text,
        );

        // Phone numbers written with separators, in national or +international
        // form. Digit runs without separators are left alone on purpose so dates
        // and identifiers in a document are not destroyed.
        $text = (string) preg_replace(
            '/(?<![\w.+-])\+?\d{1,3}?[\s.-]*\(?\d{2,4}\)?[\s.-]*\d{3,4}[\s.-]*\d{3,4}(?![\w-])/u',
            '[PHONE]',
            $text,
        );

        return $text;
    }
}
