<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Eliminar sin «Eliminados» (27/10/2026, RN-M04-32): lo eliminado ya no se restaura, así que deja libre su
// nombre (o su correo, o su nombre de usuario) para crear otro igual. Lo que tenía historial sigue en la
// base, fuera de todas las listas, para que los pedidos, los reportes y la auditoría lo sigan nombrando;
// por eso los índices únicos pasan a contar solo lo vigente (`eliminado_at` nulo). PostgreSQL y SQLite
// aceptan índices únicos parciales.
return new class extends Migration
{
    /** tabla => [columna, índice viejo, índice nuevo] */
    private const INDICES = [
        ['productos', 'nombre', 'productos_nombre_unique', 'productos_nombre_vigente_unique'],
        ['categorias', 'nombre', 'categorias_nombre_unique', 'categorias_nombre_vigente_unique'],
        ['mesas', 'nombre', 'mesas_nombre_unique', 'mesas_nombre_vigente_unique'],
        ['users', 'email', 'users_email_unique', 'users_email_vigente_unique'],
        ['users', 'username', 'users_username_unique', 'users_username_vigente_unique'],
    ];

    public function up(): void
    {
        foreach (self::INDICES as [$tabla, $columna, $viejo, $nuevo]) {
            Schema::table($tabla, fn ($table) => $table->dropUnique($viejo));
            DB::statement("CREATE UNIQUE INDEX {$nuevo} ON {$tabla} ({$columna}) WHERE eliminado_at IS NULL");
        }
    }

    public function down(): void
    {
        foreach (self::INDICES as [$tabla, $columna, $viejo, $nuevo]) {
            DB::statement("DROP INDEX {$nuevo}");
            Schema::table($tabla, fn ($table) => $table->unique($columna, $viejo));
        }
    }
};
