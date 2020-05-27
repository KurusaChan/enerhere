<?php

namespace App\Http\Controllers\Api\V2;

use Illuminate\Http\Request;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\StationApi;
use App\Models\ChargingStation;
use App\Models\ChargingStationStatus;

class StationApiController extends ApiController
{
    use StationApi;
    
    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonRespons
     */
    public function postInfo(Request $request)
    {
        $key = $this->getKey();
        $data = $this->getJson();
        
        if(env('APP_DEBUG')){
            $key = 'asdasdsuper'; // 42
            $data = [
                "device" => [
                    "mac" => "5C:CF:7F:80:DD:7C", 
                    "wattmeters" => 3, 
                    "relays" => 3, 
                    "tempdevices" => 3, 
                    "ssid" => "nasa", 
                    "rssi" => "-86", 
                    "fw" => 3, 
                    "millis" => 26178527
                ],
                "wattmeter" => [
                    [
                        ["v" => rand(206.01, 240.99), "i" => 20.00, "p" => 5000.00, "e" => 1800.00],
                        ["v" => -1, "i" => -1, "p" => -1, "e" => -1],
                        ["v" => -1, "i" => -1, "p" => -1, "e" => -1]
                    ],
                    [
                        ["v" => rand(206.01, 240.99), "i" => 0.00, "p" => 0.00, "e" => 105.00],
                        ["v" => rand(206.01, 240.99), "i" => 0.00, "p" => 0.00, "e" => 105.00],
                        ["v" => rand(206.01, 240.99), "i" => 0.00, "p" => 0.00, "e" => 50.00],
                    ],
                    [
                        ["v" => rand(206.01, 240.99), "i" => 0.00, "p" => 0.00, "e" => 10.00],
                        ["v" => rand(206.01, 240.99), "i" => 0.00, "p" => 0.00, "e" => 10.00],
                        ["v" => rand(206.01, 240.99), "i" => 0.00, "p" => 0.00, "e" => 5.00],
                    ]
                ],
                "temp" => [15, 13, 17],
                "relays" => [0, 0, 0]
            ];
        }
        
        preg_match_all("/(ESP[0-9+]{2,})/", $request->header('user-agent'), $agent);
        $controller = $agent[0][0] ?? null;
        
        $station = $this->getStationByApiKey($key);
        
        if($key && $data && $station && empty($data['ro'])){
            $r_prev_info = [
                1 => null, 
                2 => null, 
                3 => null
            ];
            $r_prev_info[1] = $this->getPrevInfo($station['id'], 1);
            $r_prev_info[2] = $this->getPrevInfo($station['id'], 2);
            $r_prev_info[3] = $this->getPrevInfo($station['id'], 3);
            
            $info = [
                'broken' => null,
                'r_1_broken' => null,
                'r_2_broken' => null,
                'r_3_broken' => null,
                'temp_1' => null,
                'temp_2' => null,
                'temp_3' => null,
                'charging_station_id' => $station['id'] ?? null,
                'api_key' => $key,
                'mac' => $data['device']['mac'] ?? null, 
                'model' => $data['device']['model'] ?? null, 
                'wattmeters' => $data['device']['wattmeters'] ?? null, 
                'relays' => $data['device']['relays'] ?? null, 
                'temp_devices' => $data['device']['tempdevices'] ?? null, 
                'ssid' => $data['device']['ssid'] ?? null, 
                'rssi' => $data['device']['rssi'] ?? null, 
                'fw' => $data['device']['fw'] ?? null,
                'controller' => $controller,
                'json' => json_encode($data)
            ];
            
            $values = [];
            for($r = 0; $r < 3; $r ++){
                $r_i = $r + 1;
                for($f = 0; $f < 3; $f ++){
                    $f_i = $f + 1;
                    $values[$r_i]['u_'.$f_i] = $data['wattmeter'][$r][$f]['v'] >= 0 ? (float)$data['wattmeter'][$r][$f]['v'] : null;
                    $values[$r_i]['i_'.$f_i] = $data['wattmeter'][$r][$f]['i'] >= 0 ? (float)$data['wattmeter'][$r][$f]['i'] : null; 
                    $values[$r_i]['p_'.$f_i] = $data['wattmeter'][$r][$f]['p'] >= 0 ? (float)$data['wattmeter'][$r][$f]['p'] : null;
                    $values[$r_i]['e_'.$f_i] = $data['wattmeter'][$r][$f]['e'] >= 0 ? (float)$data['wattmeter'][$r][$f]['e'] : null;
                }
                
                $info['temp_'.$r_i] = isset($data['temp'][$r]) ? ($data['temp'][$r] > 65535 ? 65535 : (int)$data['temp'][$r]) : null;
            }
            
            $meter_values = [];
            foreach($station->ports as $port){
                $phases = [
                    'e_1_reseted' => null,
                    'e_2_reseted' => null,
                    'e_3_reseted' => null,
                    'temp' => $info['temp_'.$port->relay_num]
                ] + $values[$port->relay_num];
            
                $prev_info = $r_prev_info[$port->relay_num] ? (array)$r_prev_info[$port->relay_num] : null;
                
                // check infos
                $info = $this->checkInfo($info, $prev_info, $phases, $port);
                
                $meter_values[] = [
                    'relay_num' => $port->relay_num
                ] + $phases;
            }
            
            $info = $this->checkBrokenInfo($info, $station);
            
            $this->saveInfoWithMeterValues($info, $meter_values);
        }
        
        $response = $this->makeInfoResponse($station);
        
        return $this->apiResponse($response);
    }
    
