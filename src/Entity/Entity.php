<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Entity;

use ApiPlatform\Core\Annotation\ApiProperty;
use ApiPlatform\Core\Annotation\ApiResource;
use Dbp\Relay\BlobBundle\Controller\LoggedInOnly;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * @ApiResource(
 *     collectionOperations={
 *         "get" = {
 *             "path" = "/blob/entitys",
 *             "openapi_context" = {
 *                 "tags" = {"Blob"},
 *             },
 *         }
 *     },
 *     itemOperations={
 *         "get" = {
 *             "path" = "/blob/entitys/{identifier}",
 *             "openapi_context" = {
 *                 "tags" = {"Blob"},
 *             },
 *         },
 *         "put" = {
 *             "path" = "/blob/entitys/{identifier}",
 *             "openapi_context" = {
 *                 "tags" = {"Blob"},
 *             },
 *         },
 *         "delete" = {
 *             "path" = "/blob/entitys/{identifier}",
 *             "openapi_context" = {
 *                 "tags" = {"Blob"},
 *             },
 *         },
 *         "loggedin_only" = {
 *             "security" = "is_granted('IS_AUTHENTICATED_FULLY')",
 *             "method" = "GET",
 *             "path" = "/blob/entitys/{identifier}/loggedin-only",
 *             "controller" = LoggedInOnly::class,
 *             "openapi_context" = {
 *                 "summary" = "Only works when logged in.",
 *                 "tags" = {"Blob"},
 *             },
 *         }
 *     },
 *     iri="https://schema.org/Entity",
 *     shortName="BlobEntity",
 *     normalizationContext={
 *         "groups" = {"BlobEntity:output"},
 *         "jsonld_embed_context" = true
 *     },
 *     denormalizationContext={
 *         "groups" = {"BlobEntity:input"},
 *         "jsonld_embed_context" = true
 *     }
 * )
 */
class Entity
{
    /**
     * @ApiProperty(identifier=true)
     */
    private $identifier;

    /**
     * @ApiProperty(iri="https://schema.org/name")
     * @Groups({"BlobEntity:output", "BlobEntity:input"})
     *
     * @var string
     */
    private $name;

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function setIdentifier(string $identifier): void
    {
        $this->identifier = $identifier;
    }
}
