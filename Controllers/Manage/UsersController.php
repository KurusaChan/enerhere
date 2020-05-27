<?php

namespace App\Http\Controllers\Manage;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserBalanceTransaction;
use App\Services\Balance\Balance;
use App\Services\Balance\Transaction;

class UsersController extends Controller
{

    /**
     * @return \Illuminate\View\View
     */
    public function index(Request $request)
    {
        return view('manage.users.users',[
            'users' => User::paginate(30)
        ]);
    }

    /**
     * @return \Illuminate\View\View
     */
    public function edit(Request $request, $id)
    {
        $user = User::findOrFail($id);
        
        return view('manage.users.user.edit',[
            'user' => $user
        ]);
    }

    /**
     * @return \Illuminate\View\View
     */
    public function saveUser(Request $request, $id)
    {
        $user = User::findOrFail($id);
        
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'lastname' => ['required', 'string', 'max:255']
        ]);
        $validator->validate();
        
        $user->name = $request->input('name');
        $user->lastname = $request->input('lastname');
        $user->inn = $request->input('inn') ?? null;
        $user->phone = $request->input('phone', null);
        
        $discount = $request->input('discount_percent', null);
        $user->discount_percent = $discount > 0 && $discount <= 100 ? $discount : null;
        $user->save();
        
        if($user->discount_percent){
            $connections = $user->chargingConnections;
            // обновить скидку на активных конекшенах
            if($connections){
                foreach($connections as $connection){
                    $connection->discount_percent = $user->discount_percent;
                    $connection->save();
                }
            }
        }
        
        return redirect()->back();
    }

    /**
     * @return \Illuminate\View\View
     */
    public function balance(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $balance = new Balance($user);
        
        return view('manage.users.user.balance',[
            'balance' => $balance,
            'user' => $user
        ]);
    }

    /**
     * @return \Illuminate\View\View
     */
    public function balanceTransactions(Request $request, $id)
    {
        $user = User::findOrFail($id);
        
        return view('manage.users.user.transactions',[
            'user' => $user,
            'transactions' => UserBalanceTransaction::where('user_id', $user->id)->orderByDesc('created_at')->paginate(50)
        ]);
    }

    /**
     * @return \Illuminate\Http\RedirectResponse
     */
    public function balanceRefill(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $balance = new Balance($user);
        
        $status = false;
        $message = '';
        
        $summ = $request->input('summ', 0);
        $comment = $request->input('comment');
        if($summ != 0){
            try{
                if($summ > 0){
                    $tr = Transaction::incomeByAdmin();
                }else{
                    $summ *= -1;
                    $tr = Transaction::expenseByAdmin();
                }
                $tr->setUserId($user->id);
                $tr->setObjectId(Auth::user()->id);
                $tr->setSumm($summ);
                if($comment){
                    $tr->setComment($comment);
                }
                $tr->save();
                $status = true;
            }catch(\App\Exceptions\TransactionBuilderException $ex){ 
                $message = $ex->getMessage();
            }
        }
        
        return redirect()->route('manage-users-balance', ['id' => $user->id])
                         ->with(['refill-status' => $status, 'refill-message' => $message]);
    }
    
}
