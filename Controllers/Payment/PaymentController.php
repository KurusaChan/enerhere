<?php

namespace App\Http\Controllers\Payment;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Services\Payment\PaymentMerchant;
use App\Services\Balance\Transaction;
use App\Services\Payment\PaymentMerchantResult;
use App\Models\Invoice;
use App\Models\UserCreditCard;
use App\Models\ChargingPaymentReservation;
use App\Services\Connection;

class PaymentController extends Controller
{
    
    /**
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function receiveFromMerchant(Request $request)
    {
        try{
            $merchant_name = PaymentMerchant::detectReceiveMerchant();
            $merchant = new PaymentMerchant($merchant_name);
        }catch(\App\Exceptions\PaymentMerchantException $ex) {
            $this->notifyAdmin('Enerhere payment: PaymentMerchantException', 
                                 $ex->getMessage().'; '.   
                                 $ex->getFile().'; '.
                                 $ex->getLine().'; ');
            return response('');
        }
        
        $result = $merchant->merchant()->receivePayment();
        
        if(!$result->getResultStatus()){
            $this->notifyAdmin('Enerhere payment: failed', '<pre>'.print_r($result->getMerchantData(), true).'</pre>');
            return $result->response();
        }
        
        $invoice = Invoice::where('order_id', $result->getOrderId())->first();
        if($invoice){
            
            if($result->getMerchantSenderPhone()){
                $invoice->merchant_sender_phone = $result->getMerchantSenderPhone();
            }
            if($result->getMerchantSenderEmail()){
                $invoice->merchant_sender_email = $result->getMerchantSenderEmail();
            }
            if($result->getMerchantSenderName()){
                $invoice->merchant_sender_name = $result->getMerchantSenderName();
            }
            if($result->getMerchantTransactionId()){
                $invoice->merchant_transaction_id = $result->getMerchantTransactionId();
            }
            if($result->getMerchantCode()){
                $invoice->merchant_code = $result->getMerchantCode();
            }
            if($result->getMerchantCodeDescription()){
                $invoice->merchant_code_description = $result->getMerchantCodeDescription();
            }
            
            $invoice->merchant_status = $result->getMerchantStatus();
            
            if($result->isFailure()){
                $invoice->save();
                
                $this->failure($invoice, $result);
            }
            
            if($result->isRefunded() && !$invoice->refunded_at){
                $invoice->refunded_at = now();
                $invoice->merchant_refund_amount = $result->getMerchantAmount();
                $invoice->save();
                
                $this->refunded($invoice, $result);
            }
            
            if($result->isPayed() && !$invoice->payed_at){
                $invoice->merchant_transaction_id = $result->getMerchantTransactionId();
                if($invoice->final_summ){
                    $invoice->merchant_final_amount = $result->getMerchantAmount();
                    $invoice->merchant_final_fee = $result->getMerchantFee();
                }else{
                    $invoice->merchant_amount = $result->getMerchantAmount();
                    $invoice->merchant_fee = $result->getMerchantFee();
                }
                $invoice->payed_at = now();
                $invoice->save();
                
                $this->payed($invoice, $result);
            }
            
            if($result->isAuthCompleted() && !$invoice->auth_completed_at){
                $invoice->merchant_amount = $result->getMerchantAmount();
                $invoice->merchant_fee = $result->getMerchantFee();
                $invoice->auth_completed_at = now();
                $invoice->save();
                
                $this->authCompleted($invoice, $result);
            }
            
            if($result->isWaitSecure() && !$result->isWaiting3DS()){
                $invoice->save();
                
                $this->waitSecure($invoice, $result);
            }
            
            $invoice->save();
            
            if($result->hasCard() && !$result->isFailure()){
                $this->saveUserCard($invoice, $result);
            }
        }
        
        $this->notifyAdmin('Enerhere payment: result', '<pre>'.print_r($result->getMerchantData(), true).'</pre>');
        return $result->response();
    }
    
    /**
     * @param Request $request
     * @param integer $request
     * @return \Illuminate\Http\Response
     */
    public function receive3dsFromMerchant(Request $request, $invoice_id)
    {
        $invoice = Invoice::find($invoice_id);
        if(!$invoice || $invoice->complete_3ds_at){
            return response('');
        }
        
        try{
            $merchant = new PaymentMerchant($invoice->merchant);
        }catch(\App\Exceptions\PaymentMerchantException $ex) {
            $this->notifyAdmin('Enerhere payment: complete 3ds result merchant exception', 
                                 $ex->getMessage().'; '.   
                                 $ex->getFile().'; '.
                                 $ex->getLine().'; ');
            return response('');
        }
        
        $invoice->complete_3ds_at = now();
        $invoice->save();
        
        $response = $merchant->merchant()->complete3DS($invoice, $request->all());
        
        $this->notifyAdmin('Enerhere payment: complete3DS', 
                           '<pre>'.print_r($invoice, true).'</pre>'.
                           '<pre>'.print_r((array)$response, true).'</pre>');
        
        $reservation = ChargingPaymentReservation::withTrashed()->where('invoice_id', $invoice->id)->first();
        return redirect()->route('payment-reservation-merchant-result', ['id' => $reservation->id]);
    }
    
    /**
     * @param Invoice $invoice
     * @param PaymentMerchantResult $result
     * @return UserCreditCard
     */
    private function saveUserCard(Invoice $invoice, PaymentMerchantResult $result)
    {
        $card = UserCreditCard::firstOrCreate([
            'user_id' => $invoice->user_id,
            'merchant' => $invoice->merchant,
            'card_pan' => $result->getCardPan()
        ], [
            'card_type' => $result->getCardType(),
            'issuer_bank_country' => $result->getCardIssuerBankCountry(),
            'issuer_bank_name' => $result->getCardIssuerBankName(),
            'rec_token' => $result->getCardRecToken()
        ]);
        if(!$card->wasRecentlyCreated){
            $card->rec_token = $result->getCardRecToken();
            $card->save();
        }
        return $card;
    }
    
