<?php

/**
 * @package Gadgets
 * @license http://opensource.org/licenses/MIT
 */

declare(strict_types=1);

namespace DecodeLabs\Gadgets\Constraint;

trait NullableTrait
{
    /**
     * @var bool
     */
    protected $nullable = false;

    /**
     * Is this nullable?
     */
    public function isNullable(): bool
    {
        return $this->nullable;
    }

    /**
     * Set nullable
     *
     * @return $this
     */
    public function setNullable(bool $nullable): Nullable
    {
        $this->nullable = $nullable;
        return $this;
    }
}
