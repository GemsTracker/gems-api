<?php

namespace Gems\Api\Model;

use DateTimeInterface;
use Laminas\Db\Sql\Expression;
use MUtil\Model\Type\JsonData;
use Zalt\Model\MetaModel;
use Zalt\Model\MetaModelInterface;

class ModelApiHelper
{
    protected array $structureAttributes = [
        'label',
        'description',
        'required',
        'size',
        'cols',
        'rows',
        'maxlength',
        'type',
        'multiOptions',
        'default',
        'elementClass',
        'multiOptionSettings',
        'disable',
        'raw',
    ];

    public function applyAllowedColumnsToModel(MetaModelInterface $model, array $rules): MetaModelInterface
    {
        $translatedColumns = $this->getApiNames($model, true);

        if (isset($rules['allowedFields'])) {
            foreach($rules['allowedFields'] as $key => $allowedField) {
                if (is_array($allowedField)) {
                    if ($model->has($key, 'model') && $model->get($key, 'type') === MetaModelInterface::TYPE_CHILD_MODEL) {
                        $subModel = $model->get($key, 'model');
                        $subRules = ['allowedFields' => $allowedField];
                        $this->applyAllowedColumnsToModel($subModel, $subRules);
                        $model->set($key, 'allow_api_load', true);
                    }
                    continue;
                }
                if ($model->has($allowedField)) {
                    $model->set($allowedField, 'allow_api_load', true);
                    continue;
                }
                if (isset($translatedColumns[$allowedField]) && is_string($translatedColumns[$allowedField]) && $model->has($translatedColumns[$allowedField])) {
                    $model->set($translatedColumns[$allowedField], 'allow_api_load', true);
                    continue;
                }
            }
        }

        if (isset($rules['allowedSaveFields'])) {
            foreach($rules['allowedSaveFields'] as $key => $allowedSaveField) {
                if (is_array($allowedSaveField)) {
                    if ($model->has($key, 'model') && $model->get($key, 'type') === MetaModelInterface::TYPE_CHILD_MODEL) {
                        $subModel = $model->get($key, 'model');
                        $subRules = ['allowedSaveFields' => $allowedSaveField];
                        $this->applyAllowedColumnsToModel($subModel, $subRules);
                    }
                    continue;
                }
                if ($model->has($allowedSaveField)) {
                    $model->set($allowedSaveField, 'allow_api_save', true);
                    continue;
                }
                if (isset($translatedColumns[$allowedSaveField]) && is_string($translatedColumns[$allowedSaveField]) && $model->has($translatedColumns[$allowedSaveField])) {
                    $model->set($translatedColumns[$allowedSaveField], 'allow_api_save', true);
                    continue;
                }
            }
        }

        return $model;
    }

    protected function flipMultiArray(array $array): array
    {
        $flipped = [];
        foreach($array as $key=>$value)
        {
            if (is_array($value)) {
                $flipped[$key] = $this->flipMultiArray($value);
            } else {
                $flipped[$value] = $key;
            }
        }
        return $flipped;
    }

    /**
     * Get the api column names translations if they are set
     *
     * @param bool $reverse return the reversed translations
     * @return array
     */
    public function getApiNames(MetaModelInterface $model, bool $reverse = false): array
    {
        $apiNames = $this->getApiSubModelNames($model);

        if ($reverse) {
            return $this->flipMultiArray($apiNames);
        }

        return $apiNames;
    }

    protected function getApiSubModelNames(MetaModelInterface $model): array
    {
        $apiNames = $model->getCol('apiName');

        $subModels = $model->getCol('model');
        foreach($subModels as $subModelName=>$subModel) {
            $apiNames[$subModelName] = $this->getApiSubModelNames($subModel->getMetaModel());
        }
        return $apiNames;
    }

