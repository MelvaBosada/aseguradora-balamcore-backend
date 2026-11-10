<?php

namespace App\Http\Controllers;

use App\Models\Aseguradora;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Nombre y logo de MI aseguradora para los documentos PDF (evaluación, indemnización).
 * El logo viaja ya incrustado (data URI) y reducido: así el PDF generado en el navegador
 * no depende de URLs externas ni de permisos CORS.
 */
class MarcaAseguradoraController extends Controller
{
    public function show(Request $request)
    {
        $aseguradora = Aseguradora::find($request->user()->id_aseguradora);
        abort_if(!$aseguradora, 404, 'Aseguradora no encontrada.');

        return response()->json([
            'nombre'       => $aseguradora->nombre,
            'razon_social' => $aseguradora->razon_social,
            'logo'         => $this->logoComoDataUri($aseguradora->logo),
        ])->header('Cache-Control', 'private, max-age=300');
    }

    private function logoComoDataUri(?string $logo): ?string
    {
        if (!$logo) {
            return null;
        }

        if (str_starts_with($logo, 'data:image/')) {
            return $logo;
        }

        // URL externa: no se puede leer desde aquí; el navegador deberá poder descargarla (CORS)
        if (preg_match('#^https?://#i', $logo)) {
            return $logo;
        }

        foreach (['public', 'local'] as $disco) {
            $almacen = Storage::disk($disco);
            if ($almacen->exists($logo)) {
                return $this->incrustar($almacen->get($logo));
            }
        }

        return null;
    }

    private function incrustar(string $binario): ?string
    {
        // SVG: se manda tal cual
        if (str_contains(substr($binario, 0, 300), '<svg')) {
            return 'data:image/svg+xml;base64,' . base64_encode($binario);
        }

        $img = @imagecreatefromstring($binario);
        if (!$img) {
            return null;
        }

        $w = imagesx($img);
        $h = imagesy($img);
        $f = min(1, 480 / $w, 240 / $h);
        $nw = max(1, (int) round($w * $f));
        $nh = max(1, (int) round($h * $f));

        $salida = imagecreatetruecolor($nw, $nh);
        imagealphablending($salida, false);
        imagesavealpha($salida, true);
        imagefill($salida, 0, 0, imagecolorallocatealpha($salida, 0, 0, 0, 127)); // conserva la transparencia
        imagecopyresampled($salida, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);

        ob_start();
        imagepng($salida);
        $png = ob_get_clean();

        return 'data:image/png;base64,' . base64_encode($png);
    }
}
