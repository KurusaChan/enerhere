<?php

namespace App\Http\Controllers\Manage;

use App\Models\ChargingStationImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use App\Http\Controllers\Controller;

class StationImagesController extends Controller {

    /**
     * @return \Illuminate\View\View
     */
    public function index(Request $request)
    {
        return view('manage.images.images', [
            'station_images' => ChargingStationImage::with('user', 'chargingStation')->orderBy('created_at', 'DESC')->paginate(50)
        ]);
    }

}
