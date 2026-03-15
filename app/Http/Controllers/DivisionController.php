<?php

namespace App\Http\Controllers;

use App\Http\Resources\DivisionResource;
use App\Models\Division;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DivisionController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $divisions = Division::with(['projects', 'users'])->get();

        return DivisionResource::collection($divisions);
    }

    public function show(Division $division): DivisionResource
    {
        $division->load(['projects', 'users']);

        return new DivisionResource($division);
    }
}
