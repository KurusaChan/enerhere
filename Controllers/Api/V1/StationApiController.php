<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\StationApi;
use App\Models\ChargingStationStatus;
use App\Models\ChargingStation;

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
////        $key = 'ewrwerwerlimiter31'; // 31 limiter 3ф
//        $key = 'asdasdasdasd12'; // 12 1ф 3п
////        $key = 'JKHGffef3fJFDESFkDLDC'; // 14 1ф 1п Без Контактора
////        $key = '1212121212'; // 17 3ф 3п*
////        $key = 'asd'; // 33 3ф 1п
//        $json = '{"device": {"mac": "5C:CF:7F:80:DD:7C", "wattmeters": 3, "relays": 1, "ssid": "nasa", "rssi": "-86", "fw": 3, "millis": 261785271, "tempdevices": 3},'
//               .'"wattmeter": ['
////                             .'{"v": -1, "i": -1, "p": -1, "e": -1},'
////                             .'{"v": -1, "i": -1, "p": -1, "e": -1},'
////                             .'{"v": -1, "i": -1, "p": -1, "e": -1}'
////                             .'{"v": '.rand(216.01, 240.99).', "i": 0.00, "p": 0.00, "e": -1},'
//                             .'{"v": '.rand(216.01, 240.99).', "i": 0.00, "p": 1230.00, "e": 530.00},'
//                             .'{"v": '.rand(216.01, 240.99).', "i": 0.00, "p": 0.00, "e": 24559.00},'
//                             .'{"v": '.rand(216.01, 240.99).', "i": 0.00, "p": 0.00, "e": 20336.00}'
//                            .'],'
//               .'"temp": ['.rand(8, 15).','.rand(8, 15).','.rand(8, 15).'],'
//               .'"relays": [0]}';
//        $data = json_decode($json, true);
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
                $values['u_'.$r_i] = $data['wattmeter'][$r]['v'] >= 0 ? (float)$data['wattmeter'][$r]['v'] : null;
                $values['i_'.$r_i] = $data['wattmeter'][$r]['i'] >= 0 ? (float)$data['wattmeter'][$r]['i'] : null; 
                $values['p_'.$r_i] = $data['wattmeter'][$r]['p'] >= 0 ? (float)$data['wattmeter'][$r]['p'] : null;
                $values['e_'.$r_i] = $data['wattmeter'][$r]['e'] >= 0 ? (float)$data['wattmeter'][$r]['e'] : null;
                $info['temp_'.$r_i] = isset($data['temp'][$r]) ? ($data['temp'][$r] > 65535 ? 65535 : (int)$data['temp'][$r]) : null;
            }
            
            $meter_values = [];
            foreach($station->ports as $port){
                $phases = [
                    'e_1_reseted' => null,
                    'e_2_reseted' => null,
                    'e_3_reseted' => null,
                ];
                // 220
                if($port->is1PhasePort){
                    $phases['u_1'] = $values['u_'.$port->relay_num];
                    $phases['i_1'] = $values['i_'.$port->relay_num];
                    $phases['p_1'] = $values['p_'.$port->relay_num];
                    $phases['e_1'] = $values['e_'.$port->relay_num];
                    $phases['u_2'] = null;
                    $phases['i_2'] = null;
                    $phases['p_2'] = null;
                    $phases['e_2'] = null;
                    $phases['u_3'] = null;
                    $phases['i_3'] = null;
                    $phases['p_3'] = null;
                    $phases['e_3'] = null;
                    $phases['temp'] = $info['temp_'.$port->relay_num];
                }
                // 380
                if($port->is3PhasePort){
                    // записать все 3 ватметра в один relay_num
                    $phases['u_1'] = $values['u_1'];
                    $phases['i_1'] = $values['i_1'];
                    $phases['p_1'] = $values['p_1'];
                    $phases['e_1'] = $values['e_1'];
                    $phases['u_2'] = $values['u_2'];
                    $phases['i_2'] = $values['i_2'];
                    $phases['p_2'] = $values['p_2'];
                    $phases['e_2'] = $values['e_2'];
                    $phases['u_3'] = $values['u_3'];
                    $phases['i_3'] = $values['i_3'];
                    $phases['p_3'] = $values['p_3'];
                    $phases['e_3'] = $values['e_3'];
                    $phases['temp'] = max([$info['temp_1'], $info['temp_2'], $info['temp_3']]);
                }
                // фейк 380 *
                if($port->is3PhaseXPort){
                    // записать 3 раза по relay_num
                    $phases['u_1'] = $phases['u_2'] = $phases['u_3'] = $values['u_'.$port->relay_num] * 1.7273;
                    $phases['i_1'] = $phases['i_2'] = $phases['i_3'] = $values['i_'.$port->relay_num];
                    $phases['p_1'] = $phases['p_2'] = $phases['p_3'] = $values['p_'.$port->relay_num];
                    $phases['e_1'] = $phases['e_2'] = $phases['e_3'] = $values['e_'.$port->relay_num];
                    $phases['temp'] = $info['temp_'.$port->relay_num];
                }
            
                $prev_info = $prev_info = $r_prev_info[$port->relay_num] ? (array)$r_prev_info[$port->relay_num] : null;
                
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
                // конекшн на трехфазном порту - отдать всем реле вкл
                if($connection->port->is3PhasePort){
                    $response['relay1'] = 1;
                    $response['relay2'] = 1;
                    $response['relay3'] = 1;
                }else{
                    $response['relay'.$connection->port->relay_num] = 1;
                }
            }
        }
        
        $response['protectReconnect'] = !empty($station['protect_reconnect']) ? 1 : 0;
        $response['maxCurrent'] = !empty($station['ports'][0]) ? $station->ports[0]->max_amperage : "";
        $response['maxCurrent1'] = "";
        $response['maxCurrent2'] = "";
        $response['maxCurrent3'] = "";
        foreach($station->ports as $port){
            if($port->is3PhasePort){
                $response['maxCurrent1'] = $port->max_amperage;
                $response['maxCurrent2'] = $port->max_amperage;
                $response['maxCurrent3'] = $port->max_amperage;
            }else{
                $response['maxCurrent'.$port->relay_num] = $port->max_amperage;
            }
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
////            "currentStatus" => "overheat",
//            "currentStatus" => "maxCurrentExceeded",
//            "currentData" => "Port: 1, current 49"
//        ];
        
        $station = ChargingStation::where('api_key', $key)
                                  ->select('id')->first();
        
        if($key && $data){
            $current_data = $data['currentData'] ?? null;
            $status_data = [];
            
            switch($data['currentStatus']){
                case 'maxCurrentExceeded':
                    preg_match_all('/Port: ([0-9]+), current ([0-9]+(\.[0-9]+)?)/', $current_data, $parsed);
                    if(isset($parsed[1][0])){
                        $status_data['relay_num'] = (int)$parsed[1][0];
                    }
                    if(isset($parsed[2][0])){
                        $status_data['current'] = (float)$parsed[2][0];
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
        
        //file_put_contents(storage_path('logs/api_status.log'), $request);
        
        return $this->apiResponse($response);
    }
    
}