<?php


namespace Gems\Api\Model;

use Gems\Api\Exception\ModelValidationException;
use Laminas\Validator\ValidatorInterface;
use Psr\Log\LoggerInterface;
use Zalt\Loader\ProjectOverloader;

class ModelProcessor
{
    protected ProjectOverloader $loader;

    protected \MUtil_Model_ModelAbstract $model;

    protected int $userId;

    protected ?LoggerInterface $logger;

    public function __construct(ProjectOverloader $loader, \MUtil_Model_ModelAbstract $model, $userId, $logger=null)
    {
        $this->loader = $loader;
        $this->model = $model;
        $this->userId = $userId;
        $this->logger = $logger;
    }

    protected function addDefaults(array $row): array
    {
        $defaults = $this->model->loadNew();
        if ($this->model instanceof \MUtil_Model_JoinModel && method_exists($this->model, 'getSaveTables')) {
            $requiredColumns = [];
            $saveableTables = $this->model->getSaveTables();
            foreach($this->model->getCol('table') as $colName=>$table) {
                if (isset($saveableTables[$table])) {
                    $requiredColumns[$colName] = true;
                }
            }

            $defaults = array_intersect_key($defaults, $requiredColumns);
        }

        $row += $defaults;
        return $row;
    }

    /**
     * @return int number of changed rows
     */
    public function getChanged(): int
    {
        return $this->model->getChanged();
    }

    /**
     * Get the id field of the model if it is set in the model keys
     *
     * @return string Field name
     */
    protected function getIdField(): string
    {
        if (!$this->idField) {
            $keys = $this->model->getKeys();
            if (isset($keys['id'])) {
                $this->idField = $keys['id'];
            } elseif (is_array($keys) && count($keys) === 1) {
                $this->idField = reset($keys);
            }
        }

        return $this->idField;
    }

    /**
     * Get a specific validator to be run during validation
     *
     * @param $validator
     * @param null $options
     * @return ValidatorInterface
     * @throws \Zalt\Loader\Exception\LoadException
     */
    public function getValidator($validator, $options=null): ValidatorInterface
    {
        if ($validator instanceof ValidatorInterface) {
            return $validator;
        } elseif (is_string($validator)) {
            $validatorName = $validator;
            if ($options !== null) {
                $validator = $this->loader->create('Validate\\' . $validator, $options);
            } else {
                $validator = $this->loader->create('Validate\\'.$validator);
            }

            if ($validator) {
                return $validator;
            } else {
                throw new \Exception(sprintf('Validator %s not found', $validatorName));
            }
        } else {
            throw new \Exception(
                sprintf(
                    'Invalid validator provided to addValidator; must be string or Zend_Validate_Interface. Supplied %s',
                    gettype($validator)
                )
            );
        }
    }

