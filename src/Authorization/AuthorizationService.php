<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Authorization;

use Dbp\Relay\BlobBundle\DependencyInjection\Configuration;
use Dbp\Relay\CoreBundle\Authorization\AbstractAuthorizationService;

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
        $this->denyAccessUnlessIsGrantedRole(Configuration::ROLE_METADATA_BACKUP_AND_RESTORE);
    }

    /**
     * Throws if the current user doesn't have permissions to sign with any qualified profile.
     */
    public function checkCanAccessMetadataBackup(): void
    {
        $this->checkCanRoleAccessMetadataBackup();
    }

    public function getCanUse(): bool
    {
        return $this->isGrantedRole(Configuration::ROLE_METADATA_BACKUP_AND_RESTORE);
    }
}
