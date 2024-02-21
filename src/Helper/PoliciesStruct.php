<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Helper;

/**
 * A Struct to save the policies of an bucket
 * create = POST is allowed
 * open = open with an docreader is enabled (e.g. nextcloud filesharing ohne write rights)
 * download = only file download
 * rename = rename is allowed
 * work = collab working is allowed.
 */
class PoliciesStruct
{
    /**
     * @var bool
     */
    public $create = false;

    /**
     * @var bool
     */
    public $open = false;

    /**
     * @var bool
     */
    public $download = false;

    /**
     * @var bool
     */
    public $rename = false;

    /**
     * @var bool
     */
    public $work = false;

    /**
     * @var bool
     */
    public $delete = false;

    public static function withPolicies($create, $delete, $open, $download, $rename, $work): PoliciesStruct
    {
        $instance = new self();
        $instance->create = $create;
        $instance->delete = $delete;
        $instance->open = $open;
        $instance->download = $download;
        $instance->rename = $rename;
        $instance->work = $work;

        return $instance;
    }

    public static function withPoliciesArray($policies): PoliciesStruct
    {
        $instance = new self();
        $instance->create = $policies['create'];
        $instance->delete = $policies['delete'];
        $instance->open = $policies['open'];
        $instance->download = $policies['download'];
        $instance->rename = $policies['rename'];
        $instance->work = $policies['work'];

        return $instance;
    }
}
