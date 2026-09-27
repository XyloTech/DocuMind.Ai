<?php

namespace Tests\Unit;

use App\Services\RAG\IntentClassifier;
use Tests\TestCase;

class IntentClassifierTest extends TestCase
{
    private IntentClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->classifier = new IntentClassifier;
    }

    public function test_greetings_are_treated_as_small_talk(): void
    {
        foreach (['hi', 'Hello!', 'hii', 'hey there', 'yo', 'good morning', 'hiya', 'sup'] as $message) {
            $this->assertTrue($this->classifier->isCasual($message), $message.' should be casual');
        }
    }

    public function test_thanks_and_sign_offs_are_small_talk(): void
    {
        foreach (['thanks', 'thank you', 'ty', 'ok thanks', 'cool', 'bye', 'good night', 'see you'] as $message) {
            $this->assertTrue($this->classifier->isCasual($message), $message.' should be casual');
        }
    }

    public function test_meta_questions_about_the_assistant_are_small_talk(): void
    {
        foreach (['who are you', 'what are you', 'what can you do', 'help', 'testing'] as $message) {
            $this->assertTrue($this->classifier->isCasual($message), $message.' should be casual');
        }
    }

    public function test_real_document_questions_are_not_small_talk(): void
    {
        $questions = [
            'What is this document about?',
            'Summarize the refund policy',
            'When are invoices due',
            'What does the proxy engine do?',
            'List the security rules',
            'Explain the deployment steps',
            'Who is responsible for Firestore reviews',
            'What happens when a student is absent',
        ];

        foreach ($questions as $question) {
            $this->assertFalse($this->classifier->isCasual($question), $question.' should not be casual');
        }
    }

    public function test_a_greeting_followed_by_a_real_question_is_still_a_question(): void
    {
        $this->assertFalse($this->classifier->isCasual('Hi, what is the refund policy?'));
        $this->assertFalse($this->classifier->isCasual('hello, explain the deployment steps'));
    }

    public function test_a_greeting_carrying_unknown_words_is_still_small_talk(): void
    {
        // A name, a company or a place we have never seen must not push a
        // greeting into document mode, where it can only be refused.
        $messages = [
            'hello i am harshit',
            'ello',
            'good morning, this is Priya from Acme Corp',
            'hey there, it is Rahul',
            'thanks a lot, you saved my day',
        ];

        foreach ($messages as $message) {
            $this->assertTrue($this->classifier->isCasual($message), $message.' should be casual');
        }
    }

    public function test_a_statement_without_a_greeting_is_not_small_talk(): void
    {
        $messages = [
            'is the invoice ready',
            'please check the invoice status',
            'the deadline for submission',
        ];

        foreach ($messages as $message) {
            $this->assertFalse($this->classifier->isCasual($message), $message.' should not be casual');
        }
    }

    public function test_long_messages_are_never_small_talk(): void
    {
        $long = 'hi '.str_repeat('this is a long question about the document ', 5);

        $this->assertFalse($this->classifier->isCasual($long));
    }

    public function test_a_previous_refusal_can_be_detected(): void
    {
        $this->assertTrue($this->classifier->followsRefusal('I cannot find this information in the document.'));
        $this->assertTrue($this->classifier->followsRefusal('That information is not found in the document.'));
        $this->assertFalse($this->classifier->followsRefusal('The policy allows 30 days.'));
        $this->assertFalse($this->classifier->followsRefusal(null));
    }
}
