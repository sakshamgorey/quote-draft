<?php

namespace App\Services\Drafts;

use App\Models\Account;

/**
 * Masks personal data before notes go to the AI. The salesperson picks the
 * account first, so its contacts' names, emails and phones are known exactly;
 * regexes catch any other emails and phone numbers.
 *
 * The AI never needs these: it only lists products. Quotes it returns are
 * checked against this redacted text.
 */
class Redactor
{
    private const EMAIL = '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i';

    // 555-201-3344, (555) 201 3344, +1 555.201.3344 — needs separators, so SKUs and sizes are safe.
    private const PHONE = '/(?<![\w-])(?:\+?1[\s.-])?\(?\d{3}\)?[\s.-]\d{3}[\s.-]\d{4}(?![\w-])/';

    public function redact(string $text, ?Account $account): string
    {
        // Emails and phones first: masking names first would leave "[CONTACT_1]@shah.example" behind.
        $text = preg_replace(self::EMAIL, '[EMAIL]', $text) ?? $text;
        $text = preg_replace(self::PHONE, '[PHONE]', $text) ?? $text;

        $contacts = array_values(array_filter($account?->contacts ?? [], fn ($c) => filled($c['name'] ?? null)));

        // Full names first ("Priya Shah"), then single parts ("Priya"), so one person keeps one label.
        foreach ($contacts as $i => $contact) {
            $text = $this->replaceWord($text, trim($contact['name']), '[CONTACT_'.($i + 1).']');
        }

        foreach ($contacts as $i => $contact) {
            foreach (preg_split('/\s+/', trim($contact['name'])) as $part) {
                if (mb_strlen($part) >= 3) {
                    $text = $this->replaceWord($text, $part, '[CONTACT_'.($i + 1).']');
                }
            }
        }

        return $text;
    }

    private function replaceWord(string $text, string $word, string $label): string
    {
        return preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($word, '/').'(?![\p{L}\p{N}])/iu', $label, $text) ?? $text;
    }
}
