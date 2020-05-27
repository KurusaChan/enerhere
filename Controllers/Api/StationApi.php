<?php

namespace App\Http\Controllers\Api;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\ChargingStation;
use App\Models\ChargingStationInfo;
use App\Models\ChargingStationInfoMeterValue;

trait StationApi{
    
    /**
     * @param array $info
     * @param array $meter_values
     */
    private function saveInfoWithMeterValues(array $info, array $meter_values)
    {
        $station_info = DB::transaction(function() use ($info, $meter_values){
            $station_info = ChargingStationInfo::create($info);
            foreach($meter_values as $k => $mv){
                $mv['charging_station_info_id'] = $station_info->id;
                ChargingStationInfoMeterValue::create($mv);
            }
            return $station_info;
        });
        if($station_info){
            event(new \App\Events\ChargingStationInfoReceived($station_info));
        }
    }
    
    /**
     * @param int $station_id
     * @param int $relay_num
     * @return StdClass Object
     */
    private function getPrevInfo($station_id, $relay_num)
    {
        return DB::table('charging_station_info_meter_values as mv')
                ->leftJoin('charging_station_infos', 'mv.charging_station_info_id', '=', 'charging_station_infos.id')
                ->select('charging_station_infos.id', 'mv.*', 'mv.id as mv_id', 'charging_station_infos.json')
                ->whereNull('charging_station_infos.broken')
                ->whereNull('charging_station_infos.r_'.$relay_num.'_broken')
                ->where('mv.relay_num', $relay_num)
                ->where('charging_station_infos.charging_station_id', $station_id)
                ->orderByDesc('charging_station_infos.id')->limit(1)->first();
    }
    
    /**
     * @param type $key
     * @return type
     */
    private function getStationByApiKey($key)
    {
        return ChargingStation::with(['connections', 'ports' => function($q){ $q->available(); }])
                ->where('api_key', $key)
                ->select('id', 'command', 'protect_reconnect', 'without_relay')->first(); 
    }
    
    /**
     * @param array $info
     * @param array $prev_info
     * @param array $phases
     * @param ChargingStationPort $port
     * @return array
     */
    private function checkInfo($info, $prev_info, &$phases, $port)
    {
        if($prev_info){
            $fs = $port->is1PhasePort ? 1 : 3;
            for($f = 0; $f < $fs; $f ++){
                $f_i = $f + 1;
                // check info
                foreach(['u', 'i', 'p', 'e'] as $param){
                    // если в предыдущем пакете было значение, а в этом не пришло, то что-то с датчиком
                    if(!is_null($prev_info[$param.'_'.$f_i]) && is_null($phases[$param.'_'.$f_i])){
                        $info['r_'.$port->relay_num.'_broken'] = 1;
                    }
                }
                if(!$info['r_'.$port->relay_num.'_broken'] && !$port->station->isOcppStation){ 
                    // проверка на максимально возможное увеличение E
                        // в оспп записывает все потребление на 1 порт и может не пройти эту проверку
                    $port_voltage = 240; // 220 так как считаем по каждой фазе отдельно, а не на весь порт
                    $max_e_delta_by_sec = ($port->max_amperage * $port_voltage) / 3600;
                    $packages_time_diff_sec = Carbon::parse($prev_info['created_at'])->diffInSeconds(Carbon::now());
                    $max_e_delta = $max_e_delta_by_sec * $packages_time_diff_sec * 1.1; // умножить с запасом
                    if(($phases['e_'.$f_i] - $prev_info['e_'.$f_i]) > $max_e_delta){
                        $info['r_'.$port->relay_num.'_broken'] = 1;
                    }
                }
                // проверка был ли сброс
                if(!$info['r_'.$port->relay_num.'_broken'] && $prev_info['e_'.$f_i] > $phases['e_'.$f_i]){
                    // если пришел 0 и есть напряжение, то считаем что сброс
                    if($phases['e_'.$f_i] === 0.00 && $phases['u_'.$f_i] > 90){
                        $phases['e_'.$f_i.'_reseted'] = 1;
                        mail('kv@fonbrand.com', 'e_'.$f_i.'_reseted (e === 0.00)', 'prev: '.print_r($prev_info, true).' current '.print_r($info + $phases, true));
                    }else{
                        // смотрим миллис
                        $prev_json_arr = json_decode($prev_info['json'], true);
                        $curr_json_arr = json_decode($info['json'], true);
                        $prev_millis = $prev_json_arr['device']['millis'] ?? 0;
                        $curr_millis = $curr_json_arr['device']['millis'] ?? 0;
                        $millis_timeout = config('charging.reset_millis_timeout') * 1000;
                        if($prev_millis > $curr_millis/* && $prev_millis > $millis_timeout*/ && $curr_millis < $millis_timeout){
                            $phases['e_'.$f_i.'_reseted'] = 1;
                            mail('kv@fonbrand.com', 'e_'.$f_i.'_reseted', 'prev: '.print_r($prev_info, true).' current '.print_r($info + $phases, true));
                        }else{
                            $info['r_'.$port->relay_num.'_broken'] = 1;
                            $info['broken'] = 1;
                        }
                    }
                }
            }
        }
        return $info;
    }
    
    /**
     * @param array $info
     * @param ChargingStation $station
     * @return array
     */
    private function checkBrokenInfo($info, $station)
    {
        if($station->without_relay){
            // если без контактора и всем ватметрам п*зда то весь пакет бракуем
            if($info['r_1_broken'] && $info['r_2_broken'] && $info['r_3_broken']){
                $info['broken'] = 1;
            }
        }else{
            // если с контаткором то при ошибке на любом ватметре весь пакет бракуем
            if($info['r_1_broken'] || $info['r_2_broken'] || $info['r_3_broken']){
                $info['broken'] = 1;
            }
        }
        return $info;
    }
}