    /**
     * @param Invoice $invoice
     * @param PaymentMerchantResult $result
     */
    private function waitSecure(Invoice $invoice, PaymentMerchantResult $result)
    {
        // todo 0
        switch($invoice->payment_type){
            case config('payment.invoice.payment_type.charging'):
                $reserve = ChargingPaymentReservation::withTrashed()->where('invoice_id', $invoice->id)->first();
                // если пришел Pending (wait_secure) то добавить еще 10 мин
                if($reserve->isActive){
                    $reserve->reserved_by = \Illuminate\Support\Carbon::parse($reserve->reserved_by)->addMinutes(10);
                    $reserve->save();
                }
            break;
        }
    }
    
    /**
     * @param Invoice $invoice
     * @param PaymentMerchantResult $result
     */
    private function payed(Invoice $invoice, PaymentMerchantResult $result)
    {
        // todo 1
        switch($invoice->payment_type){
            case config('payment.invoice.payment_type.balance_refill'):
                $this->balanceIncome($invoice, $result);
            break;
            case config('payment.invoice.payment_type.charging'):

            break;
        }
    }
    
    /**
     * @param Invoice $invoice
     * @param PaymentMerchantResult $result
     */
    private function authCompleted(Invoice $invoice, PaymentMerchantResult $result)
    {
        // todo 2
        switch($invoice->payment_type){
            case config('payment.invoice.payment_type.charging'):
                $reserve = ChargingPaymentReservation::withTrashed()->where('invoice_id', $invoice->id)->first();
                if(!$reserve->isActive){
                    // вернуть деньги
                    $this->refundPayment($invoice, __('Отмена заказа №'.$reserve->id), __('Срок сессии на оплату истек.'));
                }else{
                    // запустить сессию
                    try{
                        Connection::createByPaymentReservation($reserve);
                    }catch (\App\Exceptions\ConnectionException $ex){
                        // если сессия не запустилась то вернуть деньги 
                        $this->refundPayment($invoice, __('Отмена заказа №'.$reserve->id), $ex->getMessage());
                    }
                }
            break;
        }
    }
    
    /**
     * @param Invoice $invoice
     * @param PaymentMerchantResult $result
     */
    private function refunded(Invoice $invoice, PaymentMerchantResult $result)
    {
        // todo 3
        switch($invoice->payment_type){
            case config('payment.invoice.payment_type.balance_refill'):
                $this->balanceExpense($invoice, $result);
            break;
            case config('payment.invoice.payment_type.charging'):

            break;
        }
    }
    
    /**
     * @param Invoice $invoice
     * @param PaymentMerchantResult $result
     */
    private function failure(Invoice $invoice, PaymentMerchantResult $result)
    {
        // todo 4
        switch($invoice->payment_type){
            case config('payment.invoice.payment_type.charging'):
                
            break;
        }
    }
    
    /**
     * @param Invoice $invoice
     */
    private function refundPayment(Invoice $invoice, $reason, $message)
    {
        $paymentMerchant = new PaymentMerchant($invoice->merchant);
        
        $response = $paymentMerchant
            ->merchant()
            ->setPaymentDescription($reason)
            ->refundPayment($invoice, $invoice->summ);
        
        $this->notifyAdmin('authCompleted -> refundPayment', 'Message: <b>'.$message.'</b><br>'.
                                                             'Reason Code: '.$response->getMerchantCode().'<br>'.
                                                             'Reason Code Description: '.$response->getMerchantCodeDescription().'<br>'.
                                                             'Invoice: '.print_r($invoice->toArray(), true).'<br>'.
                                                             'Refund reason: '.$reason.'<br>'.
                                                             'Order: '.$response->getOrderId().'<br>'.
                                                             'Order status: <b>'.$response->getMerchantStatus().'</b>');
        return true;
    }
    
    /**
     * @param Invoice $invoice
     * @param PaymentMerchantResult $result
     */
    private function balanceExpense(Invoice $invoice, PaymentMerchantResult $result)
    {
        try{
            $tr = Transaction::expenseByUserInvoice($invoice->id);
            $tr->setSumm($invoice->summ);
            $tr->setUserId($invoice->user_id);
            $tr->save();
        }catch(\App\Exceptions\TransactionBuilderException $ex){ 
            $this->notifyAdmin('Enerhere payment: refund transaction failed', 
                                 $ex->getMessage().'; '.   
                                 $ex->getFile().'; '.
                                 $ex->getLine().'; '.   
                                 '<pre>'.print_r($result->getMerchantData(), true).'</pre>');
        }
    }
    
    /**
     * @param Invoice $invoice
     * @param PaymentMerchantResult $result
     */
    private function balanceIncome(Invoice $invoice, PaymentMerchantResult $result)
    {
        try{
            $tr = Transaction::incomeByUserInvoice($invoice->id);
            $tr->setSumm($invoice->summ);
            $tr->setUserId($invoice->user_id);
            $tr->save();
        }catch(\App\Exceptions\TransactionBuilderException $ex){ 
            $this->notifyAdmin('Enerhere payment: refill transaction failed', 
                                 $ex->getMessage().'; '.   
                                 $ex->getFile().'; '.
                                 $ex->getLine().'; '.   
                                 '<pre>'.print_r($result->getMerchantData(), true).'</pre>');
        }
    }
}
