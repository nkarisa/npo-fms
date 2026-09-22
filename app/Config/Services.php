<?php

namespace Config;

use CodeIgniter\Config\BaseService;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 *
 * This file holds any application-specific services, or service overrides
 * that you might need. An example has been included with the general
 * method format you should use for your service methods. For more examples,
 * see the core Services file at system/Config/Services.php.
 */
class Services extends BaseService
{
    /**
     * Where supporting documents are kept (Config\Documents::$disk): the server's
     * disk, or an S3 bucket with Object Lock.
     */
    public static function documents(bool $getShared = true): \App\Libraries\Storage\DocumentStore
    {
        if ($getShared) {
            return static::getSharedInstance('documents');
        }
        $config = config(Documents::class);

        return match ($config->disk) {
            'local' => new \App\Libraries\Storage\LocalStore(),
            's3'    => \App\Libraries\Storage\S3Store::fromConfig($config),
            default => throw new \RuntimeException('documents.disk must be local or s3, not ' . $config->disk . '.'),
        };
    }

    /*
     * public static function example($getShared = true)
     * {
     *     if ($getShared) {
     *         return static::getSharedInstance('example');
     *     }
     *
     *     return new \CodeIgniter\Example();
     * }
     */
}
