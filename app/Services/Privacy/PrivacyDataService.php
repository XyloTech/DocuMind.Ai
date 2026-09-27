<?php

namespace App\Services\Privacy;

use App\Models\Chat;
use App\Models\Document;
use App\Models\Site;
use App\Models\User;
use App\Support\ModelBrand;
use Illuminate\Support\Facades\Storage;

/**
 * Everything an owner can ask for regarding their own data: a portable export
 * and a permanent deletion. Both are audited, and deletion removes the actual
 * content (conversations, uploaded files, widget transcripts) rather than just
 * flagging it.
 */
final class PrivacyDataService
{
    public function __construct(
        private readonly PrivacyAudit $audit,
        private readonly PiiAnonymizer $anonymizer,
    ) {}

    /**
     * A machine-readable copy of everything stored against the account.
     *
     * @return array<string, mixed>
     */
    public function export(User $user): array
    {
        $chats = Chat::query()
            ->where('user_id', $user->getKey())
            ->with(['messages', 'document:id,filename,page_count'])
            ->orderBy('created_at')
            ->get();

        return [
            'exported_at' => now()->toIso8601String(),
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'privacy' => [
                'consent_recorded_at' => $user->privacy_consent_at?->toIso8601String(),
                'consent_version' => $user->privacy_consent_version,
                'store_chat_history' => $user->allowsHistoryStorage(),
                'allow_model_training' => $user->allowsTraining(),
                'chat_retention_days' => $user->chat_retention_days,
            ],
            'conversations' => $chats->map(fn (Chat $chat): array => [
                'title' => $chat->title,
                'document' => $chat->document?->filename,
                'created_at' => $chat->created_at?->toIso8601String(),
                'messages' => $chat->messages->map(fn ($message): array => [
                    'role' => $message->role->value,
                    'content' => $message->content,
                    'model' => ModelBrand::name($message->model_used),
                    'latency_ms' => $message->latency_ms,
                    'created_at' => $message->created_at?->toIso8601String(),
                ])->values()->all(),
            ])->values()->all(),
            'documents' => Document::query()
                ->where('user_id', $user->getKey())
                ->orderBy('created_at')
                ->get(['filename', 'page_count', 'chunk_count', 'created_at'])
                ->map(fn (Document $document): array => [
                    'filename' => $document->filename,
                    'pages' => $document->page_count,
                    'chunks' => $document->chunk_count,
                    'created_at' => $document->created_at?->toIso8601String(),
                ])
                ->all(),
            'widget_sites' => Site::query()
                ->where('user_id', $user->getKey())
                ->orderBy('created_at')
                ->get(['name', 'site_key', 'domain', 'enabled', 'created_at'])
                ->map(fn (Site $site): array => [
                    'name' => $site->name,
                    'site_key' => $site->site_key,
                    'domain' => $site->domain,
                    'active' => (bool) $site->enabled,
                    'created_at' => $site->created_at?->toIso8601String(),
                ])
                ->all(),
            'activity' => $user->auditLogs()
                ->orderByDesc('created_at')
                ->limit(200)
                ->get(['action', 'summary', 'created_at'])
                ->map(fn ($log): array => [
                    'action' => $log->action,
                    'summary' => $log->summary,
                    'at' => $log->created_at?->toIso8601String(),
                ])
                ->all(),
        ];
    }

    /**
     * Permanently remove stored content for the account. The audit trail is
     * deliberately kept: it records that data was deleted without retaining any
     * of the data itself.
     *
     * @return array<string, int>
     */
    public function erase(User $user, ?string $ip = null): array
    {
        $chats = Chat::query()->where('user_id', $user->getKey())->get(['id']);
        $messages = $chats->isEmpty()
            ? 0
            : (int) $chats->first()->messages()->whereIn('chat_id', $chats->pluck('id'))->count();

        $documents = Document::query()->where('user_id', $user->getKey())->get();
        $sites = Site::query()->where('user_id', $user->getKey())->get(['id']);

        $disk = (string) config('documents.disk');

        foreach ($documents as $document) {
            Storage::disk($disk)->delete($document->file_path);
        }

        // Chats cascade to messages, documents to chunks, sites to widget
        // conversations and messages.
        Chat::query()->whereIn('id', $chats->pluck('id'))->delete();
        Document::query()->whereIn('id', $documents->pluck('id'))->delete();
        Site::query()->whereIn('id', $sites->pluck('id'))->delete();

        $counts = [
            'conversations' => $chats->count(),
            'messages' => $messages,
            'documents' => $documents->count(),
            'widget_sites' => $sites->count(),
        ];

        $this->audit->record(
            $user,
            'data.deleted',
            'Requested deletion of stored data: '.json_encode($counts, JSON_UNESCAPED_SLASHES),
            $counts,
            $ip,
        );

        return $counts;
    }

    /**
     * The account holder's conversation content, anonymised and only collected
     * from accounts that consented to training. Returns one entry per
     * user/assistant pair.
     *
     * @return list<array{prompt: string, response: string}>
     */
    public function trainingPairs(User $user): array
    {
        if (! $user->allowsTraining()) {
            return [];
        }

        $pairs = [];

        Chat::query()
            ->where('user_id', $user->getKey())
            ->with('messages')
            ->orderBy('created_at')
            ->get()
            ->each(function (Chat $chat) use (&$pairs, $user): void {
                $pending = null;

                foreach ($chat->messages->sortBy('id') as $message) {
                    if ($message->role->value === 'user') {
                        $pending = $message->content;

                        continue;
                    }

                    if ($pending === null || trim($message->content) === '') {
                        continue;
                    }

                    $pairs[] = [
                        'prompt' => $this->anonymizer->anonymize($pending, $user),
                        'response' => $this->anonymizer->anonymize($message->content, $user),
                    ];

                    $pending = null;
                }
            });

        return $pairs;
    }
}
