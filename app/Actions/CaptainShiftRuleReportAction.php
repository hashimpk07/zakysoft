<?php

namespace App\Actions;

use App\Captain;
use App\ShiftStatus;
use Carbon\Carbon;
use App\CaptainShiftRuleReport;
use Illuminate\Support\Facades\Log;

class CaptainShiftRuleReportAction
{
    public function execute(string $date): void
    {
        $date = Carbon::parse($date)->toDateString();
        Log::channel('commission')->info("Shift Rule Execution [date => $date]");

        $businessStart = Carbon::parse($date)->setTime(6, 0, 0);
        $businessEnd   = (clone $businessStart)->addDay()->setTime(5, 59, 59);

        Log::channel('commission')->info("Business Window [start: $businessStart, end: $businessEnd]");

        // Get all captains with assigned shift rules
        $carbonDate = Carbon::parse($date);
        $dayNumber = $carbonDate->dayOfWeek; 
        $selectedDate = Carbon::parse($date)->startOfDay();

        Log::channel('commission')->info("Tested Shif B end Hashim #{$carbonDate} $dayNumber");

        // All shift rules for captains fetched with settings for the specific day
        $captains = Captain::with(['shiftRule.settings' => function ($q) use ($dayNumber) {
            $q->where('day', $dayNumber);
        }])
        ->whereNotNull('shift_rule_id') 
        ->get();

        Log::channel('commission')->info("Tested Shif B end Hashim #{$captains}");

        foreach ($captains as $captain) {

            if (empty($captain->shiftRule)) {
                continue;
            }

            $setting = optional($captain->shiftRule->settings->first());

            if (!$setting) {
                continue;
            }

            $captainId = $captain->id;
            $shiftRuleId = $captain->shift_rule_id;
            $actualShiftAStart = Carbon::parse($selectedDate->toDateString() . ' ' . $setting->shift_a_start);
            $actualShiftAEnd   = Carbon::parse($selectedDate->toDateString() . ' ' . $setting->shift_a_end);
            $actualShiftBStart = $setting->shift_b_start? Carbon::parse($selectedDate->toDateString() . ' ' . $setting->shift_b_start)
                                : null;
            $actualShiftBEnd = $setting->shift_b_end  ? Carbon::parse($selectedDate->toDateString() . ' ' . $setting->shift_b_end)
                                : null;


            // Get all shift statuses for the business day ( Attendance records )
            $shiftStatuses = ShiftStatus::where('captain_id', $captainId)
                ->where(function ($query) use ($businessStart, $businessEnd) {
                    $query->whereBetween('shift_start', [$businessStart, $businessEnd])
                        ->orWhereBetween('shift_end', [$businessStart, $businessEnd]);
                })
                ->orderBy('shift_start')
                ->get();

            // If Absent marked the Captain with no shifts
            if ($shiftStatuses->isEmpty()) {

                $shiftAStart = '00:00:00';
                $shiftAEnd   = '00:00:00';
                $shiftADuration = 0;
                $totalDuration  = 0;
                $actualShiftADuration = round($actualShiftAEnd->floatDiffInHours($actualShiftAStart), 2);

                if($actualShiftBStart){
                    $shiftBStart = '00:00:00';
                    $shiftBEnd   = '00:00:00';
                    $shiftBDuration = 0;
                    $actualShiftBDuration = round($actualShiftBEnd->floatDiffInHours($shiftBStart), 2);
                    //  Log::channel('commission')->info("Shif B Duration A [{$actualShiftBDuration}h]");
                }else{
                    $shiftBStart = null;
                    $shiftBEnd   = null;
                    $shiftBDuration = null;
                    $actualShiftBDuration = null;
                    //  Log::channel('commission')->info("Shif B Duration B [{$actualShiftBDuration}h]");
                }

                if($setting->shift_b_start == null || $setting->shift_b_end == null){
                    $shiftB = null;
                }else{
                    $shiftB = $setting->shift_b_start. ' to ' .$setting->shift_b_end;
                }

                if($setting->shift_a_start){
                    $shiftA = $setting->shift_a_start. ' to ' .$setting->shift_a_end;
                }else{
                    $shiftA = null;
                }

                CaptainShiftRuleReport::updateOrCreate(
                    [
                        'captain_id' => $captainId,
                        'date'   => $date,
                    ],
                    [
                        'shift_id'          => $shiftRuleId,
                        'shift_a_start_time' => $shiftAStart,
                        'shift_a_end_time'   => $shiftAEnd,
                        'shift_a'            => $shiftA,
                        'shift_b_start_time' => $shiftBStart,
                        'shift_b_end_time'   => $shiftBEnd,
                        'shift_b'            => $shiftB,
                        'shift_duration_a'   => $actualShiftADuration,
                        'captain_working_duration_a' => $shiftADuration,
                        'shift_duration_b'   => $actualShiftBDuration,
                        'captain_working_duration_b' => $shiftBDuration,
                        'total_duration'     => $totalDuration,
                    ]
                );
            }

            // --- Initialize ---
            $bufferMinutes = 90; 
            $totalHours    = 0.0;
            $shiftAStart   = null;
            $shiftAEnd     = null;
            $shiftBStart   = null;
            $shiftBEnd     = null;

            for ( $i = 0; $i < $shiftStatuses->count(); $i++ ) {

                $current = $shiftStatuses[$i];
                $start = Carbon::parse($current->shift_start);
                $end = Carbon::parse($current->shift_end);

                if (!$end) continue;
                if ($start->lt($businessStart)) $start = $businessStart;
                if ($end->gt($businessEnd)) $end = $businessEnd;

                // Only one shift Applied capatain not Assigned to B shift
                if(!$actualShiftBStart && !$actualShiftBEnd){
                
                    $shiftAStart = Carbon::parse($shiftStatuses->first()->shift_start);
                    $shiftAEnd   = Carbon::parse($shiftStatuses->last()->shift_end);

                    $shiftADuration = round($shiftAEnd->floatDiffInHours($shiftAStart), 2);

                    $duration    = $end->floatDiffInHours($start); 
                    $totalHours += $duration;   
                    $totalDuration = round($totalHours, 2);

                    $shiftBStart = null;
                    $shiftBEnd   = null;
                    $shiftBDuration = 0;
                    $shiftBDuration = 0;
                    break;
                }else{
                    // Multiple shifts Applied captain ( Shift A and Shift B )

                    // Captain with  one time log entry
                    if( $shiftStatuses->count() == 1 ){
                        $shiftAStart = Carbon::parse($shiftStatuses->first()->shift_start);
                        if($actualShiftAEnd > Carbon::parse($shiftStatuses->last()->shift_end)){
                            $shiftAEnd = Carbon::parse($shiftStatuses->last()->shift_end);
                        }else{
                            $shiftAEnd = $actualShiftAEnd;
                            
                        }   
                       
                        $shiftADuration = round($shiftAEnd->floatDiffInHours($shiftAStart), 2);
                        
                        if($actualShiftBStart < Carbon::parse($shiftStatuses->last()->shift_end)){
                            $shiftBStart = Carbon::parse($actualShiftBStart);
                            $shiftBEnd = Carbon::parse($shiftStatuses->last()->shift_end);
                            $shiftBDuration = round($shiftBEnd->floatDiffInHours($shiftBStart), 2);
                        }else{
                            // Captain did not work in Shift B
                            $shiftBStart = '00:00:00';
                            $shiftBEnd = '00:00:00';
                            $shiftBDuration = 0;
                        }
                       

                        $duration = $end->floatDiffInHours($start); 
                        $totalHours += $duration;   
                        $totalDuration = round($totalHours, 2);
                        break;
                    
                       // Log::channel('commission')->info("Single Login: start [$start] end [$end] duration [$duration] End totalHours [$totalHours] totalDuration [{$totalDuration}h]");
                    
                    }
                    // If first shift
                    if (!$shiftAStart) {
                        $shiftAStart = $start;
                    }

                    // Always track total duration
                    $totalHours += $end->floatDiffInHours($start);

                    // Check if next shift has a big gap to separate A and B shifts
                    $next = $shiftStatuses[$i + 1] ?? null;
                    if ($next) {
                        $nextStart = Carbon::parse($next->shift_start);
                        $gap = $end->diffInMinutes($nextStart);

                        if ($gap > $bufferMinutes && !$shiftBStart) {
                            $shiftAEnd = $end;
                            $shiftBStart = $nextStart;
                        }
                    }
                    $shiftBEnd = $end;
                }
               
              
                // --- Duration Calculations ---
                $shiftADuration = ($shiftAStart && $shiftAEnd)
                    ? round($shiftAEnd->floatDiffInHours($shiftAStart), 2)
                    : 0;

                $shiftBDuration = ($shiftBStart && $shiftBEnd)
                    ? round($shiftBEnd->floatDiffInHours($shiftBStart), 2)
                    : 0;

                $totalDuration = round($totalHours, 2);

            }
            $actualShiftADuration = round($actualShiftAEnd->floatDiffInHours($actualShiftAStart), 2);

            if ($actualShiftBStart && $actualShiftBEnd) {
                $actualShiftBDuration = round($actualShiftBEnd->floatDiffInHours($actualShiftBStart));

            } else {
                $actualShiftBDuration = 0;
            }
            // Log::channel('commission')->info("Captain #{$captain->id} — Dual Shift Mode");
            // Log::channel('commission')->info("  Shift A: Start [$shiftAStart] End [$shiftAEnd] Duration [{$shiftADuration}h]");
            // Log::channel('commission')->info("  Shift B: Start [$shiftBStart] End [$shiftBEnd] Duration [{$shiftBDuration}h]");
            // Log::channel('commission')->info("  Total Duration: {$totalHours}h");

            if($setting->shift_b_start == null || $setting->shift_b_end == null){
               $shiftB = null;
            }else{
               $shiftB = $setting->shift_b_start. ' to ' .$setting->shift_b_end;
            }

            if($setting->shift_a_start){
                $shiftA = $setting->shift_a_start. ' to ' .$setting->shift_a_end;
            }else{
                $shiftA = null;
            }
        
            CaptainShiftRuleReport::updateOrCreate(
                [
                    'captain_id' => $captainId,
                    'date'   => $date,
                ],
                [
                    'shift_id'           => $shiftRuleId,
                    'shift_a_start_time' => $shiftAStart,
                    'shift_a_end_time'   => $shiftAEnd,
                    'shift_a'            => $shiftA,
                    'shift_b_start_time' => $shiftBStart,
                    'shift_b_end_time'   => $shiftBEnd,
                    'shift_b'            => $shiftB,
                    'shift_duration_a'   => $actualShiftADuration,
                    'captain_working_duration_a' => $shiftADuration,
                    'shift_duration_b'   => $actualShiftBDuration,
                    'captain_working_duration_b' => $shiftBDuration,
                    'total_duration'     => $totalDuration,
                ]
            );
           
        }
    }
}
