<?php

namespace App\Exports;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class CatalogoExport implements
    FromQuery,
    WithHeadings,
    WithMapping,
    ShouldAutoSize,
    WithStyles,
    WithTitle,
    WithCustomCsvSettings
{
    public function __construct(
        private Builder $query,
        private array $columnas,   // ['Encabezado' => 'atributo']
        private string $titulo = 'Listado'
    ) {
    }

    public function query()
    {
        return $this->query;
    }

    public function headings(): array
    {
        return array_keys($this->columnas);
    }

    public function map($row): array
    {
        return self::fila($row, $this->columnas);
    }

    /** Convierte un registro en una fila de valores (la usa también el PDF) */
        /** Convierte un registro en una fila de valores (la usa también el PDF) */
    public static function fila($row, array $columnas): array
    {
        return array_map(function ($ruta) use ($row) {
            // 'cliente.nombre+cliente.apellidos' → "Juan Pérez"
            if (str_contains($ruta, '+')) {
                $partes = array_map(
                    fn ($r) => data_get($row, trim($r)),
                    explode('+', $ruta)
                );
                return trim(implode(' ', array_filter(
                    $partes,
                    fn ($p) => $p !== null && $p !== ''
                )));
            }

            $valor = data_get($row, $ruta);

            if ($valor instanceof \DateTimeInterface) {
                return $valor->format('Y-m-d');
            }
            if (is_bool($valor)) {
                return $valor ? 'Sí' : 'No';
            }
            return $valor;
        }, array_values($columnas));
    }
    
    public function styles(Worksheet $sheet)
    {
        return [1 => ['font' => ['bold' => true]]];
    }

    public function title(): string
    {
        // Excel: máximo 31 caracteres y sin \ / ? * [ ] :
        $limpio = preg_replace('/[\\\\\/\?\*\[\]:]/', '', $this->titulo);
        return mb_substr($limpio ?: 'Listado', 0, 31);
    }

    // BOM para que Excel abra el CSV con acentos y ñ bien
    public function getCsvSettings(): array
    {
        return [
            'use_bom'         => true,
            'delimiter'       => ',',
            'output_encoding' => 'UTF-8',
        ];
    }
}