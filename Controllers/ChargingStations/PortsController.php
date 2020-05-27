<?php

namespace App\Http\Controllers\ChargingStations;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use App\Exceptions\ConnectionException;
use App\Http\Controllers\ChargingStations\Controller;
use App\Models\ChargingStation;
use App\Models\ChargingStationPort;
use App\Models\ChargingConnection;
use App\Models\UserCar;
use App\Models\ChargingPaymentReservation;
use App\Services\Connection;
use App\Services\PortStatus;
use App\Services\Payment\PaymentMerchant;
use App\Services\Payment\PaymentInvoice;
use App\Models\UserCreditCard;
use App\Services\Payment\UserPayments;

class PortsController extends Controller
{

    /**
     * @param ChargingStationPort $port
     * @param int $active
     */
    private function setPortActive(ChargingStationPort $port, $active)
    {
        if(isset(config('charging.port_actives')[$active])){
            $port->active = $active;
            $port->save();
            
            if($port->active == config('charging.port_active.broken') && $port->connection){
                $end_type = config('charging.connection_end_type.auto_port_broken');
                (new Connection($port->connection))->close($end_type);
            }
            
            (new PortStatus($port))->makeByActive();
        }
    }
    
    /**
     * @param int $station_id
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function ports($station_id, Request $request)
    {
        $station = ChargingStation::owner()->find($station_id);
        if(!$station){
            return $this->stationNotFound();
        }
        
        $add_new = (int)$request->input('add-new', 0);
        $ports = $request->input('port');
        foreach($ports as $id => $port){
            if($id == 'new' && $add_new){
                $create = $this->create($station, $port);
                if($create !== true){
                    return $create;
                }
            }
            if($id > 0){
                $model = ChargingStationPort::where('charging_station_id', $station->id)->find($id);
                $update = $this->update($model, $port);
                if($update !== true){
                    return $update;
                }
            }
        }
        
        return redirect()->to(route('charging-stations-edit', ['id' => $station->id]).'#station-ports'); 
    }
    
    /**
     * @param ChargingStationPort $port
     * @param array $data
     * @return \Illuminate\Http\RedirectResponse
     */
    private function update(ChargingStationPort $port, $data)
    {
        if(!$port){
            return $this->portNotFound();
        }
        
        $this->stationPortFormValidator($data)->validate();
        
        $active = array_get($data, 'active', 0);
        $connector = array_get($data, 'connector', 0);
        $type = array_get($data, 'type');
        $p_type = config('charging.port_type_by_class')[$port->station->class] ?? null;
        $tariff_cost = (float)array_get($data, 'tariff_cost', 0);
        
        if($tariff_cost >= 0.00){
            $port->tariff_cost = $tariff_cost;
            // обновить тариф активному конекшену на этом порту
            if($port->connection){
                $port->connection->tariff_cost = $tariff_cost;
                $port->connection->save();
            }
        }
        if(is_array($p_type) && in_array($type, $p_type)){
            $port->type = $type;
        }
        
        $port->max_amperage = array_get($data, 'max_amperage');
        if(isset(config('charging.port_connectors')[$connector])){
            $port->connector = $connector;
        }
        if($port->station->limiter_id){
            if($port->is3PhasePort){
                $port->limiter_port_id = null;
                $port->all_ports_limiter_id = $port->station->limiter_id;
            }else{
                $prev_limiter_port_id = $port->limiter_port_id;
                $port->limiter_port_id = array_get($data, 'limiter_port');
                if(!$port->limiter_port_id || $prev_limiter_port_id != $port->limiter_port_id){
                    (new PortStatus($port))->makeByDeleteLimiter();
                }
            }
        }else{
            $port->limiter_port_id = null;
        }
        $port->save();
        
        $this->setPortActive($port, $active);
        
        return true;
    }
    
