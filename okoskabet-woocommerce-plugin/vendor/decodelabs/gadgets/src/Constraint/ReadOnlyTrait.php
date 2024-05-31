<?php

/**
 * @package Gadgets
 * @license http://opensource.org/licenses/MIT
 */

declare(strict_types=1);

namespace DecodeLabs\Gadgets\Constraint;

trait ReadOnlyTrait
{
    /**
     * @var bool
     */
    protected $readOnly = false;

    /**
     * Is this readOnly?
     */
    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }

    /**
     * Set readOnly
     *
     * @return $this
     */
    public function setReadOnly(bool $readOnly): ReadOnly
    {
        $this->readOnly = $readOnly;
        return $this;
    }
}
