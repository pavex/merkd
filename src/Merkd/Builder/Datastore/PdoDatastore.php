<?php

/**
 * Abstract PDO-backed datastore base for Merkd Builder.
 *
 * Extends Merkd\Datastore\AbstractPdoDatastore — connection setup lives there.
 *
 * @author    pavex@ines.cz
 * @copyright 2026 Pavel Macháček
 * @license   MIT
 * @package   Merkd\Builder\Datastore
 */

namespace Merkd\Builder\Datastore;

use Merkd\Datastore\AbstractPdoDatastore;


abstract class PdoDatastore extends AbstractPdoDatastore
{
}
