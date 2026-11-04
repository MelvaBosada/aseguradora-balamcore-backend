<?php

namespace App\Http\Controllers;

use App\Models\Evidencias;
use App\Models\Siniestros; // <-- ajusta al nombre real de tu modelo de siniestros
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\TblWidth;

/**
 * Reporte fotográfico por siniestro.
 *
 *  GET  /evidencias/reporte/buscar?numero=1519530
 *  POST /evidencias/reporte/generar  { id_siniestro, formato: pdf|docx, fotos_por_hoja: 2|4|6, ids: [] }
 *
 * composer require barryvdh/laravel-dompdf phpoffice/phpword
 * (requiere extensiones PHP: gd, zip, xml; exif es opcional pero recomendada)
 */
class ReporteEvidenciasController extends Controller
{
    private const MIMES_IMAGEN = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /**
     * Cajas por foto en puntos (letter útil ≈ 522 x 612 pt).
     * Equivalen a tus macros: 2 por hoja (1 columna), 4 (2x2) y 6 (2x3).
     */
    private const LAYOUTS = [
        2 => ['cols' => 1, 'w' => 470, 'h' => 235, 'pie' => 90],
        4 => ['cols' => 2, 'w' => 245, 'h' => 250, 'pie' => 50],
        6 => ['cols' => 2, 'w' => 245, 'h' => 158, 'pie' => 50],
    ];

    /** GET /evidencias/reporte/buscar */
    public function buscar(Request $request): JsonResponse
    {
        $numero = trim((string) $request->input('numero'));

        if (mb_strlen($numero) < 2) {
            return response()->json(['message' => 'Escribe al menos 2 caracteres del número de siniestro.'], 422);
        }

        $siniestro = Siniestros::where('numero_siniestro', $numero)->first();

        if (!$siniestro) {
            $like = addcslashes($numero, '%_\\');
            $coincidencias = Siniestros::where('numero_siniestro', 'ILIKE', "%{$like}%")->limit(10)->get();

            if ($coincidencias->isEmpty()) {
                return response()->json(['message' => "No se encontró ningún siniestro con \"{$numero}\"."], 404);
            }

            if ($coincidencias->count() > 1) {
                return response()->json([
                    'coincidencias' => $coincidencias->map(fn ($s) => [
                        'id' => $s->id,
                        'numero_siniestro' => $s->numero_siniestro,
                        'cliente' => $s->cliente ?? null,
                    ])->values(),
                ]);
            }

            $siniestro = $coincidencias->first();
        }

        $fotos = $this->fotosDe($siniestro->id)->get([
            'id', 'nombre_archivo', 'ruta_archivo', 'mime_type', 'tipo',
            'estatus_validacion', 'descripcion', 'fecha_subida',
        ]);

        $otros = Evidencias::where('id_siniestro', $siniestro->id)->count() - $fotos->count();

        return response()->json([
            'siniestro' => [
                'id' => $siniestro->id,
                'numero_siniestro' => $siniestro->numero_siniestro,
                'cliente' => $siniestro->cliente ?? null,
            ],
            'evidencias' => $fotos,
            'otros_archivos' => max(0, $otros), // pdf, videos, rechazadas, etc. (no entran al reporte)
        ]);
    }

