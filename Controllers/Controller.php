<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Mail;
use App\Mail\SimpleHtmlMail;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;
    
    /**
     * @param string $subj
     * @param string $body
     */
    public function notifyAdmin($subj, $body)
    {
        $admin_email = env('APP_NOTIFICATIONS_EMAIL');
        if($admin_email){
            
            $country_city = '';
            if(function_exists('geoip_record_by_name')){
                $record = @geoip_record_by_name($ip);
                $country = isset($record['country_name']) && $record['country_name'] ? $record['country_name'] : 
                          (isset($record['country_code']) && $record['country_code'] ? $record['country_code'] : ''); 
                $city = isset($record['city']) && $record['city'] ? ' ('.$record['city'].')' : '';
                if($country || $city){
                    $country_city = ', '.$country.$city;
                }
            }
            $xip = ISSET($_SERVER['HTTP_X_FORWARDED_FOR']) ? ' xIP: '.$_SERVER['HTTP_X_FORWARDED_FOR'] : '';
            $body .= '<hr>IP: '.$_SERVER['REMOTE_ADDR'].$xip.$country_city.
                      '<br>From: '.(isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : $_SERVER['HTTP_HOST']).
                      '<br>User referer: '.htmlspecialchars(urldecode(request()->cookie('referer', '-')));
            
            $mail = Mail::to(explode(',', $admin_email));
            $mail->send(new SimpleHtmlMail($subj, $body));
        }
    }
}
