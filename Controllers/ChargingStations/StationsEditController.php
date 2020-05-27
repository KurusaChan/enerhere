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
use App\Services\PortStatus;
use App\Facades\Settings;
use App\Facades\Role;
use App\Models\User;
use App\Models\ChargingStationShare;
use App\Models\ChargingStationTariff;

class StationsEditController extends Controller
{

    /**
     * @param int $id
     * @return type
     */
    public function editForm($id)
    {
        $station = ChargingStation::owner()->with(['ports', 'shares.user', 'tariffs', 'stationLimiter.ports'])->find($id);
        if(!$station){
            abort(404);
        }
        
        $limiters = null;
        if($station->isChargingStation){
            $limiters = ChargingStation::owner()->limiter()->get();
        }
        
        return view('charging_stations.edit_form', [
            'station' => $station,
            'limiters' => $limiters
        ]);
    }
    
    /**
     * Редактирование зарядной станции
     * @param int $id
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update($id, Request $request)
    {
        $station = ChargingStation::owner()->chargingStation()->find($id);
        if(!$station){
            return $this->stationNotFound();
        }
        $this->stationFormValidator($request->all(), $id)->validate();
        
        $has_limiter = !!$station->limiter_id;
        
        $api_key = $request->input('api_key', null);
        $this->setStationKey($api_key, $station);
        
        $active = $request->input('active', 0);
        $type = $request->input('type', 0);
        $class = $request->input('class', 0);
        $controller = $request->input('controller', 0);
        $model = $request->input('model', 0);
        
        if(isset(config('charging.controllers')[$controller])){
            $station->controller_brand = $controller;
        }
        if(isset(config('charging.controllers')[$controller]['models'][$model])){
            $station->controller_model = $model;
        }
        
        $station->name = $request->input('name', null);
        $station->api_key = $api_key;
        if(isset(config('charging.station_actives')[$active])){
            $station->active = $active;
        }
        if(isset(config('charging.station_types')[$type])){
            $station->type = $type;
        }
        $update_ports = [];
        if(isset(config('charging.station_classes')[$class])){
            $station->class = $class;
        }
        $limiter = (int)$request->input('limiter');
        if($limiter && ChargingStation::active()->limiter()->find($limiter)){
            $station->limiter_id = $limiter;
            if($station->is380Class){
                $update_ports['all_ports_limiter_id'] = $station->limiter_id;
            }
        }else{
            $station->limiter_id = null;
            $update_ports['limiter_port_id'] = null;
            $update_ports['all_ports_limiter_id'] = null;
        }
        $station->private = $request->input('private', 0) ? 1 : 0;
        $station->without_relay = $request->input('without_relay', 0) ? 1 : 0;
        $station->protect_reconnect = $request->input('protect_reconnect', 0) ? 1 : 0;
        $station->map_lat = $request->input('map_lat');
        $station->map_lng = $request->input('map_lng');
        $station->working_time = $request->input('working_time');
        $station->description = $request->input('description', null);
        $station->save();
        
        if($update_ports){
            $station->ports->each(function($port) use ($update_ports){
                $port->update($update_ports);
            });
        }
        
        $this->setStationType($station);
        
        // если удалили лимитер со станции лимитер
        if($has_limiter && is_null($station->limiter_id)){
            foreach($station->ports as $port){
                $port->limiter_port_id = null;
                $port->save();
                (new PortStatus($port))->makeByDeleteLimiter();
            }
        }
        
        return redirect()->route('charging-stations-edit', ['id' => $station->id]);
    }
    
    /**
     * Редактирование зарядной станции OCPP
     * @param int $id
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateOCPP($id, Request $request)
    {
        $station = ChargingStation::owner()->OcppChargingStation()->find($id);
        if(!$station){
            return $this->stationNotFound();
        }
        $this->stationFormValidator($request->all(), $id)->validate();
        
        $identity_key = $request->input('identity_key', null);
        
        $active = $request->input('active', 0);
        $type = $request->input('type', 0);
        $model = $request->input('model', 0);
        
        if(isset(config('charging.controllers')[3]['models'][$model])){
            $station->controller_model = $model;
            $station->controller_version = config('charging.controllers')[3]['models'][$model]['version'] ?? 0;
        }
        
        $station->name = $request->input('name', null);
        $station->identity_key = $identity_key;
        if(isset(config('charging.station_actives')[$active])){
            $station->active = $active;
        }
        if(isset(config('charging.station_types')[$type])){
            $station->type = $type;
        }
        $station->private = $request->input('private', 0) ? 1 : 0;
        $station->map_lat = $request->input('map_lat');
        $station->map_lng = $request->input('map_lng');
        $station->working_time = $request->input('working_time');
        $station->description = $request->input('description', null);
        $station->save();
        
        return redirect()->route('charging-stations-edit', ['id' => $station->id]);
    }
    
    /**
     * Редактирование неуправляемой станции
     * @param int $id
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateForeign($id, Request $request)
    {
        $station = ChargingStation::owner()->foreign()->find($id);
        if(!$station){
            return $this->stationNotFound();
        }
        $this->stationFormValidator($request->all(), $id)->validate();
        
        $active = $request->input('active', 0);
        
        $station->name = $request->input('name', null);
        if(isset(config('charging.station_actives')[$active])){
            $station->active = $active;
        }
        
        $station->private = $request->input('private', 0) ? 1 : 0;
        $station->map_lat = $request->input('map_lat');
        $station->map_lng = $request->input('map_lng');
        $station->working_time = $request->input('working_time');
        $station->description = $request->input('description', null);
        $station->save();
        
        $this->setStationType($station);
        
        return redirect()->route('charging-stations-edit', ['id' => $station->id]);
    }
    
    /**
     * Редактирование лимитера
     * @param int $id
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateLimiter($id, Request $request)
    {
        $station = ChargingStation::owner()->limiter()->find($id);
        if(!$station){
            return $this->stationNotFound();
        }
        $this->stationFormValidator($request->all(), $id)->validate();
        
        $api_key = $request->input('api_key', null);
        $this->setStationKey($api_key, $station);
        $station->api_key = $api_key;
        
        $station->name = $request->input('name', null);
        $active = $request->input('active', 0);
        $class = $request->input('class', 0);
        $controller = $request->input('controller', 0);
        $model = $request->input('model', 0);
        
        if(isset(config('charging.controllers')[$controller])){
            $station->controller_brand = $controller;
        }
        if(isset(config('charging.controllers')[$controller]['models'][$model])){
            $station->controller_model = $model;
        }
        if(isset(config('charging.station_classes')[$class])){
            $station->class = $class;
        }
        if(isset(config('charging.station_actives')[$active])){
            $station->active = $active;
        }
        
        
        $station->map_lat = $request->input('map_lat');
        $station->map_lng = $request->input('map_lng');
        $station->description = $request->input('description', null);
        $station->save();
        
        $this->setStationType($station);
        
        $limiter_ports = $station->ports;
        foreach($limiter_ports as $limiter_port){
            $ports = $limiter_port->limiterPorts;
            foreach($ports as $port){
                (new PortStatus($port))->makeByLimiterActive($station->active);
            }
            $ports3f = $limiter_port->limiter3fPorts;
            foreach($ports3f as $port3f){
                (new PortStatus($port3f))->makeByLimiterActive($station->active);
            }
        }
        
        return redirect()->route('charging-stations-edit', ['id' => $station->id]);
    }
    
    /**
     * @param int $id
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function share($id, Request $request)
    {
        $station = ChargingStation::owner()->find($id);
        if(!$station){
            return $this->stationNotFound();
        }
        
        $share = $request->input('share_to_emails');
        $share_sections = (array)$request->input('shared_sections');

        $emails = explode(',', $share);
        $emails = array_map('trim', $emails);
        if($emails){
            $users = User::whereIn('email', $emails)
                         ->where('id', '<>', Auth::id())
                         ->select('id', 'email')->get()
                         ->pluck('email', 'id')->toArray();
            if($users){
                $sections = [];
                foreach($share_sections as $section){
                    if(in_array($section, ['statistics'])){
                        $sections[] = $section;
                    }
                }
                foreach($users as $id => $email){
                    ChargingStationShare::firstOrCreate([
                        'charging_station_id' => $station->id,
                        'user_id' => $id
                    ], [
                        'sections' => $sections ? implode(',', $sections) : null
                    ]);
                }
            }
        }
            
        return redirect()->route('charging-stations-edit', ['id' => $station->id]);
    }
    
    /**
     * @param int $id
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function shareDelete(Request $request, $id, $share_id)
    {
        $station = ChargingStation::owner()->find($id);
        if(!$station){
            return $this->stationNotFound();
        }
        
        $share = ChargingStationShare::where('charging_station_id', $station->id)->where('id', $share_id)->first();
        if($share){
            $share->delete();
        }
        
        return redirect()->route('charging-stations-edit', ['id' => $station->id]);
    }
    
    /**
     * @param array $data
     * @param int $id
     * @return Validator
     */
    private function stationFormValidator($data, $id = 0)
    {
        return Validator::make($data, [
            'api_key' => [!empty($data['api_key']) ? Rule::unique('charging_stations')->ignore($id) : ''],
            'name' => ['required', 'string', 'max:255'],
            'map_lat' => ['required', 'string', 'max:255'],
            'map_lng' => ['required', 'string', 'max:255'],
//            'working_time' => ['required', 'string', 'max:255']
        ]);
    }
    
