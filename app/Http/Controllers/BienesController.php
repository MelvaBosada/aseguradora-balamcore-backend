<?php

namespace App\Http\Controllers;

use App\Models\Bienes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BienesController extends Controller
{
    public function index()
    {
        return response()->json(Bienes::orderBy('id', 'asc')->get());
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id_cliente' => 'required|integer',
            'tipo'       => 'required|string',
            'valor'      => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $bien = Bienes::create($request->all());

            return response()->json([
                'message' => 'Success',
                'data' => $bien
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        $bien = Bienes::find($id);

        if (!$bien) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json($bien);
    }

    public function update(Request $request, $id)
    {
        $bien = Bienes::find($id);

        if (!$bien) {
            return response()->json(['message' => 'Not found'], 404);
        }

        try {
            $bien->update($request->all());

            return response()->json([
                'message' => 'Updated',
                'data' => $bien
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        $bien = Bienes::find($id);

        if (!$bien) {
            return response()->json(['message' => 'Not found'], 404);
        }

        try {
            $bien->delete();
            return response()->json(['message' => 'Deleted']);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}