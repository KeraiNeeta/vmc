<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Services\MasterDataManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MasterDataController extends Controller
{
    private const TITLES = [
        'stations' => 'Fire station',
        'wards' => 'Ward',
        'vehicles' => 'Vehicle',
        'teams' => 'Response team',
    ];

    public function create(Request $request, string $kind, MasterDataManager $manager): View
    {
        return $this->form($request, $kind, null, $manager);
    }

    public function edit(Request $request, string $id, string $kind, MasterDataManager $manager): View
    {
        $record = $manager->find($kind, $id);
        $manager->authorizeWebRecord($kind, $record, $request->user());

        return $this->form($request, $kind, $record, $manager);
    }

    public function store(Request $request, string $kind, MasterDataManager $manager, AuditLogger $auditLogger): RedirectResponse
    {
        $manager->create($request, $kind, $request->user(), $auditLogger);

        return redirect()->route('app.'.$kind)->with('status', self::TITLES[$kind].' created.');
    }

    public function update(Request $request, string $id, string $kind, MasterDataManager $manager, AuditLogger $auditLogger): RedirectResponse
    {
        $record = $manager->find($kind, $id);
        $manager->update($request, $kind, $record, $request->user(), $auditLogger);

        return redirect()->route('app.'.$kind)->with('status', self::TITLES[$kind].' updated.');
    }

    public function destroy(Request $request, string $id, string $kind, MasterDataManager $manager, AuditLogger $auditLogger): RedirectResponse
    {
        $manager->delete($kind, $manager->find($kind, $id), $request->user(), $auditLogger);

        return redirect()->route('app.'.$kind)->with('status', self::TITLES[$kind].' archived.');
    }

    private function form(Request $request, string $kind, ?Model $record, MasterDataManager $manager): View
    {
        abort_unless(isset(self::TITLES[$kind]), 404);
        $stations = $manager->stationsFor($request->user());
        $users = $manager->usersFor($request->user());

        if ($record && $kind === 'wards') {
            $record->load('fireStations:id,name,code');
        } elseif ($record && $kind === 'teams') {
            $record->load('members:id,name');
        }

        return view('master-data.form', [
            'kind' => $kind,
            'title' => self::TITLES[$kind],
            'record' => $record,
            'stations' => $stations,
            'users' => $users,
            'vehicleTypes' => MasterDataManager::VEHICLE_TYPES,
            'vehicleStatuses' => MasterDataManager::VEHICLE_STATUSES,
            'teamStatuses' => MasterDataManager::TEAM_STATUSES,
        ]);
    }
}
