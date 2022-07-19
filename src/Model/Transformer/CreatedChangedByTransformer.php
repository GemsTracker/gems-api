<?php

declare(strict_types=1);

namespace Gems\Api\Model\Transformer;

/**
 * Add created and changed by values from currentuser
 */
class CreatedChangedByTransformer extends \MUtil_Model_ModelTransformerAbstract
{
    protected int $currentUserId;

    public function __construct(int $currentUserId)
    {
        $this->currentUserId = $currentUserId;
    }

    public function transformRowBeforeSave(\MUtil_Model_ModelAbstract $model, array $row): array
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
