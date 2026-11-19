<?php

namespace TechiesAfrica\Nomad\Casts;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use TechiesAfrica\Nomad\Services\Timezone\NomadTimezoneResolver;

class NomadDatetimeCast implements CastsAttributes
{
    /**
     * ISO 8601 date and time that names its own timezone: a `Z`, or an
     * offset such as `+01:00`, `+0100` or `+01`. This is what JSON APIs and
     * JavaScript's `toISOString()` send, e.g. `2026-11-19T07:27:21.188Z`.
     * Group 1 is the date as written.
     */
    protected const ZONED_ISO_8601 = '/^(\d{4}-\d{2}-\d{2})[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?\s?(?:Z|[+-]\d{2}(?::?\d{2})?)$/';

    /**
     * Cast the given value from the database to the user's timezone.
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }

        $resolver = app(NomadTimezoneResolver::class);
        $userTz = $resolver->getTimezone();

        if ($value instanceof CarbonInterface) {
            return $value->copy()->setTimezone($userTz);
        }

        return Carbon::parse($value, 'UTC')->setTimezone($userTz);
    }

    /**
     * Prepare the given value for storage by converting to UTC.
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->copy()->setTimezone('UTC')->format($model->getDateFormat());
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->setTimezone('UTC')->format($model->getDateFormat());
        }

        if (is_string($value) && ($utc = $this->zonedTextToUtc($value)) !== null) {
            return $utc->format($model->getDateFormat());
        }

        // Anything else passes through as-is: text with no timezone is taken
        // to be in UTC already, and the database decides about the rest.
        return $value;
    }

    /**
     * Read ISO 8601 text that names its own timezone, as UTC. Null for any
     * other text, which is then stored exactly as before.
     *
     * Such text cannot be stored as written: MySQL refuses a trailing `Z`
     * ("Incorrect datetime value") and PostgreSQL drops the offset without
     * applying it. A model loaded from the database has this cast in place
     * of Laravel's own, so before this an API client could create a record
     * with such a date and then fail to update it.
     */
    protected function zonedTextToUtc(string $value): ?CarbonInterface
    {
        $value = trim($value);

        if (! preg_match(self::ZONED_ISO_8601, $value, $match)) {
            return null;
        }

        try {
            $parsed = Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }

        // PHP rolls an impossible date over (30 February becomes 2 March,
        // 24:00 the next day). Leave that for the database to refuse, as
        // before, rather than store a day nobody sent.
        if ($parsed->format('Y-m-d') !== $match[1]) {
            return null;
        }

        return $parsed->setTimezone('UTC');
    }
}
