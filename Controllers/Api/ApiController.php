<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ApiController extends Controller
{
    function __construct(Request $request)
    {
        $this->request = $request;
    }
    
    /**
     * @return string
     */
    protected function getKey()
    {
        $key = $this->request->input('apikey', null);
//        if(empty($key)){
//            return $this->apiErrorResponse('apikey required');
//        }
        return $key;
    }
    
    /**
     * @return array
     */
    protected function getJson()
    {
        $json = $this->request->input('json', "{}");
        $data = json_decode($json, true);
//        if(empty($data)){
//            return $this->apiErrorResponse('json required');
//        }
        return $data;
    }
    
    /**
     * @param string $call_url
     * @return \Illuminate\Http\JsonRespons
     */
    public function anyCall($call_url)
    {
        return $this->apiErrorResponse('Api url not found');
    }
    
    /**
     * @param string $message
     * @return \Illuminate\Http\JsonRespons
     */
    protected function apiErrorResponse($message)
    {
        return response()->json([
            'error' => [
                'message' => $message,
                'url' => $this->request->getRequestUri(),
                'method' => $this->request->getMethod()
            ]
        ]);
    }
    
    /**
     * @param array $data
     * @return \Illuminate\Http\JsonRespons
     */
    protected function apiResponse($data)
    {
        return response()->json($data);
    }
    
}
