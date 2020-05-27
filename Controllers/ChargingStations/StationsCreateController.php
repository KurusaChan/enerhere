<?php

namespace App\Http\Controllers\ChargingStations;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\ChargingStations\Controller;
use App\Models\ChargingStation;
use App\Services\ConfigHelper;

class StationsCreateController extends Controller
{

    /**
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function create(Request $request)
    {
        $this->stationFormValidator($request->all())->validate();
        
        $api_key = $request->input('api_key', null);
        
        $private = $request->input('private', 0) ? 1 : 0;
        $without_relay = $request->input('without_relay', 0) ? 1 : 0;
        $protect_reconnect = $request->input('protect_reconnect', 0) ? 1 : 0;
        $active = $request->input('active', 0);
        $type = $request->input('type', 0);
        $class = $request->input('class', 0);
        $controller = $request->input('controller', 0);
        $model = $request->input('model', 0);
                
        $station = ChargingStation::create([
            'user_id' => Auth::user()->id,
            'name' => $request->input('name', null),
            'api_key' => $api_key,
            'active' => isset(config('charging.station_actives')[$active]) ? $active : 0,
            'controller_brand' => isset(config('charging.controllers')[$controller]) ? $controller : 0,
            'controller_model' => isset(config('charging.controllers')[$controller]['models'][$model]) ? $model : 0,
            'type' => isset(config('charging.station_types')[$type]) ? $type : 0,
            'class' => isset(config('charging.station_classes')[$class]) ? $class : 0,
            'private' => $private,
            'without_relay' => $without_relay,
            'protect_reconnect' => $protect_reconnect,
            'map_lat' => $request->input('map_lat'),
            'map_lng' => $request->input('map_lng'),
            'working_time' => $request->input('working_time', '') ?: '-',
            'description' => $request->input('description', null)
        ]);
        
        $this->setStationKey($api_key, $station);
        $this->setStationType($station);
        
        return redirect()->route('charging-stations-edit', ['id' => $station->id]);
    }
    
    /**
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function createOCPP(Request $request)
    {
        $this->stationFormValidator($request->all())->validate();
        
        $identity_key = $request->input('identity_key', null);
        
        $private = $request->input('private', 0) ? 1 : 0;
        $active = $request->input('active', 0);
        $type = $request->input('type', 0);
        $model = $request->input('model', 0);
                
        $station = ChargingStation::create([
            'user_id' => Auth::user()->id,
            'name' => $request->input('name', null),
            'identity_key' => $identity_key,
            'active' => isset(config('charging.station_actives')[$active]) ? $active : 0,
            'controller_brand' => 3,
            'controller_model' => isset(config('charging.controllers')[3]['models'][$model]) ? $model : 0,
            'controller_version' => config('charging.controllers')[3]['models'][$model]['version'] ?? 0,
            'type' => isset(config('charging.station_types')[$type]) ? $type : 0,
            'class' => -1,
            'private' => $private,
            'map_lat' => $request->input('map_lat'),
            'map_lng' => $request->input('map_lng'),
            'working_time' => $request->input('working_time', '') ?: '-',
            'description' => $request->input('description', null)
        ]);
        
        return redirect()->route('charging-stations-edit', ['id' => $station->id]);
    }
    
    /**
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function createForeign(Request $request)
    {
        $this->stationFormValidator($request->all())->validate();
        
        $private = $request->input('private', 0) ? 1 : 0;
        $without_relay = $private && $request->input('without_relay', 0) ? 1 : 0;
        $protect_reconnect = !$without_relay && $request->input('protect_reconnect', 0) ? 1 : 0;
        $active = $request->input('active', 0);
        
        $station = ChargingStation::create([
            'user_id' => Auth::user()->id,
            'name' => $request->input('name', null),
            'active' => isset(config('charging.station_actives')[$active]) ? $active : 0,
            'controller_brand' => ConfigHelper::controllerForForeign(),
            'controller_model' => ConfigHelper::modelForForeign(),
            'type' => config('charging.station_type.foreign'),
            'class' => config('charging.station_class.foreign'),
            'private' => $private,
            'without_relay' => $without_relay,
            'protect_reconnect' => $protect_reconnect,
            'map_lat' => $request->input('map_lat'),
            'map_lng' => $request->input('map_lng'),
            'working_time' => $request->input('working_time', '') ?: '-',
            'description' => $request->input('description', null)
        ]);
        
        $this->setStationType($station);
        
        return redirect()->route('charging-stations-edit', ['id' => $station->id]);
    }
    
    /**
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function createLimiter(Request $request)
    {
        $this->stationFormValidator($request->all())->validate();
        
        $api_key = $request->input('api_key', null);
        
        $active = $request->input('active', 0);
        $class = $request->input('class', 0);
        
        $station = ChargingStation::create([
            'user_id' => Auth::user()->id,
            'name' => $request->input('name', null),
            'api_key' => $api_key,
            'active' => isset(config('charging.station_actives')[$active]) ? $active : 0,
            'controller_brand' => $request->input('controller', 2),
            'controller_model' => $request->input('model', 0),
            'type' => config('charging.station_type.limiter'),
            'class' => $class,
            'private' => 1,
            'without_relay' => 0,
            'protect_reconnect' => 0,
            'map_lat' => $request->input('map_lat'),
            'map_lng' => $request->input('map_lng'),
            'working_time' => '-',
            'description' => $request->input('description', null)
        ]);
        
        $this->setStationKey($api_key, $station);
        $this->setStationType($station);
        
        return redirect()->route('charging-stations-edit', ['id' => $station->id]);
    }
    
    /**
     * @return type
     */
    public function createForm()
    {
        return view('charging_stations.create_form');
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
            'identity_key' => [!empty($data['identity_key']) ? Rule::unique('charging_stations')->ignore($id) : ''],
            'name' => ['required', 'string', 'max:255'],
            'map_lat' => ['required', 'string', 'max:255'],
            'map_lng' => ['required', 'string', 'max:255'],
//            'working_time' => ['required', 'string', 'max:255']
        ]);
    }
    
    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonRespons
     */
    public function ajaxControllerForm(Request $request)
    {
        $changed = $request->input('changed');
        $type = $request->input('form.type');
        $controller = $request->input('form.controller');
        $model = $request->input('form.model');
        
        $form = [];
        if($changed == 'type'){
            $controllers = [];
            foreach(ConfigHelper::controllersByType($type) as $c_id => $contr){
                if(!$controllers){
                    $controller = $c_id;
                }
                $controllers[$c_id] = $contr['name'];
            }
            $form['controller'] = $controllers;
        }
        if(in_array($changed, ['type', 'controller'])){
            $models = [];
            foreach(ConfigHelper::modelsByController($controller) as $m_id => $mod){
                if(!$models){
                    $model = $c_id;
                }
                $models[$m_id] = $mod['name'];
            }
            $form['model'] = $models;
        }
        if(in_array($changed, ['type', 'controller', 'model'])){
            $classes = [];
            foreach(ConfigHelper::classesByControllerAndModel($controller, $model) as $cl_id => $class){
                $classes[$cl_id] = $class;
            }
            $form['class'] = $classes;
        }
        
        return response()->json([
            'changed' => $changed,
            'form' => $form
         ]);
    }
}
