<?php

namespace App\Http\Controllers\Api\OCPP\V1p6;

use Illuminate\Http\Request;
use App\Http\Controllers\Api\ApiController;
use Carbon\Carbon;

class OCPPController extends ApiController
{
    
    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonRespons
     */
    public function info(Request $request, $erg = null)
    {
        file_put_contents(storage_path('logs/ocpp_info.log'), $request, FILE_APPEND);
        
        return $this->apiResponse([]);
    }
    
}