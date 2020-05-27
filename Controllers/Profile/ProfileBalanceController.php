<?php

namespace App\Http\Controllers\Profile;

use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\Controller;
use App\Services\Balance\Balance;
use App\Services\Payment\PaymentMerchant;
use App\Models\Invoice;
use App\Services\Payment\PaymentInvoice;

class ProfileBalanceController extends Controller
{
    
    /**
     * @return View
     */
    public function index(Request $request)
    {
        $balance = new Balance(Auth::user());
        
        return view('profile.balance.index', [
            'balance' => $balance,
            'balance_money' => $balance->getBalance()
        ]);
    }
    
    /**
     * @return View
     */
    public function paymentResult(Request $request, $order_id = null)
    {
        $invoice = Invoice::where('order_id', $order_id)->where('user_id', Auth::id())->first();
        if(!$invoice){
            return redirect()->route('profile-balance');
        }
        
        return view('profile.balance.invoice', [
            'invoice' => $invoice
        ]);
    }
    
    /**
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxRefill(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'merchant' => ['required', Rule::in(array_keys(config('payment.active_merchants')))],
            'summ' => ['required', 'numeric', 'min:0.01']
        ], [
            'merchant.in' => __('Выберите способ оплаты из доступных'),
            'summ.min' => __('Сумма указана неверно'),
            'summ.integer' => __('Сумма указана неверно')
        ]);
        if($validator->fails()){
            return response()->json([
                'state' => false,
                'msg' => $validator->errors()->first()
            ]);
        }
        
        $merchant = $request->input('merchant');
        $summ = $request->input('summ');
        
        try{
            $paymentMerchant = new PaymentMerchant($merchant);
        }catch(\App\Exceptions\PaymentMerchantException $ex) {
            return response()->json([
                'state' => false,
                'message' => __('Выберите способ оплаты из доступных')
            ]);
        }
        
        $invoice = PaymentInvoice::createFromArray([
            'payment_type' => config('payment.invoice.payment_type.balance_refill'),
            'summ' => $summ,
            'merchant' => $merchant,
            'currency' => 'UAH'
        ]);
        
        $form = $paymentMerchant
            ->merchant()
            ->setPaymentDescription(__('Пополнение баланса'))
            ->setReturnUrl(route('profile-balance-result', ['order_id' => $invoice->order_id], true))
            ->genFormForInvoice($invoice);
        
        $this->notifyAdmin('Enerhere: создан инвойс на пополнение баланса', '<pre>'.print_r($invoice, true).'</pre>');
        
        return response()->json([
            'state' => true,
            'form' => $form
        ]);
    }
    
}
