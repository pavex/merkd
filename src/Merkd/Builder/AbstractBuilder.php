<?php

/**
 * Base class for all Merkd builders.
 *
 * Centralizes common functionality like output logging.
 *
 * @author    pavex@ines.cz
 * @copyright 2026 Pavel Macháček
 * @license   MIT
 * @package   Merkd\Builder
 */

namespace Merkd\Builder;

use Closure;


abstract class AbstractBuilder
{

    protected ?Closure $output_callback = null;


    /** Sets the output callback for logging progress (null to disable). */
    public function setOutput(?callable $callback): static
    {
        $this->output_callback = $callback ? $callback(...) : null;
        return $this;
    }


    /** Returns the output callback, or null if not set. */
    public function getOutputCallback(): ?Closure
    {
        return $this->output_callback;
    }


    /** Logs a message to the output callback, if registered. */
    protected function log(string $message): void
    {
        if ($this->output_callback) {
            ($this->output_callback)($message);
        }
    }


}
