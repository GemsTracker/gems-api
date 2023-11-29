<?php

declare(strict_types=1);


namespace Gems\Api\Model\Transformer;


use Gems\Api\Exception\ModelValidationException;
use Laminas\Validator\NotEmpty;
use Laminas\Validator\ValidatorInterface;
use MUtil\Model\ModelTransformerAbstract;
use Zalt\Loader\ProjectOverloader;
use Zalt\Model\Bridge\Laminas\LaminasValidatorBridge;
use Zalt\Model\Data\DataReaderInterface;
use Zalt\Model\MetaModel;
use Zalt\Model\MetaModelInterface;
use Zalt\Model\Sql\JoinModel;

class ValidateFieldsTransformer extends ModelTransformerAbstract
{
    protected ?string $idField = null;

    protected $validators = null;

    public function __construct(
        protected readonly DataReaderInterface $model,
        protected readonly ProjectOverloader $overLoader,
        protected readonly int $currentUserId)
    {
    }

    /**
     * Get the id field of the model if it is set in the model keys
     *
     * @return string|null
     */
    protected function getIdField(): string|null
    {
        if (!$this->idField) {
            $keys = $this->model->getMetaModel()->getKeys();
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
    public function getValidator(string|ValidatorInterface $validator, array $options = null)
    {
        if ($validator instanceof ValidatorInterface) {
            return $validator;
        }

        if (is_string($validator)) {
            if (class_exists($validator)) {
                return new $validator();
            }

            if ($options !== null) {
                $validator = $this->overLoader->create('Validator\\' . $validator, $options);
            } else {
                $validator = $this->overLoader->create('Validator\\'.$validator);
            }

            return $validator;
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
            $validatorBridge = new LaminasValidatorBridge($this->model, $this->overLoader);
            $validators = [];

            $metaModel = $this->model->getMetaModel();

            $saveColumns = array_keys($metaModel->getCol('table'));

            //$allRequiredFields = $metaModel->getCol('required');
            //$joinFields = [];

            if ($this->model instanceof JoinModel || $this->model instanceof \MUtil\Model\JoinModel ) {
                $saveableTables = $this->model->getSaveTables();
                //$allRequiredFields = [];
                foreach($metaModel->getCol('table') as $colName=>$table) {
                    if (isset($saveableTables[$table])) {
                        $saveColumns[] = $colName;
                        /*if ($metaModel->get($colName, 'required') === true && $metaModel->get($colName, 'key') !== true) {
                            $allRequiredFields[] = $colName;
                        }*/
                    }
                }
                //$joinFields = $this->model->getJoinFields();
            }

            foreach($saveColumns as $columnName) {
                $validators[$columnName] = $validatorBridge->getValidatorsFor($columnName);
            }

            /*$defaultFields = $metaModel->getCol('default');

            $saveTransformers = $metaModel->getCol(MetaModel::SAVE_TRANSFORMER);

            $changeFields = [];
            foreach($saveTransformers as $columnName=>$value) {
                if (str_ends_with($columnName, '_by') && $value === $this->currentUserId) {
                    $changeFields[$columnName] = true;
                    $withoutBy = str_replace('_by', '', $columnName);
                    if (isset($saveTransformers[$withoutBy])) {
                        $changeFields[$withoutBy] = true;
                    }
                }
            }

            $requiredFields = array_diff_key($allRequiredFields, $defaultFields, $changeFields, $joinFields);

            foreach($requiredFields as $columnName=>$required) {

                if ($required && $this->model->get($columnName, 'autoInsertNotEmptyValidator') !== false) {
                    $validators[$columnName][] = new NotEmpty();
                    continue;
                }

                $requiredFields[$columnName] = false;
            }

            $this->requiredFields = $requiredFields;*/

            $this->validators = $validators;
        }

        return $this->validators;
    }

    public function transformRowBeforeSave(MetaModelInterface $model, array $row): array
    {
        $rowValidators = $this->getValidators();

        $idField = $this->getIdField();

        // No ID field is needed when updating
        if (array_key_exists($idField, $rowValidators)) {
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
                (!isset($validators[NotEmpty::class]))
            ) {
                continue;
            }

            foreach($validators as $validator) {
                if (is_array($value)) {
                    foreach($value as $key => $subValue) {
                        if (!$validator->isValid($subValue, $row)) {
                            if (!isset($this->errors[$colName])) {
                                $errors[$colName] = [];
                            }
                            if (!isset($this->errors[$colName][$key])) {
                                $errors[$colName][$key] = [];
                            }
                            $errors[$colName][$key] += $validator->getMessages();
                        }
                    }
                } else {
                    if (!$validator->isValid($value, $row)) {
                        if (!isset($this->errors[$colName])) {
                            $errors[$colName] = [];
                        }
                        $errors[$colName] += $validator->getMessages(
                        );//array_merge($this->errors[$colName], $validator->getMessages());
                    }
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
