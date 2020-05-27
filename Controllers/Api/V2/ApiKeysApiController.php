<?php

namespace App\Http\Controllers\Api\V2;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\StationApi;
use App\Models\ControllerApiKeyRequest;
use App\Models\ChargingStation;
use App\Models\UserDevice;

class ApiKeysApiController extends ApiController
{
    use StationApi;
    
    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function postApiKey(Request $request)
    {
        $key = $this->getKey();
        $data = $this->getJson();
 
//        $data = [
//                "mac" => "5C:CF:7F:80:DD:9D", 
//                "model" => 1, 
//                "wattmeters" => 3, 
//                "relays" => 3, 
//                "tempdevices" => 3, 
//                "ssid" => "nasa", 
//                "rssi" => "-86", 
//                "fw" => 3, 
//                "millis" => 26178527
//        ];
        
        $required = ['mac' => 1, 'model' => 0, 'wattmeters' => 0, 'relays' => 0, 'tempdevices' => 0, 'ssid' => 0, 'rssi' => 0, 'fw' => 0, 'millis' => 0];
        foreach($required as $required_field => $required_data){
            if(!isset($data[$required_field])){
                return $this->apiErrorResponse('Missed required fields.');
            }
            if($required_data && empty($data[$required_field])){
                return $this->apiErrorResponse('Missed required fields data.');
            }
        }
        
        $api_key = $this->generateKey();
        $request = ControllerApiKeyRequest::create([
            'mac' => $data['mac'],
            'model' => $data['model'] ?? null,
            'api_key' => $api_key,
            'ip' => $_SERVER['REMOTE_ADDR'],
            'json' => json_encode($data)
        ]);
        if($request){
            UserDevice::where('code', $data['mac'])->update(['api_key' => $api_key]);

            return $this->apiResponse([
                'api_key' => $request->api_key,
                'mac' => $request->mac,
                'created_at' => (string)$request->created_at,
                'url' => route('charging-stations-create-form').'?key='.$request->api_key
            ]);
        }
        
        return $this->apiErrorResponse('Api key generation failed.');
    }
    
    /**
     * @return string
     */
    private function generateKey()
    {
        $api_key = Str::random(32);
        $stations = ChargingStation::where('api_key', $api_key)->count();
        $requests = ControllerApiKeyRequest::where('api_key', $api_key)->count();
        if(!$stations && !$requests){
            return $api_key;
        }else{
            return $this->generateKey();
        }
    }
}