    /**
     * @param ChargingStation $station
     * @param array $data
     * @return \Illuminate\Http\RedirectResponse
     */
    private function create(ChargingStation $station, $data)
    {
        if($station->ports->count() >= config('charging.ports_qty_by_class')[$station->class]){
            return redirect()->route('charging-stations-edit', ['id' => $station->id])
                             ->with('global-error', __('Максимальное количество портов уже добавлено'));
        }
        
        $this->stationPortFormValidator($data)->validate();
        
        $active = array_get($data, 'active', 0);
        $connector = array_get($data, 'connector', 0);
        $type = array_get($data, 'type');
        $tariff_cost = (float)array_get($data, 'tariff_cost', 0);
        
        if($tariff_cost <= 0.00){
            $tariff_cost = 0.00;
        }
        
        $p_type = config('charging.port_type_by_class')[$station->class];
        if(is_array($p_type) && in_array($type, $p_type)){
            $p_type = $type;
        }
        $all_ports_limiter_id = null;
        if($station->limiter_id && $type == config('charging.port_type.3phase')){
            $all_ports_limiter_id = $station->limiter_id;
        }
        
        $port = ChargingStationPort::create([
            'relay_num' => $station->ports->count() + 1,
            'charging_station_id' => $station->id,
            'type' => $p_type,
            'connector' => isset(config('charging.port_connectors')[$connector]) ? $connector : 0,
            'max_amperage' => array_get($data, 'max_amperage'),
            'all_ports_limiter_id' => $all_ports_limiter_id,
            'tariff_cost' => $tariff_cost
        ]);
        
        $this->setPortActive($port, $active);
        (new PortStatus($port))->makeByStationType($station->type);
        
        return true;
    }
    
    /**
     * @param array $data
     * @return Validator
     */
    private function stationPortFormValidator($data)
    {
        $min_a = config('charging.min_amperage');
        $max_a = config('charging.max_amperage');
        
        return Validator::make($data, [
            'max_amperage' => ['required', 'numeric', 'min:'.$min_a, 'max:'.$max_a],
        ]);
    }
    
    /**
     * @param int $port_id
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function createConnection($port_id, Request $request)
    {
        $port = ChargingStationPort::available()->with(['station' => function($query){
            $query->active();
        }])->find($port_id);
        
        if(!$port || !$port->station){
            return $this->stationNotFound();
        }
        
        $car_id = $request->input('user_car.id', 0);
        $battery = $request->input('battery', 1);
        
        $payment_variant = $request->input('payment_variant');
        $pv = (new UserPayments(Auth::user()))->getStationPaymentTypesByPaymentVariant($port->station, $payment_variant);
        if(is_null($pv)){
            return response()->json(['state' => false, 'msg' => __('Вариант оплаты не активен')]);
        }
        
        $merchant = $pv['merchant'];
        $payment_type = $pv['type'];
        $card_id = $pv['card_id'] ?? null;
        
        $car = UserCar::currentUser()->find($car_id);
        if(!$car){
            return response()->json(['state' => false, 'msg' => __('Автомобиль не найден')]);
        }
        $card = null;
        if($card_id){
            $card = UserCreditCard::where('user_id', Auth::id())->find($card_id);
            if(!$card){
                return response()->json(['state' => false, 'msg' => __('Карта не найдена, выберите другую.')]);
            }
            $merchant = $card->merchant;
        }
        
        // конект с оплатой и блокировкой суммы на карте
        if($payment_type == config('payment.type.card')){
            return $this->createConnectionWithPaymentReservation($port, $car, $merchant, $battery, $card);
        }
        
        return $this->createConnectionDefault($port, $car);
    }
    
    /**
     * @param ChargingStationPort $port
     * @param UserCar $car
     * @return \Illuminate\Http\JsonResponse
     */
    private function createConnectionDefault(ChargingStationPort $port, UserCar $car)
    {
        try{
            Connection::create($port, $car);
        }catch(ConnectionException $e){
            return response()->json(['state' => false, 'msg' => $e->getMessage()]);
        }
        
        return response()->json(['state' => true]);
    }
    
