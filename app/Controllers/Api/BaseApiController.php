<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;

/** Common JSON response helper for the prototype-data API endpoints. */
abstract class BaseApiController extends BaseController
{
    protected function json(array $data)
    {
        return $this->response->setJSON($data);
    }
}
