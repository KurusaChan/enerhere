<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ChargingStations\StationsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use App\Models\User;
use App\Models\UserCar;
use App\Models\ChargingConnection;
use App\Models\ChargingStation;
use App\Models\UserDevice;
use App\Services\UserNotificationManager;
use App\Models\ControllerApiKeyRequest;

class ProfileController extends Controller
{
    
    /**
     * @return View
     */
    public function profilePage()
    {
        $user = User::current()->with('cars')->first();
        
        return view('profile.profile_page', [
            'user' => $user
        ]);
    }
    
    /**
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function profilePageUpdate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'lastname' => ['required', 'string', 'max:255']
        ]);
        $validator->validate();
        
        $user = User::current()->first();
        $user->name = $request->input('name');
        $user->lastname = $request->input('lastname');
        $user->inn = $request->input('inn') ?? null;
        $user->phone = $request->input('phone', null);
        $user->save();
        
        $notifications = (array)$request->input('notifications', []);
        (new UserNotificationManager($user))->updateUserNotifications($notifications);
        
        return redirect()->route('user-profile');
    }
    
    /**
     * @param array $data
     * @param int $car_id
     * @return Validator
     */
    private function carValidator($data, $car_id = 0)
    {
        return Validator::make($data, [
            'brand' => ['required', 'string', 'max:255'],
            'model' => ['required', 'string', 'max:255'],
            'color' => ['required', 'string', 'max:255'],
            'license_plate' => ['required', 'string', 'max:255', 
                                Rule::unique('user_cars')->ignore($car_id)],
            'vin' => ['required', 'string', 'max:255', 
                      Rule::unique('user_cars')->ignore($car_id)],
            'battery' => ['required', 'integer', 'max:1000', 'min:10'],
        ]);
    }
    
    /**
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function carSave(Request $request)
    {
        $this->carValidator($request->all())->validate();
        
        UserCar::create([
            'user_id' => Auth::id(),
            'brand' => $request->input('brand'),
            'model' => $request->input('model'),
            'color' => $request->input('color'),
            'license_plate' => $request->input('license_plate'),
            'vin' => $request->input('vin'),
            'battery' => $request->input('battery'),
        ]);
        
        return redirect()->route('user-profile');
    }
    
    /**
     * @param type $car_id
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function carUpdate($car_id, Request $request)
    {
        $car = UserCar::currentUser()->find($car_id);
        if(!$car){
            return redirect()->route('user-profile')->with('global-error', __('Автомобиль не найден'));
        }
        
        $this->carValidator($request->all(), $car->id)->validate();
        
        $car->brand = $request->input('brand');
        $car->model = $request->input('model');
        $car->color = $request->input('color');
        $car->license_plate = $request->input('license_plate');
        $car->vin = $request->input('vin');
        $car->battery = $request->input('battery');
        $car->save();
        
        return redirect()->route('user-profile');
    }
    
    /**
     * @param StationsController $stations_controller
     * @return \Illuminate\View\View
     */
    public function userStationsList(StationsController $stations_controller)
    {
        return $this->stationsList($stations_controller, 'my');
    }
    
    /**
     * @param StationsController $stations_controller
     * @return \Illuminate\View\View
     */
    public function sharedToUserStationsList(StationsController $stations_controller)
    {
        return $this->stationsList($stations_controller, 'shared');
    }
    
    /**
     * @param StationsController $stations_controller
     * @return \Illuminate\View\View
     */
    public function userLimitersStationsList(StationsController $stations_controller)
    {
        return $this->stationsList($stations_controller, 'limiters');
    }
    
    /**
     * @param StationsController $stations_controller
     * @param string $tab
     * @return \Illuminate\View\View
     */
    private function stationsList(StationsController $stations_controller, $tab)
    {
        $stations = $stations_controller->getStationsListQuery(false);
        
        switch($tab){
            case 'my':
                $stations->currentUser()->notLimiter();
            break;
            case 'shared':
                $stations->sharedToCurrentUser();
            break;
            case 'limiters':
                $stations->currentUser()->limiter();
            break;
            default:
                throw new Exception("Tab not found");
        }
        
        return view('profile.stations_list',[
            'qty_my' => ChargingStation::currentUser()->notLimiter()->count(),
            'qty_shared' => ChargingStation::available()->sharedToCurrentUser()->count(),
            'qty_limiters' => ChargingStation::currentUser()->limiter()->count(),
            'active_my' => $tab == 'my',
            'active_shared' => $tab == 'shared',
            'active_limiters' => $tab == 'limiters',
            'stations' => $stations->paginate(10)
        ]);
    }
    
    /**
     * @param Request $request
     * @return View
     */
    public function devices(Request $request)
    {
        $devices = UserDevice::with(['chargingStation', 'controllerApiRequests' => function($query){
            $query->where('created_at', '>=', now()->addHour(-1))->orderByDesc('created_at');
        }])->where('user_id', Auth::id())
        ->orderByDesc('created_at')
        ->paginate(15);
        
        return view('profile.devices.list', [
            'devices' => $devices
        ]);
    }
    
    /**
     * @param Request $request
     * @return View
     */
    public function deleteDevice($id, Request $request)
    {
        $device = UserDevice::where('user_id', Auth::id())->find($id);
        
        if($device){
            $device->delete();
        }
        
        return redirect()->route('user-profile-devices');
    }
    
    /**
     * @param Request $request
     * @return View
     */
    public function devicesAddForm(Request $request)
    {
        return view('profile.devices.add_form');
    }
    
    /**
     * @param Request $request
     * @return View
     */
    public function devicesAddFormByApiKey(Request $request)
    {
        return view('profile.devices.add_form_api_key');
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function createDevice(Request $request)
    {
        $data = $request->all();
        
        $code = $request->input('code') ?? null;
        
        preg_match_all("/([A-z0-9]+)/", $code, $matches);
        $clear_code = implode('', $matches[0]);
        $mac = implode(':', str_split($clear_code, 2));
        $data['code'] = $mac;
        
        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:191'],
            'code' => ['required', Rule::unique('user_devices'), Rule::exists('controller_api_key_requests', 'mac')->where(function ($query) {
                            $query->where('created_at', '>=', now()->addHour(-1));
                        })],
        ], [
            'name.required' => 'Пожалуйста, укажите название',
            'code.required' => 'Пожалуйста, укажите пин-код',
            'code.unique' => 'Устройство с таким пин-кодом уже добавлено',
            'code.exists' => 'Устройство с таким пин-кодом не найдено',
        ]);
        $validator->validate();
        
        $requests = ControllerApiKeyRequest::where('mac', $mac)
                    ->where('created_at', '>=', now()->addHour(-1))
                    ->orderByDesc('created_at')
                    ->first();
        
        UserDevice::create([
            'user_id' => Auth::id(),
            'name' => $request->input('name'),
            'code' => $mac,
            'model' => $requests->model,
            'api_key' => $requests->api_key
        ]);
        
        return redirect()->route('user-profile-devices');
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function createDeviceByApiKey(Request $request)
    {
        $data = $request->all();
        
        $model = $request->input('model');
        $api_key = $request->input('api_key') ?? null;
        
        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:191'],
            'api_key' => ['required', Rule::unique('user_devices')]
        ]);
        $validator->validate();
        
        UserDevice::create([
            'user_id' => Auth::id(),
            'name' => $request->input('name'),
            'code' => null,
            'model' => isset(config('charging.devices_models')[$model]) ? $model : null,
            'api_key' => $api_key,
        ]);
        
        return redirect()->route('user-profile-devices');
    }
}
