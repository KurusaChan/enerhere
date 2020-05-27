<?php

namespace App\Http\Controllers\Manage;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\Invoice;

class InvoicesController extends Controller
{

    /**
     * @return \Illuminate\View\View
     */
    public function index(Request $request)
    {
        $invoices = Invoice::with(['chargingPaymentReservation' => function($query){
                        $query->with('chargingStation', 'userCar', 'port')->withTrashed();
                    }])->orderByDesc('created_at')->paginate(30);
                    
        return view('manage.invoices.invoices',[
            'invoices' => $invoices
        ]);
    }
    
}
