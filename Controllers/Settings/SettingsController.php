<?php

namespace App\Http\Controllers\Settings;

use Illuminate\Http\Request;
use App\Models\Setting;
use App\Facades\Settings;
use App\Http\Controllers\Controller;

class SettingsController extends Controller
{

    public function save(Request $request)
    {
        $settings = $request->input('settings');
        
        foreach($settings as $id => $value){
            $s = Setting::find($id);
            if($s){
                $s->update(['value' => $value]);
            }
        }
        
        return redirect()->back();
    }
    
}