    /**
     * Get the structural information of each model field so it will be easier to see what information is
     * received or needed for a POST/PATCH
     *
     * @return array
     * @throws \Zend_Date_Exception
     */
    public function getStructure(MetaModelInterface $model, bool $useApiNames = true, bool $filterAllowedColumns = true): array
    {
        $structureFields = $model->getItemsUsed();
        $structureAttributes = $this->getStructureAttributes();

        foreach($structureFields as $fieldName) {
            if ($filterAllowedColumns && (!$model->has($fieldName, 'allow_api_load') || $model->get($fieldName, 'allow_api_load') !== true)) {
                continue;
            }

            $fieldLabel = $fieldName;
            if ($useApiNames && $model->has($fieldName, 'apiName')) {
                $fieldLabel = $model->get($fieldName, 'apiName');
            }

            foreach ($structureAttributes as $attributeName) {
                if ($model->has($fieldName, $attributeName)) {
                    $propertyValue = $model->get($fieldName, $attributeName);

                    if (!isset($structure[$fieldLabel])) {
                        $structure[$fieldLabel] = [];
                    }

                    $structure[$fieldLabel][$attributeName] = $propertyValue;

                    if ($attributeName === 'type') {
                        $structure[$fieldLabel][$attributeName] = match ($structure[$fieldLabel][$attributeName]) {
                            MetaModelInterface::TYPE_STRING => 'string',
                            MetaModelInterface::TYPE_NUMERIC => 'numeric',
                            MetaModelInterface::TYPE_DATE => 'date',
                            MetaModelInterface::TYPE_DATETIME => 'datetime',
                            MetaModelInterface::TYPE_TIME => 'time',
                            MetaModelInterface::TYPE_CHILD_MODEL => 'child_model',
                            default => 'no_value',
                        };
                        if ($model->has($fieldName, MetaModel::SAVE_TRANSFORMER)) {
                            $transformer = $model->get($fieldName, MetaModel::SAVE_TRANSFORMER);
                            if (is_array($transformer) && $transformer[0] instanceof JsonData) {
                                $structure[$fieldLabel][$attributeName] = 'json';
                            }
                        }
                    }

                    if ($attributeName === 'default') {
                        switch (true) {
                            case $structure[$fieldLabel][$attributeName] instanceof \Zend_Db_Expr:
                                $structure[$fieldLabel][$attributeName] = $structure[$fieldLabel][$attributeName]->__toString(
                                );
                                break;
                            case $structure[$fieldLabel][$attributeName] instanceof Expression:
                                $structure[$fieldLabel][$attributeName] = $structure[$fieldLabel][$attributeName]->getExpression(
                                );
                                break;
                            case ($structure[$fieldLabel][$attributeName] instanceof \Zend_Date
                                && $structure[$fieldLabel][$attributeName] == new \Zend_Date):
                                $structure[$fieldLabel][$attributeName] = 'NOW()';
                                break;
                            case is_object($structure[$fieldLabel][$attributeName]):
                                $structure[$fieldLabel][$attributeName] = null;
                        }
                    }

                    if ($attributeName === 'maxlength') {
                        $structure[$fieldLabel][$attributeName] = (int)$propertyValue;
                    }
                }
            }
            if (isset($structure[$fieldLabel])) {
                $structure[$fieldLabel]['name'] = $fieldLabel;
            }
            if (isset($structure[$fieldLabel], $structure[$fieldLabel]['type']) && $structure[$fieldLabel]['type'] === 'child_model') {
                $subModel = $model->get($fieldName, 'model');
                if ($subModel instanceof MetaModelInterface) {
                    $structure[$fieldLabel]['structure'] = $this->getStructure($subModel, $useApiNames);
                }
            }
        }

        return $structure;
    }

    public function getStructureAttributes(): array
    {
        return $this->structureAttributes;
    }

    /**
     * Translate a row with the api names and a date transformation to ISO 8601
     *
     * @param array $row
     * @param bool $reversed
     * @return array
     */
    public function translateRow(MetaModelInterface $metaModel, array $row, bool $reversed=false): array
    {
        $translations = $this->getApiNames($metaModel, $reversed);

        return $this->translateList($row, $translations);
    }

    public function translateList(array $row, array $translations): array
    {
        $translatedRow = [];
        foreach($row as $colName=>$value) {

            if (is_array($value) && isset($translations[$colName]) && is_array($translations[$colName])) {
                foreach($value as $key=>$subRow) {
                    $translatedRow[$colName][$key] = $this->translateList($subRow, $translations[$colName]);
                }
                continue;
            }

            if ($value instanceof DateTimeInterface) {
                $value = $value->format(DateTimeInterface::ATOM);
            }

            if (isset($translations[$colName]) && is_string($translations[$colName])) {
                $translatedRow[$translations[$colName]] = $value;
            } else {
                $translatedRow[$colName] = $value;
            }
        }

        return $translatedRow;
    }
}