    /**
     * @param ChargingStation $station
     * @return array
     */
    private function makeInfoResponse(ChargingStation $station)
    {
        $response = array();
        
        $response['relay1'] = 0;
        $response['relay2'] = 0;
        $response['relay3'] = 0;
        if($station){
            foreach($station->connections as $connection){
                $response['relay'.$connection->port->relay_num] = 1;
            }
        }
        
        $response['protectReconnect'] = !empty($station['protect_reconnect']) ? 1 : 0;
        $response['maxCurrent1'] = "";
        $response['maxCurrent2'] = "";
        $response['maxCurrent3'] = "";
        foreach($station->ports as $port){
            $response['maxCurrent'.$port->relay_num] = $port->max_amperage;
            $response['maxCurrent'.$port->relay_num] = $port->max_amperage;
            $response['maxCurrent'.$port->relay_num] = $port->max_amperage;
        }
        
        $command = !empty($station['command']) ? json_decode($station['command'], true) : [];
        if(!empty($command)){
            $station->update(['command' => null]);
        }
        $response['command'] = $command;
        // для совместимости
        $response['updateFile'] = $response['link'] = (!empty($command['fwLink']) ? $command['fwLink'] : '');
        
        return $response;
    }
    
    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonRespons
     */
    public function postStatus(Request $request)
    {
        $response = array();
        
        $key = $this->getKey();
        $data = $this->getJson();
        
//        $key = 'asdasdsuper'; // 42
//        $data = [
//            "device" => [
//                "mac" => "5C:CF:7F:80:DD:7C", 
//                "ssid" => "nasa", 
//                "rssi" => "-86", 
//                "fw" => 3, 
//                "millis" => 26178527
//            ],
//            "currentStatus" => "maxCurrentExceeded",
////            "currentStatus" => "Available",
////            "currentStatus" => "Unavailable",
////            "currentStatus" => "Preparing",
//            "currentData" => "current data...",
//            "port" => 2,
//            "current" => 50
//        ];
        
        $station = ChargingStation::where('api_key', $key)
                                  ->select('id')->first();
        
        if($station && $data){
            $current_data = $data['currentData'] ?? null;
            $status_data = [];
            
            switch($data['currentStatus']){
                case 'maxCurrentExceeded':
                    if(isset($data['port'])){
                        $status_data['relay_num'] = (int)$data['port'];
                    }
                    if(isset($data['current'])){
                        $status_data['current'] = (float)$data['current'];
                    }
                break;
                case 'Available':
                case 'Preparing':
                case 'Unavailable':
                    if(isset($data['port'])){
                        $status_data['relay_num'] = (int)$data['port'];
                    }
                break;
            }
            ChargingStationStatus::create([
                'charging_station_id' => $station['id'] ?? null,
                'api_key' => $key,
                
                'mac' => $data['device']['mac'] ?? null, 
                'ssid' => $data['device']['ssid'] ?? null, 
                'rssi' => $data['device']['rssi'] ?? null, 
                'fw' => $data['device']['fw'] ?? null,
                'millis' => $data['device']['millis'] ?? null,
                
                'status' => $data['currentStatus'] ?? null,
                'status_data' => $status_data ? json_encode($status_data) : null,
                'current_data' => $current_data,
                
                'json' => $request->input('json', '{}')
            ]);
        }
        
        return $this->apiResponse($response);
    }
    
}