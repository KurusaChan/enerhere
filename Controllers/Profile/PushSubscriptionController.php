<?php
namespace App\Http\Controllers\Profile;

use Illuminate\Http\Request;
use Illuminate\Foundation\Validation\ValidatesRequests;
use App\Http\Controllers\Controller;

class PushSubscriptionController extends Controller
{
    use ValidatesRequests;
    
    /**
     * Update user's subscription.
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request)
    {
        $this->validate($request, ['endpoint' => 'required']);
        
        $request->user()->updatePushSubscription(
            $request->endpoint,
            $request->key,
            $request->token
        );
        return response()->json([
            'state' => true
        ]);
    }
    
    /**
     * Delete the specified subscription.
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(Request $request)
    {
        $this->validate($request, ['endpoint' => 'required']);
        
        $request->user()->deletePushSubscription($request->endpoint);
        
        return response()->json(['state' => true], 204);
    }
}