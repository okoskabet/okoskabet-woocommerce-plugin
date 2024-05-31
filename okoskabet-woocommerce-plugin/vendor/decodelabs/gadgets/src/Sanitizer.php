<?php

/**
 * @package Gadgets
 * @license http://opensource.org/licenses/MIT
 */

declare(strict_types=1);

namespace DecodeLabs\Gadgets;

use Closure;

use DecodeLabs\Exceptional;
use DecodeLabs\Gadgets\Constraint\Requirable;
use DecodeLabs\Gadgets\Constraint\RequirableTrait;

class Sanitizer implements Requirable
{
    use RequirableTrait;

    /**
     * @var mixed
     */
    protected $value;

    /**
     * Init with raw value
     *
     * @param mixed $value
     */
    public function __construct($value, bool $required = true)
    {
        $this->value = $value;
        $this->required = $required;
    }


    /**
     * Get original value
     *
     * @return mixed
     */
    public function asIs()
    {
        return $this->value;
    }

    /**
     * Get value as boolean
     *
     * @param mixed $default
     */
    public function asBool($default = null): ?bool
    {
        if (null === ($value = $this->prepareValue($default))) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /**
     * Get value as int
     *
     * @param mixed $default
     */
    public function asInt($default = null): ?int
    {
        if (null === ($value = $this->prepareValue($default))) {
            return null;
        }

        if (!is_numeric($value)) {
            throw Exceptional::UnexpectedValue(
                'Value is not numeric',
                null,
                $value
            );
        }

        return (int)$value;
    }

    /**
     * Get value as float
     *
     * @param mixed $default
     */
    public function asFloat($default = null): ?float
    {
        if (null === ($value = $this->prepareValue($default))) {
            return null;
        }

        if (!is_numeric($value)) {
            throw Exceptional::UnexpectedValue(
                'Value is not numeric',
                null,
                $value
            );
        }

        return (float)$value;
    }

    /**
     * Get value as string
     *
     * @param mixed $default
     */
    public function asString($default = null): ?string
    {
        if (null === ($value = $this->prepareValue($default))) {
            return null;
        }

        return (string)$value;
    }

    /**
     * Get value as slug string
     *
     * @param mixed $default
     */
    public function asSlug($default = null): ?string
    {
        if (null === ($value = $this->prepareValue($default))) {
            return null;
        }

        $value = strtolower($value);

        if (!preg_match('/^[a-z0-9]([a-z0-9-_]*[a-z0-9])?$/', $value)) {
            throw Exceptional::UnexpectedValue(
                'Value is not a valid slug',
                null,
                $value
            );
        }

        return $value;
    }

    /**
     * Get value as Guid string
     *
     * @param mixed $default
     */
    public function asGuid($default = null): ?string
    {
        if (null === ($value = $this->prepareValue($default))) {
            return null;
        }

        $value = strtolower($value);

        if (!preg_match('/^[a-z0-9]{8}-(?:[a-z0-9]{4}-){3}[a-z0-9]{12}$/', $value)) {
            throw Exceptional::UnexpectedValue(
                'Value is not a valid GUID',
                null,
                $value
            );
        }

        return $value;
    }

    /**
     * Prepare output value
     *
     * @param mixed $default
     * @return mixed
     */
    protected function prepareValue($default = null)
    {
        $value = $this->value ?? $default;

        if ($value instanceof Closure) {
            $value = $value();
        }

        if ($this->required && $value === null) {
            throw Exceptional::UnexpectedValue(
                'Value is required'
            );
        }

        return $value;
    }

    /**
     * Sanitize value using callback
     *
     * @return mixed
     */
    public function with(callable $callback)
    {
        $value = $callback($this->value);

        if ($this->required && $value === null) {
            throw Exceptional::UnexpectedValue(
                'Value is required'
            );
        }

        return $value;
    }
}
