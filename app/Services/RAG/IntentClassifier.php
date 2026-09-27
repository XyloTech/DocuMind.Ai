<?php

namespace App\Services\RAG;

/**
 * Decides whether a message is small talk or a real document question.
 *
 * Greetings, thanks and "what can you do" style messages must never be sent
 * through retrieval, otherwise the model would be forced to answer from
 * document context and reply with the refusal line. They are answered by the
 * local model directly instead.
 */
class IntentClassifier
{
    /**
     * Words that can open or continue a casual exchange.
     *
     * Interrogatives are intentionally included: "who are you" and "what can
     * you do" are meta-questions about the assistant, and answering them from
     * document context would only produce a confusing refusal.
     *
     * @var list<string>
     */
    private const array CASUAL_WORDS = [
        'a', 'about', 'afternoon', 'again', 'ai', 'all', 'also', 'am', 'an', 'and', 'any', 'anything',
        'are', 'awesome', 'be', 'been', 'bye', 'can', 'cool', 'could', 'did', 'do', 'doing', 'docs',
        'documind', 'ello', 'evening', 'explain', 'fine', 'for', 'friend', 'get', 'go', 'good', 'got', 'great',
        'greetings', 'haha', 'hello', 'help', 'hey', 'hi', 'hii', 'him', 'hiya', 'hm', 'hmm', 'how',
        'howdy', 'i', 'if', 'in', 'info', 'is', 'it', 'know', 'later', 'let', 'like', 'looks', 'lot',
        'morning', 'me', 'my', 'need', 'night', 'nice', 'no', 'not', 'now', 'np', 'ok', 'okay', 'one',
        'perfect', 'ping', 'please', 'pls', 'pong', 'really', 'right', 'see', 'sounds', 'still', 'sup',
        'sure', 'test', 'testing', 'thank', 'thanks', 'that', 'the', 'there', 'they', 'things', 'this',
        'time', 'to', 'today', 'too', 'ty', 'up', 'very', 'welcome', 'weird', 'what', 'when', 'where',
        'who', 'whoever', 'why', 'will', 'work', 'working', 'works', 'yeah', 'yes', 'yo', 'you', 'your',
        'yours',
    ];

    /**
     * @var list<string>
     */
    private const array FILLER_WORDS = [
        'a', 'again', 'ai', 'all', 'am', 'and', 'any', 'anything', 'are', 'awesome', 'bye', 'can',
        'chat', 'cool', 'could', 'do', 'doing', 'docs', 'documind', 'fine', 'for', 'friend', 'get',
        'go', 'good', 'got', 'great', 'hello', 'help', 'hey', 'hi', 'hii', 'how', 'i', 'in', 'info',
        'is', 'it', 'know', 'let', 'like', 'me', 'my', 'need', 'no', 'not', 'now', 'ok', 'okay',
        'perfect', 'please', 'really', 'right', 'sounds', 'still', 'sup', 'sure', 'thank', 'thanks',
        'that', 'the', 'there', 'this', 'to', 'today', 'too', 'ty', 'up', 'very', 'weird', 'what',
        'who', 'why', 'will', 'work', 'working', 'works', 'yeah', 'yes', 'yo', 'you', 'your',
    ];

    /**
     * Words that only ever open a conversation rather than ask about the
     * document. A message that starts with one of these and contains no
     * information-seeking word is small talk even when the rest of it is
     * unknown to us — "hello i am harshit" is a greeting with a name in it,
     * not a question about the PDF.
     *
     * @var list<string>
     */
    private const array CONVERSATION_OPENERS = [
        'afternoon', 'bye', 'evening', 'greetings', 'good', 'hello', 'hey', 'hi', 'hii',
        'hiya', 'howdy', 'morning', 'night', 'ok', 'okay', 'please', 'sorry', 'sup',
        'thank', 'thanks', 'ty', 'yo',
    ];

    /**
     * Words that make a sentence a real request for information. Deliberately
     * excludes linking verbs ("is", "are", "can"): "hello my name is Priya"
     * must stay small talk, and any message without a conversation opener is
     * already treated as a document question by the final check.
     *
     * @var list<string>
     */
    private const array INFORMATION_REQUESTS = [
        'calculate', 'check', 'compare', 'convert', 'define', 'describe', 'detail',
        'does', 'explain', 'extract', 'find', 'generate', 'give', 'how', 'identify',
        'list', 'mention', 'outline', 'report', 'search', 'show', 'state', 'summarise',
        'summarize', 'tell', 'translate', 'verify', 'what', 'when', 'where', 'which',
        'who', 'whom', 'whose', 'why', 'write',
    ];

    /**
     * @var list<string>
     */
    private const array REFUSAL_PHRASES = [
        'not enough confirmed support information',
        'not found in the document',
        'cannot find',
        'no answer',
        'not sure',
    ];

    public function isCasual(string $message): bool
    {
        $text = $this->normalize($message);

        if ($text === '' || mb_strlen($text) > 80) {
            return false;
        }

        $words = explode(' ', $text);

        // A question mark means the user asked something, even if it is polite.
        if (str_contains($message, '?') && count($words) > 4) {
            return false;
        }

        if ($this->onlySmallTalkWords($words)) {
            return true;
        }

        if (str_contains($message, '?') || ! $this->opensConversation($words)) {
            return false;
        }

        return ! $this->seeksInformation(array_slice($words, 1));
    }

    /**
     * Whether the assistant previously declined to answer from the document.
     */
    public function followsRefusal(?string $previousAnswer): bool
    {
        if ($previousAnswer === null || $previousAnswer === '') {
            return false;
        }

        $text = mb_strtolower($previousAnswer);

        foreach (self::REFUSAL_PHRASES as $phrase) {
            if (str_contains($text, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $words
     */
    private function onlySmallTalkWords(array $words): bool
    {
        foreach ($words as $word) {
            if (! in_array($word, self::CASUAL_WORDS, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $words
     */
    private function opensConversation(array $words): bool
    {
        foreach (array_slice($words, 0, 2) as $word) {
            if (in_array($word, self::CONVERSATION_OPENERS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $words
     */
    private function seeksInformation(array $words): bool
    {
        foreach ($words as $word) {
            if (in_array($word, self::INFORMATION_REQUESTS, true)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $message): string
    {
        $text = mb_strtolower(trim($message));
        $text = str_replace(["'’", '-'], [' ', ' '], $text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
