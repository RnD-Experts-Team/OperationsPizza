<?php

namespace App\Support;

/**
 * Position-label rules shared by the Humanity position lookup and the TCP
 * job-code lookup.
 */
final class PositionLabel
{
    /**
     * The names to try for a label, most specific first: the label itself,
     * then the label with a configured variant suffix removed —
     * "Crew Member - AT" -> ["Crew Member - AT", "Crew Member"]. The full label
     * comes first so a variant that does get its own position keeps it.
     *
     * @return array<int, string>
     */
    public static function candidates(?string $label): array
    {
        $label = trim((string) $label);

        if ($label === '') {
            return [];
        }

        return array_values(array_unique([$label, self::withoutVariantSuffix($label)]));
    }

    /** "Assistant Manager - AT" -> "Assistant Manager"; unchanged if no configured suffix ends it. */
    public static function withoutVariantSuffix(string $label): string
    {
        $suffixes = array_map(
            fn ($suffix) => preg_quote((string) $suffix, '/'),
            (array) config('operations.position_variant_suffixes', [])
        );

        if ($suffixes === []) {
            return $label;
        }

        $base = trim((string) preg_replace('/\s*-\s*(?:' . implode('|', $suffixes) . ')\s*$/i', '', $label));

        return $base === '' ? $label : $base;
    }
}
