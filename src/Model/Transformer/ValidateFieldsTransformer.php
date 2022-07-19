<?php

declare(strict_types=1);


namespace Gems\Api\Model\Transformer;


use Gems\Api\Exception\ModelValidationException;
use Laminas\Validator\ValidatorInterface;
use Zalt\Loader\ProjectOverloader;

class ValidateFieldsTransformer extends \MUtil_Model_ModelTransformerAbstract
{
    protected ?string $idField = null;

    protected \MUtil_Model_ModelAbstract $model;

    protected ProjectOverloader $overLoader;

    protected int $currentUserId;

    protected $validators = null;

    public function __construct(ProjectOverloader $overLoader, int $currentUserId)
    {
        $this->overLoader = $overLoader;
        $this->currentUserId = $currentUserId;
    }

    /**
     * Get the id field of the model if it is set in the model keys
     *
     * @return string|null
     */
    protected function getIdField(): string|null
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
     * @return object
     * @throws \Zalt\Loader\Exception\LoadException
     */
    public function getValidator(string|ValidatorInterface $validator, array $options = null): ValidatorInterface
    {
        if ($validator instanceof ValidatorInterface) {
            return $validator;
        }

        if (is_string($validator)) {
            $validatorName = $validator;
            if (class_exists($validator)) {
                return new $validator();
            }

            if ($options !== null) {
                $validator = $this->overLoader->create('Validate\\' . $validator, $options);
            } else {
                $validator = $this->overLoader->create('Validate\\'.$validator);
            }

            if ($validator) {
                return $validator;
            } else {
                throw new ModelValidationException(sprintf('Validator %s not found', $validatorName));
            }
        }
        throw new ModelValidationException(
            sprintf(
                'Invalid validator provided to addValidator; must be string or Zend_Validate_Interface. Supplied %s',
                gettype($validator)
            )
        );
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
                            //if ($this->update === true || $this->model->get($colName, 'key') !== true) {
                            if ($this->model->get($colName, 'key') !== true) {

                                $allRequiredFields[$colName] = $columnRequired;
                            }
                        }
                        if ($this->model->has($colName, 'type')) {
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
                if (substr_compare( $columnName, '_by', -3 ) === 0 && $value == $this->currentUserId) {
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
                            $multiValidators[$columnName][] = $this->getValidator('Date', ['format' => 'yyyy-MM-dd']);
                            break;

                        case \MUtil_Model::TYPE_DATETIME:
                            $multiValidators[$columnName][] = $this->getValidator('Date', ['format' => 'yyyy-MM-dd HH:mm:ss']);
                            break;
                    }
                }
            }

            $this->validators = $multiValidators;
        }

        return $this->validators;
    }

    public function transformRowBeforeSave(\MUtil_Model_ModelAbstract $model, array $row): array
    {
        $this->model = $model;
        $rowValidators = $this->getValidators();

        $idField = $this->getIdField();

        // No ID field is needed when updating
        if (!is_array($idField) && array_key_exists($idField, $rowValidators)) {
            unset($rowValidators[$idField]);
        }

        $errors = [];

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
                        $errors[$colName] = [];
                    }
                    $errors[$colName] += $validator->getMessages();//array_merge($this->errors[$colName], $validator->getMessages());
                }
            }
        }

        if (count($errors)) {
            $modelName = get_class($this->model);
            throw new ModelValidationException(sprintf('Errors were found when validating %s', $modelName), $errors);
        }

        return $row;
    }
}
