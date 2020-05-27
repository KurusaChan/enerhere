<?php

namespace App\Http\Controllers\ChargingStations;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\ChargingStations\Controller;
use App\Models\ChargingStation;

class MapController extends Controller
{

    /**
     * @param boolean $only_available true
     * @return \Illuminate\Database\Query\Builder
     */
    public function getStationsForMapQuery()
    {
        $stations = 
            ChargingStation::select(
                'charging_stations.id', 
                'charging_stations.name', 
                'charging_stations.map_lat', 
                'charging_stations.map_lng', 
                'charging_stations.type', 
                'charging_stations.active', 
                DB::raw('COUNT(p.id) as ports_qty'),
                DB::raw('SUM(IF(p.id IS NOT NULL AND p.current_connection_id IS NOT NULL, 1, 0)) qty_connections'),
                DB::raw('SUM(IF(p.current_status='.config('charging.status.offline').', 1, 0)) qty_offline'),
                DB::raw('SUM(IF(p.current_status IN ('.implode(',', config('charging.troubles_statuses')).'), 1, 0)) qty_broken')
            )
            ->available()
            ->availableInLists()
            ->leftJoin('charging_station_ports as p', function ($join) {
                $join->on('p.charging_station_id', '=', 'charging_stations.id')
                     ->whereIn('p.active', config('charging.is_port_active'));
            })
            ->groupBy('charging_stations.id');
        return $stations;
    }
    
    /**
     * @param ChargingStation $station
     * @return string
     */
    private function getIconName(ChargingStation $station)
    {
        $marker = 'marker';
        $marker_t = 'marker-t'.$station->type;
        
        if($station->type == config('charging.station_type.foreign')){
            return $marker_t;
        }
        if(!$station->ports_qty){
            return $marker_t.'-broken';
        }
        if($station->active == config('charging.station_active.dismantled')){
            return $marker_t.'-broken';
        }
        if($station->ports_qty == $station->qty_broken){
            return $marker_t.'-broken';
        }
        if($station->ports_qty == $station->qty_offline){
            return $marker_t.'-offline';
        }
        if($station->ports_qty == ($station->qty_offline + $station->qty_broken)){
            return $marker_t.'-offline';
        }
        if(($station->ports_qty - $station->qty_broken) == $station->qty_connections){
            return $marker.'-charging';
        }
        return $marker_t;
    }
    
    /**
     * @param ChargingStation $station
     * @return string
     */
    private function getIcon(ChargingStation $station)
    {
        $icon = $this->getIconName($station).'.png';
        if(!file_exists(public_path('images/map/'.$icon))){
            return 'marker-default.png'; 
        }
        return $icon;
    }
    
    private function getIconSize($icon)
    {
        $size = getimagesize(public_path('images/map/'.$icon));
        return [$size[0], $size[1]];
    }
    
    /**
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $stations = $this->getStationsForMapQuery()->get();
        
        $markers = [];
        $stations->each(function($station) use (&$markers){
            $icon = $this->getIcon($station);
            $markers[] = [
                'id' => $station->id,
                'title' => $station->title,
                'lat' => (float)$station->map_lat,
                'lng' => (float)$station->map_lng,
                'icon' => $icon,
                'icon_size' => $this->getIconSize($icon)
            ];
        });
        
        unset($stations);
        return view('charging_stations.map',[
            'markers' => $markers
        ]);
    }
    
    /**
     * @param int $id
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function marker($id, Request $request)
    {
        if(!$request->ajax()){
            return redirect()->route('charging-stations-page', ['id' => $id]);
        }
        $station = 
            ChargingStation::available()
            ->with(['ports' => function($query){
                $query->available();
            }])->find($id);
        if(!$station){
            return response()->json(['state' => true]);
        }
        
        return response()->json([
            'state' => true,
            'content' => view('charging_stations.map.marker', [
                'station' => $station
            ])->render()
        ]);
    }
    
}
