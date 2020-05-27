<?php

namespace App\Http\Controllers\ChargingStations;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\ChargingStations\Controller;
use App\Models\ChargingStation;
use App\Models\Statistic;
use App\Models\StatisticDetail;

class StatisticsExpenseController extends Controller
{
    
    private $min_date = '2018-01-01';
    
    /**
     * @param int $id
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function index($id, Request $request)
    {
        $this->station = ChargingStation::with(['ports'])->find($id);
        
        if(!$this->station || !($this->station->owner || $this->station->sharedToUserWithSection(Auth::id(), 'statistics'))){
            abort(404);
        }
        
        $period_input = $request->input('period', '');
        $period = $period_input ? explode('-', $period_input) : [];
        
        $stats_periods = [];
        $back_period = null;
        
        switch(count($period)){
            
            // статистика по годам
            default:
                $stats = $this->getStatsByYears();
                $stats_periods = $stats->keys();
            break;
            
            // статистика по месяцам
            case 1:
                $y = (int)$period[0];
                $y = $y < 2000 ? 2000 : $y;
                $stats = $this->getStatsByMonths($y);
                $back_period = '';
                for($m = 1; $m <= 12; $m ++){
                    $p_date = $y.'-'.($m < 10 ? '0'.$m : $m);
                    if(strtotime($p_date) < strtotime(date('Y-m-d 00:00:00'))){
                        $stats_periods[] = $p_date;
                    }
                }
            break;
            
            // статистика по дням
            case 2:
                $y = (int)$period[0];
                $y = $y < 2000 ? 2000 : $y;
                $m = (int)$period[1];
                $m = $m < 1 || $m > 12 ? 1 : $m;
                $month = $y.'-'.($m < 10 ? '0'.$m : $m);
                $stats = $this->getStatsByDays($month);
                $carbon = Carbon::parse($month);
                $back_period = $y;
                for($d = 1; $d <= $carbon->daysInMonth; $d ++){
                    $p_date = $month.'-'.($d < 10 ? '0'.$d : $d);
                    if(strtotime($p_date) < strtotime(date('Y-m-d 00:00:00'))){
                        $stats_periods[] = $p_date;
                    }
                }
            break;
            
            // статистика за день
            case 3:
                return $this->statsByDay($period_input);
        }
        
        return view('charging_stations.statistics.expense.index', [
            'station' => $this->station,
            'stats' => $stats,
            'stats_periods' => $stats_periods,
            'back_period' => $back_period
        ]);
    }
    
    /**
     * @param string $date
     * @return \Illuminate\View\View
     */
    private function statsByDay($date)
    {
        $stats = false;
        $back_period = null;
        $time = strtotime($date);
        if($time > 0){
            $date = date('Y-m-d', $time);
            $back_period = date('Y-m', $time);
            $stats = Statistic::with(['details', 'port'])
                        ->where('charging_station_id', $this->station->id)
                        ->where('date', $date)
                        ->get();
        }
        return view('charging_stations.statistics.expense.day', [
            'station' => $this->station,
            'stats' => $stats,
            'date' => $date,
            'back_period' => $back_period
        ]);
    }
    
    private function getStatsByYears()
    {
        $stats = Statistic::selectRaw('
            YEAR(date) as d, 
            SUM(used) as used, SUM(cost) as cost, port_id, 
            GROUP_CONCAT(statistics.id) as ids, statistics.charging_station_id, p.relay_num
        ')
        ->leftJoin('charging_station_ports as p', 'p.id', '=', 'statistics.port_id')
        ->where('statistics.charging_station_id', $this->station->id)
        ->groupBy('d', 'port_id')
        ->orderBy('relay_num')
        ->get()
        ->groupBy([
            'd'
        ]);
        return $stats;
    }
    
    private function getStatsByMonths($year)
    {
        $stats = Statistic::selectRaw('
            DATE_FORMAT(date, "%Y-%m") as d, 
            SUM(used) as used, SUM(cost) as cost, port_id, 
            GROUP_CONCAT(statistics.id) as ids, statistics.charging_station_id, p.relay_num
        ')
        ->leftJoin('charging_station_ports as p', 'p.id', '=', 'statistics.port_id')
        ->where('statistics.charging_station_id', $this->station->id)
        ->whereRaw('YEAR(date) = "'.$year.'"')
        ->groupBy('d', 'port_id')
        ->orderBy('relay_num')
        ->get()
        ->groupBy([
            'd'
        ]);
        return $stats;
    }
    
    private function getStatsByDays($year_month)
    {
        $stats = Statistic::selectRaw('
            date as d, 
            SUM(used) as used, SUM(cost) as cost, port_id, 
            GROUP_CONCAT(statistics.id) as ids, statistics.charging_station_id, p.relay_num
        ')
        ->leftJoin('charging_station_ports as p', 'p.id', '=', 'statistics.port_id')
        ->where('statistics.charging_station_id', $this->station->id)
        ->whereRaw('DATE_FORMAT(date, "%Y-%m") = "'.$year_month.'"')
        ->groupBy('d', 'port_id')
        ->orderBy('relay_num')
        ->get()
        ->groupBy([
            'd'
        ]);
        return $stats;
    }
    
    private function getStatsByTariffs(array $stats_ids)
    {
        $ids = $stats_ids->implode(',');
        $details = StatisticDetail::selectRaw('
                        tariff, tariff_time_from, tariff_time_by, 
                        SUM(used) as used, SUM(cost) as cost
                   ')
                   ->whereRaw('statistic_id IN ('.$ids.')')
                   ->groupBy('tariff')
                   ->orderBy('tariff')
                   ->get();
        return $details;
    }
}
