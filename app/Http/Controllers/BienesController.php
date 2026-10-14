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
    return response()->json(
        Bienes::orderBy('id', 'asc')->get()
    );
}

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id_cliente' => 'required|integer',
            'tipo'       => 'required|string',
            'valor'      => 'required|numeric',
            'foto'       => 'nullable|image|max:5120', // máx 5MB
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $data = $request->except('foto');

            if ($request->hasFile('foto')) {
                $file = $request->file('foto');
                $data['foto']         = base64_encode(file_get_contents($file->getRealPath()));
                $data['foto_nombre']  = $file->getClientOriginalName();
                $data['foto_tipo']    = $file->getMimeType();
                $data['foto_tamanio'] = $file->getSize();
            }

            $bien = Bienes::create($data);

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

        $validator = Validator::make($request->all(), [
            'foto' => 'nullable|image|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $data = $request->except('foto');

            if ($request->hasFile('foto')) {
                $file = $request->file('foto');
                $data['foto']         = base64_encode(file_get_contents($file->getRealPath()));
                $data['foto_nombre']  = $file->getClientOriginalName();
                $data['foto_tipo']    = $file->getMimeType();
                $data['foto_tamanio'] = $file->getSize();
            }

            $bien->update($data);

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

    // Borra solo la foto, sin eliminar el bien completo
    public function destroyFoto($id)
    {
        $bien = Bienes::find($id);

        if (!$bien) {
            return response()->json(['message' => 'Not found'], 404);
        }

        try {
            $bien->update([
                'foto' => null,
                'foto_nombre' => null,
                'foto_tipo' => null,
                'foto_tamanio' => null,
            ]);

            return response()->json(['message' => 'Foto eliminada']);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getByRfcCliente(Request $request)
    {
        try {
            $request->validate(['rfc' => 'required|string|max:13']);

            $cliente = Cliente::where('rfc', $request->rfc)->first();

            if (!$cliente) {
                return response()->json(['message' => 'Cliente no encontrado'], 404);
            }

            $bienes = Bienes::where('id_cliente', $cliente->id)
                ->where(function($query) {
                    $query->whereNull('id_poliza')
                          ->orWhere('id_poliza', 0);
                })
                ->get()
                ->makeHidden(['foto', 'foto_url']);

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

            $bienes = Bienes::where('id_cliente', $clienteId)
                ->get()
                ->makeHidden(['foto', 'foto_url']);

            return response()->json($bienes);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }
}