<?php

namespace App\Http\Controllers\ChargingStations;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\ChargingStations\Controller;
use App\Models\ChargingStation;
use App\Models\ChargingConnection;
use App\Models\ChargingStationInfo;
use App\Models\ChargingStationStatus;
use App\Services\PortStatus;
use App\Facades\Settings;
use App\Facades\Role;

class StationsDebugController extends Controller
{

    /**
     * @param ChargingStation $station
     */
    private function setCommands(ChargingStation $station, array $commands)
    {
        if(!empty($commands)){
            $station->command = json_encode($commands);
        }else{
            $station->command = null;
        }
        $station->save();
    }
    
    /**
     * @param int $id
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function index($id, Request $request)
    {
        $station = ChargingStation::find($id);
        if(!$station){
            return $this->stationNotFound();
        }
        
        $date = $request->input('log_date', null);
        $time_date = $date ? strtotime($date) : 0;
        
        $admin_logs = ChargingStationInfo::orderByDesc('id')
                      ->where('charging_station_id', $station->id)
                      ->when($time_date, function($query) use ($time_date){
                          $query->where('created_at', '<=', date('Y-m-d H:i:s', $time_date));
                      })
                      ->limit(100)
                      ->get();
        $admin_statuses = ChargingStationStatus::orderByDesc('id')
                          ->where('charging_station_id', $station->id)
                          ->when($time_date, function($query) use ($time_date){
                              $query->where('created_at', '<=', date('Y-m-d H:i:s', $time_date));
                          })
                          ->limit(100)
                          ->get();
        
        $info = $this->lastInfo($station);
        
        return view('charging_stations.debug.index', [
            'info' => $info,
            'station' => $station,
            'log_date' => $time_date ? date('Y-m-d H:i:s', $time_date) : '',
            'admin_logs' => $admin_logs ?? null,
            'admin_statuses' => $admin_statuses ?? null,
            'settings' => Settings::all(),
            'command' => !empty($station['command']) ? json_decode($station['command'], true) : [],
            'has_command' => !empty($station['command'])
        ]);
    }
    
    /**
     * @param int $id
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function clearCommand($id, Request $request)
    {
        $station = ChargingStation::find($id);
        if(!$station){
            return $this->stationNotFound();
        }
        if($station->command){
            $station->command = null;
            $station->save();
            return redirect()->back()->with('global-success', 'Последняя команда отменена.');
        }
        return redirect()->back()->with('global-error', 'Последняя команда не найдена или уже была выполнена.');
    }
    
    /**
     * @param int $id
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function pzem($id, Request $request)
    {
        $station = ChargingStation::find($id);
        if(!$station){
            return $this->stationNotFound();
        }
        
        $pzemA = $request->input('pzemA', null);
        $pzemB = $request->input('pzemB', null);
        if(!is_null($pzemA) && !is_null($pzemB) && $pzemA >= 1 && $pzemA <= 6 && $pzemB >= 1 && $pzemB <= 6){
            $this->setCommands($station, ['pzemA' => $pzemA, 'pzemB' => $pzemB]);
        }
        
        return redirect()->back();
    }
    
    /**
     * @param int $id
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function wifi($id, Request $request)
    {
        $station = ChargingStation::find($id);
        if(!$station){
            return $this->stationNotFound();
        }
        
        $wifiLogin = $request->input('wifiLogin', null);
        $wifiPass = $request->input('wifiPass', null);
        if(!empty($wifiLogin) && !empty($wifiPass)){
            $this->setCommands($station, ['wifiLogin' => $wifiLogin, 'wifiPass' => $wifiPass]);
        }
        
        return redirect()->back();
    }
    
    /**
     * @param int $id
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function invertRelay($id, Request $request)
    {
        $station = ChargingStation::find($id);
        if(!$station){
            return $this->stationNotFound();
        }
        
        $invert = $request->input('invertRelay', null) ? 1 : 0;
        $this->setCommands($station, ['invertRelay' => $invert]);
        
        return redirect()->back();
    }
    
    /**
     * @param int $id
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function reboot($id, Request $request)
    {
        $station = ChargingStation::find($id);
        if(!$station){
            return $this->stationNotFound();
        }
        
        $this->setCommands($station, ['reboot' => 1]);
        
        return redirect()->back();
    }
    
    /**
     * @param int $id
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function fwUpdate($id, Request $request)
    {
        $station = ChargingStation::find($id);
        if(!$station){
            return $this->stationNotFound();
        }
        
        $link = Settings::get('fw-link');
        $info = $this->lastInfo($station);
        
        if(!empty($info->controller)){
            $link = Settings::get('fw-link-'.strtolower($info->controller));
        }
        
        if(!$link){
            session()->flash('global-error', 'Ссылка на прошивку не найдена');
        }else{
            $this->setCommands($station, ['fwLink' => $link]);
        }
        
        return redirect()->back();
    }
    
    private function lastInfo(ChargingStation $station)
    {
        return ChargingStationInfo::where('charging_station_id', $station->id)
                                  ->orderByDesc('id')->limit(1)->first();
    }
}
