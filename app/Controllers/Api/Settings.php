<?php

namespace App\Controllers\Api;

use App\Libraries\Prototype;

class Settings extends BaseApiController
{
    public function index()
    {
        return $this->json([
            'roles'      => Prototype::load('ROLES'),
            'entities'   => Prototype::load('ST_ENTITIES'),
            'segments'   => Prototype::load('ST_SEGMENTS'),
            'approvals'  => Prototype::load('ST_APPROVALS'),
            'users'      => Prototype::load('ST_USERS'),
            'audit'      => Prototype::load('ST_AUDIT'),
            'toggles'    => Prototype::load('ST_TOGGLES'),
            'sections'   => ['Organisation', 'Ledger', 'Segments', 'Approvals', 'Users', 'Audit log'],
        ]);
    }
}
