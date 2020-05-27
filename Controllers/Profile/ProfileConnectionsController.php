<?php

namespace App\Http\Controllers\Profile;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Models\ChargingConnection;

class ProfileConnectionsController extends Controller
{
    
    /**
     * @return View
     */
    public function index()
    {
        $connections_current = 
            ChargingConnection::currentUser()->with('chargingStation', 'userCar', 'port')->orderByDesc('charging_start')->get();
        $connections_history = 
            ChargingConnection::onlyTrashed()->with('chargingStation', 'userCar', 'port')->currentUser()
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
                ->orderByDesc('charging_connections.charging_end')->paginate(30);
                
        return view('profile.connections.index', [
            'current' => $connections_current,
            'history' => $connections_history
        ]);
    }
    
    /**
     * @param int $id
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update($id, Request $request)
    {
        $connection = ChargingConnection::onlyTrashed()->currentUser()->find($id);
        if(!$connection){
            return redirect()->route('user-profile-connections')
                             ->with('global-error', __('Подключение не найдено'));
        }
        
        $odometr = (int)$request->input('odometr');
        $full_charge = $request->input('full_charge') ? 1 : 0;
        $prev_skipped = $request->input('prev_skipped') ? 1 : 0;
        $connection->odometr = $odometr >= 0 ? $odometr : null;
        $connection->full_charge = $full_charge;
        $connection->prev_skipped = $prev_skipped;
        $connection->save();
        
        return redirect()->back()->with('global-success', __('Значение одометра сохранено.'));
    }
    
}
