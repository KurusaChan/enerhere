<?php

namespace App\Http\Controllers\ChargingStations;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\ChargingStations\Controller;
use App\Models\ChargingStation;
use App\Models\ChargingConnection;
use App\Models\ChargingStationInfo;
use App\Models\ChargingPaymentReservation;
use App\Services\Payment\UserPayments;

class AjaxController extends Controller
{

    /**
     * @param int $station_id
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function stationPageAjax($station_id, Request $request)
    {
        $act = $request->input('act');
        $data = [];
        
        switch($act){
            case 'load-ports':
                $data = $this->ajaxLoadPorts($station_id);
            break;
            case 'load-ports-info':
                $data = $this->ajaxLoadPortsInfo($station_id);
            break;
            case 'save-odometr':
                $data = $this->ajaxSaveOdometr($station_id, $request);
            break;
        }
        
        return response()->json($data);
    }
    
    /**
     * @param int $station_id
     * @return array
     */
    private function ajaxSaveOdometr($station_id, Request $request)
    {
        $station = ChargingStation::available()
                        ->with(['ports' => function($query){
                            $query->available()->with('connection');
                        }])
                        ->find($station_id);
        if(!$station){
            return $this->stationNotFound();
        }
        
        $connection_id = $request->input('connection_id');
        $odometr = $request->input('odometr.value');
        $full_charge = (int)$request->input('odometr.full_charge') ? 1 : 0;
        $prev_skipped = (int)$request->input('odometr.prev_skipped') ? 1 : 0;
        
        $connection = ChargingConnection::find($connection_id);
        if(!$connection){
            return $this->connectionNotFound();
        }
        
        if($odometr > 0 && $odometr < 99999999){
            $connection->odometr = $odometr > 0 ? $odometr : null;
        }
        $connection->full_charge = is_null($connection->odometr) ? null : $full_charge;
        $connection->prev_skipped = is_null($connection->odometr) ? null : $prev_skipped;
        $connection->save();
        
        return [
            'state' => true,
            'odometr' => $connection->odometr,
            'prev_skipped' => $connection->prev_skipped,
            'full_charge' => $connection->full_charge
        ];
    }
    
    /**
     * Обновление каждых * сек
     * @param int $station_id
     * @return array
     */
    private function ajaxLoadPortsInfo($station_id)
    {
        $station = ChargingStation::available()
                        ->with(['ports' => function($query){
                            $query->available()->with('connection');
                        }])
                        ->find($station_id);
        if(!$station){
            return $this->stationNotFound();
        }
        
        return [
            'state' => true,
            'ports' => $this->getPorts($station),
            'ports_info' => $this->getStationPortsInfo($station, false)
        ];
    }
    
    /**
     * Первоначальна загрузка
     * 
     * @param int $station_id
     * @return array
     */
    private function ajaxLoadPorts($station_id)
    {
        $station = ChargingStation::available()
                        ->with(['ports' => function($query){
                            $query->available()->with('connection');
                        }])
                        ->find($station_id);
        if(!$station){
            return $this->stationNotFound();
        }
        
        if(!$station->isPortsAvailable){
            $a_state = mb_strtolower(config('charging.station_actives')[$station->active]);
            return [
                'state' => false,
                'msg' => __('Порты недоступны. Станция '.$a_state.'.')
            ];
        }
        
        return [
            'state' => true,
            'station' => $station->only('id', 'name', 'type', 'isChargingStation', 'isLimiter', 'payment_type') + [
                'station_owner' => $this->isStationOwner($station),
            ],
            'ports' => $this->getPorts($station),
            'ports_info' => $this->getStationPortsInfo($station)
        ];
    }
    
    /**
     * @param Station $station
     * @return Collection
     */
    private function getPorts(ChargingStation $station)
    {
        return $station->ports->map(function($port, $i){
            $p = $port->only(['id', 'max_amperage', 'type', 'current_status', 'connector', 'relay_num', 'title', 'tariff_cost']);
            $p['tariff_cost'] = number_format($p['tariff_cost'], 2);
            $p['max_amperage'] = floor($p['max_amperage']);
            $p['connector'] = [
                'name' => __(config('charging.port_connectors')[$port->connector]['name']),
                'icon' => asset(config('charging.port_connectors')[$port->connector]['icon'])
            ];
            $p['type_name'] = __(config('charging.port_types')[$port->type]);
            $p['status_name'] = __(config('charging.statuses')[$port->current_status]);
            return $p;
        });
    }
    
