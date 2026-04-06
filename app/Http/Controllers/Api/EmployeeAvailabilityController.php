<?php

namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployeeAvailabilityRequest;
use App\Http\Requests\UpdateEmployeeAvailabilityRequest;
use App\Services\Api\EmployeeAvailabilityService;
 class EmployeeAvailabilityController extends Controller
{
    public function __construct(
        protected EmployeeAvailabilityService $service
    ) {}

    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => $this->service->getAll()
        ]);
    }

    public function store(StoreEmployeeAvailabilityRequest $request)
    {
        return response()->json([
            'success' => true,
            'data' => $this->service->create($request->validated())
        ], 201);
    }

    public function show($id)
    {
        return response()->json([
            'success' => true,
            'data' => $this->service->getById($id)
        ]);
    }

    public function update(UpdateEmployeeAvailabilityRequest $request, $id)
    {
        $availability = $this->service->getById($id);

        return response()->json([
            'success' => true,
            'data' => $this->service->update($availability, $request->validated())
        ]);
    }

    public function destroy($id)
    {
        $availability = $this->service->getById($id);

        $this->service->delete($availability);

        return response()->json([
            'success' => true
        ]);
    }
}