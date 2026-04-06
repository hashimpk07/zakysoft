<?php
namespace App\Services\General\SystemSetting;

use App\Interfaces\General\SystemSettingInterface;
use Illuminate\Support\Facades\Auth;
use App\ShiftRule;
use App\Exports\ShiftRuleImportErrorExport;
use App\Imports\ShiftCaptainRuleImport;
use App\Imports\ShiftRuleImport;
use Maatwebsite\Excel\Facades\Excel;


class ShiftRuleService
{
    public function __construct(private readonly SystemSettingInterface $systemSettingInterface)
    {
    }

    public function createShiftRule(array $data)
    {
        return $this->systemSettingInterface->createShiftRule($data);
    }

    public function getShiftRuleList(array $filters,$perPage)
    {
        return $this->systemSettingInterface->getShiftRuleList($filters,$perPage);
    }

    public function getShiftRuleDetails(int $ruleId)
    {
        return $this->systemSettingInterface->getShiftRuleDetails($ruleId);    
    }

    public function getShiftRuleLogsDetails(int $ruleId)
    {
        return $this->systemSettingInterface->getShiftRuleLogsDetails($ruleId);    
    }

    public function getShiftRuleSelectedCaptain(int $ruleId)
    {
        return $this->systemSettingInterface->getShiftRuleSelectedCaptain($ruleId);   
    }

    public function getShiftRuleCaptainList(array $filters,int $shift_rule)
    {
        return $this->systemSettingInterface->getShiftRuleCaptainList($filters,$shift_rule);
    }

    public function updateShiftRule(int $id, array $data)
    {
        return $this->systemSettingInterface->updateShiftRule($id,$data);
    }

    public function assignShiftRuleCaptainList(int $shiftRule,array $filters,int $perPage)
    {
        return $this->systemSettingInterface->assignShiftRuleCaptainList($filters,$shiftRule,$perPage);
    }

    public function assignShiftRuleByCaptain(array $data)
    {
        return $this->systemSettingInterface->assignShiftRuleByCaptain($data);
    }
    public function importShiftCaptainAssignRule($request)
    {
        $import = new ShiftCaptainRuleImport($request->shift_rule_id);
        $import->import($request->file('import'));

        if ($import->failures()->isNotEmpty()) {
            $errors = $import->failures()->map(function ($failed) {
                return array_merge(
                    $failed->values(),
                    ["errors" => implode(', ', $failed->errors())]
                );
            });
            $fileName = "shift-rule-import-errors-" . now()->format('Y-m-d-H-i-s') . ".xlsx";
            Excel::store( new ShiftRuleImportErrorExport($errors),"public/zone-import-errors/$fileName" );
            return [
                'message' => 'Some records failed to import',
                'data' => [
                    'error_file' => asset("storage/zone-import-errors/$fileName")
                ]
            ];
        }

        return [
            'message' => 'Shift import successfully completed',
            'data' => null
        ];
    }
}


