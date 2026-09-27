<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\WidgetPosition;
use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Fillable([
    'user_id',
    'workspace_id',
    'name',
    'site_key',
    'domain',
    'enabled',
    'bot_name',
    'greeting',
    'accent_color',
    'logo_url',
    'theme',
    'launcher_icon',
    'launcher_label',
    'launcher_animation',
    'blobatar_seed',
    'blobatar_size',
    'blobatar_hue',
    'blobatar_tone',
    'blobatar_background',
    'blobatar_expression',
    'blobatar_animation',
    'position',
    'collect_email',
    'visitor_retention_days',
    'monthly_quota',
    'messages_used',
    'quota_period',
    'quota_started_at',
])]
class Site extends Model
{
    /** @use HasFactory<SiteFactory> */
    use HasFactory;

    /**
     * The pose roster the widget avatar can hold. Keys must match the exports
     * of `blobatar/expression`, since the browser bundle maps one to the other.
     *
     * @var list<string>
     */
    public const EXPRESSIONS = [
        'idle', 'happy', 'sad', 'mad', 'surprised', 'wink', 'sleepy', 'smug',
        'unsure', 'scared', 'love', 'shy', 'sick', 'thinking',
    ];

    /**
     * Idle motion styles: `live` animates constantly, `hover` only while the
     * visitor's pointer is over the avatar, `static` never animates.
     *
     * @var list<string>
     */
    public const ANIMATIONS = ['live', 'hover', 'static'];

    /**
     * Backdrop shapes the avatar can sit on; `none` is transparent.
     *
     * @var list<string>
     */
    public const BACKGROUNDS = ['squircle', 'circle', 'square', 'none'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'collect_email' => 'boolean',
            'visitor_retention_days' => 'integer',
            'monthly_quota' => 'integer',
            'messages_used' => 'integer',
            'blobatar_size' => 'integer',
            'blobatar_hue' => 'integer',
            'blobatar_tone' => 'float',
            'position' => WidgetPosition::class,
            'quota_started_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $site): void {
            $site->site_key ??= self::generateKey();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Documents this site's bot is allowed to answer from.
     *
     * @return BelongsToMany<Document, $this>
     */
    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'site_documents')->withTimestamps();
    }

    /**
     * @return HasMany<WidgetConversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(WidgetConversation::class);
    }

    /**
     * @return HasMany<WidgetMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(WidgetMessage::class);
    }

    public function isLive(): bool
    {
        return $this->enabled && $this->documents()->exists();
    }

    /**
     * Whether a browser may call the public widget API for this site.
     *
     * The site key is public, so it cannot authenticate anything on its own.
     * When the owner sets an authorized domain, the `Origin` of the request has
     * to land on that domain (or one of its subdomains); otherwise any page on
     * the internet could spend this site's quota by pasting in the key.
     *
     * A missing `Origin` is allowed on purpose: only browsers send it, and they
     * always do for cross-origin `fetch`, so a non-browser caller is not the
     * threat this guards against.
     */
    public function allowsOrigin(?string $origin): bool
    {
        $domain = self::normalizeHost($this->domain);

        if ($domain === '') {
            return true;
        }

        $host = self::normalizeHost($origin === null ? '' : (string) parse_url($origin, PHP_URL_HOST));

        if ($host === '') {
            return true;
        }

        return $host === $domain || str_ends_with($host, '.'.$domain);
    }

    /**
     * Lowercase host with any scheme, path, port and `www.` prefix removed.
     */
    private static function normalizeHost(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        if (! str_contains($value, '://')) {
            $value = 'http://'.$value;
        }

        $host = strtolower((string) parse_url($value, PHP_URL_HOST));

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return $host;
    }

    /**
     * Remaining visitor messages before the site is paused.
     */
    public function remainingQuota(): int
    {
        return max(0, $this->monthly_quota - $this->messagesUsedThisPeriod());
    }

    /**
     * Visitor messages charged against the current billing period.
     *
     * This is the billing authority for the quota. Counting rows instead would
     * make a refunded unit impossible to give back, and would run an aggregate
     * over the whole message history on every single widget request.
     */
    public function messagesUsedThisPeriod(): int
    {
        return (int) $this->messages_used;
    }

    /**
     * Consume one visitor message, refusing once the quota is spent.
     *
     * Read-then-write used to race: two visitors hitting the last unit both saw
     * "1 remaining" and both were served, overshooting the quota and leaving the
     * stored counter out of step with reality. The read-check-write now runs in
     * a transaction holding a row lock on the site.
     */
    public function consumeQuota(): bool
    {
        if ($this->monthly_quota <= 0) {
            return false;
        }

        $consumed = DB::transaction(function (): bool {
            $locked = self::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            $used = (int) $locked->messages_used;

            if ($used >= $this->monthly_quota) {
                return false;
            }

            $this->forceFill(['messages_used' => $used + 1])->save();

            return true;
        });

        $this->refresh();

        return $consumed;
    }

    /**
     * Give back one unit after a refused or failed answer.
     */
    public function releaseQuota(): void
    {
        $this->forceFill([
            'messages_used' => max(0, (int) $this->messages_used - 1),
        ])->save();
    }

    public function periodStart(): \DateTimeInterface
    {
        $start = $this->quota_started_at;

        if ($start === null) {
            return now()->startOfMonth();
        }

        return $start->isCurrentMonth() ? $start : now()->startOfMonth();
    }

    public function rotateQuotaIfNeeded(): void
    {
        $start = $this->quota_started_at;

        if ($start !== null && $start->isCurrentMonth()) {
            return;
        }

        $this->forceFill([
            'quota_started_at' => now()->startOfMonth(),
            'messages_used' => 0,
        ])->save();
    }

    /**
     * Starter questions drawn from the linked documents.
     *
     * @return list<string>
     */
    public function suggestedQuestions(): array
    {
        return $this->documents()
            ->where('status', DocumentStatus::Processed)
            ->get()
            ->flatMap(fn (Document $document): array => $document->suggestedQuestions())
            ->unique()
            ->values()
            ->take(3)
            ->all();
    }

    /**
     * Configuration handed to the browser widget.
     *
     * @return array<string, mixed>
     */
    public function widgetConfig(): array
    {
        return [
            'bot_name' => $this->bot_name,
            'greeting' => $this->greeting ?: 'Hi! I can help with the product. What do you need help with?',
            'accent_color' => $this->accent_color,
            'logo_url' => $this->logo_url,
            'theme' => $this->theme,
            'launcher_icon' => $this->launcher_icon,
            'launcher_label' => $this->launcher_label ?: 'Need help?',
            'launcher_animation' => $this->launcher_animation ?: 'pulse',
            'position' => $this->position->value,
            'collect_email' => $this->collect_email,
            'blobatar' => [
                'seed' => $this->blobatar_seed ?: ($this->bot_name ?: 'Assistant'),
                'size' => $this->blobatar_size ?: 40,
                'hue' => $this->blobatar_hue,
                'tone' => $this->blobatar_tone,
                'background' => in_array($this->blobatar_background, self::BACKGROUNDS, true)
                    ? $this->blobatar_background
                    : 'squircle',
                'expression' => in_array($this->blobatar_expression, self::EXPRESSIONS, true)
                    ? $this->blobatar_expression
                    : 'idle',
                'animation' => in_array($this->blobatar_animation, self::ANIMATIONS, true)
                    ? $this->blobatar_animation
                    : 'live',
            ],
            'suggestions' => $this->suggestedQuestions(),
        ];
    }

    public static function generateKey(): string
    {
        return 'pk_'.Str::lower((string) Str::random(24));
    }
}
