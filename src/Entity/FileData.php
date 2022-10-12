<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Entity;

use ApiPlatform\Core\Annotation\ApiProperty;
use ApiPlatform\Core\Annotation\ApiResource;
use Dbp\Relay\BlobBundle\Controller\CreateFileDataAction;
use Dbp\Relay\BlobBundle\Controller\DeleteFilesByPrefix;
use Dbp\Relay\BlobBundle\Controller\GetFilesByPrefix;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * @ORM\Entity
 * @ORM\Table(name="blob_files")
 * @ApiResource(
 *     collectionOperations={
 *         "post" = {
 *             "method" = "POST",
 *             "security" = "is_granted('IS_AUTHENTICATED_FULLY')",
 *             "path" = "/blob/files",
 *             "controller" = CreateFileDataAction::class,
 *             "deserialize" = false,
 *             "openapi_context" = {
 *                 "tags" = {"Blob"},
 *                 "requestBody" = {
 *                     "content" = {
 *                         "multipart/form-data" = {
 *                             "schema" = {
 *                                 "type" = "object",
 *                                 "properties" = {
 *                                     "file" = {"type" = "string", "format" = "binary"},
 *                                     "prefix" = {"description" = "Prefix of the file", "type" = "string", "example" = "my-prefix/my-subprefix"},
 *                                     "fileName" = {"description" = "Friendly name of the file", "type" = "string", "example" = "myfile"},
 *                                     "bucketID" = {"description" = "ID of the bucket", "type" = "string", "example" = "1234"},
 *                                     "retentionDuration" = {"description" = "Max time in timestamp duration format from creation date when file will be deleted", "type" = "integer", "format" = "int64", "example" = "00000000200000"},
 *                                     "additionalMetadata" = {"description" = "Additional Metadata for the file", "type" = "object", "example" = "{""myFileData"": ""my File additional Data""}"},
 *                                 },
 *                                 "required" = {"file", "bucketID"},
 *                             },
 *                         }
 *                     }
 *                 },
 *             },
 *         },
 *         "get_byPrefix" = {
 *             "method" = "GET",
 *             "security" = "is_granted('IS_AUTHENTICATED_FULLY')",
 *             "path" = "/blob/files",
 *             "pagination_client_partial" = true,
 *             "controller" = GetFilesByPrefix::class,
 *             "read" = false,
 *             "normalization_context" = {
 *                 "jsonld_embed_context" = true,
 *                 "groups" = {"BlobFiles:output"}
 *             },
 *             "openapi_context" = {
 *                 "tags" = {"Blob"},
 *                 "summary" = "Get the fileshares of a specific bucket with a specific prefix",
 *                 "parameters" = {
 *                     {"name" = "bucketID", "in" = "query", "description" = "Identifier of bucket", "type" = "string", "required" = true, "example" = "12345"},
 *                     {"name" = "prefix", "in" = "query", "description" = "Prefix of a file collection", "type" = "string", "required" = true, "example" = "my-path/my-subpath"}
 *                 }
 *             }
 *         },
 *         "delete_byPrefix" = {
 *             "method" = "DELETE",
 *             "security" = "is_granted('IS_AUTHENTICATED_FULLY')",
 *             "path" = "/blob/files",
 *             "controller" = DeleteFilesByPrefix::class,
 *             "read" = false,
 *             "normalization_context" = {
 *                 "jsonld_embed_context" = true,
 *                 "groups" = {"BlobFiles:output"}
 *             },
 *             "openapi_context" = {
 *                 "tags" = {"Blob"},
 *                 "summary" = "Deletes the files of a specific bucket with a specific prefix",
 *                 "parameters" = {
 *                     {"name" = "bucketID", "in" = "query", "description" = "Identifier of bucket", "type" = "string", "required" = true, "example" = "12345"},
 *                     {"name" = "prefix", "in" = "query", "description" = "Prefix of a file collection", "type" = "string", "required" = true, "example" = "my-path/my-subpath"}
 *                 }
 *             }
 *         }
 *     },
 *     itemOperations={
 *         "get" = {
 *             "security" = "is_granted('IS_AUTHENTICATED_FULLY')",
 *             "path" = "/blob/files/{identifier}",
 *             "openapi_context" = {
 *                 "tags" = {"Blob"},
 *             },
 *         },
 *         "put" = {
 *             "security" = "is_granted('IS_AUTHENTICATED_FULLY')",
 *             "path" = "/blob/files/{identifier}",
 *             "openapi_context" = {
 *                 "tags" = {"Blob"},
 *             },
 *         },
 *         "delete" = {
 *             "security" = "is_granted('IS_AUTHENTICATED_FULLY')",
 *             "path" = "/blob/files/{identifier}",
 *             "openapi_context" = {
 *                 "tags" = {"Blob"},
 *             },
 *         }
 *     },
 *     iri="https://schema.org/Entity",
 *     shortName="BlobFiles",
 *     normalizationContext={
 *         "groups" = {"BlobFiles:output"},
 *         "jsonld_embed_context" = true
 *     },
 *     denormalizationContext={
 *         "groups" = {"BlobFiles:input"},
 *         "jsonld_embed_context" = true
 *     }
 * )
 */
