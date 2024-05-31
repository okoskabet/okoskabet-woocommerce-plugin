<?php

/**
 * @package Gadgets
 * @license http://opensource.org/licenses/MIT
 */

declare(strict_types=1);

namespace DecodeLabs\Gadgets\Constraint;

interface ReadOnly
{
    public function isReadOnly(): bool;

    /**
     * @return $this
     */
    public function setReadOnly(bool $readOnly): ReadOnly;
}
