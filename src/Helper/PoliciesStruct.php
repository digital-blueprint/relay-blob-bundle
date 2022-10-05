<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Helper;

/**
 * A Struct to save the policies of an bucket
 * create = POST is allowed
 * open = open with an docreader is enabled (e.g. nextcloud filesharing ohne write rights)
 * download = only file download
 * rename = rename is allowed
 * work = collab working is allowed
 *
 */
class PoliciesStruct
{
    /**
     * @var boolean
     */
    public $create = false;

    /**
     * @var boolean
     */
    public $open = false;

    /**
     * @var boolean
     */
    public $download = false;

    /**
     * @var boolean
     */
    public $rename = false;

    /**
     * @var boolean
     */
    public $work = false;


    /**
     * @param $create
     * @param $open
     * @param $download
     * @param $rename
     * @param $work
     * @return PoliciesStruct
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
