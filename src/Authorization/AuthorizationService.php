<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Authorization;

use Dbp\Relay\BlobBundle\DependencyInjection\Configuration;
use Dbp\Relay\CoreBundle\Authorization\AbstractAuthorizationService;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class AuthorizationService extends AbstractAuthorizationService
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Throws if the user doesnt have appropriate role.
     */
    public function checkCanRoleAccessMetadataBackup(): void
    {
        $this->denyAccessUnlessIsGrantedRole(Configuration::ROLE_METADATABACKUPS);
    }

    /**
     * Returns if the current user has permissions access.
     */
    private function getCanAccessBucket(string $bucketName): bool
    {
        $resource = new BucketData($bucketName);

        return $this->isGrantedResourcePermission(Configuration::ROLE_PROFILE_METADATABACKUPS, $resource);
    }

    /**
     * Throws if the current user doesn't have permissions to sign with any qualified profile.
     */
    public function checkCanAccessMetadataBackup(string $bucketName): void
    {
        $this->checkCanRoleAccessMetadataBackup();
        if (!$this->getCanAccessBucket($bucketName)) {
            throw new AccessDeniedException();
        }
    }
}