    /** POST /evidencias/reporte/generar */
    public function generar(Request $request)
    {
        $data = $request->validate([
            'id_siniestro' => ['required', 'integer', 'exists:siniestros,id'],
            'formato' => ['required', 'in:pdf,docx'],
            'fotos_por_hoja' => ['required', 'in:2,4,6'],
            'ids' => ['required', 'array', 'min:1', 'max:300'],
            'ids.*' => ['integer'],
        ]);

        @set_time_limit(300);
        @ini_set('memory_limit', '768M');

        $siniestro = Siniestros::findOrFail($data['id_siniestro']);
        $porHoja = (int) $data['fotos_por_hoja'];
        $lay = self::LAYOUTS[$porHoja];

        $evidencias = $this->fotosDe($siniestro->id)->whereIn('id', $data['ids'])->get();

        $fotos = [];
        foreach ($evidencias as $e) {
            $img = $this->desdeStorage($e->ruta_archivo, $lay['w'], $lay['h']);
            if (!$img) {
                continue; // archivo faltante o dañado
            }
            $n = count($fotos) + 1;
            $img['pie'] = $this->pie($n, $e->descripcion, $lay['pie']);
            $fotos[] = $img;
        }

        if (!$fotos) {
            return response()->json(['message' => 'No se pudo leer ninguna de las fotos seleccionadas.'], 422);
        }

        $aseg = $siniestro->aseguradora ?? null; // relación u columna de texto
        $nombreAseg = is_object($aseg) ? ($aseg->nombre ?? null) : (is_string($aseg) ? $aseg : null);
        $logoRuta = is_object($aseg) ? ($aseg->logo ?? null) : null;

        $ctx = [
            'numero' => $siniestro->numero_siniestro,
            'aseguradora' => $nombreAseg,
            'logo' => $logoRuta ? $this->desdeStorage($logoRuta, 150, 48) : null,
            'marca' => $this->desdeArchivo(public_path('img/balamcore.png'), 330, 330),
            'datos' => $this->datosCabecera($siniestro, $nombreAseg, count($fotos)),
            'paginas' => array_chunk($fotos, $porHoja),
            'lay' => $lay,
        ];

        $base = 'Reporte_fotografico_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $siniestro->numero_siniestro);

        return $data['formato'] === 'pdf'
            ? $this->crearPdf($ctx, "{$base}.pdf")
            : $this->crearDocx($ctx, "{$base}.docx");
    }

    // ------------------------------------------------------------------
    // PDF
    // ------------------------------------------------------------------
    private function crearPdf(array $ctx, string $nombre)
    {
        $uri = fn (?array $i) => $i ? 'data:image/jpeg;base64,' . base64_encode($i['bin']) : null;

        foreach ($ctx['paginas'] as &$pagina) {
            foreach ($pagina as &$f) {
                $f['uri'] = $uri($f);
                unset($f['bin']); // libera memoria
            }
        }
        unset($pagina, $f);

        foreach (['logo', 'marca'] as $k) {
            if ($ctx[$k]) {
                $ctx[$k]['uri'] = $uri($ctx[$k]);
                unset($ctx[$k]['bin']);
            }
        }

        return Pdf::loadView('reportes.evidencias', $ctx)
            ->setPaper('letter', 'portrait')
            ->download($nombre);
    }

