<?php

declare(strict_types=1);

namespace Gems\Api\Model\Transformer;

use MUtil\Model\ModelTransformerAbstract;
use Zalt\Model\MetaModelInterface;

/**
 * Add created and changed by values from currentuser
 */
class CreatedChangedByTransformer extends ModelTransformerAbstract
{
    protected int $currentUserId;

    public function __construct(int $currentUserId)
    {
        $this->currentUserId = $currentUserId;
    }

    public function transformRowBeforeSave(MetaModelInterface $model, array $row): array
    {
        $saveTransformers = $model->getCol($model::SAVE_TRANSFORMER);

        foreach($saveTransformers as $columnName=>$value) {
            if (substr_compare($columnName, '_by', -3) === 0 && $value != $this->currentUserId) {
                $row[$columnName] = $this->currentUserId;
            }
        }

        return $row;
    }

}
