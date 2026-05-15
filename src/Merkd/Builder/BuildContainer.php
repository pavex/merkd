<?php

/**
 * Composition root for the build pipeline.
 *
 * @author    pavex@ines.cz
 * @copyright 2026 Pavel Macháček
 * @license   MIT
 * @package   Merkd\Builder
 */

namespace Merkd\Builder;

use Merkd\Builder\Content\ContentBuilder;
use Merkd\Builder\Datastore\AssetDatastoreInterface;
use Merkd\Builder\Datastore\AssetPdoDatastore;
use Merkd\Builder\Datastore\BuildDatastoreInterface;
use Merkd\Builder\Datastore\BuildPdoDatastore;


class BuildContainer
{

    private Config $config;
    private ?BuildDatastoreInterface $buildDatastore = null;
    private ?AssetDatastoreInterface $assetDatastore = null;
    private ?ContentBuilder $contentBuilder = null;


    public function __construct(Config $config)
    {
        $this->config = $config;
    }


    public function getConfig(): Config
    {
        return $this->config;
    }


    public function getBuildDatastore(): BuildDatastoreInterface
    {
        return $this->buildDatastore ??= new BuildPdoDatastore($this->config->db_path);
    }


    public function getAssetDatastore(): AssetDatastoreInterface
    {
        return $this->assetDatastore ??= new AssetPdoDatastore($this->config->db_path);
    }


    public function getContentBuilder(): ContentBuilder
    {
        return $this->contentBuilder ??= new ContentBuilder(
            $this->getBuildDatastore(),
            $this->getAssetDatastore(),
            $this->getConfig()
        );
    }


}
