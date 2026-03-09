<?php

declare(strict_types=1);

namespace App\Services\Translation\Utils;

trait SmartSplitGlossaryTrait
{
    /**
     * Splits a glossary term into variants (Full, Long form, Short form/Acronym).
     * Example: "Hochschulrechenzentrum (HRZ)" -> ["Hochschulrechenzentrum (HRZ)", "Hochschulrechenzentrum", "HRZ"]
     *
     * @return array<string>
     */
    protected function getTermVariants(string $term): array
    {
        $variants = [trim($term)];

        // Match Pattern: "Long Form (Acronym)" or "Long Form(Acronym)"
        // Captures everything before the last set of parentheses and the content inside them.
        if (preg_match('/^(.*?)\s*\(([^)]+)\)$/', $term, $matches)) {
            $longForm = trim($matches[1]);
            $acronym = trim($matches[2]);

            if ($longForm !== '') {
                $variants[] = $longForm;
            }
            if ($acronym !== '') {
                $variants[] = $acronym;
            }
        }

        return array_unique($variants);
    }
}
