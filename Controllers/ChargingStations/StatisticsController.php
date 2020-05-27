<?php

namespace App\Http\Controllers\ChargingStations;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use App\Models\ChargingStation;
use App\Http\Controllers\ChargingStations\Controller;
use App\Models\ChargingStationInfo;

class StatisticsController extends Controller
{
    private $min_date = '2018-01-01';
    
    /**
     * @var int шаг для точек, в секундах для группировки инфо
     */
    private $step_size = 60; 
    
    /**
     * @var aray  периоды
     */
    private $periods = [
        'hour' => [
            'name' => '1 час',
            'period_str' => '1 hour',
            'label_format' => 'H:i'
        ],
        'sixhours' => [
            'name' => '6 часов',
            'period_str' => '6 hour',
            'label_format' => 'H:i'
        ],
        'day' => [
            'name' => '1 день',
            'period_str' => '24 hour',
            'label_format' => 'H:i'
        ],
        'week' => [
            'name' => 'Неделя',
            'period_str' => '1 week',
            'label_format' => 'd M H:i'
        ],
        'month' => [
            'name' => 'Месяц',
            'period_str' => '1 month',
            'label_format' => 'd M H:i'
        ],
        'year' => [
            'name' => 'Год',
            'period_str' => '1 year',
            'label_format' => 'd M H:i'
        ]
    ];

    /**
     * @var string текущий период
     */
    private $period = 'hour';
    
    /**
     * Станция
     * @var ChargingStation 
     */
    private $station = null;

    /**
     * @var boolean|Carbon 
     */
    private $date = false;
    
    /**
     * @param int $id
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function index($id, Request $request)
    {
        $this->station = ChargingStation::with(['ports' => function($query){
                            $query->available();
                        }])->find($id);
        if(!$this->station || !($this->station->owner || $this->station->sharedToUserWithSection(Auth::id(), 'statistics'))){
            abort(404);
        }
        
        $period = $request->input('period');
        if(isset($this->periods[$period])){
            $this->period = $period;
        }
        $date_input = $request->input('date');
        if(!is_null($date_input)){
            $date = strtotime($date_input);
            if($date > 0 && $date >= strtotime($this->min_date) && $date <= time()){
                $this->date = Carbon::createFromTimestamp($date, date('P'));
            }else{
                return redirect()->route('charging-stations-statistics', ['id' => $id]);
            }
        }
        
        if(!$this->station->isForeign){
            $stats = $this->getStats();
        }else{
            $stats = false;
        }
        
        return view('charging_stations.statistics.charts.index', [
            'station' => $this->station,
            'stats' => $stats,
            'periods' => $this->periods,
            'current_period' => $this->period,
            'date' => $this->date,
            'min_date' => $this->min_date
        ]);
    }
    
    /**
     * @return array
     */
    private function getStats()
    {
        $start = $this->getPeriodStart();
        $end = $this->getPeriodEnd();
        $step = $this->getStepForPeriod();
        $temp_devices = $this->getStationTempDevices();
        
        $select = [];
        // температура
        for($t = 1; $t <= $temp_devices; $t++){
            $field = 'temp_'.$t;
            $select[] = 'IFNULL(SUBSTRING_INDEX(GROUP_CONCAT('.$field.' ORDER BY id),",",1), 0) as '.$field;
        }
        $infos = ChargingStationInfo::notBroken()->with('meterValues')
                ->selectRaw(
                    'UNIX_TIMESTAMP(created_at) DIV '.$step.' AS division, 
                     SUBSTRING_INDEX(GROUP_CONCAT(id ORDER BY id), ",", 1) as id
                    '.($select ? ','.implode(',', $select) : '')
                )
                ->where('charging_station_id', $this->station->id)
                ->whereBetween('created_at', [Carbon::createFromTimestamp($start, date('P')), Carbon::createFromTimestamp($end, date('P'))])
                ->groupBy('division')
                ->get()
                ->keyBy('division');
        
        $min_disivion = ceil($start / $step);
        $max_disivion = ceil($end / $step) - 1;
        $all_divisions = range($min_disivion, $max_disivion);
        $labels = array_map(function($v) use ($step){
            $format = $this->periods[$this->period]['label_format'];
            return date($format, $v * $step);
        }, $all_divisions);
        
        $datasets = [
            'u' => [],
            'i' => [],
            'p' => [],
            't' => []
        ];
        
        foreach($all_divisions as $division){
            $division = (int)$division;
            $info = $infos->has($division) ? $infos[$division] : [];
            $meter_values = !empty($info) ? $info->meterValues->keyBy('relay_num') : [];
            
            foreach($this->station->ports as $port){
                $metrics = $meter_values[$port->relay_num] ?? [];
                // для 3 фазной мощность суммарная и инфа по фазам
                
                // u, i, p по фазам
                for($f = 1; $f <= ($port->is1PhasePort ? 1 : 3); $f ++){
                    $i = ($f - 1) + (($port->relay_num -1) * 3);
                    $datasets['u'][$i]['label'] = __('Фаза '.$f.' на порту '.$port->relay_num);
                    $datasets['u'][$i]['data'][] = $metrics ? $metrics->{'u_'.$f} : 0;
                    $datasets['i'][$i]['label'] = __('Фаза '.$f.' на порту '.$port->relay_num);
                    $datasets['i'][$i]['data'][] = $metrics ? $metrics->{'i_'.$f} : 0;
                }
                $datasets['p'][($port->relay_num - 1) * 3]['label'] = __('Мощность на порту '.$port->relay_num);
                if($port->is1PhasePort){
                    $datasets['p'][($port->relay_num - 1) * 3]['data'][] = $metrics ? $metrics->p_1 : 0;
                }else{
                    $datasets['p'][($port->relay_num - 1) * 3]['data'][] = $metrics ? array_sum([$metrics->p_1, $metrics->p_2, $metrics->p_3]) : 0;
                }
            }
            
            // температура
            for($t = 1; $t <= $temp_devices; $t ++){
                $ti = $t - 1;
                $datasets['t'][$ti]['label'] = __('Датчик '.$t);
                $temp = $info['temp_'.$t] ?? 0;
                $datasets['t'][$ti]['data'][] = $temp > 300 ? 300 : $temp;
            }
        }
        
        unset($info);
        
        return [
            'labels' => $labels,
            'datasets' => $datasets
        ];
    }
    
