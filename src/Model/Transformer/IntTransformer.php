<?php


namespace Gems\Api\Model\Transformer;

use Zalt\Model\MetaModelInterface;
use MUtil\Model\ModelTransformerAbstract;

class IntTransformer extends ModelTransformerAbstract
{
    protected array $fields;

    public function __construct(array $fields)
    {
        $this->fields = $fields;
    }

    /**
     * The transform function performs the actual transformation of the data and is called after
     * the loading of the data in the source model.
     *
     * @param MetaModelInterface $model The parent model
     * @param array $data Nested array
     * @param boolean $new True when loading a new item
     * @param boolean $isPostData With post data, unselected multiOptions values are not set so should be added
     * @return array Nested array containing (optionally) transformed data
     */
    public function transformLoad(MetaModelInterface $model, array $data, $new = false, $isPostData = false): array
    {
        foreach($data as $key=>$item) {
            foreach($this->fields as $field) {
                if (isset($item[$field])) {
                    if (is_array($item[$field])) {
                        foreach($item[$field] as $itemKey => $itemValue) {
                            $data[$key][$field][$itemKey] = (int)$itemValue;
                        }
                    } else {
                        $data[$key][$field] = (int)$data[$key][$field];
                    }
                }
            }
        }

        return $data;
    }

}