    /**
     * Get the validators for each of the columns in the model
     * This function will also create required validators and type validators for rows that are required.
     * If a POST method is used, the key values will be excluded
     *
     * @return array
     * @throws \Zalt\Loader\Exception\LoadException
     */
    public function getValidators(): array
    {
        if (!$this->validators) {
            if ($this->model instanceof \MUtil_Model_JoinModel && method_exists($this->model, 'getSaveTables')) {
                $saveableTables = $this->model->getSaveTables();

                $multiValidators = [];
                $singleValidators = [];
                $allRequiredFields = [];
                $types = [];

                foreach($this->model->getCol('table') as $colName=>$table) {
                    if (isset($saveableTables[$table])) {
                        $columnValidators = $this->model->get($colName, 'validators');
                        if ($columnValidators !== null) {
                            $multiValidators[$colName] = $columnValidators;
                        }
                        $columnValidator = $this->model->get($colName, 'validator');
                        if ($columnValidator) {
                            $singleValidators[$colName] = $columnValidator;
                        }
                        $columnRequired = $this->model->get($colName, 'required');
                        if ($columnRequired === true) {
                            if ($this->update === true || $this->model->get($colName, 'key') !== true) {
                                $allRequiredFields[$colName] = $columnRequired;
                            }
                        }
                        if ($columnType = $this->model->get($colName, 'type')) {
                            $types[$colName] = $this->model->get($colName, 'type');
                        }
                    }
                }
            } else {
                $multiValidators = $this->model->getCol('validators');
                $singleValidators = $this->model->getCol('validator');
                $allRequiredFields = $this->model->getCol('required');

                $types = $this->model->getCol('type');
            }

            $defaultFields = $this->model->getCol('default');

            $model = $this->model;
            $saveTransformers = $this->model->getCol($model::SAVE_TRANSFORMER);

            $changeFields = [];
            foreach($saveTransformers as $columnName=>$value) {
                if (substr_compare( $columnName, '_by', -3 ) === 0 && $value == $this->userId) {
                    $changeFields[$columnName] = true;
                    $withoutBy = str_replace('_by', '', $columnName);
                    if (isset($saveTransformers[$withoutBy])) {
                        $changeFields[$withoutBy] = true;
                    }
                }
            }

            $joinFields = [];
            if ($this->model instanceof \MUtil_Model_JoinModel && method_exists($this->model, 'getJoinFields')) {
                $joinFields = array_flip($this->model->getJoinFields());
            }

            $requiredFields = array_diff_key($allRequiredFields, $defaultFields, $changeFields, $joinFields);

            $this->requiredFields = $requiredFields;

            foreach($multiValidators as $columnName=>$validators) {
                if (is_array($validators)) {
                    foreach($validators as $key=>$validator) {
                        $multiValidators[$columnName][$key] = $this->getValidator($validator);
                    }
                } else {
                    $multiValidators[$columnName] = [$this->getValidator($validators)];
                }
            }

            foreach($singleValidators as $columnName=>$validators) {
                if (is_array($validators)) {
                    foreach($validators as $key=>$validator) {
                        $multiValidators[$columnName][$key] = $this->getValidator($validator);
                    }
                } else {
                    $multiValidators[$columnName][] = $this->getValidator($validators);
                }
            }

            foreach($requiredFields as $columnName=>$required) {

                if ($required && $this->model->get($columnName, 'autoInsertNotEmptyValidator') !== false) {
                    $multiValidators[$columnName][] = $this->getValidator('NotEmpty');

                } else {
                    $this->requiredFields[$columnName] = false;
                    continue;
                }

                if (!isset($multiValidators[$columnName]) || count($multiValidators[$columnName]) === 1 && array_key_exists($columnName, $types)) {
                    switch ($types[$columnName]) {
                        case \MUtil_Model::TYPE_STRING:
                            //$multiValidators[$columnName][] = $this->getValidator('Alnum', ['allowWhiteSpace' => true]);
                            break;

                        case \MUtil_Model::TYPE_NUMERIC:
                            $multiValidators[$columnName][] = $this->getValidator('Float');
                            break;

                        case \MUtil_Model::TYPE_DATE:
                            $multiValidators[$columnName][] = $this->getValidator('Date');
                            break;

                        case \MUtil_Model::TYPE_DATETIME:
                            $multiValidators[$columnName][] = $this->getValidator('Date', ['format' => \Zend_Date::ISO_8601]);
                            break;
                    }
                }
            }

            $this->validators = $multiValidators;
        }

        return $this->validators;
    }

    public function save(array $row, bool $update=false): array
    {
        $this->update = $update;

        $row = $this->validateRow($row);
        $row = $this->setModelDates($row);

        if ($this->addDefaults && ($update == false)) {
            $row = $this->addDefaults($row);
        }

        return $this->model->save($row);
    }

    /**
     * Set the dateformat if none is supplied in the current model. Otherwise dates will not be transformed to \MUtil_Date
     * Also removes the timezone from the date, as \MUtil_Date does not understand it with timezone.
     *
     * @param $row
     * @return mixed
     */
    protected function setModelDates(array $row): array
    {
        foreach($row as $columnName=>$value) {
            if ($value === null) {
                continue;
            }
            $type = $this->model->get($columnName, 'type');
            if ($type === \MUtil_Model::TYPE_DATETIME || $type === \MUtil_Model::TYPE_DATE) {
                //if ($this->model->get($columnName, 'dateFormat') === null) {
                //    $this->model->set($columnName, 'dateFormat', \MUtil_Date::ISO_8601);
                //}

                if (strpos($value, '+') === 19 || strpos($value, '.') === 19) {
                    $value = substr($value, 0, 19);
                }
                $row[$columnName] = new \MUtil_Date($value, \MUtil_Date::ISO_8601);
            }

        }
        return $row;
    }

    /**
     * Validate a row before saving it to the model and store the errors in $this->errors
     *
     * @param $row
     * @throws \Zalt\Loader\Exception\LoadException
     */
    public function validateRow(array $row)
    {
        $rowValidators = $this->getValidators();
        $idField = $this->getIdField();

        // No ID field is needed when updating
        if (!is_array($idField) && array_key_exists($idField, $rowValidators)) {
            unset($rowValidators[$idField]);
        }

        foreach ($rowValidators as $colName=>$validators) {
            $value = null;
            if (isset($row[$colName])) {
                $value = $row[$colName];
            }

            if (
                (null === $value || '' === $value) &&
                (!$this->requiredFields || !isset($this->requiredFields[$colName]) || !$this->requiredFields[$colName])
            ) {
                continue;
            }

            foreach($validators as $validator) {
                if (!$validator->isValid($value, $row)) {
                    if (!isset($this->errors[$colName])) {
                        $this->errors[$colName] = [];
                    }
                    $this->errors[$colName] += $validator->getMessages();//array_merge($this->errors[$colName], $validator->getMessages());
                }
            }
        }

        if ($this->errors) {
            $modelName = get_class($this->model);
            throw new ModelValidationException(sprintf('Errors were found when validating %s', $modelName), $this->errors);
        }

        return $row;
    }

    public function setAddDefaults(bool $value): void
    {
        $this->addDefaults = $value;
    }
}