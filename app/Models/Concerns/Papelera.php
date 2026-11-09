<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Papelera = SoftDeletes + guardar quién mandó el registro a la papelera.
 * Requiere las columnas deleted_at y deleted_by (migración de la papelera).
 */
trait Papelera
{
    use SoftDeletes;

    /** Manda el registro a la papelera y guarda el usuario que lo hizo. */
    public function enviarAPapelera(): bool
    {
        $this->deleted_by = auth()->id();
        $this->saveQuietly();

        return (bool) $this->delete();
    }

    /** Saca el registro de la papelera y limpia quién lo había borrado. */
    public function sacarDePapelera(): bool
    {
        $this->deleted_by = null;

        return (bool) $this->restore();
    }
}
