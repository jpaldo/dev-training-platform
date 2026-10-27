<?php

namespace App\Http\Requests\Concerns;

/**
 * Browsers send textarea line breaks as "\r\n". A Form Request that uses this trait turns them into "\n" before
 * validation, so multi-line text is stored the same way however it was entered and an untouched save of multi-line
 * text changes nothing.
 */
trait NormalizesLineEndings
{
    /**
     * Replace the given inputs that were sent with the same strings, each "\r\n" and lone "\r" line break turned into
     * "\n". Inputs that were not sent stay absent and non-string values are left as they are.
     *
     * @param  list<string>  $fields
     */
    protected function normalizeLineEndings(array $fields): void
    {
        $this->merge(
            collect($this->only($fields))
                ->map(fn (mixed $value): mixed => is_string($value) ? str_replace(["\r\n", "\r"], "\n", $value) : $value)
                ->all(),
        );
    }
}
