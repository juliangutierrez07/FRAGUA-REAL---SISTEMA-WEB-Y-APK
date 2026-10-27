<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Eliminar categorías, combos, extras y promociones (27/10/2026, RF-M04-057 a 060): igual que las mesas,
// los productos y los usuarios (2026_10_31_000001), lo que nunca se usó se borra de verdad y lo que tiene
// historial se archiva y se restaura desde «Eliminados». Estas columnas dicen cuándo, quién y por qué.
return new class extends Migration
{
    private const TABLAS = ['categorias', 'combos', 'extras', 'promociones'];

    public function up(): void
    {
        foreach (self::TABLAS as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->timestamp('eliminado_at')->nullable()->index();
                $table->foreignId('eliminado_por')->nullable()->constrained('users')->nullOnDelete();
                $table->string('eliminado_motivo', 255)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLAS as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->dropConstrainedForeignId('eliminado_por');
                $table->dropIndex(['eliminado_at']);
                $table->dropColumn(['eliminado_at', 'eliminado_motivo']);
            });
        }
    }
};
