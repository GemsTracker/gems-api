<?php

declare(strict_types=1);

namespace Gems\Api\Model\Transformer;

use DateTimeInterface;
use DateTimeImmutable;
use MUtil\Model;
use MUtil\Model\ModelTransformerAbstract;
use Zalt\Model\MetaModelInterface;

/**
 * transform date values from model to mutil dates
 */
class DateTransformer extends ModelTransformerAbstract
{
    public function transformRowBeforeSave(MetaModelInterface $model, array $row): array
    {
        foreach($row as $columnName=>$value) {
            if ($value === null) {
                continue;
            }
            $type = $model->get($columnName, 'type');
            if ($type === Model::TYPE_DATETIME || $type === Model::TYPE_DATE) {
                if ($value instanceof DateTimeInterface || $value === null) {
                    continue;
                }

                if (is_string($value) && strpos($value, '+') === 19 || strpos($value, '.') === 19) {
                    $value = substr($value, 0, 19);
                }

                $row[$columnName] = new DateTimeImmutable($value);
            }

        }
        return $row;
    }
}
