<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Catálogo inicial de documentos por tipo de siniestro. Cada aseguradora lo puede
 * cambiar después (agregar, quitar, volver opcional un documento, cambiar plazos).
 * Formato: [nombre, descripción, obligatorio]
 */
class CatalogoDocumentosBase
{
    public const ITEMS = [
        'todos' => [
            ['Identificación oficial vigente', 'INE, pasaporte o cédula profesional del asegurado.', true],
        ],
        'robo' => [
            ['Denuncia ante la autoridad', 'Copia de la denuncia o acta ministerial.', true],
            ['Comprobante de propiedad', 'Factura, nota de compra o documento que acredite el bien.', true],
        ],
        'accidente' => [
            ['Informe o parte de la autoridad', 'Parte de tránsito, informe policial o reporte equivalente.', true],
        ],
        'incendio' => [
            ['Dictamen de bomberos o protección civil', 'Informe de la autoridad que atendió el incendio.', true],
            ['Comprobante de propiedad', 'Escritura, factura o documento que acredite el bien.', true],
        ],
        'inundacion' => [
            ['Comprobante de propiedad o domicilio', 'Escritura, contrato o recibo del inmueble afectado.', true],
        ],
        'responsabilidad_civil' => [
            ['Reclamación del tercero afectado', 'Escrito o documento con el que el tercero reclama el daño.', true],
            ['Identificación del tercero afectado', 'Identificación oficial del tercero.', true],
        ],
        'fallecimiento' => [
            ['Acta de defunción', 'Copia certificada del acta de defunción.', true],
            ['Identificación del beneficiario', 'Identificación oficial vigente del beneficiario.', true],
        ],
        'invalidez' => [
            ['Dictamen médico de invalidez', 'Dictamen emitido por institución o médico certificado.', true],
        ],
        'otro' => [
            ['Escrito describiendo el hecho', 'Relato firmado por el asegurado.', true],
        ],
    ];

    /** Inserta lo que falte; se puede llamar varias veces sin duplicar. */
    public static function sembrar(int $idAseguradora): void
    {
        $orden = 0;
        $filas = [];

        foreach (self::ITEMS as $tipo => $docs) {
            foreach ($docs as [$nombre, $descripcion, $obligatorio]) {
                $filas[] = [
                    'id_aseguradora' => $idAseguradora,
                    'tipo_siniestro' => $tipo,
                    'nombre'         => $nombre,
                    'descripcion'    => $descripcion,
                    'obligatorio'    => $obligatorio,
                    'activo'         => true,
                    'orden'          => ++$orden,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ];
            }
        }

        DB::table('catalogo_documentos')->insertOrIgnore($filas);
    }
}
