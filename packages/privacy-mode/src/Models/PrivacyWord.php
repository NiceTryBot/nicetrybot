<?php

namespace NiceTry\PrivacyMode\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use NiceTry\PrivacyMode\Database\Factories\PrivacyWordFactory;

/**
 * A word privacy mode hides on every page, for every team, wherever it turns up: "acme" in
 * /assets/ast_acmelogo.png as much as on its own. Words are kept lowercased and matched in any case.
 *
 * @property int $id
 * @property string $word The lowercased word to hide.
 * @property string|null $replacement What shows in its place; null shows a dot per letter.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['word', 'replacement'])]
class PrivacyWord extends Model
{
    /** @use HasFactory<PrivacyWordFactory> */
    use HasFactory;

    /**
     * Where every page's copy of the word → stand-in map is cached.
     */
    public const string CACHE_KEY = 'privacy-words';

    /**
     * Drop the cached map whenever a word changes.
     */
    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    /**
     * What shows in the word's place while privacy mode is on.
     */
    public function standIn(): string
    {
        return filled($this->replacement) ? $this->replacement : str_repeat('•', mb_strlen($this->word));
    }

    /**
     * Every hidden word with its stand-in, as privacy mode swaps them.
     *
     * @return array<string, string>
     */
    public static function map(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn (): array => self::query()
            ->orderBy('word')
            ->get()
            ->mapWithKeys(fn (self $word): array => [$word->word => $word->standIn()])
            ->all());
    }

    protected static function newFactory(): PrivacyWordFactory
    {
        return PrivacyWordFactory::new();
    }
}
