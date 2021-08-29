<?php

declare(strict_types=1);

namespace SimpleSAML\Metadata;

use SimpleSAML\Assert\Assert;
use SimpleSAML\Configuration;
use SimpleSAML\Logger;
use SimpleSAML\Utils;

/**
 * Class for handling metadata files in serialized format.
 *
 * @package SimpleSAMLphp
 */

class MetaDataStorageHandlerSerialize extends MetaDataStorageSource
{
    /**
     * The file extension we use for our metadata files.
     *
     * @var string
     */
    public const EXTENSION = '.serialized';

    /**
     * The base directory where metadata is stored.
     *
     * @var string
     */
    private string $directory = '/';

    /** \SimpleSAML\Logger */
    private Logger $logger;


    /**
     * Constructor for this metadata handler.
     *
     * Parses configuration.
     *
     * @param array $config The configuration for this metadata handler.
     */
    public function __construct(array $config)
    {
        $globalConfig = Configuration::getInstance();
        $this->logger = Logger::getInstance();

        $cfgHelp = Configuration::loadFromArray($config, 'serialize metadata source');

        $this->directory = $cfgHelp->getString('directory');

        /* Resolve this directory relative to the SimpleSAMLphp directory (unless it is
         * an absolute path).
         */
        $sysUtils = new Utils\System();
        $this->directory = $sysUtils->resolvePath($this->directory, $globalConfig->getBaseDir());
    }


    /**
     * Helper function for retrieving the path of a metadata file.
     *
     * @param string $entityId The entity ID.
     * @param string $set The metadata set.
     *
     * @return string The path to the metadata file.
     */
    private function getMetadataPath(string $entityId, string $set): string
    {
        return $this->directory . '/' . rawurlencode($set) . '/' . rawurlencode($entityId) . self::EXTENSION;
    }


    /**
     * Retrieve a list of all available metadata sets.
     *
     * @return array An array with the available sets.
     */
    public function getMetadataSets(): array
    {
        $ret = [];

        $dh = @opendir($this->directory);
        if ($dh === false) {
            $this->logger->warning(
                'Serialize metadata handler: Unable to open directory: ' . var_export($this->directory, true)
            );
            return $ret;
        }

        while (($entry = readdir($dh)) !== false) {
            if ($entry[0] === '.') {
                // skip '..', '.' and hidden files
                continue;
            }

            $path = $this->directory . '/' . $entry;

            if (!is_dir($path)) {
                $this->logger->warning(
                    'Serialize metadata handler: Metadata directory contained a file where only directories should ' .
                    'exist: ' . var_export($path, true)
                );
                continue;
            }

            $ret[] = rawurldecode($entry);
        }

        closedir($dh);

        return $ret;
    }


    /**
     * Retrieve a list of all available metadata for a given set.
     *
     * @param string $set The set we are looking for metadata in.
     *
     * @return array An associative array with all the metadata for the given set.
     */
    public function getMetadataSet(string $set): array
    {
        $ret = [];

        $dir = $this->directory . '/' . rawurlencode($set);
        if (!is_dir($dir)) {
            // probably some code asked for a metadata set which wasn't available
            return $ret;
        }

        $dh = @opendir($dir);
        if ($dh === false) {
            $this->logger->warning(
                'Serialize metadata handler: Unable to open directory: ' . var_export($dir, true)
            );
            return $ret;
        }

        $extLen = strlen(self::EXTENSION);

        while (($file = readdir($dh)) !== false) {
            if (strlen($file) <= $extLen) {
                continue;
            }

            if (substr($file, -$extLen) !== self::EXTENSION) {
                continue;
            }

            $entityId = substr($file, 0, -$extLen);
            $entityId = rawurldecode($entityId);

            $md = $this->getMetaData($entityId, $set);
            if ($md !== null) {
                $ret[$entityId] = $md;
            }
        }

        closedir($dh);

        return $ret;
    }


    /**
     * Retrieve a metadata entry.
     *
     * @param string $entityId The entityId we are looking up.
     * @param string $set The set we are looking for metadata in.
     *
     * @return array|null An associative array with metadata for the given entity, or NULL if we are unable to
     *         locate the entity.
     */
    public function getMetaData(string $entityId, string $set): ?array
    {
        $filePath = $this->getMetadataPath($entityId, $set);

        if (!file_exists($filePath)) {
            return null;
        }

        $data = @file_get_contents($filePath);
        if ($data === false) {
            /** @var array $error */
            $error = error_get_last();
            $this->logger->warning(
                'Error reading file ' . $filePath . ': ' . $error['message']
            );
            return null;
        }

        $data = @unserialize($data);
        if ($data === false) {
            $this->logger->warning('Error unserializing file: ' . $filePath);
            return null;
        }

        if (!array_key_exists('entityid', $data)) {
            $data['entityid'] = $entityId;
        }

        return $data;
    }


    /**
     * Save a metadata entry.
     *
     * @param string $entityId The entityId of the metadata entry.
     * @param string $set The metadata set this metadata entry belongs to.
     * @param array $metadata The metadata.
     *
     * @return bool True if successfully saved, false otherwise.
     */
    public function saveMetadata(string $entityId, string $set, array $metadata): bool
    {
        $filePath = $this->getMetadataPath($entityId, $set);
        $newPath = $filePath . '.new';

        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            $this->logger->info('Creating directory: ' . $dir);
            $res = @mkdir($dir, 0777, true);
            if ($res === false) {
                /** @var array $error */
                $error = error_get_last();
                $this->logger->error('Failed to create directory ' . $dir . ': ' . $error['message']);
                return false;
            }
        }

        $data = serialize($metadata);

        $this->logger->debug('Writing: ' . $newPath);

        $res = file_put_contents($newPath, $data);
        if ($res === false) {
            /** @var array $error */
            $error = error_get_last();
            $this->logger->error('Error saving file ' . $newPath . ': ' . $error['message']);
            return false;
        }

        $res = rename($newPath, $filePath);
        if ($res === false) {
            /** @var array $error */
            $error = error_get_last();
            $this->logger->error('Error renaming ' . $newPath . ' to ' . $filePath . ': ' . $error['message']);
            return false;
        }

        return true;
    }


    /**
     * Delete a metadata entry.
     *
     * @param string $entityId The entityId of the metadata entry.
     * @param string $set The metadata set this metadata entry belongs to.
     */
    public function deleteMetadata(string $entityId, string $set): void
    {
        $filePath = $this->getMetadataPath($entityId, $set);

        if (!file_exists($filePath)) {
            $this->logger->warning(
                'Attempted to erase nonexistent metadata entry ' .
                var_export($entityId, true) . ' in set ' . var_export($set, true) . '.'
            );
            return;
        }

        $res = unlink($filePath);
        if ($res === false) {
            /** @var array $error */
            $error = error_get_last();
            $this->logger->error(
                'Failed to delete file ' . $filePath .
                ': ' . $error['message']
            );
        }
    }


    /**
     * This function loads the metadata for entity IDs in $entityIds. It is returned as an associative array
     * where the key is the entity id. An empty array may be returned if no matching entities were found
     * @param array $entityIds The entity ids to load
     * @param string $set The set we want to get metadata from.
     * @return array An associative array with the metadata for the requested entities, if found.
     */
    public function getMetaDataForEntities(array $entityIds, string $set): array
    {
        return $this->getMetaDataForEntitiesIndividually($entityIds, $set);
    }
}
