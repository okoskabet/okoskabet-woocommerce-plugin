<?php

/**
 * @package Gadgets
 * @license http://opensource.org/licenses/MIT
 */

declare(strict_types=1);

namespace DecodeLabs\Gadgets\Constraint;

interface Requirable
{
    public function isRequired(): bool;

    /**
     * @return $this
     */
    public function setRequired(bool $required): Requirable;
}
