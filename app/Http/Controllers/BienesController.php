<?php

namespace App\Http\Controllers;

use App\Models\Bienes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Cliente;

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

    // app/Http/Controllers/BienesController.php

public function getByRfcCliente(Request $request)
{
    try {
        $request->validate(['rfc' => 'required|string|max:13']);
        
        // Buscar cliente por RFC
        $cliente = Cliente::where('rfc', $request->rfc)->first();
        
        if (!$cliente) {
            return response()->json(['message' => 'Cliente no encontrado'], 404);
        }
        
        // Obtener bienes del cliente que NO están asociados a ninguna póliza
        $bienes = Bienes::where('id_cliente', $cliente->id)
            ->where(function($query) {
                $query->whereNull('id_poliza')
                      ->orWhere('id_poliza', 0);
            })
            ->get();
        
        return response()->json([
            'cliente_id' => $cliente->id,
            'cliente_nombre' => $cliente->nombre . ' ' . $cliente->apellidos,
            'bienes' => $bienes
        ]);
        
    } catch (\Exception $e) {
        return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
    }
}

public function getByClienteId($clienteId)
{
    try {
        $cliente = Cliente::find($clienteId);
        
        if (!$cliente) {
            return response()->json(['message' => 'Cliente no encontrado'], 404);
        }
        
        // Mostrar TODOS los bienes del cliente, sin filtrar por id_poliza
        $bienes = Bienes::where('id_cliente', $clienteId)->get();
        
        return response()->json($bienes);
        
    } catch (\Exception $e) {
        return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
    }
}
}