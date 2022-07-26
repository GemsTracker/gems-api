<?php

declare(strict_types=1);

namespace Gems\Api\Model\Transformer;

use MUtil\Model\ModelAbstract;
use MUtil\Model\ModelTransformerAbstract;

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

    public function transformRowBeforeSave(ModelAbstract $model, array $row): array
    {
        $saveTransformers = $model->getCol($model::SAVE_TRANSFORMER);

        foreach($saveTransformers as $columnName=>$value) {
            if (substr_compare($columnName, '_by', -3) === 0 && $value != $this->currentUserId) {
                $model->set($columnName, $model::SAVE_TRANSFORMER, $this->currentUserId);
            }
        }
        /*if ($this->prefix) {
            \Gems_Model::setChangeFieldsByPrefix($model, $this->prefix, $this->currentUser->getUserId());
        }*/

        return $row;
    }

}
