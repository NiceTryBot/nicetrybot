<?php

namespace App\Models;

use App\Support\VisitorDataHolders;
use Carbon\CarbonImmutable;
use Database\Factories\VisitorDataSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * How a team handles its visitors' data: how many days each kind is kept before visitor-data:prune
 * deletes it, and who a visitor can contact when the gym won't let them through. Each kind is a
 * RetentionPeriod a VisitorDataHolder declares, stored in the column of the same name; a team that
 * hasn't chosen gets the periods' defaults and no contact.
 *
 * @property int $id
 * @property int $team_id
 * @property int $request_days The edge request log, and everything kept beside it.
 * @property int $gym_days Gym sessions and the answers given in them.
 * @property int $verdict_days Verdicts, counted from when they expired or were lifted.
 * @property string|null $contact An email address or https URL shown to visitors the gym holds or turns away.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 */
#[Guarded(['id'])]
class VisitorDataSetting extends Model
{
    /** @use HasFactory<VisitorDataSettingFactory> */
    use HasFactory;

    /**
     * Get the team's settings, or the defaults (unsaved) when it hasn't chosen any.
     */
    public static function for(Team $team): self
    {
        return self::query()->where('team_id', $team->id)->first()
            ?? new self(['team_id' => $team->id, ...app(VisitorDataHolders::class)->defaults()]);
    }

    /**
     * Get the team the settings belong to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the moment before which one kind of data is deleted.
     *
     * @param  string  $field  A RetentionPeriod's field.
     */
    public function cutoff(string $field, CarbonImmutable $now): CarbonImmutable
    {
        return $now->subDays($this->{$field});
    }

    /**
     * Determine if the contact is an email address rather than a link.
     */
    public function contactIsEmail(): bool
    {
        return filter_var($this->contact, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Get the address visitors are sent to for help, quoting their reference: a mailto link for an
     * email address, the link itself otherwise. Null without a contact.
     */
    public function contactHref(string $reference): ?string
    {
        if (blank($this->contact)) {
            return null;
        }

        return $this->contactIsEmail()
            ? 'mailto:'.$this->contact.'?subject='.rawurlencode(__('Reference :reference', ['reference' => $reference]))
            : $this->contact;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_map(fn () => 'integer', app(VisitorDataHolders::class)->defaults());
    }
}
