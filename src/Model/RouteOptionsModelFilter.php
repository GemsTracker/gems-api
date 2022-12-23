<?php


namespace Gems\Api\Model;


class RouteOptionsModelFilter
{
    /**
     * Filter the columns of a row with routeoptions like allowed_fields, disallowed_fields and readonly_fields
     *
     * @param array $row Row with model values
     * @param bool $save Will the row be saved after filter (enables readonly
     * @param bool $useKeys Use keys or values in the filter of the row
     * @return array Filtered array
     */
    public static function filterColumns(array $row, array $routeOptions, bool $save=false, bool $useKeys=true): array
    {
        $flag = ARRAY_FILTER_USE_KEY;
        if ($useKeys === false) {
            $flag = ARRAY_FILTER_USE_BOTH;
        }

        if ($save && isset($routeOptions['allowed_save_fields'])) {
            $allowedSaveFields = $routeOptions['allowed_save_fields'];

            $row = static::filterAllowedFields($row, $allowedSaveFields);
        } elseif (isset($routeOptions['allowed_fields'])) {
            $allowedFields = $routeOptions['allowed_fields'];

            $row = static::filterAllowedFields($row, $allowedFields);
        }

        if (isset($routeOptions['disallowed_fields'])) {
            $disallowedFields = $routeOptions['disallowed_fields'];

            $row = static::filterDisallowedFields($row, $disallowedFields);
        }

        if ($save && isset($routeOptions['readonly_fields'])) {
            $readonlyFields = $routeOptions['readonly_fields'];

            $row = static::filterDisallowedFields($row, $disallowedFields);
        }

        return $row;
    }

    protected static function filterAllowedFields(array $row, array $fields): array
    {
        $filteredRow = [];
        foreach($row as $key=>$value) {
            if (isset($fields[$key]) && is_array($value)) {
                foreach($row[$key] as $index=>$subRow) {
                    if (is_array($subRow)) {
                        $filteredRow[$key][$index] = static::filterAllowedFields($subRow, $fields[$key]);
                    }
                }
            }
            if (in_array($key, $fields)) {
                $filteredRow[$key] = $value;
            }
        }

        return $filteredRow;
    }

    protected static function filterDisallowedFields(array $row, array $fields): array
    {
        foreach($row as $key=>$value) {
            if (isset($fields[$key]) && is_array($value)) {
                foreach($row[$key] as $index=>$subRow) {
                    if (is_array($subRow)) {
                        $filteredRow[$key][$index] = static::filterDisallowedFields($subRow, $fields[$key]);
                    }
                }
            }
            if (!in_array($key, $fields)) {
                unset($row[$key]);
            }
        }

        return $filteredRow;
    }
}