<?php

namespace App\Http\Controllers\ChargingStations;

use Illuminate\Support\Facades\Auth;
use App\Models\ChargingStation;
use App\Facades\UserAccess;
use App\Http\Controllers\Controller as BaseController;
use App\Models\ChargingStationStatus;
use App\Models\ChargingStationInfo;
use App\Services\PortStatus;
use App\Models\ControllerApiKeyRequest;
use App\Models\UserDevice;

class Controller extends BaseController
{
    
    /**
     * @param string $key
     * @param ChargingStation $station
     * @return boolean
     */
    protected function setStationKey($key, ChargingStation $station)
    {
        if($station->api_key != $key || $station->wasRecentlyCreated){
            ChargingStationStatus::where('api_key', $key)
                                 ->update(['charging_station_id' => $station->id]);
            ChargingStationInfo::where('api_key', $key)
                               ->update(['charging_station_id' => $station->id]);
            return true;
        }
        return false;
    }
    
    /**
     * @param ChargingStation $station
     */
    protected function setStationType(ChargingStation $station)
    {
        $ports = $station->ports;
        foreach($ports as $port){
            (new PortStatus($port))->makeByStationType($station->type);
        }
    }
    
    /**
     * @param ChargingStation $station
     * @return boolean
     */
    protected function isStationOwner(ChargingStation $station)
    {
        if(UserAccess::hasPermission('super')){
            return true;
        }
        return Auth::check() && $station->user_id == Auth::id();
    }
    
    /**
     * @return \Illuminate\Http\RedirectResponse
     */
    protected function stationNotFound()
    {
        return redirect()->route('charging-stations')
                         ->with('global-error', __('Зарядная станция не найдена'));
    }
    
}
