<?php

namespace App\Http\Controllers\ChargingStations;

use App\Http\Controllers\ChargingStations\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use App\Models\ChargingStation;
use App\Models\ChargingConnection;
use App\Facades\Settings;
use App\Facades\Role;
use App\Models\User;
use App\Models\ChargingStationShare;
use App\Models\ChargingStationTariff;
use App\Models\ChargingPaymentReservation;
use App\Services\Payment\UserPayments;

class StationsController extends Controller
{

    /**
     * @param int $id
     * @return \Illuminate\View\View
     */
    public function stationPage($id, Request $request)
    {
        $station = ChargingStation::available()
                  ->with('ports.connection', 'shares')
                  ->find($id);
        if(!$station){
            return $this->stationNotFound();
        }

        $station_owner = $this->isStationOwner($station);

        // владельцу история зарядок на его станции
        if($station_owner){
            $connections_history =
                ChargingConnection::with('port')->onlyTrashed()
                    ->select('charging_connections.*', DB::raw('COALESCE(user_balance_transactions.summ * -1, i.final_summ) as summ'))
                    ->leftJoin('user_balance_transactions', function($join){
                        $join->on('object_id', '=', 'charging_connections.id');
                        $join->where('user_balance_transactions.reason', config('transactions.reasons.expense_by_charging_connection'));
			$join->whereNull('user_balance_transactions.deleted_at');
                    })
                    ->leftJoin('charging_payment_reservations as pr', 'pr.id', '=', 'charging_connections.by_payment_reservation_id')
                    ->leftJoin('invoices as i', function($join){
                        $join->on('i.id', '=', 'pr.invoice_id');
                        $join->whereNotNull('i.final_summ');
                    })
                    ->where('charging_connections.charging_station_id', $station->id)
                    ->orderByDesc('charging_connections.charging_end')->take(50)->get();
        }

        return view('charging_stations.station_page',[
            'station_owner' => $station_owner,
            'connections_history' => $connections_history ?? null,
            'station' => $station,

            // данные для vue Ports.store
            'station_data' => [
                'user' => Auth::check() ? Auth::user()->only(['id', 'name']) : [],
                'user_cars' => Auth::check() ? Auth::user()->cars->map(function($car){
                                                    return $car->only(['car_name', 'id', 'battery']);
                                                }) : [],
                'user_payment_variants' => Auth::check() ? (new UserPayments(Auth::user()))->getUserPaymentVariantsForStation($station) : [],
                'port_type' => config('charging.port_type'),
                'station_type' => config('charging.station_type'),
                'payment_type' => config('payment.type'),
                'charging_status' => config('charging.status'),
                'charging_statuses_colors' => config('charging.statuses_colors'),
                'charging_statuses_to_connect' => config('charging.statuses_to_connect'),
                'connection_statuses_blocked' => config('charging.connection_statuses_blocked')
            ]
        ]);
    }

    /**
     * @param type $id
     * @param Request $request
     * @return type
     */
    public function paymentReservationMerchantResult($id, Request $request)
    {
        $reservation = ChargingPaymentReservation::withTrashed()->find($id);
        if(!$reservation){
            return redirect()->route('charging-stations')
                             ->with('global-error', __('Сессия на оплату не найдена.'));
        }
        if($reservation->connection){
            return redirect()->route('charging-stations-page', ['id' => $reservation->charging_station_id])
                             ->with('global-success', __('Оплата принята. Зарядная сессия запущена.'));
        }
        if(!$reservation->isActive){
            return redirect()->route('charging-stations-page', ['id' => $reservation->charging_station_id])
                             ->with('global-error', __('Срок сессии на оплату истек. Повторите попытку.'));
        }
        return redirect()->route('charging-stations-page', ['id' => $reservation->charging_station_id]);
    }

    /**
     * @param boolean $only_available true
     * @return \Illuminate\Database\Query\Builder
     */
    public function getStationsListQuery($only_available = true)
    {
        $stations =
            ChargingStation::select(
                '*',
                DB::raw('(SELECT count(*) FROM charging_station_ports WHERE charging_station_id = charging_stations.id) as ports_qty'),
                DB::raw('IF(api_key IS NULL, 0, 1) as with_key')
            )
            ->when($only_available, function($query){
                $query->available()->availableInLists();
            })
            ->with(['ports' => function($query){
                $query->available();
            }])
            ->orderByDesc('with_key')
            ->orderByDesc('ports_qty')
            ->orderByDesc('created_at');
        return $stations;
    }

    /**
     * @return \Illuminate\View\View
     */
    public function stationsList()
    {
        return view('charging_stations.list',[
            'stations' => $this->getStationsListQuery()->paginate(10)
        ]);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function addImage(Request $request)
    {
        $station = ChargingStation::available()->find($request->input('station-id'));
        if (!$station) {
            return $this->stationNotFound();
        }

        $image = $request->file('station-image');
        $mimes = implode(',', config('app.avatar_mimes'));
        $validator = Validator::make($request->all(), [
            'station-image' => 'image|mimes:' . $mimes . '|max:15360',
        ],
            [
                'station-image.mimes' => __('Пожалуйста, загрузите изображение'),
                'station-image.image' => __('Пожалуйста, загрузите изображение'),
            ]);
        if ($validator->fails()) {
            return back()->withErrors($validator->errors());
        }

        $image_name = md5(Auth::id() . '-' . microtime(true));
        $image_name_m = $image_name . '_m';


        // check original image
        $max_width  = 1920;
        $max_height = 1080;

        $image_make = Image::make($image);
        $resized = ($image_make->height() > $image_make->width() ? $image_make->heighten($max_height) : $image_make->widen($max_width));
        $image_make->resize($resized, function ($constraint) {
            $constraint->aspectRatio();
        })->save();
        Storage::disk('public')->putFileAs('/photos/' . $station->id, $image, $image_name . '.' . $image->getClientOriginalExtension(), 'public');
        // check original image

        $image_make->fit(100, 100, function ($constraint) {
            $constraint->upsize();
            $constraint->aspectRatio();
        })->save();
        Storage::disk('public')->putFileAs('/photos/' . $station->id, $image, $image_name_m . '.' . $image->getClientOriginalExtension(), 'public');
        // make miniature

        // save original image name to db
        ChargingStationImage::create([
            'user_id' => Auth::id(),
            'charging_station_id' => $station->id,
            'image' => $image_name,
            'mime' => $image->getClientOriginalExtension()
        ]);
        // save original image name to db

        $user = Auth::user();
        $this->notifyAdmin(__('Добавлена новая фотография на станцию'), url()->previous() . "<br>" .
            "<a href=" . route('manage-users-edit', ['id' => Auth::id()]) . ">" . $user->name . $user->lastName) . "</a>";

        return back()->with('success', __('Фото загружено.'));
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function deleteImage(Request $request) {
        if (UserAccess::hasPermission('manage-station-images')) {
            $station = ChargingStation::available()->find($request->input('station_id'));
            if (!$station) {
                return $this->stationNotFound();
            }

            $image_data = ChargingStationImage::find($request->input('image_id'));
            if ($image_data) {
                $file = public_path() . '/photos/' . $image_data->charging_station_id . '/' . $image_data->image . '.' . $image_data->mime;
                if (File::exists($file)) {
                    $file_m = public_path() . '/photos/' . $image_data->charging_station_id . '/' . $image_data->image . '_m.' . $image_data->mime;

                    File::delete($file);
                    File::delete($file_m);

                    $image_data->delete();

                    return redirect()->back()->with(['success']);
                }
            }
        }
    }
}