    /**
     * @param int $id
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function saveTariffs($id, Request $request)
    {
        $station = ChargingStation::owner()->find($id);
        if(!$station){
            return $this->stationNotFound();
        }
        $default_cost = (float)str_replace(',', '.', $request->input('tariff_cost', 0));
        $station->tariff_cost = $default_cost > 0 ? $default_cost : 0;
        $payment_type = $request->input('payment_type', null);
        $station->payment_type = isset(config('payment.types')[$payment_type]) ? $payment_type : null;
        $station->save();
        
        $tariffs = (array)$request->input('tariff');
        foreach($tariffs as $id => $tariff){
            $tariff['cost'] = (float)str_replace(',', '.', $tariff['cost']);
            if($id == 'new'){
                if($tariff['cost'] > 0){
                    $this->saveTariff($station, $tariff);
                }
            }else{
                $this->saveTariff($station, $tariff, $id);
            }
        }
        return redirect()->to(route('charging-stations-edit', ['id' => $station->id]).'#station-tariffs');
    }
    
    /**
     * @param ChargingStation $station
     * @param array $data
     * @param int|null $id
     */
    private function saveTariff(ChargingStation $station, $data, $id = null)
    {
        $time_from_h = (int)$data['time_from']['h'];
        $time_from_m = (int)$data['time_from']['m'];
        $time_by_h = (int)$data['time_by']['h'];
        $time_by_m = (int)$data['time_by']['m'];
        
        $from_h = $time_from_h >= 0 && $time_from_h < 24 ? $time_from_h : 0;
        $from_m = $time_from_m >= 0 && $time_from_m < 60 ? $time_from_m : 0;
        $by_h = $time_by_h >= 0 && $time_by_h < 24 ? $time_by_h : 0;
        $by_m = $time_by_m >= 0 && $time_by_m < 60 ? $time_by_m : 0;
        
        $time_from = ($from_h < 10 ? '0' : '').$from_h.':'.($from_m < 10 ? '0' : '').$from_m.':00';
        $time_by = ($by_h < 10 ? '0' : '').$by_h.':'.($by_m < 10 ? '0' : '').$by_m.':00';
        $cost = $data['cost'];
        
        if(is_null($id)){
            ChargingStationTariff::create([
                'charging_station_id' => $station->id,
                'time_from' => $time_from,
                'time_by' => $time_by,
                'cost' => $cost
            ]);
        }else{
            $tariff = ChargingStationTariff::where('id', $id)->where('charging_station_id', $station->id)->first();
            if($tariff){
                $tariff->charging_station_id = $station->id;
                $tariff->time_from = $time_from;
                $tariff->time_by = $time_by;
                $tariff->cost = $cost;
                $tariff->save();
            }
        }
    }
    
    /**
     * @param int $station_id
     * @param int $id
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function deleteTariff($station_id, $id, Request $request)
    {
        $station = ChargingStation::owner()->find($station_id);
        if(!$station){
            return $this->stationNotFound();
        }
        $tariff = ChargingStationTariff::find($id);
        if($tariff){
            $tariff->delete();
        }
        return redirect()->to(route('charging-stations-edit', ['id' => $station->id]).'#station-tariffs');
    }
}