    // ------------------------------------------------------------------
    // Word
    // ------------------------------------------------------------------
    private function crearDocx(array $ctx, string $nombre)
    {
        $tmps = [];
        $aTmp = function (array $img) use (&$tmps): string {
            $ruta = sys_get_temp_dir() . '/' . Str::uuid() . '.jpg';
            file_put_contents($ruta, $img['bin']);
            $tmps[] = $ruta;
            return $ruta;
        };

        $lay = $ctx['lay'];
        $verde = '1B6B45';

        $word = new PhpWord();
        $word->setDefaultFontName('Arial');
        $word->setDefaultFontSize(9);

        $sec = $word->addSection([
            'pageSizeW' => Converter::inchToTwip(8.5),
            'pageSizeH' => Converter::inchToTwip(11),
            'marginTop' => Converter::pointToTwip(112),
            'marginBottom' => Converter::pointToTwip(60),
            'marginLeft' => Converter::pointToTwip(45),
            'marginRight' => Converter::pointToTwip(45),
            'headerHeight' => Converter::pointToTwip(20),
            'footerHeight' => Converter::pointToTwip(20),
        ]);

        // ---- Encabezado: logo + aseguradora a la izquierda, datos a la derecha
        $header = $sec->addHeader();

        if ($ctx['marca']) {
            // Si en tu Word no se ve centrada, ajusta marginTop/marginLeft aquí.
            $header->addWatermark($aTmp($ctx['marca']), [
                'width' => $ctx['marca']['w'],
                'height' => $ctx['marca']['h'],
                'posHorizontal' => 'center',
                'posHorizontalRel' => 'margin',
                'posVertical' => 'center',
                'posVerticalRel' => 'margin',
            ]);
        }

        $t = $header->addTable([
            'width' => 5000,
            'unit' => TblWidth::PERCENT,
            'borderBottomSize' => 12,
            'borderBottomColor' => $verde,
            'cellMargin' => 40,
        ]);
        $t->addRow();

        $izq = $t->addCell(Converter::pointToTwip(215), ['valign' => 'center']);
        if ($ctx['logo']) {
            $izq->addImage($aTmp($ctx['logo']), ['width' => $ctx['logo']['w'], 'height' => $ctx['logo']['h']]);
        }
        if ($ctx['aseguradora']) {
            $izq->addText($ctx['aseguradora'], ['bold' => true, 'size' => 12, 'color' => $verde], ['spaceBefore' => 60]);
        }

        $der = $t->addCell(Converter::pointToTwip(307), ['valign' => 'center']);
        $d = $der->addTable(['cellMargin' => 20]);
        foreach ($ctx['datos'] as $label => $valor) {
            $d->addRow();
            $d->addCell(Converter::pointToTwip(100))
                ->addText($label, ['bold' => true, 'size' => 8.5, 'color' => '666666'], ['spaceAfter' => 0]);
            $d->addCell(Converter::pointToTwip(200))
                ->addText((string) $valor, ['size' => 8.5], ['spaceAfter' => 0]);
        }

        // ---- Pie: BalamCore + número de página
        $footer = $sec->addFooter();
        $ft = $footer->addTable(['width' => 5000, 'unit' => TblWidth::PERCENT, 'borderTopSize' => 4, 'borderTopColor' => 'CCCCCC']);
        $ft->addRow();
        $ft->addCell(Converter::pointToTwip(380))->addText(
            "BalamCore · Reporte fotográfico · Siniestro {$ctx['numero']}",
            ['size' => 7.5, 'color' => '888888'],
            ['spaceBefore' => 60]
        );
        $ft->addCell(Converter::pointToTwip(142))->addPreserveText(
            'Pág. {PAGE} de {NUMPAGES}',
            ['size' => 7.5, 'color' => '888888'],
            ['alignment' => 'right', 'spaceBefore' => 60]
        );

        // ---- Fotos
        $anchoCelda = Converter::pointToTwip($lay['w'] + 10);

        foreach ($ctx['paginas'] as $i => $pagina) {
            if ($i > 0) {
                $sec->addPageBreak();
            }

            $tabla = $sec->addTable(['alignment' => Jc::CENTER, 'cellMargin' => 50]);

            foreach (array_chunk($pagina, $lay['cols']) as $fila) {
                $tabla->addRow(Converter::pointToTwip($lay['h'] + 6));
                foreach ($fila as $f) {
                    $tabla->addCell($anchoCelda, ['valign' => 'center'])->addImage($aTmp($f), [
                        'width' => $f['w'],
                        'height' => $f['h'],
                        'alignment' => 'center',
                    ]);
                }
                for ($c = count($fila); $c < $lay['cols']; $c++) {
                    $tabla->addCell($anchoCelda);
                }

                $tabla->addRow();
                foreach ($fila as $f) {
                    $tabla->addCell($anchoCelda)->addText(
                        $f['pie'],
                        ['size' => 8, 'color' => '555555'],
                        ['alignment' => 'center', 'spaceAfter' => 120]
                    );
                }
                for ($c = count($fila); $c < $lay['cols']; $c++) {
                    $tabla->addCell($anchoCelda);
                }
            }
        }

        $salida = sys_get_temp_dir() . '/' . Str::uuid() . '.docx';
        IOFactory::createWriter($word, 'Word2007')->save($salida);

        foreach ($tmps as $t) {
            @unlink($t);
        }

        return response()->download($salida, $nombre)->deleteFileAfterSend(true);
    }

