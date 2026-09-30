<?php

namespace App\Services\Support;

/**
 * Decides whether a chat message is asking for a person rather than the
 * model.
 *
 * The detector is deliberately conservative: it only fires on phrases that
 * express intent to reach a human ("speak with a human", "connect me to
 * support"), and a negation window in front of the match keeps "I don't want
 * to speak with a human" from opening a ticket nobody asked for. A false
 * negative costs the user one more message; a false positive costs a support
 * agent an empty conversation, so the pattern list stays short and explicit
 * instead of guessing from keywords like "support".
 */
final class HandoffDetector
{
    /**
     * Phrases that mean "put me in front of a person".
     *
     * @var list<string>
     */
    private const PHRASES = [
        'speak with a human',
        'speak to a human',
        'speak with someone',
        'speak to someone',
        'talk to a human',
        'talk to a person',
        'talk to someone',
        'talk to a human being',
        'want a human',
        'need a human',
        'real person',
        'live person',
        'live agent',
        'actual person',
        'actual human',
        'human being',
        'human support',
        'customer service',
        'customer support',
        'support agent',
        'support representative',
        'support team member',
        'someone who can help',
        'a person here',
        'connect me to',
        'get me in touch',
        'hand me over',
        'escalate this',
        'escalate my request',
        'contact a human',
        'reach a human',
        'reach a person',
    ];

    /**
     * Words that turn the phrase in front of them into a refusal. Matched
     * against the characters immediately preceding the phrase.
     *
     * @var list<string>
     */
    private const NEGATIONS = [
        "don't",
        'don’t',
        'dont',
        'do not',
        "didn't",
        'did not',
        'never',
        'no need',
        'not going to',
        'i do not want to',
        "i don't want to",
        'without speaking to',
    ];

    public function wantsHuman(string $message): bool
    {
        $text = mb_strtolower(trim($message));

        if ($text === '') {
            return false;
        }

        foreach (self::PHRASES as $phrase) {
            $offset = mb_strpos($text, $phrase);

            if ($offset === false) {
                continue;
            }

            if ($this->negated($text, $offset)) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function negated(string $text, int $offset): bool
    {
        $prefix = mb_substr($text, max(0, $offset - 40), min(40, $offset));

        foreach (self::NEGATIONS as $negation) {
            if (str_contains($prefix, $negation)) {
                return true;
            }
        }

        return false;
    }
}