    /**
     * @return int
     */
    private function getStationTempDevices()
    {
        $last_info = ChargingStationInfo::notBroken()->where('charging_station_id', $this->station->id)
                    ->orderByDesc('id')->select('temp_devices')->limit(1)->first();
        return $last_info ? $last_info->temp_devices : 0;
    }
    
    /**
     * @return int
     */
    private function getStepForPeriod()
    {
        $period_hours = (time() - strtotime('-'.$this->periods[$this->period]['period_str'])) / 3600;
        return $this->step_size * $period_hours;
    }
    
    /**
     * @return int
     */
    private function getPeriodStart()
    {
        $min_start = strtotime($this->min_date);
        if($this->date){
            $start = $this->{'getDatePeriodStartFor'.ucfirst($this->period).'Period'}();
        }else{
            $start = strtotime('-'.$this->periods[$this->period]['period_str']);
        }
        return $start >= $min_start ? $start : $min_start;
    }
    private function getDatePeriodStartForHourPeriod()
    {
        $date = $this->date->copy()->addMinute(-30);
        return $date->timestamp;
    }
    private function getDatePeriodStartForSixhoursPeriod()
    {
        $date = $this->date->copy()->addHour(-3);
        return $date->timestamp;
    }
    private function getDatePeriodStartForDayPeriod()
    {
        $date = $this->date->copy();
        $date->hour = 00;
        $date->minute = 00;
        $date->second = 00;
        return $date->timestamp;
    }
    private function getDatePeriodStartForWeekPeriod()
    {
        $date = $this->date->copy()->startOfWeek();
        return $date->timestamp;
    }
    private function getDatePeriodStartForMonthPeriod()
    {
        $date = $this->date->copy()->startOfMonth();
        return $date->timestamp;
    }
    private function getDatePeriodStartForYearPeriod()
    {
        $date = $this->date->copy()->startOfYear();
        return $date->timestamp;
    }
    
    /**
     * @return int
     */
    private function getPeriodEnd()
    {
        $max_end = time();
        if($this->date){
            $end = $this->{'getDatePeriodEndFor'.ucfirst($this->period).'Period'}();
        }else{
            $end = time();
        }
        return $end <= $max_end ? $end : $max_end;
    }
    private function getDatePeriodEndForHourPeriod()
    {
        $date = $this->date->copy()->addMinute(30);
        return $date->timestamp;
    }
    private function getDatePeriodEndForSixhoursPeriod()
    {
        $date = $this->date->copy()->addHour(3);
        return $date->timestamp;
    }
    private function getDatePeriodEndForDayPeriod()
    {
        $date = $this->date->copy();
        $date->hour = 23;
        $date->minute = 59;
        $date->second = 59;
        return $date->timestamp;
    }
    private function getDatePeriodEndForWeekPeriod()
    {
        $date = $this->date->copy()->endOfWeek();
        return $date->timestamp;
    }
    private function getDatePeriodEndForMonthPeriod()
    {
        $date = $this->date->copy()->endOfMonth();
        return $date->timestamp;
    }
    private function getDatePeriodEndForYearPeriod()
    {
        $date = $this->date->copy()->endOfYear();
        return $date->timestamp;
    }
    
}
