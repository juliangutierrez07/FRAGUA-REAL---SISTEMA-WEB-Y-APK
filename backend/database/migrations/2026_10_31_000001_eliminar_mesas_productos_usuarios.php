<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Eliminar mesas, productos y usuarios (24/10/2026): lo que nunca se usó se borra de verdad; lo que
// tiene historial (pedidos, reservas, ventas, acciones en la auditoría) se archiva: queda fuera de
// todas las listas y se puede restaurar desde «Eliminados», pero los reportes, los comprobantes y la
// auditoría lo siguen nombrando. Estas columnas dicen cuándo, quién y por qué se archivó.
return new class extends Migration
{
    private const TABLAS = ['mesas', 'productos', 'users'];

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
