<?php

namespace Gems\Api\Model;

use MUtil\Model\ModelAbstract;
use MUtil\Model\Type\JsonData;
use Zalt\Model\MetaModelInterface;

class ModelApiHelper
{
    protected array $structureAttributes = [
        'label',
        'description',
        'required',
        'size',
        'maxlength',
        'type',
        'multiOptions',
        'default',
        'elementClass',
        'multiOptionSettings',
        'disable',
        'raw',
    ];

    /**
     * Filter the columns of a row with routeoptions like allowed_fields, disallowed_fields and readonly_fields
     *
     * @param array $row Row with model values
     * @param bool $save Will the row be saved after filter (enables readonly
     * @param bool $useKeys Use keys or values in the filter of the row
     * @return array Filtered array
     */
    protected function filterColumns(array $row, bool $save=false, bool $useKeys=true): array
    {
        $filterOptions = $this->routeOptions;
        $modelAllowFields = $this->getColNames('allow_api_load');
        $modelAllowSaveFields = $this->getColNames('allow_api_save');
        if ($modelAllowFields && count($modelAllowFields)) {
            if (!isset($filterOptions['allowed_fields'])) {
                $filterOptions['allowed_fields'] = [];
            }
            $filterOptions['allowed_fields'] = array_merge($modelAllowFields, $filterOptions['allowed_fields']);
        }
        if ($modelAllowSaveFields && count($modelAllowSaveFields)) {
            if (!isset($filterOptions['allowed_save_fields'])) {
                $filterOptions['allowed_save_fields'] = [];
            }
            $filterOptions['allowed_save_fields'] = array_merge($modelAllowSaveFields, $filterOptions['allowed_save_fields']);
        }

        return RouteOptionsModelFilter::filterColumns($row, $filterOptions, $save, $useKeys);
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
    public function getApiNames(MetaModelInterface $model, bool $reverse=false): array
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
            $apiNames[$subModelName] = $this->getApiSubModelNames($subModel);
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
    public function getStructure(MetaModelInterface $model): array
    {
        if (!$this->structure) {
            $columns = $model->getItemsOrdered();

            $translations = $this->getApiNames($model);

            $structureAttributes = $this->getStructureAttributes();

            $translatedColumns = [];

            foreach($columns as $columnName) {
                $columnLabel = $columnName;
                if (isset($translations[$columnName]) && !empty($translations[$columnName])) {
                    $columnLabel = $translations[$columnName];
                }
                $translatedColumns[$columnName] = $columnLabel;
            }
            $columns = $this->filterColumns($translatedColumns, false, false);

            $structure = [];

            foreach ($columns as $columnName => $columnLabel) {
                foreach ($structureAttributes as $attributeName) {
                    if ($model->has($columnName, $attributeName)) {

                        $propertyValue = $model->get($columnName, $attributeName);

                        $structure[$columnLabel][$attributeName] = $propertyValue;

                        if ($attributeName === 'type') {
                            $structure[$columnLabel][$attributeName] = match ($structure[$columnLabel][$attributeName]) {
                                1 => 'string',
                                2 => 'numeric',
                                3 => 'date',
                                4 => 'datetime',
                                5 => 'time',
                                6 => 'child_model',
                                default => 'no_value',
                            };
                            if ($model->has($columnName, ModelAbstract::SAVE_TRANSFORMER)) {
                                $transformer = $model->get($columnName, ModelAbstract::SAVE_TRANSFORMER);
                                if (is_array($transformer) && $transformer[0] instanceof JsonData) {
                                    $structure[$columnLabel][$attributeName] = 'json';
                                }
                            }
                        }

                        if ($attributeName == 'default') {
                            switch (true) {
                                case $structure[$columnLabel][$attributeName] instanceof \Zend_Db_Expr:
                                    $structure[$columnLabel][$attributeName] = $structure[$columnLabel][$attributeName]->__toString();
                                    break;
                                case ($structure[$columnLabel][$attributeName] instanceof \Zend_Date
                                    && $structure[$columnLabel][$attributeName] == new \Zend_Date):
                                    $structure[$columnLabel][$attributeName] = 'NOW()';
                                    break;
                                case is_object($structure[$columnLabel][$attributeName]):
                                    $structure[$columnLabel][$attributeName] = null;
                            }
                        }
                    }
                }
                if (isset($structure[$columnLabel])) {
                    $structure[$columnLabel]['name'] = $columnLabel;
                }
            }

            $usedColumns = array_flip(array_keys($structure));

            $columns = $this->filterColumns($usedColumns, false, false);
            $structure = array_intersect_key($structure, $columns);

            $this->structure = $structure;
        }

        return $this->structure;
    }

    public function getStructureAttributes(): array
    {
        return $this->structureAttributes;
    }
}
