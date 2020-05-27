<?php

namespace App\Http\Controllers\Notifications;

use Illuminate\Http\Request;
use NotificationChannels\WebPush\PushSubscription;
use App\Http\Controllers\Controller;

class NotificationsController extends Controller
{

    /**
     * Get user's notifications.
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        if(!$request->ajax()){
            return redirect()->route('user-profile-notifications');
        }
        $user = $request->user();
        // Limit the number of returned notifications, or return all
        $query = $user->unreadNotifications()->select('id', 'created_at', 'data');
        $limit = (int)$request->input('limit', 0);
        if($limit){
            $query = $query->limit($limit);
        }
        $notifications = $query->get()->each(function ($n){
            $data = $n->data;
            $data['body'] = nl2br(htmlspecialchars($data['body']));
            $n->data = $data;
        });
        $total = $user->unreadNotifications()->count();
        return response()->json([
            'state' => true,
            'total' => $total,
            'notifications' => $notifications
        ]);
    }
    
    /**
     * Get user's all notifications history
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function history(Request $request)
    {
        $user = $request->user();
        $notifications = $user->readNotifications()->select('id', 'created_at', 'data')->paginate(21);
        return view('profile.notifications.index', [
            'notifications' => $notifications
        ]);
    }

    /**
     * Mark user's notification as read.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function markAsRead(Request $request, $id)
    {
        $notification = $request->user()
                ->unreadNotifications()
                ->where('id', $id)
                ->first();
        if(!is_null($notification)){
            $notification->markAsRead();
//            event(new NotificationRead($request->user()->id, $id));
        }
        return response()->json([
            'state' => true
        ]);
    }

    /**
     * Mark all user's notifications as read.
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function markAllRead(Request $request)
    {
        $request->user()
                ->unreadNotifications()
                ->get()->each(function ($n){
            $n->markAsRead();
        });
//        event(new NotificationReadAll($request->user()->id));
        return response()->json([
            'state' => true
        ]);
    }

    /**
     * Mark the notification as read and dismiss it from other devices.
     *
     * This method will be accessed by the service worker
     * so the user is not authenticated and it requires an endpoint.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $id
     * @return \Illuminate\Http\Response
     */
    public function dismiss(Request $request, $id)
    {
        if(empty($request->endpoint)){
            return response()->json('Endpoint missing.', 403);
        }
        $subscription = PushSubscription::findByEndpoint($request->endpoint);
        if(is_null($subscription)){
            return response()->json('Subscription not found.', 404);
        }
        $notification = $subscription->user->notifications()->where('id', $id)->first();
        if(is_null($notification)){
            return response()->json('Notification not found.', 404);
        }
        $notification->markAsRead();
//        event(new NotificationRead($subscription->user->id, $id));
    }

}
