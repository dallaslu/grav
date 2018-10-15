<?php
/**
 * @package    Grav.Common.Data
 *
 * @copyright  Copyright (C) 2015 - 2018 Trilby Media, LLC. All rights reserved.
 * @license    MIT License; see LICENSE file for details.
 */

namespace Grav\Common\Data;

use Grav\Common\Grav;
use RocketTheme\Toolbox\ArrayTraits\Export;
use RocketTheme\Toolbox\ArrayTraits\ExportInterface;
use RocketTheme\Toolbox\Blueprints\BlueprintSchema as BlueprintSchemaBase;

class BlueprintSchema extends BlueprintSchemaBase implements ExportInterface
{
    use Export;

    protected $ignoreFormKeys = [
        'title' => true,
        'help' => true,
        'placeholder' => true,
        'placeholder_key' => true,
        'placeholder_value' => true,
        'fields' => true
    ];

    /**
     * @return array
     */
    public function getTypes()
    {
        return $this->types;
    }

    /**
     * @param string $name
     * @return array
     */
    public function getType($name)
    {
        return $this->types[$name] ?? [];
    }

    /**
     * Validate data against blueprints.
     *
     * @param  array $data
     * @throws \RuntimeException
     */
    public function validate(array $data)
    {
        try {
            $messages = $this->validateArray($data, $this->nested);

        } catch (\RuntimeException $e) {
            throw (new ValidationException($e->getMessage(), $e->getCode(), $e))->setMessages();
        }

        if (!empty($messages)) {
            throw (new ValidationException())->setMessages($messages);
        }
    }

    /**
     * Filter data by using blueprints.
     *
     * @param  array $data                  Incoming data, for example from a form.
     * @param  bool  $missingValuesAsNull   Include missing values as nulls.
     * @return array
     */
    public function filter(array $data, $missingValuesAsNull = false)
    {
        return $this->filterArray($data, $this->nested, $missingValuesAsNull);
    }

    /**
     * @param array $data
     * @param array $rules
     * @return array
     * @throws \RuntimeException
     */
    protected function validateArray(array $data, array $rules)
    {
        $messages = $this->checkRequired($data, $rules);

        foreach ($data as $key => $field) {
            $val = $rules[$key] ?? $rules['*'] ?? null;
            $rule = \is_string($val) ? $this->items[$val] : null;

            if ($rule) {
                // Item has been defined in blueprints.
                if (!empty($rule['validate']['ignore'])) {
                    // Skip validation in the ignored field.
                    continue;
                }

                $messages += Validation::validate($field, $rule);
            } elseif (\is_array($field) && \is_array($val)) {
                // Array has been defined in blueprints.
                $messages += $this->validateArray($field, $val);
            } elseif (isset($rules['validation']) && $rules['validation'] === 'strict') {
                // Undefined/extra item.
                throw new \RuntimeException(sprintf('%s is not defined in blueprints', $key));
            }
        }

        return $messages;
    }

    /**
     * @param array $data
     * @param array $rules
     * @param bool  $missingValuesAsNull
     * @return array
     */
    protected function filterArray(array $data, array $rules, $missingValuesAsNull)
    {
        $results = [];

        if ($missingValuesAsNull) {
            // First pass is to fill up all the fields with null. This is done to lock the ordering of the fields.
            foreach ($rules as $key => $rule) {
                if ($key && !isset($results[$key])) {
                    $val = $rules[$key] ?? $rules['*'] ?? null;
                    $rule = \is_string($val) ? $this->items[$val] : null;

                    if (empty($rule['validate']['ignore'])) {
                        $results[$key] = null;
                    }
                }
            }
        }

        foreach ($data as $key => $field) {
            $val = $rules[$key] ?? $rules['*'] ?? null;
            $rule = \is_string($val) ? $this->items[$val] : null;

            if ($rule) {
                // Item has been defined in blueprints.
                if (!empty($rule['validate']['ignore'])) {
                    // Skip any data in the ignored field.
                    continue;
                }

                $field = Validation::filter($field, $rule);
            } elseif (\is_array($field) && \is_array($val)) {
                // Array has been defined in blueprints.
                $field = $this->filterArray($field, $val, $missingValuesAsNull);
            } elseif (isset($rules['validation']) && $rules['validation'] === 'strict') {
                $field = null;
            }

            if (null !== $field && (!\is_array($field) || !empty($field))) {
                $results[$key] = $field;
            }
        }

        return $results;
    }

    /**
     * @param array $data
     * @param array $fields
     * @return array
     */
    protected function checkRequired(array $data, array $fields)
    {
        $messages = [];

        foreach ($fields as $name => $field) {
            if (!\is_string($field)) {
                continue;
            }

            $field = $this->items[$field];

            // Skip ignored field, it will not be required.
            if (!empty($field['validate']['ignore'])) {
                continue;
            }

            // Check if required.
            if (isset($field['validate']['required'])
                && $field['validate']['required'] === true) {

                if (isset($data[$name])) {
                    continue;
                }
                if ($field['type'] === 'file' && isset($data['data']['name'][$name])) { //handle case of file input fields required
                    continue;
                }

                $value = $field['label'] ?? $field['name'];
                $language = Grav::instance()['language'];
                $message  = sprintf($language->translate('FORM.MISSING_REQUIRED_FIELD', null, true) . ' %s', $language->translate($value));
                $messages[$field['name']][] = $message;
            }
        }

        return $messages;
    }

    /**
     * @param array $field
     * @param string $property
     * @param array $call
     */
    protected function dynamicConfig(array &$field, $property, array &$call)
    {
        $value = $call['params'];

        $default = $field[$property] ?? null;
        $config = Grav::instance()['config']->get($value, $default);

        if (null !== $config) {
            $field[$property] = $config;
        }
    }
}