    /**
     * @param ChargingStation $station
     * @param boolean $initial
     * @return array
     */
    protected function getStationPortsInfo(ChargingStation $station, $initial = true)
    {
        $station_owner = $this->isStationOwner($station);
        
        $data = array(
            'connections' => array()
        );
        $connections = 
            ChargingConnection::with([
                    'userCar', 
                    'paymentReservation' => function($query){
                        $query->select('id', 'summ', 'battery');
                    },
                    'port' => function($query){ 
                        $query->available(); 
                    }])
                    ->when(!$station_owner, function($q){
                        $q->currentUser();
                    })
                    ->where('charging_station_id', $station->id)
                    ->get()->keyBy('port_id');
        
        foreach($connections as $port_id => $connection){
            $port = $connection->port;
            
            $charging_time = $connection->charging_time;
            
            $data['connections'][$port_id] = [
                'id' => $connection->id,
                'status' => [
                    'id' => $connection->status,
                    'type' => array_search($connection->status, config('charging.connection_status')),
                    'name' => config('charging.connection_statuses')[$connection->status] ?? null
                ],
                'by_payment_reservation' => 
                    $connection->paymentReservation ?? null,
                'user_id' => $connection->user_id,
                'connection_info' => [],
                'charging_time' => [
                    'hours' => $charging_time->hours,
                    'minutes' => $charging_time->minutes,
                    'seconds' => $charging_time->seconds
                ],
                'odometr' => $connection->odometr,
                'full_charge' => $connection->full_charge,
                'prev_skipped' => $connection->prev_skipped,
                'charging_time_sec' => time() - strtotime($connection->created_at),
                'user_car' => [
                    'car_name' => $connection->userCar->car_name
                ],
                'tariff_cost' => $connection->tariff_cost,
                'discount_percent' => $connection->discount_percent,
                // инфомация по потребелнию зарядки (текущей сессии)
                'connection_info' => [
                    'used_e' => number_format($connection->used_e_kwth, 3),
                    'current_cost' => number_format($connection->current_cost, 2),
                    'metrics' => [
                        'u' => number_format($connection->current_u, 2),
                        'i' => number_format($connection->current_i, 2),
                        'p' => number_format($connection->current_p_kwth, 3)
                    ]
                ]
            ];
            
            // расширенная инфо юзера для владельца станции
            if($station_owner){
                $data['connections'][$port_id]['user'] = [
                    'name' => $connection->user->fullName,
                    'phone' => $connection->user->phone
                ];
            }
        }
        
        // расширенная инфа по фазам на порту для владельца
        if($station_owner){
            $data['station_info'] = [];
            $station_info = 
                ChargingStationInfo::with('meterValues')->notBroken()
                    ->where('charging_station_id', $station->id)
                    ->where('created_at', '>', date('Y-m-d H:i:s', time() - config('charging.time_to_offline')))
                    ->orderByDesc('id')
                    ->select('id', 'temp_1', 'temp_2', 'temp_3', 'temp_devices', 'created_at')
                    ->limit(1)
                    ->first();
            foreach($station->ports as $port){
                if($station_info){
                    $meter_values = $station_info->meterValues->where('relay_num', $port->relay_num)->first();
                    if($meter_values){
                        $data['station_info'][$port->id] = [
                            'info_sec' => time() - strtotime($station_info->created_at),
                            'temp_devices' => $station_info->temp_devices,
                            'temp_1' => $station_info->temp_1,
                            'temp_2' => $station_info->temp_2,
                            'temp_3' => $station_info->temp_3,
                            'u_1' => number_format($meter_values->u_1 ?? 0, 2), 
                            'i_1' => number_format($meter_values->i_1 ?? 0, 2), 
                            'p_1_kwth' => number_format($meter_values->p_1_kwth ?? 0, 3), 
                            'u_2' => number_format($meter_values->u_2 ?? 0, 2), 
                            'i_2' => number_format($meter_values->i_2 ?? 0, 2), 
                            'p_2_kwth' => number_format($meter_values->p_2_kwth ?? 0, 3), 
                            'u_3' => number_format($meter_values->u_3 ?? 0, 2), 
                            'i_3' => number_format($meter_values->i_3 ?? 0, 2), 
                            'p_3_kwth' => number_format($meter_values->p_3_kwth ?? 0, 3),
                            'temp' => $meter_values->temp
                        ];
                    }
                }
            }
        }
        
        $payment_reservations = 
            ChargingPaymentReservation::with(['userCar', 'invoice'])
                    ->available()
                    ->where('charging_payment_reservations.user_id', Auth::id())
                    ->where('charging_station_id', $station->id)
                    ->select('charging_payment_reservations.battery', 'charging_payment_reservations.summ', 'charging_payment_reservations.reserved_by', 
                             'charging_payment_reservations.user_car_id', 'charging_payment_reservations.payment_type', 'charging_payment_reservations.id', 
                             'charging_payment_reservations.port_id', 'charging_payment_reservations.invoice_id', 'charging_payment_reservations.charging_station_id',
                             'charging_payment_reservations.user_id')
                    ->get()
                    ->keyBy('port_id');
        if($payment_reservations){
            $data['payment_reservations'] = [];
            foreach($payment_reservations as $port_id => $reservation){
                $data['payment_reservations'][$port_id] = $reservation->toArray();
                $pv = (new UserPayments($reservation->user))->getPaymentVariantForPaymentReservation($reservation);
                $data['payment_reservations'][$port_id]['payment_variant'] = $pv;
                $data['payment_reservations'][$port_id]['reservation_sec'] = strtotime($reservation->reserved_by) - time();
                $data['payment_reservations'][$port_id]['user_car'] = $reservation->userCar->only('id', 'car_name', 'battery');
                $data['payment_reservations'][$port_id]['battery'] = $reservation->battery;
                $data['payment_reservations'][$port_id]['invoice'] = $reservation->invoice->only('id', 'merchant_status', 'merchant_code_description');
            }
            
        }
        
        return $data;
    }
    
    private function connectionNotFound()
    {
        return [
            'state' => false
        ];
    }
    
    protected function stationNotFound()
    {
        return [
            'state' => false
        ];
    }
}
