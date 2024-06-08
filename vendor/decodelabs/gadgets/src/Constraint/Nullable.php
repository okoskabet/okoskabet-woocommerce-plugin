<?php

/**
 * @package Gadgets
 * @license http://opensource.org/licenses/MIT
 */

declare(strict_types=1);

namespace DecodeLabs\Gadgets\Constraint;

interface Nullable
{
    public function isNullable(): bool;

    /**
     * @return $this
     */
    public function setNullable(bool $nullable): Nullable;
}
