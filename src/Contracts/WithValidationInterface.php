<?php

declare(strict_types=1);

namespace Toporia\Tabula\Contracts;

/**
 * Interface WithValidationInterface
 *
 * Implement this interface to add validation rules to import rows.
 */
interface WithValidationInterface
{
    /**
     * Get validation rules for each row.
     *
     * @return array<string, string|array<string>> Validation rules
     *
     * @example
     * return [
     *     'email' => 'required|email',
     *     'name' => 'required|min:2|max:255',
     *     'quantity' => 'required|integer|min:1',
     * ];
     */
    public function rules(): array;

    /**
     * Get custom validation messages (optional).
     *
     * @return array<string, string>
     */
    public function customValidationMessages(): array;
}