    // ------------------------------------------------------------------
    // Utilidades
    // ------------------------------------------------------------------

    /** Fotos del siniestro: solo imágenes soportadas y sin rechazadas. */
    private function fotosDe(int $idSiniestro)
    {
        return Evidencias::where('id_siniestro', $idSiniestro)
            ->whereIn('mime_type', self::MIMES_IMAGEN)
            ->where(fn ($q) => $q->whereNull('estatus_validacion')->orWhere('estatus_validacion', '!=', 'rechazada'))
            ->orderBy('fecha_subida')
            ->orderBy('id');
    }

    /** Datos del recuadro de encabezado (se omiten los vacíos). */
    private function datosCabecera($s, ?string $nombreAseg, int $total): array
    {
        $fecha = data_get($s, 'fecha_siniestro');
        try {
            $fecha = $fecha ? \Carbon\Carbon::parse($fecha)->format('d/m/Y') : null;
        } catch (\Throwable) {
            $fecha = null;
        }

        $datos = [
            'Siniestro' => $s->numero_siniestro,
            'Asegurado' => $s->cliente ?? data_get($s, 'asegurado'),
            'Ref. ' . ($nombreAseg ?: 'aseguradora') => data_get($s, 'referencia') ?? data_get($s, 'ref_aseguradora'),
            'Fecha del siniestro' => $fecha,
            'Fotografías' => $total,
            'Fecha del reporte' => now()->format('d/m/Y'),
        ];

        return array_filter($datos, fn ($v) => $v !== null && $v !== '');
    }

    private function pie(int $n, ?string $descripcion, int $limite): string
    {
        $texto = "Foto {$n}";
        $desc = trim(preg_replace('/\s+/', ' ', (string) $descripcion));
        return $desc !== '' ? $texto . ' — ' . Str::limit($desc, $limite) : $texto;
    }

    private function desdeStorage(string $ruta, float $w, float $h): ?array
    {
        $disk = Storage::disk('public');
        return $disk->exists($ruta) ? $this->procesar($disk->path($ruta), $w, $h) : null;
    }

    private function desdeArchivo(string $abs, float $w, float $h): ?array
    {
        return $this->procesar($abs, $w, $h);
    }

    /**
     * Abre la imagen, corrige la orientación EXIF, la reduce a ~180 dpi
     * y devuelve JPEG + tamaño (en puntos) ajustado a la caja sin deformarla.
     */
    private function procesar(string $abs, float $boxW, float $boxH): ?array
    {
        if (!is_file($abs)) {
            return null;
        }

        $src = @imagecreatefromstring((string) file_get_contents($abs));
        if (!$src) {
            return null;
        }

        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        if (function_exists('exif_read_data') && in_array($ext, ['jpg', 'jpeg'], true)) {
            $exif = @exif_read_data($abs);
            $giro = match ($exif['Orientation'] ?? 1) {
                3 => 180,
                6 => -90,
                8 => 90,
                default => 0,
            };
            if ($giro) {
                $rot = imagerotate($src, $giro, 0);
                if ($rot) {
                    $src = $rot;
                }
            }
        }

        $w = imagesx($src);
        $h = imagesy($src);

        $r = min($boxW / $w, $boxH / $h);          // puntos por pixel original
        $f = min(1, $r * 2.5);                      // ~180 dpi de salida
        $nw = max(1, (int) round($w * $f));
        $nh = max(1, (int) round($h * $f));

        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // PNG transparente -> fondo blanco
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        ob_start();
        imagejpeg($dst, null, 82);
        $bin = ob_get_clean();

        unset($src, $dst);

        return ['bin' => $bin, 'w' => round($w * $r, 1), 'h' => round($h * $r, 1)];
    }
}
