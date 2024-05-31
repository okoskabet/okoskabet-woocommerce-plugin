<?php

/**
 * @package Gadgets
 * @license http://opensource.org/licenses/MIT
 */

declare(strict_types=1);

namespace DecodeLabs\Gadgets\Constraint;

trait RequirableTrait
{
    /**
     * @var bool
     */
    protected $required = false;

    /**
     * Is this required?
     */
    public function isRequired(): bool
    {
        return $this->required;
    }

    /**
     * Set required
     *
     * @return $this
     */
    public function setRequired(bool $required): Requirable
    {
        $this->required = $required;
        return $this;
    }
}
