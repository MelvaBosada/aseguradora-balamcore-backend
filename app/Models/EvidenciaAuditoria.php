<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use LogicException;

/**
 * Bitácora inmutable: solo se pueden crear registros.
 * (En PostgreSQL además hay un trigger que bloquea UPDATE/DELETE.)
 */
class EvidenciaAuditoria extends Model
{
    protected $table = 'evidencia_auditoria';

    public const UPDATED_AT = null;

    protected $fillable = [
        'id_evidencia',
        'id_usuario',
        'accion',
        'datos_antes',
        'datos_despues',
        'ip',
        'user_agent',
    ];

    protected $casts = [
        'datos_antes' => 'array',
        'datos_despues' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('La bitácora de auditoría es inmutable.'));
        static::deleting(fn () => throw new LogicException('La bitácora de auditoría es inmutable.'));
    }

    public static function registrar(
        Request $request,
        int $idEvidencia,
        string $accion,
        ?array $antes = null,
        ?array $despues = null
    ): self {
        return static::create([
            'id_evidencia' => $idEvidencia,
            'id_usuario' => $request->user()?->id,
            'accion' => $accion,
            'datos_antes' => $antes,
            'datos_despues' => $despues,
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);
    }
}