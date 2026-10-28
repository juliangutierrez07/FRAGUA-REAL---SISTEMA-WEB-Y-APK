<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cierre automático de la caja (27/10/2026, RF-M06-039): el turno que nadie cerró se cierra solo a la hora
// de cierre más la tolerancia, sin contar el efectivo. `cierre_automatico` lo distingue; el conteo
// (`efectivo_contado`) queda vacío hasta que el Administrador lo registre.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('caja_turnos', function (Blueprint $table) {
            $table->boolean('cierre_automatico')->default(false)->after('estado');
        });
    }

    public function down(): void
    {
        Schema::table('caja_turnos', function (Blueprint $table) {
            $table->dropColumn('cierre_automatico');
        });
    }
};
