<?php

declare(strict_types=1);

namespace Gems\Api\Model\Transformer;

/**
 * transform date values from model to mutil dates
 */
class DateTransformer extends \MUtil_Model_ModelTransformerAbstract
{
    public function transformRowBeforeSave(\MUtil_Model_ModelAbstract $model, array $row): array
    {
        foreach($row as $columnName=>$value) {
            if ($value === null) {
                continue;
            }
            $type = $model->get($columnName, 'type');
            if ($type === \MUtil_Model::TYPE_DATETIME || $type === \MUtil_Model::TYPE_DATE) {
                if ($value instanceof \MUtil_Date) {
                    continue;
                }
                if (strpos($value, '+') === 19 || strpos($value, '.') === 19) {
                    $value = substr($value, 0, 19);
                }
                $dateTimeObject = $value;
                if (!($value instanceof \DateTimeInterface)) {
                    $dateTimeObject = new \DateTime($value);
                }

                $row[$columnName] = new \MUtil_Date($dateTimeObject, \MUtil_Date::ISO_8601);
            }

        }
        return $row;
    }
}
