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
     * @param $create
     * @param $open
     * @param $download
     * @param $rename
     * @param $work
     */
    public static function withPolicies($create, $open, $download, $rename, $work): PoliciesStruct
    {
        $instance = new self();
        $instance->create = $create;
        $instance->open = $open;
        $instance->download = $download;
        $instance->rename = $rename;
        $instance->work = $work;

        return $instance;
    }
}