class FileData
{
    /**
     * @ORM\Id
     * @ORM\Column(type="string", length=50)
     *
     * @ApiProperty(identifier=true)
     * @Groups({"BlobFiles:output", "BlobFiles:input"})
     */
    private $identifier;

    /**
     * @ORM\Column(type="string", length=255)
     * @ApiProperty(iri="https://schema.org/Text")
     * @Groups({"BlobFiles:output", "BlobFiles:input"})
     *
     * @var string
     */
    private $prefix;

    /**
     * @ORM\Column(type="string", length=50)
     * @ApiProperty(iri="https://schema.org/name")
     * @Groups({"BlobFiles:output", "BlobFiles:input"})
     *
     * @var string
     */
    private $fileName;

    /**
     * @ORM\Column(type="string", length=50)
     * @ApiProperty(iri="https://schema.org/identifier")
     * @Groups({"BlobFiles:input"})
     *
     * @var string
     */
    private $bucketID;

    /**
     * @var Bucket
     */
    private $bucket;

    /**
     * @ORM\Column(type="datetime_immutable")
     * @ApiProperty(iri="https://schema.org/dateCreated")
     * @Groups({"BlobFiles:output"})
     *
     * @var \DateTimeImmutable
     */
    private $dateCreated;

    /**
     * @ORM\Column(type="datetime_immutable")
     * @ApiProperty(iri="https://schema.org/dateRead")
     * @Groups({"BlobFiles:output"})
     *
     * @var \DateTimeImmutable
     */
    private $lastAccess;

    /**
     * @ORM\Column(type="string", length=50)
     * @ApiProperty(iri="https://schema.org/duration")
     * @Groups({"BlobFiles:output", "BlobFiles:input"})
     *
     * @var string
     */
    private $retentionDuration;

    /**
     * @ORM\Column(type="string", length=255)
     * @ApiProperty(iri="https://schema.org/url")
     * @Groups({"BlobFiles:output"})
     *
     * @var string
     */
    private $contentUrl;

    /**
     * @Groups({"BlobFiles:input"})
     *
     * @var resource
     */
    private $file;

    /**
     * @ORM\Column(type="text")
     * @ApiProperty(iri="https://schema.org/DataFeed")
     * @Groups({"BlobFiles:output", "BlobFiles:input"})
     *
     * @var string
     */
    private $additionalMetadata;

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function setIdentifier(string $identifier): void
    {
        $this->identifier = $identifier;
    }

    public function getFileName(): string
    {
        return $this->fileName;
    }

    public function setFileName(string $fileName): void
    {
        $this->fileName = $fileName;
    }

    public function getBucketID(): string
    {
        return $this->bucketID;
    }

    public function setBucketID(string $bucketID): void
    {
        $this->bucketID = $bucketID;
    }

    public function getBucket(): Bucket
    {
        return $this->bucket;
    }

    public function setBucket(Bucket $bucket): void
    {
        $this->bucket = $bucket;
    }

    public function getDateCreated(): \DateTimeImmutable
    {
        return $this->dateCreated;
    }

    public function setDateCreated(\DateTimeImmutable $dateCreated): void
    {
        $this->dateCreated = $dateCreated;
    }

    public function getLastAccess(): \DateTimeImmutable
    {
        return $this->lastAccess;
    }

    public function setLastAccess(\DateTimeImmutable $lastAccess): void
    {
        $this->lastAccess = $lastAccess;
    }

    public function getRetentionDuration(): string
    {
        return $this->retentionDuration;
    }

    public function setRetentionDuration(string $retentionDuration): void
    {
        $this->retentionDuration = $retentionDuration;
    }

    public function getContentUrl(): string
    {
        return $this->contentUrl;
    }

    public function setContentUrl(string $contentUrl): void
    {
        $this->contentUrl = $contentUrl;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function setPrefix(string $prefix): void
    {
        $this->prefix = $prefix;
    }

    public function getFile()
    {
        return $this->file;
    }

    public function setFile($file): void
    {
        $this->file = $file;
    }

    public function getAdditionalMetadata(): string
    {
        return $this->additionalMetadata;
    }

    public function setAdditionalMetadata($additionalMetadata): void
    {
        $this->additionalMetadata = $additionalMetadata;
    }
}
