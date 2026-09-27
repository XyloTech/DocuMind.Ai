<?php

namespace App\Services\Ai;

/**
 * Deterministic, offline chat driver.
 *
 * Used in tests and as a demo fallback when no API key is configured. It does
 * not "understand" anything — it quotes the strongest retrieved passage back
 * at the caller so the whole loop (retrieve → prompt → stream → persist →
 * source badges) can be exercised without a network call.
 */
class FakeChatClient implements ChatClient
{
    private const int WORDS_PER_DELTA = 4;

    public function label(): string
    {
        return 'fake';
    }

    public function complete(array $messages, ?callable $onDelta = null): ChatCompletion
    {
        $promptText = '';
        $question = '';
        $supportFallback = false;

        foreach ($messages as $turn) {
            $promptText .= (string) ($turn['content'] ?? '')."\n";

            if (($turn['role'] ?? '') === 'system'
                && str_contains((string) ($turn['content'] ?? ''), 'No relevant support knowledge was found')) {
                $supportFallback = true;
            }

            if (($turn['role'] ?? '') === 'user') {
                $question = (string) ($turn['content'] ?? '');
            }
        }

        $text = $supportFallback
            ? 'I do not have enough confirmed support information to answer that. Please share a few more details or contact the support team for help.'
            : $this->compose($question);

        foreach ($this->pieces($text) as $piece) {
            if ($onDelta !== null) {
                $onDelta($piece);
            }
        }

        return new ChatCompletion(
            text: $text,
            model: 'fake',
            promptTokens: $this->tokens($promptText),
            completionTokens: $this->tokens($text),
        );
    }

    private function compose(string $question): string
    {
        if (! preg_match('/<context>(.*?)<\/context>/su', $question, $match)) {
            return $this->greeting($question);
        }

        $context = trim((string) $match[1]);

        if ($context === '' || str_contains($context, 'no relevant passages')) {
            return ChatClient::REFUSAL;
        }

        $blocks = preg_split('/\n(?=\[\d+\]\s)/u', $context) ?: [];
        $first = trim((string) ($blocks[0] ?? ''));

        // Drop the preamble line and the "[n] chunk #x · pages y-z" header.
        $lines = array_values(array_filter(
            explode("\n", $first),
            fn (string $line): bool => ! str_starts_with(trim($line), '['),
        ));

        $passage = trim(implode("\n", $lines));

        if ($passage === '') {
            return ChatClient::REFUSAL;
        }

        return 'The available support information says: “'.mb_substr($passage, 0, 400).'”';
    }

    /**
     * Small talk: no context block means the caller used the casual prompt.
     */
    private function greeting(string $question): string
    {
        $text = mb_strtolower(trim($question));

        if (str_contains($text, 'thank')) {
            return 'Happy to help! What can I assist you with about the product or services?';
        }

        if (str_contains($text, 'bye') || str_contains($text, 'see you')) {
            return 'Goodbye! Contact support whenever you need a hand with the product.';
        }

        if (str_contains($text, 'who are you') || str_contains($text, 'what are you')) {
            return 'I am your customer-support assistant. I can help with product features, setup, troubleshooting, pricing, and policies.';
        }

        if (str_contains($text, 'what can you do') || str_contains($text, 'help')) {
            return 'I can help with product features, setup, troubleshooting, pricing, and policies using the business support information.';
        }

        return 'Hello! I can help with product features, setup, troubleshooting, pricing, or policies. What do you need help with?';
    }

    /**
     * Split the answer into word-aligned deltas so the UI streams like it does
     * against a real provider.
     *
     * @return list<string>
     */
    private function pieces(string $text): array
    {
        $words = preg_split('/(?<=\s)/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [$text];

        $pieces = [];

        foreach (array_chunk($words, self::WORDS_PER_DELTA) as $group) {
            $pieces[] = implode('', $group);
        }

        return $pieces;
    }

    private function tokens(string $text): int
    {
        return max(1, (int) ceil(mb_strlen($text) / 4));
    }
}
