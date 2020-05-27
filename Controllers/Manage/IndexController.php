<?php

namespace App\Http\Controllers\Manage;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class IndexController extends Controller
{

    /**
     * @return \Illuminate\View\View
     */
    public function index(Request $request)
    {
        return view('manage.index');
    }
    
}