    /**
     * @param ChargingStationPort $port
     * @param UserCar $car
     * @param type $merchant
     * @param type $battery
     * @param type $card
     * @return \Illuminate\Http\JsonResponse
     */
    private function createConnectionWithPaymentReservation(ChargingStationPort $port, UserCar $car, $merchant, $battery, $card)
    {
        $summ = number_format($port->tariff_cost * $battery, 2);
        if($summ < 1){
            return response()->json([
                'state' => false,
                'msg' => __('Сумма не может быть меньше 1 грн.')
            ]);
        }
        
        try{
            $paymentMerchant = new PaymentMerchant($merchant);
        }catch(\App\Exceptions\PaymentMerchantException $ex) {
            return response()->json([
                'state' => false,
                'msg' => __('Выберите способ оплаты из доступных')
            ]);
        }

        $invoice = PaymentInvoice::createFromArray([
            'payment_type' => config('payment.invoice.payment_type.charging'),
            'transaction_type' => config('payment.invoice.transaction_type.auth'),
            'summ' => $summ,
            'merchant' => $merchant,
            'currency' => 'UAH',
            'user_card_id' => $card->id ?? null
        ]);

        try{
            $payment_reservation = Connection::createPaymentReservation($port, $car, $invoice, [
                'battery' => $battery,
                'payment_type' => config('payment.type.card')
            ]);
        }catch(ConnectionException $e){
            $invoice->delete();
            
            return response()->json([
                'state' => false, 
                'msg' => $e->getMessage()
            ]);
        }

        $merch = $paymentMerchant
            ->merchant()
            ->setPaymentCard($card)
            ->setReturnUrl(route('payment-reservation-merchant-result', ['id' => $payment_reservation->id], true))
            ->setPaymentDescription(__('Заказ №').' '.$payment_reservation->id);
        
        if(!is_null($card)){
            $response = $merch
                ->setPaymentCard($card)
                ->charge($invoice);
            
            if($response->isWaiting3DS()){
                $invoice->merchant_transaction_id = $response->getMerchantTransactionId();
                $invoice->merchant_code = $response->getMerchantCode();
                $invoice->merchant_code_description = $response->getMerchantCodeDescription();
                $invoice->merchant_status = $response->getMerchantStatus();
                $invoice->save();
                
                return response()->json(['state' => true, 'form' => $response->getMerchant3DSRedirectForm()]);
            }
            
            return response()->json(['state' => $response->isAuthCompleted()]);
        }
            
        $form = $merch->genFormForInvoice($invoice);
        return response()->json(['state' => true, 'form' => $form]);
    }
    
    /**
     * @param int $port_id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function cancelPaymentReservation($port_id)
    {
        $port = ChargingStationPort::with(['station' => function($query){
            $query->active();
        }])->find($port_id);
        
        if(!$port || !$port->station){
            return $this->stationNotFound();
        }
        
        $reservation = ChargingPaymentReservation::available()->where('port_id', $port->id)
                                 ->where('charging_station_id', $port->station->id)
                                 ->where('user_id', Auth::id())
                                 ->first();
        if(!$reservation){
            return response()->json(['state' => false, 'msg' =>  __('Резерв не найден')]);
        }
        
        $reservation->delete();
        (new PortStatus($port))->makeCancelPaymentReservation();
        
        return response()->json(['state' => true]);
    }
    
    /**
     * @param int $port_id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function closeConnection($port_id)
    {
        $port = ChargingStationPort::with(['station' => function($query){
            $query->active();
        }])->find($port_id);
        
        if(!$port || !$port->station){
            return $this->stationNotFound();
        }
        
        $user_connection = ChargingConnection::where('port_id', $port->id)
                                             ->where('charging_station_id', $port->station->id)
                                             ->where('user_id', Auth::id())
                                             ->first();
        if(!$user_connection){
            return redirect()->route('charging-stations-page', ['id' => $port->station->id])
                             ->with('global-error', __('Подключение не найдено'));
        }
        
        if(!in_array($user_connection->status, config('charging.connection_statuses_blocked'))){
            (new Connection($user_connection))->close();
        }
        
        return redirect()->route('charging-stations-page', ['id' => $port->station->id]);
    }
    
    /**
     * @return \Illuminate\Http\RedirectResponse
     */
    private function portNotFound()
    {
        return redirect()->route('charging-stations')
                         ->with('global-error', __('Порт зарядной станции не найден'));
    }
    
}
