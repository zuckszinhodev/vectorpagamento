<?php

declare(strict_types=1);

namespace App\Http\Traits;

use Illuminate\Http\Request;

/**
 * SECURITY: Trait for sanitizing query parameters to prevent SQL injection
 * and parameter manipulation attacks.
 * 
 * Use this trait in controllers that accept sorting, filtering, or field selection.
 */
trait SanitizesQueryParameters
{
    /**
     * Get sanitized sort column from request.
     * 
     * @param Request $request
     * @param array $allowedColumns Whitelist of allowed column names
     * @param string $default Default column if invalid
     * @return string Safe column name
     */
    protected function getSanitizedSortColumn(
        Request $request,
        array $allowedColumns,
        string $default = 'created_at'
    ): string {
        $sortBy = $request->input('sort_by', $default);
        
        // Strict whitelist validation
        if (!in_array($sortBy, $allowedColumns, true)) {
            return $default;
        }
        
        return $sortBy;
    }

    /**
     * Get sanitized sort direction from request.
     * 
     * @param Request $request
     * @param string $default Default direction
     * @return string 'asc' or 'desc'
     */
    protected function getSanitizedSortDirection(Request $request, string $default = 'desc'): string
    {
        $sortDir = strtolower($request->input('sort_dir', $default));
        
        return in_array($sortDir, ['asc', 'desc'], true) ? $sortDir : $default;
    }

    /**
     * Get sanitized filter value from request.
     * 
     * @param Request $request
     * @param string $key Filter key
     * @param array $allowedValues Whitelist of allowed values
     * @param string|null $default Default value
     * @return string|null Safe filter value
     */
    protected function getSanitizedFilter(
        Request $request,
        string $key,
        array $allowedValues,
        ?string $default = null
    ): ?string {
        $value = $request->input($key);
        
        if ($value === null) {
            return $default;
        }
        
        // Strict whitelist validation
        if (!in_array($value, $allowedValues, true)) {
            return $default;
        }
        
        return $value;
    }

    /**
     * Get sanitized search string from request.
     * Escapes SQL wildcards and limits length.
     * 
     * @param Request $request
     * @param string $key Search key
     * @param int $maxLength Maximum allowed length
     * @return string|null Safe search string
     */
    protected function getSanitizedSearch(
        Request $request,
        string $key = 'search',
        int $maxLength = 100
    ): ?string {
        $search = $request->input($key);
        
        if (empty($search)) {
            return null;
        }
        
        // Limit length
        $search = mb_substr((string) $search, 0, $maxLength);
        
        // Escape SQL LIKE wildcards to prevent injection
        $search = addcslashes($search, '%_\\');
        
        // Remove any null bytes
        $search = str_replace("\0", '', $search);
        
        return $search;
    }

    /**
     * Get sanitized fields/columns selection from request.
     * 
     * @param Request $request
     * @param array $allowedFields Whitelist of allowed field names
     * @param string $key Request key
     * @return array Safe field list
     */
    protected function getSanitizedFields(
        Request $request,
        array $allowedFields,
        string $key = 'fields'
    ): array {
        $fields = $request->input($key);
        
        if (!is_array($fields)) {
            if (is_string($fields)) {
                // Handle comma-separated string
                $fields = array_map('trim', explode(',', $fields));
            } else {
                return $allowedFields; // Return all allowed fields as default
            }
        }
        
        // Filter to only allowed fields
        return array_values(array_intersect($fields, $allowedFields));
    }

    /**
     * Get sanitized pagination parameters.
     * 
     * @param Request $request
     * @param int $defaultPerPage Default items per page
     * @param int $maxPerPage Maximum allowed per page
     * @return array ['per_page' => int, 'page' => int]
     */
    protected function getSanitizedPagination(
        Request $request,
        int $defaultPerPage = 20,
        int $maxPerPage = 100
    ): array {
        $perPage = (int) $request->input('per_page', $defaultPerPage);
        $page = (int) $request->input('page', 1);
        
        // Enforce limits
        $perPage = max(1, min($perPage, $maxPerPage));
        $page = max(1, $page);
        
        return [
            'per_page' => $perPage,
            'page' => $page,
        ];
    }

    /**
     * Get sanitized order by parameter - prevents SQL injection via order parameter.
     * 
     * @param Request $request
     * @param array $allowedColumns Whitelist of allowed column names
     * @param string $defaultColumn Default column
     * @param string $defaultDirection Default direction
     * @return array ['column' => string, 'direction' => string]
     */
    protected function getSanitizedOrderBy(
        Request $request,
        array $allowedColumns,
        string $defaultColumn = 'created_at',
        string $defaultDirection = 'desc'
    ): array {
        return [
            'column' => $this->getSanitizedSortColumn($request, $allowedColumns, $defaultColumn),
            'direction' => $this->getSanitizedSortDirection($request, $defaultDirection),
        ];
    }

    /**
     * Validate that a value is a valid UUID.
     * 
     * @param mixed $value
     * @return bool
     */
    protected function isValidUuid($value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }

    /**
     * Get sanitized UUID from request.
     * 
     * @param Request $request
     * @param string $key
     * @return string|null Valid UUID or null
     */
    protected function getSanitizedUuid(Request $request, string $key): ?string
    {
        $value = $request->input($key);
        
        return $this->isValidUuid($value) ? $value : null;
    }
}
