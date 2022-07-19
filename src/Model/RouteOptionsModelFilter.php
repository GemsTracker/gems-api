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
    public static function filterColumns($row, $routeOptions, $save=false, $useKeys=true)
    {
        $flag = ARRAY_FILTER_USE_KEY;
        if ($useKeys === false) {
            $flag = ARRAY_FILTER_USE_BOTH;
        }

        if ($save && isset($routeOptions['allowed_save_fields'])) {
            $allowedSaveFields = $routeOptions['allowed_save_fields'];

            $row = array_filter($row, function ($key) use ($allowedSaveFields) {
                return in_array($key, $allowedSaveFields);
            }, $flag);
        } elseif (isset($routeOptions['allowed_fields'])) {
            $allowedFields = $routeOptions['allowed_fields'];

            $row = array_filter($row, function ($key) use ($allowedFields) {
                return in_array($key, $allowedFields);
            }, $flag);
        }

        if (isset($routeOptions['disallowed_fields'])) {
            $disallowedFields = $routeOptions['disallowed_fields'];

            $row = array_filter($row, function ($key) use ($disallowedFields) {
                return !in_array($key, $disallowedFields);
            }, $flag);

        }

        if ($save && isset($routeOptions['readonly_fields'])) {
            $readonlyFields = $routeOptions['readonly_fields'];

            $row = array_filter($row, function ($key) use ($readonlyFields) {
                return !in_array($key, $readonlyFields);
            }, $flag);

        }

        return $row;
    }
}